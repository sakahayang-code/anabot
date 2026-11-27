<?php
declare(strict_types=1);

namespace AnaBot\Services;

/**
 * Normalizes external spreadsheet data into standardized SQLite representations.
 * Handles data type inference, currency cleansing, date standardizing, and role classification.
 */
class DataNormalizer
{
    /**
     * Sanitize header name into clean snake_case column identifier.
     */
    public static function sanitizeColumnName(string $name, int $index = 0): string
    {
        $clean = trim($name);
        if ($clean === '') {
            return 'col_' . ($index + 1);
        }

        // Convert to lowercase and replace non-alphanumeric with underscore
        $clean = strtolower($clean);
        $clean = preg_replace('/[^a-z0-9_]+/i', '_', $clean);
        $clean = trim((string) $clean, '_');

        // Prevent empty string or starting with a number
        if ($clean === '' || is_numeric($clean[0])) {
            $clean = 'col_' . $clean;
        }

        return substr($clean, 0, 64);
    }

    /**
     * Infer SQL column data type based on a sample set of values.
     *
     * @param array $values List of sample raw values from the column
     * @return string 'INTEGER' | 'NUMERIC' | 'DATETIME' | 'BOOLEAN' | 'TEXT'
     */
    public static function inferColumnType(array $values): string
    {
        $nonEmptyValues = array_values(array_filter($values, fn($v) => $v !== null && trim((string) $v) !== ''));

        if (empty($nonEmptyValues)) {
            return 'TEXT';
        }

        $boolCount = 0;
        $intCount = 0;
        $numCount = 0;
        $dateCount = 0;
        $total = count($nonEmptyValues);

        foreach ($nonEmptyValues as $val) {
            $valStr = trim((string) $val);

            // Boolean check
            if (in_array(strtolower($valStr), ['true', 'false', 'ya', 'tidak', 'yes', 'no', '1', '0'], true)) {
                $boolCount++;
            }

            // Integer check (pure integer string)
            if (preg_match('/^-?\d+$/', $valStr)) {
                $intCount++;
                $numCount++;
            } elseif (self::isNumericOrCurrency($valStr)) {
                $numCount++;
            }

            // Date / Timestamp check
            if (self::isDateString($valStr)) {
                $dateCount++;
            }
        }

        // If >= 80% match a specific type, classify accordingly
        if ($dateCount / $total >= 0.8) {
            return 'DATETIME';
        }
        if ($intCount / $total >= 0.8) {
            return 'INTEGER';
        }
        if ($numCount / $total >= 0.8) {
            return 'NUMERIC';
        }
        if ($boolCount / $total >= 0.8) {
            return 'BOOLEAN';
        }

        return 'TEXT';
    }

    /**
     * Check if a value represents a number or formatted currency (e.g. $1,200.50 or Rp 150.000).
     */
    public static function isNumericOrCurrency(string $val): bool
    {
        $cleaned = self::cleanCurrency($val);
        return is_numeric($cleaned);
    }

    /**
     * Clean formatted currency strings to standard numeric string.
     * Handles:
     * - $1,250.50 -> 1250.50
     * - Rp 150.000 -> 150000
     * - 1.250,50 -> 1250.50
     * - 15% -> 0.15
     */
    public static function cleanCurrency(string $val): string
    {
        $clean = trim($val);
        // Remove currency symbols and non-numeric except dots, commas, minus, percent
        $clean = preg_replace('/[^\d.,\-%]/', '', $clean);

        if (str_ends_with($clean, '%')) {
            $num = rtrim($clean, '%');
            return is_numeric($num) ? (string) ((float) $num / 100) : $clean;
        }

        // Handle European format: 1.250,50 -> 1250.50
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)$/', $clean)) {
            // US format: 1,250.50 -> 1250.50
            $clean = str_replace(',', '', $clean);
        } elseif (str_contains($clean, ',') && !str_contains($clean, '.')) {
            // Indonesian format with comma decimal or thousands: 150,00 -> 150.00 or 150,000 -> 150000
            if (preg_match('/,\d{3}$/', $clean)) {
                $clean = str_replace(',', '', $clean);
            } else {
                $clean = str_replace(',', '.', $clean);
            }
        } elseif (str_contains($clean, '.') && !str_contains($clean, ',')) {
            // Check if dot is thousands separator: 150.000 -> 150000
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $clean)) {
                $clean = str_replace('.', '', $clean);
            }
        }

        return $clean;
    }

    /**
     * Check if string can be parsed as a valid calendar date or datetime.
     */
    public static function isDateString(string $val): bool
    {
        // Don't treat standalone small numbers or phone numbers as dates
        if (is_numeric($val) && strlen($val) < 8) {
            return false;
        }

        // Common date formats: YYYY-MM-DD, DD/MM/YYYY, YYYY/MM/DD, DD-MM-YYYY
        if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $val) ||
            preg_match('/^\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}/', $val)) {
            $ts = strtotime($val);
            return $ts !== false && $ts > 0;
        }

        $ts = strtotime($val);
        return $ts !== false && $ts > 946684800; // After year 2000
    }

    /**
     * Standardize a date string to ISO-8601 (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS).
     */
    public static function normalizeDate(string $val): string
    {
        $ts = strtotime($val);
        if ($ts === false) {
            return $val;
        }

        if (str_contains($val, ':')) {
            return date('c', $ts);
        }

        return date('Y-m-d', $ts);
    }

    /**
     * Classify column roles based on its name and inferred data type.
     *
     * @return array ['is_primary_date' => bool, 'is_metric' => bool, 'is_dimension' => bool]
     */
    public static function classifyColumnRole(string $columnName, string $dataType, bool $hasPrimaryDate = false): array
    {
        $lower = strtolower($columnName);

        // Date detection
        $isDate = $dataType === 'DATETIME' ||
                  str_contains($lower, 'date') ||
                  str_contains($lower, 'tanggal') ||
                  str_contains($lower, 'created_at') ||
                  str_contains($lower, 'timestamp') ||
                  str_contains($lower, 'waktu');

        $isPrimaryDate = $isDate && !$hasPrimaryDate;

        // Metric detection
        $isMetric = ($dataType === 'INTEGER' || $dataType === 'NUMERIC') &&
                    !str_contains($lower, 'id') &&
                    !str_contains($lower, 'code') &&
                    !str_contains($lower, 'zip') &&
                    !str_contains($lower, 'phone') &&
                    !str_contains($lower, 'telepon');

        // Dimension detection
        $isDimension = !$isPrimaryDate && !$isMetric;

        return [
            'is_primary_date' => $isPrimaryDate ? 1 : 0,
            'is_metric' => $isMetric ? 1 : 0,
            'is_dimension' => $isDimension ? 1 : 0,
        ];
    }
}
