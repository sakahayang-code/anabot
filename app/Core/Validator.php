<?php
declare(strict_types=1);

namespace AnaBot\Core;

/**
 * AnaBot Secure Input Validator
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->validate();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        if (empty($this->errors)) {
            return null;
        }
        $firstKey = array_key_first($this->errors);
        return $this->errors[$firstKey][0] ?? null;
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $fieldRules) {
            $rulesList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $this->data[$field] ?? null;

            foreach ($rulesList as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                } else {
                    $ruleName = $rule;
                }

                $this->applyRule($field, $value, $ruleName, $params);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule, array $params): void
    {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $this->addError($field, "Bidang {$field} wajib diisi.");
                }
                break;

            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "Format email {$field} tidak valid.");
                }
                break;

            case 'min':
                $min = (int) ($params[0] ?? 0);
                if (is_numeric($value) && $value < $min) {
                    $this->addError($field, "Nilai {$field} minimal adalah {$min}.");
                } elseif (is_string($value) && mb_strlen($value) < $min) {
                    $this->addError($field, "Panjang {$field} minimal adalah {$min} karakter.");
                }
                break;

            case 'max':
                $max = (int) ($params[0] ?? 0);
                if (is_numeric($value) && $value > $max) {
                    $this->addError($field, "Nilai {$field} maksimal adalah {$max}.");
                } elseif (is_string($value) && mb_strlen($value) > $max) {
                    $this->addError($field, "Panjang {$field} maksimal adalah {$max} karakter.");
                }
                break;

            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->addError($field, "Bidang {$field} harus berupa angka.");
                }
                break;

            case 'integer':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, "Bidang {$field} harus berupa bilangan bulat.");
                }
                break;

            case 'in':
                if (!empty($value) && !in_array((string) $value, $params, true)) {
                    $this->addError($field, "Pilihan pada {$field} tidak valid.");
                }
                break;

            case 'csrf':
                $sessionToken = $_SESSION['_csrf_token'] ?? '';
                if (empty($sessionToken) || !is_string($value) || !hash_equals($sessionToken, $value)) {
                    $this->addError($field, "Token CSRF tidak valid atau sesi telah berakhir.");
                }
                break;

            case 'same':
                $otherField = $params[0] ?? '';
                $otherValue = $this->data[$otherField] ?? null;
                if ($value !== $otherValue) {
                    $this->addError($field, "Bidang {$field} harus sama dengan {$otherField}.");
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
