<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeProvider.php';

use Bridge\Engine;
use Bridge\Domain\GovernancePolicy;
use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Exception\ValidationFailure;
use Bridge\Exception\WorkerBusy;
use Bridge\RemoteFailure;
use Bridge\Store;
use Bridge\Tests\FakeProvider;

/** Framework-free integration tests: real SQLite state, deterministic fake remote APIs. */
final class TestClock
{
    public int $now = 1_700_000_000;
}

final class Fixture
{
    public Store $store;
    public FakeProvider $github;
    public FakeProvider $zendesk;
    public TestClock $clock;
    public Engine $engine;
    public array $logs = [];
    public string $path;

    public function __construct(array $github = ['title' => 'Original', 'state' => 'open'], ?array $zendesk = null)
    {
        $this->path = sys_get_temp_dir() . '/support-bridge-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->store = new Store($this->path);
        $this->github = new FakeProvider();
        $this->zendesk = new FakeProvider();
        $this->clock = new TestClock();
        $this->github->seed('101', $github);
        $this->zendesk->seed('201', $zendesk ?? $github);
        $this->store->addPair('pair-1', '101', '201');
        $this->restart();
    }

    public function restart(): void
    {
        $this->engine = new Engine(
            $this->store,
            $this->github,
            $this->zendesk,
            function (...$args): void { $this->logs[] = $args; },
            fn (): int => $this->clock->now,
            static fn (): int => 0,
        );
    }

    public function baseline(): void
    {
        $this->engine->run();
        $this->status('synced');
        same(0, count($this->github->writes) + count($this->zendesk->writes), 'Bootstrap must not write.');
    }

    public function approve(): void
    {
        $this->status('awaiting_approval');
        $review = (new GovernancePolicy())->review($this->store->get('pair-1'));
        $this->engine->approve('pair-1', $review['plan_hash'], 'Test operator reviewed the proposed resolution change.');
    }

    public function status(string $expected, string $id = 'pair-1'): void
    {
        same($expected, $this->store->get($id)['status'], 'Pair status');
    }

    public function due(): void
    {
        $next = $this->store->get('pair-1')['next_run'];
        $this->clock->now = max($this->clock->now + 1, (int) $next);
    }

    public function assertConverged(array $fields): void
    {
        same($fields, $this->github->fields('101'), 'GitHub fields');
        same($fields, $this->zendesk->fields('201'), 'Zendesk fields');
        same($fields, $this->store->get('pair-1')['baseline'], 'Durable baseline');
        $this->status('synced');
    }

    public function cleanup(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm', $this->path . '.lock'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

function same(mixed $expected, mixed $actual, string $context = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($context . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(string $exceptionClass, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException('Expected ' . $exceptionClass . ', got ' . $error::class, 0, $error);
    }
    throw new RuntimeException('Expected ' . $exceptionClass . ' but no exception was raised.');
}

/** @var list<Fixture> $fixtures */
$fixtures = [];
function fixture(array $github = ['title' => 'Original', 'state' => 'open'], ?array $zendesk = null): Fixture
{
    global $fixtures;
    return $fixtures[] = new Fixture($github, $zendesk);
}
register_shutdown_function(static function () use (&$fixtures): void {
    foreach ($fixtures as $fixture) {
        $fixture->cleanup();
    }
});

$tests = [];
$tests['Equal initial records establish a baseline; repeated polls do not write'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->engine->run();
    same(0, count($f->github->writes) + count($f->zendesk->writes));
    $f->assertConverged(['title' => 'Original', 'state' => 'open']);
};

$tests['A GitHub edit propagates only changed canonical fields'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Fixed checkout']);
    $f->engine->run();
    $f->assertConverged(['title' => 'Fixed checkout', 'state' => 'open']);
    same([['id' => '201', 'changes' => ['title' => 'Fixed checkout']]], $f->zendesk->writes);
    same([], $f->github->writes);
};

$tests['A Zendesk state edit propagates back to GitHub'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->engine->run();
    $f->approve();
    $f->engine->run();
    $f->assertConverged(['title' => 'Original', 'state' => 'closed']);
    same([['id' => '101', 'changes' => ['state' => 'closed']]], $f->github->writes);
};

$tests['Independent edits merge field by field in both directions'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'New title']);
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->engine->run();
    $f->approve();
    $f->engine->run();
    $f->assertConverged(['title' => 'New title', 'state' => 'closed']);
    same(['state' => 'closed'], $f->github->writes[0]['changes']);
    same(['title' => 'New title'], $f->zendesk->writes[0]['changes']);
};

$tests['Equal concurrent edits advance the baseline without writes'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Same edit']);
    $f->zendesk->change('201', ['title' => 'Same edit']);
    $f->engine->run();
    $f->assertConverged(['title' => 'Same edit', 'state' => 'open']);
    same(0, count($f->github->writes) + count($f->zendesk->writes));
};

$tests['Different initial records require a deliberate source choice'] = static function (): void {
    $f = fixture(['title' => 'Engineering title', 'state' => 'open'], ['title' => 'Customer title', 'state' => 'open']);
    $f->engine->run();
    $f->status('conflict');
    same(0, count($f->github->writes) + count($f->zendesk->writes));
    $f->engine->resolve('pair-1', 'zendesk');
    $f->engine->run();
    $f->assertConverged(['title' => 'Customer title', 'state' => 'open']);
};

$tests['Divergent same-field edits quarantine the entire pair'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Engineering title']);
    $f->zendesk->change('201', ['title' => 'Customer title', 'state' => 'closed']);
    $f->engine->run();
    $f->status('conflict');
    same(0, count($f->github->writes) + count($f->zendesk->writes), 'No partial field merge during conflict.');
    $reads = count($f->github->reads) + count($f->zendesk->reads);
    $f->engine->run();
    same($reads, count($f->github->reads) + count($f->zendesk->reads), 'Quarantined pair must await operator.');
    try { $f->engine->retry('pair-1'); } catch (RuntimeException) { /* Refusal may be explicit. */ }
    $f->engine->run();
    $f->status('conflict');
    same(0, count($f->github->writes) + count($f->zendesk->writes));
};

$tests['Manual resolution honors the selected current snapshot'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Engineering decision']);
    $f->zendesk->change('201', ['title' => 'Support decision']);
    $f->engine->run();
    $f->engine->resolve('pair-1', 'github');
    $f->engine->run();
    $f->assertConverged(['title' => 'Engineering decision', 'state' => 'open']);
};

$tests['Stale manual resolution refuses to overwrite a newer user edit'] = static function (): void {
    $f = fixture(['title' => 'Engineering', 'state' => 'open'], ['title' => 'Support', 'state' => 'open']);
    $f->engine->run();
    $f->zendesk->change('201', ['title' => 'New customer context']);
    try { $f->engine->resolve('pair-1', 'github'); } catch (RuntimeException) { /* A fresh decision is required. */ }
    $f->engine->run();
    $f->status('conflict');
    same('New customer context', $f->zendesk->fields('201')['title']);
    same(0, count($f->github->writes) + count($f->zendesk->writes));
};

$tests['A revert since conflict inspection still requires a fresh resolution decision'] = static function (): void {
    $f = fixture(['title' => 'Engineering', 'state' => 'open'], ['title' => 'Support', 'state' => 'open']);
    $f->engine->run();
    $f->zendesk->change('201', ['title' => 'New customer context']);
    $f->zendesk->change('201', ['title' => 'Support']);
    $thrown = false;
    try { $f->engine->resolve('pair-1', 'github'); } catch (RuntimeException) { $thrown = true; }
    check($thrown, 'A later remote version must invalidate the observed conflict snapshot.');
    $f->status('conflict');
    same(0, count($f->github->writes) + count($f->zendesk->writes));
    // The failed choice refreshes the snapshot; a deliberate second decision can proceed.
    $f->engine->resolve('pair-1', 'github');
    $f->engine->run();
    $f->assertConverged(['title' => 'Engineering', 'state' => 'open']);
};

$tests['A persisted plan survives process restart before its first write'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $before = ['github' => $f->github->fields('101'), 'zendesk' => $f->zendesk->fields('201')];
    $f->github->change('101', ['title' => 'Durable target']);
    $before['github'] = $f->github->fields('101');
    $target = ['title' => 'Durable target', 'state' => 'open'];
    $versions = ['github' => $f->github->fetch('101')->version, 'zendesk' => $f->zendesk->fetch('201')->version];
    $f->store->update('pair-1', ['plan' => ['target' => $target, 'before' => $before, 'versions' => $versions]]);
    unset($f->engine, $f->store);
    $f->store = new Store($f->path);
    $f->restart();
    $f->engine->run();
    $f->assertConverged($target);
};

$tests['Timeout after commit is detected on read and never writes twice'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Committed despite timeout']);
    $f->zendesk->failNextPatch(new RemoteFailure('Connection lost after commit', true), true);
    $f->engine->run();
    $f->status('retry');
    same(1, count($f->zendesk->writes));
    $f->due();
    $f->restart();
    $f->engine->run();
    $f->assertConverged(['title' => 'Committed despite timeout', 'state' => 'open']);
    same(1, count($f->zendesk->writes), 'Committed patch must not be repeated.');
};

$tests['One side commits and the second fails; retry completes the durable target'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Merged target']);
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->zendesk->failNextPatch(new RemoteFailure('Temporarily unavailable', true));
    $f->engine->run();
    $f->approve();
    $f->engine->run();
    $f->status('retry');
    same(['title' => 'Merged target', 'state' => 'closed'], $f->github->fields('101'));
    same(['title' => 'Original', 'state' => 'closed'], $f->zendesk->fields('201'));
    $f->due();
    $f->engine->run();
    $f->assertConverged(['title' => 'Merged target', 'state' => 'closed']);
    same(1, count($f->github->writes));
    same(1, count($f->zendesk->writes));
};

$tests['A fresh user edit during partial failure is preserved and quarantined'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Planned title']);
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->zendesk->failNextPatch(new RemoteFailure('Unavailable', true));
    $f->engine->run();
    $f->approve();
    $f->engine->run();
    $f->zendesk->change('201', ['title' => 'New customer edit']);
    $f->due();
    $f->engine->run();
    $f->status('conflict');
    same('New customer edit', $f->zendesk->fields('201')['title']);
    same(0, count($f->zendesk->writes));
};

$tests['A deliberate reversion after a partial commit is not mistaken for an unfinished write'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Planned title']);
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->zendesk->failNextPatch(new RemoteFailure('Unavailable', true));
    $f->engine->run();
    $f->approve();
    $f->engine->run();
    $f->status('retry');
    // First destination committed state=closed, then a user deliberately reverted it.
    // Its fields equal the old preimage again, but the new version proves a later edit.
    $f->github->change('101', ['state' => 'open']);
    $f->due();
    $f->engine->run();
    $f->status('conflict');
    same('open', $f->github->fields('101')['state']);
    same(1, count($f->github->writes), 'Retry must not overwrite the deliberate reversion.');
    same(0, count($f->zendesk->writes), 'Conflict must stop all pending writes.');
};

$tests['Rate limiting persists Retry-After and prevents early requests'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Rate limited']);
    $f->zendesk->failNextPatch(new RemoteFailure('HTTP 429', true, 120));
    $f->engine->run();
    $f->status('retry');
    check((int) $f->store->get('pair-1')['next_run'] >= $f->clock->now + 120, 'Retry-After must be respected.');
    $reads = count($f->github->reads) + count($f->zendesk->reads);
    $f->clock->now += 119;
    $f->engine->run();
    same($reads, count($f->github->reads) + count($f->zendesk->reads));
    $f->due();
    $f->engine->run();
    $f->assertConverged(['title' => 'Rate limited', 'state' => 'open']);
};

$tests['Permanent failures stop automatic retries and remain inspectable'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->zendesk->failNextFetch(new RemoteFailure('HTTP 403: forbidden'));
    $f->engine->run();
    $f->status('dead');
    $reads = count($f->github->reads) + count($f->zendesk->reads);
    $f->clock->now += 86400;
    $f->engine->run();
    same($reads, count($f->github->reads) + count($f->zendesk->reads));
    check(str_contains(json_encode($f->store->get('pair-1'), JSON_THROW_ON_ERROR), 'forbidden'), 'Failure reason must be persisted.');
};

$tests['Retryable failures exhaust after five attempts; explicit retry can recover'] = static function (): void {
    $f = fixture();
    $f->baseline();
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        $f->github->failNextFetch(new RemoteFailure('HTTP 503', true));
        $f->engine->run();
        $f->status($attempt === 5 ? 'dead' : 'retry');
        $f->due();
    }
    same(5, $f->store->get('pair-1')['attempts']);
    $f->engine->retry('pair-1');
    $f->engine->run();
    $f->assertConverged(['title' => 'Original', 'state' => 'open']);
    same(0, $f->store->get('pair-1')['attempts']);
};

$tests['One failed pair does not prevent another pair from synchronizing'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->seed('102', ['title' => 'Second', 'state' => 'open']);
    $f->zendesk->seed('202', ['title' => 'Second', 'state' => 'open']);
    $f->store->addPair('pair-2', '102', '202');
    $f->engine->run();
    $f->github->failNextFetch(new RemoteFailure('Unreachable', true));
    $f->github->change('102', ['title' => 'Second updated']);
    $f->engine->run();
    $f->status('retry');
    $f->status('synced', 'pair-2');
    same('Second updated', $f->zendesk->fields('202')['title']);
};

$tests['Optimistic concurrency failure cannot overwrite an intervening remote edit'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->github->change('101', ['title' => 'Planned title']);
    $f->zendesk->beforePatch = static function (FakeProvider $provider, string $id): void {
        $provider->change($id, ['title' => 'Concurrent remote edit']);
    };
    $f->engine->run();
    same('Concurrent remote edit', $f->zendesk->fields('201')['title']);
    same(0, count($f->zendesk->writes));
    $f->due();
    $f->engine->run();
    $f->status('conflict');
    same('Concurrent remote edit', $f->zendesk->fields('201')['title']);
};

$tests['Pair identity and each remote link are unique'] = static function (): void {
    $f = fixture();
    foreach ([['pair-1', '102', '202'], ['pair-2', '101', '202'], ['pair-2', '102', '201']] as $ids) {
        $thrown = false;
        try { $f->store->addPair(...$ids); } catch (Throwable) { $thrown = true; }
        check($thrown, 'Duplicate pair IDs and reused remote records must be rejected.');
    }
    same(1, count($f->store->all()));
};

$tests['An immutable destination prevents writes to either side'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $f->zendesk->seed('201', $f->zendesk->fields('201'), false);
    $f->github->change('101', ['title' => 'Cannot reach immutable ticket']);
    $f->zendesk->change('201', ['state' => 'closed']);
    $f->engine->run();
    $f->status('dead');
    same(0, count($f->github->writes) + count($f->zendesk->writes));
    same('open', $f->github->fields('101')['state'], 'Preflight must prevent a partial merge.');
};

$tests['A second worker cannot run while the database lock is held'] = static function (): void {
    $f = fixture();
    $lock = $f->store->lock();
    $thrown = false;
    try {
        try { $f->engine->run(); } catch (RuntimeException) { $thrown = true; }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    check($thrown, 'Overlapping workers must be rejected.');
    same([], $f->github->reads, 'Rejected worker must not contact providers.');
    same([], $f->zendesk->reads);
    $f->baseline();
};

$tests['Provider identity binding survives reopen and rejects repository or account changes'] = static function (): void {
    $f = fixture();
    $identity = ['mode' => 'live', 'repository' => 'acme/widget', 'subdomain' => 'acme'];
    $f->store->bindIdentity($identity);
    $reopened = new Store($f->path);
    $reopened->bindIdentity(['subdomain' => 'acme', 'repository' => 'acme/widget', 'mode' => 'live']);
    foreach ([['repository' => 'acme/other'], ['subdomain' => 'other'], ['mode' => 'local']] as $change) {
        $thrown = false;
        try { $reopened->bindIdentity(array_replace($identity, $change)); } catch (RuntimeException) { $thrown = true; }
        check($thrown, 'Existing issue/ticket IDs must not be retargeted to different provider identities.');
    }
    $reopened->bindIdentity($identity);
    same('101', $reopened->get('pair-1')['github_id']);
    same('201', $reopened->get('pair-1')['zendesk_id']);
};

$tests['Database symlinks share the canonical worker lock'] = static function (): void {
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
        return; // Native Windows may not grant symlink permission; Linux/CI exercises this case.
    }
    $f = fixture();
    $alias = $f->path . '.alias';
    check(symlink($f->path, $alias), 'Create a database symlink for lock alias coverage.');
    $lock = null;
    $otherLock = null;
    try {
        $aliasStore = new Store($alias);
        $lock = $f->store->lock();
        $thrown = false;
        try { $otherLock = $aliasStore->lock(); } catch (RuntimeException) { $thrown = true; }
        check($thrown, 'Different paths to the same database must not permit overlapping workers.');
    } finally {
        foreach ([$otherLock, $lock] as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        @unlink($alias);
        if (is_file($alias . '.lock')) {
            @unlink($alias . '.lock');
        }
    }
};

$tests['Metrics, audit events, and structured logs expose reconciliation results'] = static function (): void {
    $f = fixture();
    $f->baseline();
    $metrics = $f->store->metrics();
    same(1, $metrics['pairs']['synced']);
    same(0, $metrics['pending_plans']);
    same($f->clock->now, (int) $metrics['last_success']);
    $types = array_column($f->store->events('pair-1'), 'type');
    check(in_array('linked', $types, true) && in_array('converged', $types, true), 'Audit history must expose linkage and convergence.');
    $log = $f->logs[0][0] ?? [];
    same('pair-1', $log['pair'] ?? null);
    same('synced', $log['status'] ?? null);
    check(isset($log['duration_ms']), 'Structured logs must include reconciliation latency.');
};

$tests['Application errors distinguish missing pairs, invalid inputs, and state conflicts'] = static function (): void {
    $f = fixture();
    $f->baseline();
    throws(NotFound::class, fn () => $f->store->get('missing'));
    throws(NotFound::class, fn () => $f->store->update('missing', ['attempts' => 1]));
    throws(NotFound::class, fn () => $f->engine->retry('missing'));
    throws(ValidationFailure::class, fn () => $f->store->addPair('invalid pair', '102', '202'));
    throws(ValidationFailure::class, fn () => $f->engine->resolve('pair-1', 'neither'));
    throws(StateConflict::class, fn () => $f->engine->resolve('pair-1', 'github'));
    throws(StateConflict::class, fn () => $f->engine->retry('pair-1'));
};

$tests['Duplicate links return a state conflict and roll back without poisoning later writes'] = static function (): void {
    $f = fixture();
    throws(StateConflict::class, fn () => $f->store->addPair('pair-2', '101', '202'));
    same(1, count($f->store->all()));
    $f->store->addPair('pair-2', '102', '202');
    same(2, count($f->store->all()));
    same(['linked'], array_column($f->store->events('pair-2'), 'type'));
};

$tests['Lock scopes release after exceptions and preserve callback results'] = static function (): void {
    $f = fixture();
    throws(StateConflict::class, fn () => $f->store->withExclusiveLock(static function (): void {
        throw new StateConflict('Operation rejected.');
    }));
    same(42, $f->store->withExclusiveLock(static fn (): int => 42));
    $f->store->withExclusiveLock(function () use ($f): void {
        throws(WorkerBusy::class, fn () => $f->engine->run());
    });
    $f->baseline();
};

$tests['Unexpected database failures remain database failures rather than duplicate-link conflicts'] = static function (): void {
    $f = fixture();
    $connection = new PDO('sqlite:' . $f->path);
    $connection->exec('DROP TABLE pairs');
    throws(PDOException::class, fn () => $f->store->addPair('pair-2', '102', '202'));
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . PHP_EOL . '  ' . str_replace("\n", "\n  ", $error->getMessage()) . PHP_EOL);
    }
}
fwrite(STDOUT, PHP_EOL . (count($tests) - $failed) . '/' . count($tests) . ' reconciliation tests passed.' . PHP_EOL);
exit($failed === 0 ? 0 : 1);
