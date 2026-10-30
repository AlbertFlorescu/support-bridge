<?php
declare(strict_types=1);

namespace Bridge\Tests\Observability;

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Observability\Prometheus;
use Bridge\Store;
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
    $code = 'require $argv[1]; $metrics = new \Bridge\\Observability\\Prometheus($argv[2]);'
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

/** The same SQLite repository consumed by the HTTP scrape, without provider construction. */
function demoStore(string $metricsPath): Store
{
    $store = new Store(dirname($metricsPath) . '/business.sqlite');
    $store->addPair('csv-export', '101', '501');
    $store->addPair('webhook-retry', '102', '502');
    $store->addPair('sso-conflict', '103', '503');
    return $store;
}

function renderLinks(Prometheus $metrics, Store $store): string
{
    $snapshot = $store->monitoringSnapshot();
    return $metrics->render($snapshot['state'], $snapshot['linked_pairs']);
}

$tests['An empty store exports a genuine zero link count and no invented rows'] = static function (): void {
    $metrics = new Prometheus(database());
    $text = $metrics->render(['pairs' => []], []);
    same(0.0, sample($text, 'support_bridge_linked_pairs'));
    same(0, preg_match_all('/^support_bridge_linked_pair_status\{/m', $text));
    same(false, str_contains($text, 'support_bridge_demo_case_'));
};

$tests['All linked gauges retain verified resolution during review without writes'] = static function (): void {
    $path = database(); $metrics = new Prometheus($path); $store = demoStore($path);
    $store->update('csv-export', ['status' => 'synced', 'baseline' => ['title' => 'PRIVATE CLOSED TITLE', 'state' => 'closed'],
        'last_success' => 1_700_000_000]);
    $store->update('webhook-retry', ['status' => 'awaiting_approval',
        'baseline' => ['title' => 'PRIVATE OPEN TITLE', 'state' => 'open'],
        'plan' => ['target' => ['title' => 'PRIVATE NEW TITLE', 'state' => 'closed']], 'last_success' => 1_699_999_900]);
    $store->update('sso-conflict', ['status' => 'conflict',
        'baseline' => ['title' => 'PRIVATE TITLE', 'state' => 'open'], 'conflict' => ['reason' => 'PRIVATE']]);
    $before = $store->all();
    $text = renderLinks($metrics, $store);
    same(3.0, sample($text, 'support_bridge_linked_pairs'));
    same(2.0, sample($text, 'support_bridge_linked_pair_status', 'github_issue="101",zendesk_ticket="501"'));
    same(2.0, sample($text, 'support_bridge_linked_pair_resolution', 'github_issue="101"'));
    same(3.0, sample($text, 'support_bridge_linked_pair_status', 'github_issue="102",zendesk_ticket="502"'));
    same(1.0, sample($text, 'support_bridge_linked_pair_resolution', 'github_issue="102"'));
    same(1.0, sample($text, 'support_bridge_linked_pair_pending_plan', 'github_issue="102"'));
    same(4.0, sample($text, 'support_bridge_linked_pair_status', 'github_issue="103",zendesk_ticket="503"'));
    same(1_700_000_000.0, sample($text, 'support_bridge_linked_pair_last_success_timestamp_seconds', 'github_issue="101"'));
    same($text, renderLinks($metrics, $store)); same($before, $store->all());
    same(0.0, sample($text, 'support_bridge_reconciliation_duration_seconds_count'));
    same(false, str_contains($text, 'PRIVATE'));
    same(true, str_contains(json_encode($store->monitoringSnapshot()), 'PRIVATE CLOSED TITLE'));
};

$tests['New retry dead and unknown states keep distinct codes and zero means never verified'] = static function (): void {
    $path = database(); $metrics = new Prometheus($path); $store = demoStore($path);
    foreach (['new' => 1, 'retry' => 5, 'dead' => 6, 'unrecognized' => 7] as $status => $code) {
        $store->update('csv-export', ['status' => $status, 'attempts' => 3]);
        $text = renderLinks($metrics, $store);
        same((float) $code, sample($text, 'support_bridge_linked_pair_status', 'github_issue="101",zendesk_ticket="501"'));
        same(0.0, sample($text, 'support_bridge_linked_pair_resolution', 'github_issue="101"'));
        same(3.0, sample($text, 'support_bridge_linked_pair_attempts', 'github_issue="101"'));
        same(0.0, sample($text, 'support_bridge_linked_pair_last_success_timestamp_seconds', 'github_issue="101"'));
    }
};

$tests['New mappings appear automatically while internal keys and content remain excluded'] = static function (): void {
    $path = database(); $metrics = new Prometheus($path); $store = demoStore($path);
    same(3.0, sample(renderLinks($metrics, $store), 'support_bridge_linked_pairs'));
    $store->addPair('PRIVATE_CUSTOMER_KEY', '30', '6');
    $store->update('PRIVATE_CUSTOMER_KEY', ['status' => 'synced',
        'baseline' => ['title' => 'PRIVATE CUSTOMER TITLE', 'state' => 'open'],
        'error' => 'PRIVATE TOKEN', 'approval' => ['rationale' => 'PRIVATE RATIONALE']]);
    $text = renderLinks($metrics, $store);
    same(4.0, sample($text, 'support_bridge_linked_pairs'));
    same(2.0, sample($text, 'support_bridge_linked_pair_status', 'github_issue="30",zendesk_ticket="6"'));
    foreach (['PRIVATE', 'csv-export', 'webhook-retry', 'sso-conflict', 'pair_id', 'support_bridge_demo_case_'] as $value) {
        same(false, str_contains($text, $value));
    }
};

$tests['Corrupt stored evidence fails visibly without exposing it or changing business rows'] = static function (): void {
    foreach ([['attempts' => -1], ['last_success' => -1], ['baseline' => ['state' => 'PRIVATE INVALID']]] as $invalid) {
        $path = database(); $metrics = new Prometheus($path); $store = demoStore($path);
        $store->update('csv-export', $invalid); $before = $store->all();
        same('Linked pair metric state is invalid.', raises(static fn(): string => renderLinks($metrics, $store))->getMessage());
        same($before, $store->all()); same(true, $metrics->status()['storage_available']);
    }
};

$tests['Monitoring reads every link beyond the operator API first-page limits'] = static function (): void {
    $path = database(); $metrics = new Prometheus($path); $store = new Store(dirname($path) . '/business.sqlite');
    for ($id = 1; $id <= 105; ++$id) { $store->addPair('link-' . $id, (string) $id, (string) (1000 + $id)); }
    $text = renderLinks($metrics, $store);
    same(105.0, sample($text, 'support_bridge_linked_pairs'));
    same(105, preg_match_all('/^support_bridge_linked_pair_status\{/m', $text));
    $table = new \Bridge\Observability\LinkedPairTable(
        new \Bridge\Application\Configuration('local', dirname($path), 'local/demo', 'local'));
    same(105, count($table->project($store->monitoringSnapshot()['linked_pairs'])['items']));
    same(1.0, sample($text, 'support_bridge_linked_pair_status', 'github_issue="105",zendesk_ticket="1105"'));
};

$tests['Per-pair note delivery counts come from durable rows without copying note content'] = static function (): void {
    $path = database(); $metrics = new Prometheus($path); $store = demoStore($path);
    $connection = new \Bridge\Infrastructure\Persistence\SqliteConnection(dirname($path) . '/business.sqlite');
    $repository = new \Bridge\Infrastructure\Persistence\CommentRepository($connection);
    foreach (['delivered', 'pending', 'uncertain', 'blocked', 'sending'] as $index => $status) {
        $comment = new \Bridge\Comments\IssueComment((string) (700 + $index), 'PRIVATE COMMENT', 'engineer',
            '2026-10-30T10:00:00Z', '2026-10-30T10:00:00Z',
            'https://github.com/example/escalations/issues/101#issuecomment-' . (700 + $index));
        $repository->prepare('csv-export', $comment);
        $delivery = $repository->list()['items'][$index];
        $repository->change($delivery, ['status' => $status], 'fixture_state');
    }
    $before = $repository->list(); $text = renderLinks($metrics, $store);
    same(1.0, sample($text, 'support_bridge_linked_pair_notes_delivered', 'github_issue="101"'));
    same(4.0, sample($text, 'support_bridge_linked_pair_notes_waiting', 'github_issue="101"'));
    same(0.0, sample($text, 'support_bridge_linked_pair_notes_delivered', 'github_issue="102"'));
    same(false, str_contains($text, 'PRIVATE')); same($before, $repository->list());
};

$tests['Duplicate or unsafe vendor identifiers fail instead of merging or injecting labels'] = static function (): void {
    $path = database(); $store = demoStore($path); $renderer = new \Bridge\Observability\LinkedPairMetrics();
    $row = $store->monitoringSnapshot()['linked_pairs'][0];
    foreach ([[$row, $row], [array_replace($row, ['github_id' => "30\"\nPRIVATE"])],
        [array_replace($row, ['zendesk_id' => '0'])]] as $invalid) {
        same('Linked pair metric state is invalid.', raises(static fn(): array => $renderer->render($invalid))->getMessage());
    }
};


$tests['Table subjects come only from verified baselines and links use configured identities'] = static function (): void {
    $path = database(); $store = demoStore($path);
    $title = '<img src=x onerror=alert(1)> "Résumé" & / ? #';
    $store->update('csv-export', ['baseline' => ['title' => $title, 'state' => 'open'],
        'plan' => ['target' => ['title' => 'UNAPPROVED PRIVATE TARGET', 'state' => 'closed']]]);
    $before = $store->all();
    $table = new \Bridge\Observability\LinkedPairTable(
        new \Bridge\Application\Configuration('live', dirname($path), 'example/escalations', 'EXAMPLE'));
    $items = $table->project($store->monitoringSnapshot()['linked_pairs'])['items'];
    same($title, $items[0]['subject']); same(null, $items[1]['subject']);
    same('https://example.zendesk.com/agent/tickets/501', $items[0]['zendesk_url']);
    same('https://github.com/example/escalations/issues/101', $items[0]['github_url']);
    same('101', $items[0]['github_issue']); same('501', $items[0]['zendesk_ticket']);
    same(1, $items[0]['pending_plan']); same(1, $items[0]['resolution']);
    same(false, str_contains(json_encode($items), 'UNAPPROVED PRIVATE TARGET'));
    same($before, $store->all());
    $local = new \Bridge\Observability\LinkedPairTable(
        new \Bridge\Application\Configuration('local', dirname($path), 'local/demo', 'local'));
    same(null, $local->project($store->monitoringSnapshot()['linked_pairs'])['items'][0]['github_url']);
    same(false, str_contains(renderLinks(new Prometheus($path), $store), 'Résumé'));
};

$tests['Malformed table subjects fail without entering metric labels or modifying stored evidence'] = static function (): void {
    $path = database(); $store = demoStore($path);
    $table = new \Bridge\Observability\LinkedPairTable(
        new \Bridge\Application\Configuration('local', dirname($path), 'local/demo', 'local'));
    $row = $store->monitoringSnapshot()['linked_pairs'][0];
    foreach (['', str_repeat('x', 501), "bad\xFF", ['not' => 'a subject']] as $title) {
        $invalid = array_replace($row, ['baseline' => ['title' => $title, 'state' => 'open']]);
        same('Linked pair table evidence is invalid.', raises(static fn(): array => $table->project([$invalid]))->getMessage());
    }
    $store->addPair('large-identifier', '9007199254740993', '9007199254740994');
    $overflow = array_replace($row, ['last_success' => PHP_INT_MAX]);
    same('Linked pair table evidence is invalid.', raises(static fn(): array => $table->project([$overflow]))->getMessage());
    $items = $table->project($store->monitoringSnapshot()['linked_pairs'])['items'];
    same('9007199254740993', $items[3]['github_issue']);
    same('9007199254740994', $items[3]['zendesk_ticket']);
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
    foreach (['metrics.sqlite', 'metrics.sqlite-wal', 'metrics.sqlite-shm',
        'business.sqlite', 'business.sqlite-wal', 'business.sqlite-shm'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory);
}
echo count($tests) . ' observability tests; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
