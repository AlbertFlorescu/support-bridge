<?php

declare(strict_types=1);

namespace Bridge\Observability;

use PDO;

/** Durable, bounded-cardinality metrics shared by short-lived CLI and HTTP processes. */
final class Prometheus
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';
    private const OUTCOMES = [
        'synced',
        'retry',
        'conflict',
        'dead',
        'skipped',
        'awaiting_approval',
    ];
    private const STATUSES = [
        'new',
        'synced',
        'retry',
        'conflict',
        'dead',
        'awaiting_approval',
    ];
    private const BUCKETS = [
        '0.005',
        '0.01',
        '0.025',
        '0.05',
        '0.1',
        '0.25',
        '0.5',
        '1',
        '2.5',
        '5',
        '10',
        '+Inf',
    ];
    private ?PDO $db = null;

    public function __construct(string $dbPath)
    {
        try {
            $directory = dirname($dbPath);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return;
            }
            $this->db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            @chmod($dbPath, 0600);
            $this->db->exec('PRAGMA busy_timeout=1000');
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=FULL');
            $this->transaction(function (): void {
                $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' " .
                    "AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                $version = '2:' . implode(',', self::BUCKETS);
                if ($tables === []) {
                    // Only first creation may initialize zero series. Repairing missing rows in an
                    // existing registry would fabricate healthy data and hide lost observations.
                    $this->db->exec('CREATE TABLE telemetry_values (name TEXT PRIMARY KEY, ' .
                        'value NUMERIC NOT NULL CHECK (value >= 0))');
                    $this->db->exec('CREATE TABLE telemetry_schema (id INTEGER PRIMARY KEY ' .
                        'CHECK (id = 1), version TEXT NOT NULL)');
                    $insert = $this->db->prepare('INSERT INTO telemetry_schema (id, version) VALUES (1, ?)');
                    $insert->execute([$version]);
                    $initialize = $this->db->prepare('INSERT INTO telemetry_values (name, value) VALUES (?, 0)');
                    foreach (self::keys() as $key) {
                        $initialize->execute([$key]);
                    }
                } elseif ($tables !== ['telemetry_schema', 'telemetry_values']) {
                    throw new \RuntimeException('Invalid telemetry registry.');
                }
                $storedVersion = $this->db->query('SELECT version FROM telemetry_schema WHERE id = 1')->fetchColumn();
                if ($storedVersion === '1:' . implode(',', self::BUCKETS)) {
                    // Validate the complete old registry before adding the one genuinely new
                    // series. Never reset counters or repair missing historical observations.
                    $legacyOutcomes = array_values(array_diff(self::OUTCOMES, ['awaiting_approval']));
                    $this->validateSnapshot(
                        $this->db->query('SELECT name, value FROM telemetry_values')->fetchAll(PDO::FETCH_KEY_PAIR),
                        $legacyOutcomes
                    );
                    $this->db->exec("INSERT INTO telemetry_values (name, value) VALUES " .
                        "('outcome:awaiting_approval', 0)");
                    $this->db->prepare('UPDATE telemetry_schema SET version = ? WHERE id = 1')->execute([$version]);
                    $storedVersion = $version;
                }
                if ($storedVersion !== $version) {
                    throw new \RuntimeException('Incompatible telemetry schema.');
                }
                $this->validateSnapshot(
                    $this->db->query('SELECT name, value FROM telemetry_values')->fetchAll(PDO::FETCH_KEY_PAIR)
                );
            });
        } catch (\Throwable) {
            $this->db = null;
        }
    }

    /** Records only the bounded reconciliation outcome; all other log fields are ignored. */
    public function __invoke(array $entry): void
    {
        $duration = $entry['duration_ms'] ?? null;
        if (
            $this->db === null || ($entry['event'] ?? null) !== 'reconcile'
            || !in_array($entry['status'] ?? null, self::OUTCOMES, true)
            || (!is_int($duration) && !is_float($duration)) || !is_finite((float) $duration) || $duration < 0
        ) {
            return;
        }
        $seconds = (float) $duration / 1000;
        try {
            // Counter, histogram buckets, count and sum are committed together, or not at all.
            $this->transaction(function () use ($entry, $seconds): void {
                $sum = (float) $this->db
                    ->query("SELECT value FROM telemetry_values WHERE name = 'duration_sum'")->fetchColumn();
                if (!is_finite($sum + $seconds)) {
                    throw new \RuntimeException('Telemetry sum overflow.');
                }
                $increment = $this->db->prepare('UPDATE telemetry_values SET value = value + 1 WHERE name = ?');
                $increment->execute(['outcome:' . $entry['status']]);
                $increment->execute(['duration_count']);
                foreach (self::BUCKETS as $bound) {
                    if ($bound === '+Inf' || $seconds <= (float) $bound) {
                        $increment->execute(['bucket:' . $bound]);
                    }
                }
                $updateSum = $this->db->prepare(
                    "UPDATE telemetry_values SET value = value + ? WHERE name = 'duration_sum'"
                );
                $updateSum->execute([$seconds]);
            });
        } catch (\Throwable) {
            // The business result is already durable. Metrics failure must not change it.
            $this->db = null;
        }
    }

    /**
     * @param array{pairs?:array,pending_plans?:int,last_success?:?int} $state Local business-store gauges.
     * @param list<array>|null $linkedPairs Actual business rows for the bounded demonstration-case projection.
     * @throws \RuntimeException A failed scrape is preferable to fabricated zero counters.
     */
    public function render(array $state = [], ?array $linkedPairs = null): string
    {
        if ($this->db === null) {
            throw new \RuntimeException('Telemetry storage is unavailable.');
        }
        try {
            // One SELECT takes a consistent SQLite snapshot across all metrics rows.
            $values = $this->db->query('SELECT name, value FROM telemetry_values')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->validateSnapshot($values);
        } catch (\Throwable) {
            $this->db = null;
            throw new \RuntimeException('Telemetry storage is unavailable.');
        }

        $lines = [
            '# HELP support_bridge_reconciliations_total Observed reconciliations by outcome ' .
                'since this metrics database was created.',
            '# TYPE support_bridge_reconciliations_total counter',
        ];
        foreach (self::OUTCOMES as $outcome) {
            $lines[] = 'support_bridge_reconciliations_total{outcome="' . $outcome . '"} ' .
                $values['outcome:' . $outcome];
        }
        $lines[] = '# HELP support_bridge_reconciliation_duration_seconds Observed per-pair ' .
            'reconciliation duration in seconds.';
        $lines[] = '# TYPE support_bridge_reconciliation_duration_seconds histogram';
        foreach (self::BUCKETS as $bound) {
            $lines[] = 'support_bridge_reconciliation_duration_seconds_bucket{le="' . $bound . '"} ' .
                $values['bucket:' . $bound];
        }
        $lines[] = 'support_bridge_reconciliation_duration_seconds_sum ' .
            self::number((float) $values['duration_sum']);
        $lines[] = 'support_bridge_reconciliation_duration_seconds_count ' . $values['duration_count'];
        $lines[] = '# HELP support_bridge_telemetry_storage_available Whether this scrape could ' .
            'read durable instrumentation storage.';
        $lines[] = '# TYPE support_bridge_telemetry_storage_available gauge';
        $lines[] = 'support_bridge_telemetry_storage_available 1';

        if ($state !== []) {
            $pairs = is_array($state['pairs'] ?? null) ? $state['pairs'] : [];
            $lines[] = '# HELP support_bridge_pairs Current linked pairs by persisted status.';
            $lines[] = '# TYPE support_bridge_pairs gauge';
            foreach (self::STATUSES as $status) {
                $lines[] = 'support_bridge_pairs{status="' . $status . '"} ' . self::gauge($pairs[$status] ?? null);
            }
            $lines[] = '# HELP support_bridge_pending_plans Current durable write plans awaiting convergence.';
            $lines[] = '# TYPE support_bridge_pending_plans gauge';
            $lines[] = 'support_bridge_pending_plans ' . self::gauge($state['pending_plans'] ?? null);
            $lines[] = '# HELP support_bridge_last_success_timestamp_seconds Most recent ' .
                'observed convergence as Unix seconds, or zero if none.';
            $lines[] = '# TYPE support_bridge_last_success_timestamp_seconds gauge';
            $lines[] = 'support_bridge_last_success_timestamp_seconds ' . self::gauge($state['last_success'] ?? null);
        }
        if ($linkedPairs !== null) {
            array_push($lines, ...(new DemoCaseMetrics())->render($linkedPairs));
        }
        return implode("\n", $lines) . "\n";
    }

    public function status(): array
    {
        return [
            'enabled'           => true,
            'vendor'            => 'prometheus',
            'storage_available' => $this->db !== null,
            'status'            => $this->db === null ? 'storage_unavailable' : 'ready',
        ];
    }

    private function transaction(callable $operation): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $operation();
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable) {
                // Preserve the original failure; neither exception is exposed to a scrape client.
            }
            throw $error;
        }
    }

    private function validateSnapshot(array $values, array $outcomes = self::OUTCOMES): void
    {
        if (count($values) !== count(self::keys($outcomes))) {
            throw new \RuntimeException('Invalid telemetry snapshot.');
        }
        foreach (self::keys($outcomes) as $key) {
            $value = $values[$key] ?? null;
            if (
                ($key === 'duration_sum' && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0))
                || ($key !== 'duration_sum' && (!is_int($value) || $value < 0))
            ) {
                throw new \RuntimeException('Invalid telemetry snapshot.');
            }
        }
        $count = $values['duration_count'];
        if ($values['bucket:+Inf'] !== $count) {
            throw new \RuntimeException('Invalid telemetry histogram.');
        }
        $previous = 0;
        foreach (self::BUCKETS as $bound) {
            $bucket = $values['bucket:' . $bound];
            if ($bucket < $previous || $bucket > $count) {
                throw new \RuntimeException('Invalid telemetry histogram.');
            }
            $previous = $bucket;
        }
        $total = 0;
        foreach ($outcomes as $outcome) {
            $total += $values['outcome:' . $outcome];
        }
        if ($total !== $count) {
            throw new \RuntimeException('Invalid telemetry counters.');
        }
    }

    private static function keys(array $outcomes = self::OUTCOMES): array
    {
        return array_merge(
            [
                'duration_count',
                'duration_sum',
            ],
            array_map(static fn (string $value): string => 'outcome:' . $value, $outcomes),
            array_map(static fn (string $value): string => 'bucket:' . $value, self::BUCKETS)
        );
    }

    private static function gauge(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private static function number(float $value): string
    {
        return json_encode($value === 0.0 ? 0.0 : $value, JSON_THROW_ON_ERROR);
    }
}
