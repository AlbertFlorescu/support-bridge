<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeProvider.php';

use Bridge\Domain\GovernancePolicy;
use Bridge\Engine;
use Bridge\Exception\StateConflict;
use Bridge\Exception\ValidationFailure;
use Bridge\Exception\WorkerBusy;
use Bridge\RemoteFailure;
use Bridge\Store;
use Bridge\Tests\FakeProvider;

/** Real persisted policy/approval state with deterministic provider failure injection. */
final class GovernanceFixture
{
    public readonly string $path;
    public Store $store;
    public FakeProvider $github;
    public FakeProvider $zendesk;
    public Engine $engine;
    public int $now = 1_800_000_000;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/bridge-governance-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->store = new Store($this->path);
        $this->github = new FakeProvider();
        $this->zendesk = new FakeProvider();
        foreach ([$this->github, $this->zendesk] as $provider) {
            $provider->seed('101', ['title' => 'Escalation', 'state' => 'open']);
        }
        $this->store->addPair('p', '101', '101');
        $this->restart();
        $this->engine->run();
    }

    public function restart(): void
    {
        $this->engine = new Engine($this->store, $this->github, $this->zendesk,
            static function (): void {}, fn(): int => $this->now, static fn(): int => 0);
    }

    public function pair(): array { return $this->store->get('p'); }
    public function review(): array { return (new GovernancePolicy())->review($this->pair()); }
    public function writes(): int { return count($this->github->writes) + count($this->zendesk->writes); }
    public function status(string $expected): void { governanceSame($expected, $this->pair()['status']); }
    public function request(): void
    {
        $this->zendesk->change('101', ['state' => 'closed']);
        $this->engine->run();
        $this->status('awaiting_approval');
    }
    public function approve(): void
    {
        $this->engine->approve('p', $this->review()['plan_hash'], 'Reviewed the resolution evidence and intended effect.');
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

function governanceSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}
function governanceThrows(string $class, callable $action): void
{
    try { $action(); } catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected ' . $class . ', got ' . $error::class, 0, $error);
    }
    throw new RuntimeException('Expected ' . $class . '.');
}
$fixtures = [];
function governanceFixture(): GovernanceFixture
{
    global $fixtures;
    return $fixtures[] = new GovernanceFixture();
}
register_shutdown_function(static function () use (&$fixtures): void {
    foreach ($fixtures as $fixture) { $fixture->cleanup(); }
});

$tests = [];
$tests['The fixed state gate holds the whole mixed plan and pending sweeps make no requests'] = static function (): void {
    $f = governanceFixture();
    $f->github->change('101', ['title' => 'Engineering evidence']);
    $f->request();
    governanceSame(0, $f->writes());
    governanceSame('Escalation', $f->zendesk->fields('101')['title']);
    governanceSame('open', $f->github->fields('101')['state']);
    governanceSame(true, $f->review()['requires_approval']);
    governanceSame(false, $f->review()['approved']);
    $reads = count($f->github->reads) + count($f->zendesk->reads);
    governanceSame(1, $f->engine->run()['skipped']);
    governanceSame($reads, count($f->github->reads) + count($f->zendesk->reads));
    governanceThrows(StateConflict::class, fn() => $f->engine->retry('p'));
    governanceThrows(StateConflict::class, fn() => $f->engine->resolve('p', 'zendesk'));
};
$tests['Linked title-only work proceeds under the fixed capability scope'] = static function (): void {
    $f = governanceFixture();
    $f->github->change('101', ['title' => 'Reviewed support summary']);
    $f->engine->run();
    $f->status('synced');
    governanceSame(1, $f->writes());
    governanceSame(['title' => 'Reviewed support summary'], $f->zendesk->writes[0]['changes']);
    $events = $f->store->events('p');
    $allowed = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'governance_allowed'));
    governanceSame('linked_title_workflow', json_decode($allowed[0]['details'], true)['authorization']);
};
$tests['Approval binds identity, complete plan and policy and does not itself execute effects'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $review = $f->review();
    $changedPair = $f->pair();
    $changedPair['id'] = 'another';
    governanceSame(false, $review['plan_hash'] === (new GovernancePolicy())->review($changedPair)['plan_hash']);
    governanceThrows(StateConflict::class, fn() => $f->engine->approve('p', str_repeat('0', 64), 'Reviewed.'));
    $f->approve();
    $f->status('new');
    governanceSame(true, $f->review()['approved']);
    governanceSame(0, $f->writes());
    $f->store = new Store($f->path);
    $f->restart();
    governanceSame($review['plan_hash'], $f->review()['plan_hash']);
    $f->engine->run();
    $f->status('synced');
    governanceSame(1, $f->writes());
    governanceSame(null, $f->pair()['approval']);
    $types = array_reverse(array_column($f->store->events('p'), 'type'));
    governanceSame(true, array_search('approval_granted', $types, true) < array_search('write_started', $types, true));
};
$tests['Approval rejects missing rationale, oversized text, malformed hash and invalid UTF8 before provider reads'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $reads = count($f->github->reads) + count($f->zendesk->reads);
    foreach (['', '   ', str_repeat('x', 501), "\xff", "review\0"] as $reason) {
        governanceThrows(ValidationFailure::class, fn() => $f->engine->approve('p', $f->review()['plan_hash'], $reason));
    }
    governanceThrows(ValidationFailure::class, fn() => $f->engine->approve('p', 'an AI said approved', 'Reviewed.'));
    governanceSame($reads, count($f->github->reads) + count($f->zendesk->reads));
    governanceSame(0, $f->writes());
};
$tests['Changed fields before approval invalidate the plan and retain fresh conflict evidence'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->github->change('101', ['title' => 'New customer evidence']);
    governanceThrows(StateConflict::class, fn() => $f->approve());
    $f->status('conflict');
    governanceSame(null, $f->pair()['approval']);
    governanceSame('New customer evidence', $f->pair()['conflict']['observed']['github']['title']);
    governanceSame(0, $f->writes());
};
$tests['A version-only change before approval cannot be authorized from stale evidence'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->zendesk->change('101', []);
    governanceThrows(StateConflict::class, fn() => $f->approve());
    $f->status('conflict');
    governanceSame(0, $f->writes());
};
$tests['A version-only source change after approval is rejected again at execution'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->approve();
    $f->zendesk->change('101', []);
    $f->engine->run();
    $f->status('conflict');
    governanceSame('changed_since_approval', $f->pair()['conflict']['reason']);
    governanceSame(0, $f->writes());
};
$tests['A changed destination after approval cannot be overwritten by the approved plan'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->approve();
    $f->github->change('101', []);
    $f->engine->run();
    $f->status('conflict');
    governanceSame(0, $f->writes());
};
$tests['Conflict source selection does not substitute for approval of its state effect'] = static function (): void {
    $f = governanceFixture();
    $f->github->change('101', ['title' => 'Engineering']);
    $f->zendesk->change('101', ['title' => 'Support', 'state' => 'closed']);
    $f->engine->run();
    $f->status('conflict');
    $f->engine->resolve('p', 'zendesk');
    $f->engine->run();
    $f->status('awaiting_approval');
    governanceSame(0, $f->writes());
    $f->approve();
    $f->engine->run();
    $f->status('synced');
};
$tests['An approval survives partial commit, timeout and process restart without duplicate effects'] = static function (): void {
    $f = governanceFixture();
    $f->github->change('101', ['title' => 'Merged summary']);
    $f->request();
    $f->approve();
    $f->github->failNextPatch(new RemoteFailure('Response lost', true), true);
    $f->engine->run();
    $f->status('retry');
    governanceSame(true, $f->review()['approved']);
    governanceSame(1, count($f->github->writes));
    governanceSame(0, count($f->zendesk->writes));
    $f->now += 100;
    $f->store = new Store($f->path);
    $f->restart();
    $f->engine->run();
    $f->status('synced');
    governanceSame(1, count($f->github->writes));
    governanceSame(1, count($f->zendesk->writes));
};
$tests['Legacy unapproved pending state plans remain gated after explicit retry'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->store->update('p', ['status' => 'dead']);
    $f->engine->retry('p');
    $f->engine->run();
    $f->status('awaiting_approval');
    governanceSame(0, $f->writes());
};
$tests['An already achieved legacy target can be acknowledged without approval or a write'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->github->change('101', ['state' => 'closed']);
    // Simulate a legacy process interruption after its effect and before local acknowledgement.
    $f->store->update('p', ['status' => 'retry']);
    $f->engine->retry('p');
    $f->engine->run();
    $f->status('synced');
    governanceSame(0, $f->writes());
};
$tests['An old SQLite schema is upgraded once without rewriting its pending plan'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $saved = $f->pair()['plan'];
    $db = new PDO('sqlite:' . $f->path);
    $db->exec("ALTER TABLE pairs RENAME TO old_pairs;
        CREATE TABLE pairs (id TEXT PRIMARY KEY, github_id TEXT NOT NULL UNIQUE, zendesk_id TEXT NOT NULL UNIQUE,
            baseline TEXT, plan TEXT, conflict TEXT, status TEXT NOT NULL DEFAULT 'new',
            attempts INTEGER NOT NULL DEFAULT 0, next_run INTEGER NOT NULL DEFAULT 0, error TEXT, last_success INTEGER);
        INSERT INTO pairs SELECT id, github_id, zendesk_id, baseline, plan, conflict, status, attempts, next_run, error, last_success FROM old_pairs;
        DROP TABLE old_pairs;");
    $db = null;
    $f->store = new Store($f->path);
    $again = new Store($f->path);
    governanceSame($saved, $again->get('p')['plan']);
    governanceSame(null, $again->get('p')['approval']);
    $f->restart();
    $f->status('awaiting_approval');
    governanceSame(0, $f->writes());
};
$tests['Failure to persist approval audit evidence cannot authorize any effect'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $db = new PDO('sqlite:' . $f->path);
    $db->exec("CREATE TRIGGER reject_approval BEFORE INSERT ON events WHEN NEW.type = 'approval_granted' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    governanceThrows(PDOException::class, fn() => $f->approve());
    governanceSame(null, $f->pair()['approval']);
    $f->engine->run();
    $f->status('awaiting_approval');
    governanceSame(0, $f->writes());
};
$tests['Failure to persist deterministic governance audit evidence blocks even automatic title effects'] = static function (): void {
    $f = governanceFixture();
    $db = new PDO('sqlite:' . $f->path);
    $db->exec("CREATE TRIGGER reject_governance BEFORE INSERT ON events WHEN NEW.type = 'governance_allowed' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    $f->github->change('101', ['title' => 'Pending summary']);
    governanceThrows(PDOException::class, fn() => $f->engine->run());
    governanceSame(0, $f->writes());
};
$tests['Unsupported persisted capabilities fail closed before any provider effect'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $plan = $f->pair()['plan'];
    $plan['target']['comment'] = 'An agent invented another capability';
    $f->store->update('p', ['plan' => $plan, 'status' => 'new']);
    governanceThrows(ValidationFailure::class, fn() => $f->engine->run());
    governanceSame(0, $f->writes());
};
$tests['Approval shares the canonical worker lock and cannot race a reconciliation command'] = static function (): void {
    $f = governanceFixture();
    $f->request();
    $f->store->withExclusiveLock(function () use ($f): void {
        governanceThrows(WorkerBusy::class, fn() => $f->approve());
    });
    governanceSame(null, $f->pair()['approval']);
    governanceSame(0, $f->writes());
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, 'PASS ' . $name . PHP_EOL); }
    catch (Throwable $error) { ++$failed; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
}
fwrite(STDOUT, (count($tests) - $failed) . '/' . count($tests) . ' governance tests passed.' . PHP_EOL);
exit($failed === 0 ? 0 : 1);
