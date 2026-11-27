<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AnaBot ScheduledTask Model
 * 
 * Manages automated scheduled jobs and provides pure PHP cron expression
 * evaluation without external dependencies.
 */
class ScheduledTask
{
    private const ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    /**
     * Create a new scheduled task.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $cron = self::normalizeCronExpression($data['cron_expression'] ?? '0 * * * *');
        $now = new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'Asia/Jakarta')));
        $nextRun = self::calculateNextRun($cron, $now);

        $db->execute(
            "INSERT INTO scheduled_tasks (task_type, task_name, target_id, cron_expression, is_active, last_run_at, next_run_at, created_at)
             VALUES (:type, :name, :target, :cron, :active, NULL, :next, :created)",
            [
                ':type'    => (string) $data['task_type'],
                ':name'    => (string) $data['task_name'],
                ':target'  => isset($data['target_id']) ? (int) $data['target_id'] : null,
                ':cron'    => $cron,
                ':active'  => isset($data['is_active']) ? (int) $data['is_active'] : 1,
                ':next'    => $nextRun,
                ':created' => $now->format('c'),
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Find scheduled task by ID.
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM scheduled_tasks WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    /**
     * Get all scheduled tasks.
     */
    public static function getAll(): array
    {
        $db = Database::getInstance();
        return $db->queryAll("SELECT * FROM scheduled_tasks ORDER BY is_active DESC, task_type ASC, id ASC");
    }

    /**
     * Get active scheduled tasks.
     */
    public static function getActive(): array
    {
        $db = Database::getInstance();
        return $db->queryAll("SELECT * FROM scheduled_tasks WHERE is_active = 1 ORDER BY id ASC");
    }

    /**
     * Get tasks that are currently due for execution.
     */
    public static function getDueTasks(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'Asia/Jakarta')));
        $activeTasks = self::getActive();
        $due = [];

        foreach ($activeTasks as $task) {
            if (self::isDue($task['cron_expression'], $task['last_run_at'], $now)) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * Update task execution timestamps after running.
     */
    public static function updateRunTimes(int $id, ?DateTimeImmutable $runTime = null): void
    {
        $db = Database::getInstance();
        $task = self::find($id);
        if (!$task) {
            return;
        }

        $now = $runTime ?? new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'Asia/Jakarta')));
        $nextRun = self::calculateNextRun($task['cron_expression'], $now);

        $db->execute(
            "UPDATE scheduled_tasks 
             SET last_run_at = :last, next_run_at = :next 
             WHERE id = :id",
            [
                ':last' => $now->format('c'),
                ':next' => $nextRun,
                ':id'   => $id,
            ]
        );
    }

    /**
     * Toggle active state of a scheduled task.
     */
    public static function toggleActive(int $id): bool
    {
        $db = Database::getInstance();
        $task = self::find($id);
        if (!$task) {
            return false;
        }

        $newActive = ((int) $task['is_active']) === 1 ? 0 : 1;
        $db->execute(
            "UPDATE scheduled_tasks SET is_active = :active WHERE id = :id",
            [':active' => $newActive, ':id' => $id]
        );

        return true;
    }

    /**
     * Delete a scheduled task.
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        return $db->execute("DELETE FROM scheduled_tasks WHERE id = :id", [':id' => $id]) > 0;
    }

    /**
     * Seed default system tasks if scheduled_tasks table is empty.
     */
    public static function seedDefaultTasksIfEmpty(): void
    {
        $db = Database::getInstance();
        $count = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM scheduled_tasks")['cnt'];
        if ($count > 0) {
            return;
        }

        $defaultTasks = [
            [
                'task_type' => 'sync_connectors',
                'task_name' => 'Sinkronisasi Konektor Otomatis',
                'cron_expression' => '*/15 * * * *',
                'is_active' => 1,
            ],
            [
                'task_type' => 'process_queue',
                'task_name' => 'Pemrosesan Antrean Background',
                'cron_expression' => '*/5 * * * *',
                'is_active' => 1,
            ],
            [
                'task_type' => 'database_backup',
                'task_name' => 'Pencadangan Database SQLite Terjadwal',
                'cron_expression' => '0 2 * * *',
                'is_active' => 1,
            ],
            [
                'task_type' => 'log_rotation',
                'task_name' => 'Rotasi Berkas Log & Pembersihan',
                'cron_expression' => '0 3 * * 0',
                'is_active' => 1,
            ],
            [
                'task_type' => 'report_cleanup',
                'task_name' => 'Pembersihan Laporan Sementara',
                'cron_expression' => '0 4 * * *',
                'is_active' => 1,
            ],
            [
                'task_type' => 'anomaly_monitoring',
                'task_name' => 'Pemantauan Anomali Dataset Terintegrasi',
                'cron_expression' => '0 */6 * * *',
                'is_active' => 1,
            ],
        ];

        foreach ($defaultTasks as $task) {
            self::create($task);
        }
    }

    /**
     * Check if a cron expression is due at the specified time.
     */
    public static function isDue(string $expression, ?string $lastRunAt, ?DateTimeImmutable $now = null): bool
    {
        $cron = self::normalizeCronExpression($expression);
        $tz = new DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $now = $now ?? new DateTimeImmutable('now', $tz);

        // Prevent duplicate execution within the exact same minute
        if (!empty($lastRunAt)) {
            $lastDt = new DateTimeImmutable($lastRunAt, $tz);
            if ($lastDt->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                return false;
            }
        }

        return self::matchesMinute($cron, $now);
    }

    /**
     * Calculate next run timestamp from given time.
     */
    public static function calculateNextRun(string $expression, ?DateTimeImmutable $from = null): string
    {
        $cron = self::normalizeCronExpression($expression);
        $tz = new DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $current = ($from ?? new DateTimeImmutable('now', $tz))->setTime(
            (int) ($from ?? new DateTimeImmutable('now', $tz))->format('H'),
            (int) ($from ?? new DateTimeImmutable('now', $tz))->format('i'),
            0
        );

        // Advance 1 minute ahead of current
        $check = $current->modify('+1 minute');

        // Look ahead up to 35 days (50,400 minutes)
        for ($i = 0; $i < 50400; $i++) {
            if (self::matchesMinute($cron, $check)) {
                return $check->format('c');
            }
            $check = $check->modify('+1 minute');
        }

        return $current->modify('+1 day')->format('c');
    }

    /**
     * Normalize cron expression resolving aliases.
     */
    public static function normalizeCronExpression(string $expression): string
    {
        $trimmed = strtolower(trim($expression));
        if (isset(self::ALIASES[$trimmed])) {
            return self::ALIASES[$trimmed];
        }

        $parts = preg_split('/\s+/', $trimmed);
        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Ekspresi cron tidak valid [{$expression}]. Wajib memiliki 5 bagian (menit jam tgl_bln bulan hari_minggu).");
        }

        return implode(' ', $parts);
    }

    /**
     * Evaluate if a 5-part cron expression matches the given minute.
     */
    private static function matchesMinute(string $cron, DateTimeImmutable $dt): bool
    {
        $parts = explode(' ', $cron);
        if (count($parts) !== 5) {
            return false;
        }

        [$minPart, $hourPart, $domPart, $monPart, $dowPart] = $parts;

        $min = (int) $dt->format('i');
        $hour = (int) $dt->format('G');
        $dom = (int) $dt->format('j');
        $mon = (int) $dt->format('n');
        $dow = (int) $dt->format('w'); // 0 (Sunday) to 6 (Saturday)

        return self::matchField($minPart, $min, 0, 59)
            && self::matchField($hourPart, $hour, 0, 23)
            && self::matchField($domPart, $dom, 1, 31)
            && self::matchField($monPart, $mon, 1, 12)
            && self::matchDow($dowPart, $dow);
    }

    /**
     * Match a single cron field against an integer value.
     */
    private static function matchField(string $field, int $value, int $minBound, int $maxBound): bool
    {
        if ($field === '*' || $field === '?') {
            return true;
        }

        // Handle list: e.g. "1,5,10"
        if (str_contains($field, ',')) {
            $items = explode(',', $field);
            foreach ($items as $item) {
                if (self::matchField(trim($item), $value, $minBound, $maxBound)) {
                    return true;
                }
            }
            return false;
        }

        // Handle step: e.g. "*/15" or "10-30/5"
        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;
            if ($step <= 0) {
                return false;
            }

            if ($range === '*' || $range === '') {
                return ($value % $step) === 0;
            }

            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                $start = (int) $start;
                $end = (int) $end;
                return ($value >= $start && $value <= $end) && (($value - $start) % $step === 0);
            }

            $start = (int) $range;
            return $value >= $start && (($value - $start) % $step === 0);
        }

        // Handle range: e.g. "1-5"
        if (str_contains($field, '-')) {
            [$start, $end] = explode('-', $field, 2);
            return $value >= (int) $start && $value <= (int) $end;
        }

        // Handle exact value
        return (int) $field === $value;
    }

    /**
     * Match day of week field supporting 7 as Sunday.
     */
    private static function matchDow(string $field, int $value): bool
    {
        if ($field === '*' || $field === '?') {
            return true;
        }

        // 7 in standard cron can mean Sunday (0)
        $normalizedField = str_replace('7', '0', $field);
        return self::matchField($normalizedField, $value, 0, 6);
    }
}
