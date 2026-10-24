<?php
declare(strict_types=1);

namespace Bridge\Tests\Observability;

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Observability\Prometheus;
use PDO;

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function sample(string $text, string $name, string $labels = ''): float
{
    $identity = $name . ($labels === '' ? '' : '{' . $labels . '}');
    if (!preg_match('/^' . preg_quote($identity, '/') . ' ([0-9.eE+-]+)$/m', $text, $matches)) {
        throw new \RuntimeException('Missing metric sample: ' . $identity);
    }
    return (float) $matches[1];
}

function raises(callable $operation): \Throwable
{
    try {
        $operation();
    } catch (\Throwable $error) {
        return $error;
    }
    throw new \RuntimeException('Expected a sanitized storage failure.');
}

function event(array $overrides = []): array
{
    return array_replace(['event' => 'reconcile', 'status' => 'synced', 'attempts' => 2, 'duration_ms' => 25.0], $overrides);
}

$directories = [];
function database(): string
{
    global $directories;
    $directory = sys_get_temp_dir() . '/support-bridge-observability-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new \RuntimeException('Could not create isolated test directory.');
    }
    $directories[] = $directory;
    return $directory . '/metrics.sqlite';
}

/** Start an actual second PHP process, with no shell and no external requests. */
function worker(string $path, int $count): array
{
    $code = 'require $argv[1]; $metrics = new Bridge\\Observability\\Prometheus($argv[2]);'
        . 'for ($i = 0; $i < (int) $argv[3]; ++$i) { $metrics(["event"=>"reconcile","status"=>"synced","duration_ms"=>10]); }'
        . 'exit($metrics->status()["storage_available"] ? 0 : 1);';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/bootstrap.php', $path, (string) $count],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start isolated metric worker.');
    }
    fclose($pipes[0]);
    return [$process, $pipes];
}

function finish(array $worker): void
{
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process));
    same('', $out . $err);
}

$tests = [];
$tests['Fresh durable storage exposes initialized cumulative outcomes and histogram'] = static function (): void {
    $metrics = new Prometheus(database());
    same(['enabled' => true, 'vendor' => 'prometheus', 'storage_available' => true, 'status' => 'ready'], $metrics->status());
    $text = $metrics->render();
    foreach (['synced', 'retry', 'conflict', 'dead', 'skipped', 'awaiting_approval'] as $outcome) {
        same(0.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="' . $outcome . '"'));
    }
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_sum'));
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_bucket', 'le="+Inf"'));
    same(1.0, sample($text, 'support_bridge_telemetry_storage_available'));
};

$tests['Outcomes and latency survive observer instances and an actual PHP restart'] = static function (): void {
    $path = database();
    $first = new Prometheus($path);
    $first(event(['status' => 'retry', 'duration_ms' => 100]));
    unset($first);
    finish(worker($path, 2));
    $text = (new Prometheus($path))->render();
    same(2.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="synced"'));
    same(1.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="retry"'));
    same(3.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(true, abs(sample($text, 'support_bridge_reconciliation_duration_seconds_sum') - 0.12) < 0.000001);
};

$tests['Approval waits persist as bounded outcomes and current operator-attention gauges'] = static function (): void {
    $path = database();
    $metrics = new Prometheus($path);
    $metrics(event(['status' => 'awaiting_approval']));
    unset($metrics);
    $text = (new Prometheus($path))->render(['pairs' => ['awaiting_approval' => 2], 'pending_plans' => 2]);
    same(1.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="awaiting_approval"'));
    same(2.0, sample($text, 'support_bridge_pairs', 'status="awaiting_approval"'));
    same(1.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
};

/** Reconstruct the exact v1 schema; retain all original counters and histogram rows. */
function legacyRegistry(string $path): PDO
{
    $metrics = new Prometheus($path);
    $metrics(event(['duration_ms' => 1000]));
    unset($metrics);
    $database = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec("DELETE FROM telemetry_values WHERE name = 'outcome:awaiting_approval'");
    $database->exec("UPDATE telemetry_schema SET version = '1:' || substr(version, 3)");
    return $database;
}

$tests['Valid v1 registries migrate atomically without resetting historical observations'] = static function (): void {
    $path = database();
    unset($database);
    $database = legacyRegistry($path);
    $upgraded = new Prometheus($path);
    $upgraded(event(['status' => 'awaiting_approval', 'duration_ms' => 25]));
    $text = (new Prometheus($path))->render();
    same(1.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="synced"'));
    same(1.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="awaiting_approval"'));
    same(2.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(true, abs(sample($text, 'support_bridge_reconciliation_duration_seconds_sum') - 1.025) < 0.000001);
    same(true, str_starts_with($database->query('SELECT version FROM telemetry_schema')->fetchColumn(), '2:'));
};

$tests['Migration refuses damaged v1 registries and missing v2 approval series'] = static function (): void {
    foreach (['legacy', 'current'] as $version) {
        $path = database();
        if ($version === 'legacy') {
            $database = legacyRegistry($path);
            $database->exec("DELETE FROM telemetry_values WHERE name = 'outcome:dead'");
        } else {
            $metrics = new Prometheus($path);
            unset($metrics);
            $database = new PDO('sqlite:' . $path);
            $database->exec("DELETE FROM telemetry_values WHERE name = 'outcome:awaiting_approval'");
        }
        same(false, (new Prometheus($path))->status()['storage_available']);
        same(0, (int) $database->query("SELECT COUNT(*) FROM telemetry_values WHERE name = 'outcome:awaiting_approval'")->fetchColumn());
    }
};

$tests['Classic histogram uses seconds, inclusive cumulative buckets and an equal +Inf count'] = static function (): void {
    $metrics = new Prometheus(database());
    foreach ([5, 25, 1000, 11000] as $milliseconds) {
        $metrics(event(['duration_ms' => $milliseconds]));
    }
    $text = $metrics->render();
    foreach (['0.005' => 1, '0.01' => 1, '0.025' => 2, '0.05' => 2, '0.1' => 2, '0.25' => 2,
        '0.5' => 2, '1' => 3, '2.5' => 3, '5' => 3, '10' => 3, '+Inf' => 4] as $bound => $count) {
        same((float) $count, sample($text, 'support_bridge_reconciliation_duration_seconds_bucket', 'le="' . $bound . '"'));
    }
    same(4.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(true, abs(sample($text, 'support_bridge_reconciliation_duration_seconds_sum') - 12.03) < 0.000001);
};

$tests['Only fixed outcomes are accepted and arbitrary event fields never become labels'] = static function (): void {
    $metrics = new Prometheus(database());
    $metrics(event(['title' => 'SECRET', 'pair' => 'SECRET', 'token' => 'SECRET', 'error' => 'SECRET']));
    foreach (['SECRET', "synced\"}\nSECRET 9", [], null] as $outcome) {
        $metrics(event(['status' => $outcome]));
    }
    $metrics(event(['event' => 'SECRET']));
    $text = $metrics->render(['pairs' => ['new' => 1, 'SECRET' => 99], 'pending_plans' => 0, 'last_success' => null]);
    same(false, str_contains($text, 'SECRET'));
    same(1.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(1.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="synced"'));
    same(1.0, sample($text, 'support_bridge_pairs', 'status="new"'));
};

$tests['Invalid numeric observations are ignored; zero latency is legitimate'] = static function (): void {
    $metrics = new Prometheus(database());
    foreach ([INF, NAN, -INF, -1, '25', [], null] as $duration) {
        $metrics(event(['duration_ms' => $duration]));
    }
    $metrics(event(['duration_ms' => -0.0]));
    $text = $metrics->render();
    same(1.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_sum'));
    same(1.0, sample($text, 'support_bridge_reconciliation_duration_seconds_bucket', 'le="0.005"'));
    same(false, str_contains($text, 'NaN'));
};

$tests['State gauges show current business state rather than accumulating scrape observations'] = static function (): void {
    $metrics = new Prometheus(database());
    $state = ['pairs' => ['new' => 1, 'synced' => 2, 'retry' => 3, 'conflict' => 4, 'dead' => 5],
        'pending_plans' => 3, 'last_success' => 1_700_000_000];
    $first = $metrics->render($state);
    $second = $metrics->render($state);
    same($first, $second);
    same(3.0, sample($first, 'support_bridge_pending_plans'));
    same(1_700_000_000.0, sample($first, 'support_bridge_last_success_timestamp_seconds'));
    same(0.0, sample($first, 'support_bridge_reconciliation_duration_seconds_count'));
    same(false, str_contains($metrics->render(), 'support_bridge_pairs{'));
    same(0.0, sample($metrics->render(['last_success' => null]), 'support_bridge_last_success_timestamp_seconds'));
};

$tests['Parallel PHP processes cannot lose counter or histogram increments'] = static function (): void {
    $path = database();
    unset($metrics);
    $metrics = new Prometheus($path);
    unset($metrics);
    $one = worker($path, 20);
    $two = worker($path, 20);
    finish($one);
    finish($two);
    $text = (new Prometheus($path))->render();
    same(40.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="synced"'));
    same(40.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(40.0, sample($text, 'support_bridge_reconciliation_duration_seconds_bucket', 'le="+Inf"'));
};

$tests['A failed histogram update rolls back the entire event and fails open for sync'] = static function (): void {
    $path = database();
    $metrics = new Prometheus($path);
    $database = new PDO('sqlite:' . $path);
    $database->exec("CREATE TRIGGER reject_bucket BEFORE UPDATE ON telemetry_values WHEN OLD.name = 'bucket:+Inf' BEGIN SELECT RAISE(ABORT, 'SECRET'); END");
    $metrics(event());
    same(false, $metrics->status()['storage_available']);
    $error = raises($metrics->render(...));
    same('Telemetry storage is unavailable.', $error->getMessage());
    same(false, str_contains($error->getMessage(), 'SECRET'));
    $restarted = new Prometheus($path);
    $text = $restarted->render();
    same(0.0, sample($text, 'support_bridge_reconciliations_total', 'outcome="synced"'));
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_sum'));
};

$tests['A busy metrics database cannot hold up business processing indefinitely'] = static function (): void {
    $path = database();
    $metrics = new Prometheus($path);
    $blocker = new PDO('sqlite:' . $path);
    $blocker->exec('BEGIN IMMEDIATE');
    $start = microtime(true);
    $metrics(event());
    same(true, microtime(true) - $start < 2.0);
    same('storage_unavailable', $metrics->status()['status']);
    $blocker->exec('ROLLBACK');
    same(0.0, sample((new Prometheus($path))->render(), 'support_bridge_reconciliation_duration_seconds_count'));
};

$tests['Unusable telemetry storage is explicitly unavailable and never exposes fabricated zeros'] = static function (): void {
    $path = database();
    file_put_contents($path, 'SECRET not a SQLite database');
    $metrics = new Prometheus($path);
    $metrics(event());
    same(['enabled' => true, 'vendor' => 'prometheus', 'storage_available' => false, 'status' => 'storage_unavailable'], $metrics->status());
    $error = raises($metrics->render(...));
    same('Telemetry storage is unavailable.', $error->getMessage());
    same(false, str_contains($error->getMessage(), $path));
};

$tests['Exporter detects missing or inconsistent stored histogram rows'] = static function (): void {
    $path = database();
    $metrics = new Prometheus($path);
    $metrics(event());
    $database = new PDO('sqlite:' . $path);
    $database->exec("UPDATE telemetry_values SET value = 0 WHERE name = 'bucket:+Inf'");
    same('Telemetry storage is unavailable.', raises($metrics->render(...))->getMessage());
    same(false, $metrics->status()['storage_available']);
};

$tests['A restarted observer cannot repair missing sum or zero-valued outcome rows as healthy zeros'] = static function (): void {
    foreach (['duration_sum', 'outcome:dead'] as $missing) {
        $path = database();
        $metrics = new Prometheus($path);
        $metrics(event(['duration_ms' => 1000]));
        unset($metrics);
        $database = new PDO('sqlite:' . $path);
        $delete = $database->prepare('DELETE FROM telemetry_values WHERE name = ?');
        $delete->execute([$missing]);
        unset($delete, $database);
        $restarted = new Prometheus($path);
        same(false, $restarted->status()['storage_available']);
        same('Telemetry storage is unavailable.', raises($restarted->render(...))->getMessage());
        $check = new PDO('sqlite:' . $path);
        $query = $check->prepare('SELECT COUNT(*) FROM telemetry_values WHERE name = ?');
        $query->execute([$missing]);
        same(0, (int) $query->fetchColumn());
    }
};

$tests['Text exposition has stable metadata, unique samples and a final newline'] = static function (): void {
    $metrics = new Prometheus(database());
    $metrics(event());
    $text = $metrics->render(['pairs' => []]);
    same('text/plain; version=0.0.4; charset=utf-8', Prometheus::CONTENT_TYPE);
    same(true, str_ends_with($text, "\n"));
    same(true, str_contains($text, '# TYPE support_bridge_reconciliations_total counter'));
    same(true, str_contains($text, '# TYPE support_bridge_reconciliation_duration_seconds histogram'));
    $identities = [];
    foreach (explode("\n", $text) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $identities[] = explode(' ', $line)[0];
    }
    same(count($identities), count(array_unique($identities)));
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (\Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
    }
}
foreach ($directories as $directory) {
    foreach (['metrics.sqlite', 'metrics.sqlite-wal', 'metrics.sqlite-shm'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory);
}
echo count($tests) . ' observability tests; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
