<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Engine;
use Bridge\Http\Api;
use Bridge\Http\Request;
use Bridge\Simulation\TrafficSettings;
use Bridge\Simulation\TrafficUnavailable;
use Bridge\Simulation\Workload;
use Bridge\RemoteFailure;

function trafficSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function trafficThrows(callable $operation, string $class = Throwable::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected an operation to be rejected.');
}

final class TrafficFixture
{
    public readonly string $home;
    public readonly Context $context;
    public Workload $workload;
    public int $now = 1_800_000_000;
    public array $calls = [];

    public function __construct(string $review = 'scripted')
    {
        $this->home = sys_get_temp_dir() . '/bridge-traffic-' . bin2hex(random_bytes(8));
        $this->context = new Context(new Configuration('local', $this->home, 'local/demo', 'local'));
        $this->context->initialize();
        $this->workload = $this->restart($review);
        $this->workload->provision();
        $this->context->engine()->run();
    }

    public function restart(string $review = 'scripted'): Workload
    {
        $token = str_repeat('t', 40);
        $api = new Api(fn(): Context => $this->context, $token);
        return new Workload($this->context, function (string $method, string $path, array $body) use ($api, $token): array {
            $this->calls[] = [$method, $path];
            $response = $api->handle(new Request($method, $path,
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                json_encode((object) $body, JSON_THROW_ON_ERROR)));
            return ['status' => $response->status, 'body' => $response->body];
        }, $review, fn(): int => $this->now);
    }

    public function recoverWhenDue(): void
    {
        [$github, $zendesk] = $this->context->providers();
        (new Engine($this->context->store, $github, $zendesk, static function (): void {},
            static fn(): int => time() + 3600, static fn(): int => 0))->run();
    }

    public function cleanup(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->home);
    }
}

$tests = [];
$tests['Cadences and random bounds reject invalid configuration'] = static function (): void {
    trafficSame(1, (new TrafficSettings('1'))->delay());
    trafficSame(5, (new TrafficSettings('5'))->delay());
    $random = new TrafficSettings('random', 2, 4);
    for ($i = 0; $i < 50; ++$i) {
        $delay = $random->delay();
        trafficSame(true, $delay >= 2 && $delay <= 4);
    }
    trafficSame(4, $random->maximumDelay());
    foreach (['0', '2', '60', 'RANDOM', '1.0', ''] as $interval) {
        trafficThrows(static fn() => new TrafficSettings($interval), InvalidArgumentException::class);
    }
    foreach ([[0, 5], [1, 61], [5, 1]] as [$minimum, $maximum]) {
        trafficThrows(static fn() => new TrafficSettings('random', $minimum, $maximum), InvalidArgumentException::class);
    }
    trafficThrows(static fn() => new TrafficSettings(review: 'auto'), InvalidArgumentException::class);
    trafficThrows(static fn() => new TrafficSettings(maxTicks: 0), InvalidArgumentException::class);
};
$tests['Environment requires local mode and explicit traffic opt-in'] = static function (): void {
    $names = ['BRIDGE_MODE', 'BRIDGE_TRAFFIC_ENABLED', 'BRIDGE_TRAFFIC_INTERVAL', 'BRIDGE_TRAFFIC_RANDOM_MIN',
        'BRIDGE_TRAFFIC_RANDOM_MAX', 'BRIDGE_TRAFFIC_REVIEW', 'BRIDGE_TRAFFIC_MAX_TICKS'];
    $previous = array_combine($names, array_map(getenv(...), $names));
    try {
        foreach ($names as $name) {
            putenv($name);
        }
        trafficThrows(TrafficSettings::fromEnvironment(...));
        putenv('BRIDGE_MODE=live');
        putenv('BRIDGE_TRAFFIC_ENABLED=1');
        trafficThrows(TrafficSettings::fromEnvironment(...));
        putenv('BRIDGE_MODE=local');
        putenv('BRIDGE_TRAFFIC_ENABLED=0');
        trafficThrows(TrafficSettings::fromEnvironment(...));
        putenv('BRIDGE_TRAFFIC_ENABLED=1');
        trafficSame('5', TrafficSettings::fromEnvironment()->interval);
        putenv('BRIDGE_TRAFFIC_MAX_TICKS=50');
        trafficSame(50, TrafficSettings::fromEnvironment()->maxTicks);
        putenv('BRIDGE_TRAFFIC_RANDOM_MIN=1.5');
        trafficThrows(TrafficSettings::fromEnvironment(...), InvalidArgumentException::class);
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }
};
$tests['Provisioning is bounded, idempotent and preserves source edits across restarts'] = static function (): void {
    $f = new TrafficFixture();
    try {
        trafficSame(9, count($f->context->store->all()));
        [$github, $zendesk] = $f->context->providers();
        $sample = $github->fetch('101');
        $github->edit('91001', ['title' => 'An edit retained across restart']);
        $edited = $github->fetch('91001');
        $f->restart()->provision();
        trafficSame($edited->fields, $github->fetch('91001')->fields);
        trafficSame($edited->version, $github->fetch('91001')->version);
        trafficSame($sample->fields, $github->fetch('101')->fields);
        trafficSame($sample->version, $github->fetch('101')->version);
        trafficSame(9, count($f->context->store->all()));
        trafficSame(true, in_array(['POST', '/api/pairs'], $f->calls, true));
    } finally { $f->cleanup(); }
};
$tests['Ownership and mapping collisions stop before adopting existing work'] = static function (): void {
    $f = new TrafficFixture();
    try {
        [$github] = $f->context->providers();
        trafficThrows(static fn() => $github->ensureRecord('91001', ['title' => 'Collision', 'state' => 'open'], str_repeat('a', 32)));
        $marker = $f->home . '/traffic-owner.json';
        unlink($marker);
        trafficThrows($f->restart()->provision(...));
        trafficSame(9, count($f->context->store->all()));
    } finally { $f->cleanup(); }
    $f = new TrafficFixture();
    try {
        $f->context->link('unrelated', '12345', '54321');
        trafficThrows($f->restart()->provision(...));
    } finally { $f->cleanup(); }
};
$tests['Source title traffic traverses the operator API and converges'] = static function (): void {
    $f = new TrafficFixture();
    try {
        trafficSame('support_title', $f->workload->tick(1)['action']);
        trafficSame('engineering_title', $f->workload->tick(2)['action']);
        [$github, $zendesk] = $f->context->providers();
        foreach ([1, 2] as $i) {
            trafficSame($github->fetch((string) (91000 + $i))->fields, $zendesk->fetch((string) (95000 + $i))->fields);
        }
        trafficSame(true, in_array(['POST', '/api/sync'], $f->calls, true));
        trafficSame('no_op', $f->workload->tick(8)['action']);
    } finally { $f->cleanup(); }
};
$tests['A mixed plan stays held until exact scripted approval after the observation delay'] = static function (): void {
    $f = new TrafficFixture();
    try {
        trafficSame('independent_merge', $f->workload->tick(3)['action']);
        $pair = $f->context->store->get('traffic-3');
        trafficSame('awaiting_approval', $pair['status']);
        trafficSame(null, $pair['approval']);
        [$github, $zendesk] = $f->context->providers();
        trafficSame(0, $github->writes() + $zendesk->writes());
        trafficSame('review_wait', $f->workload->tick(11)['action']);
        $f->now += 9;
        trafficSame('review_wait', $f->workload->tick(19)['action']);
        $f->now += 1;
        trafficSame('scripted_approval', $f->workload->tick(27)['action']);
        trafficSame('synced', $f->context->store->get('traffic-3')['status']);
        trafficSame($github->fetch('91003')->fields, $zendesk->fetch('95003')->fields);
        $events = $f->context->store->events('traffic-3');
        $approvals = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'approval_granted'));
        trafficSame(1, count($approvals));
        trafficSame(true, str_contains($approvals[0]['details'], 'Synthetic scripted review'));
    } finally { $f->cleanup(); }
};
$tests['Manual review leaves state changes and conflicts awaiting an operator'] = static function (): void {
    $f = new TrafficFixture('manual');
    try {
        $f->workload->tick(4);
        $f->workload->tick(5);
        $f->workload->tick(12);
        $f->workload->tick(13);
        $f->now += 100;
        trafficSame('review_wait', $f->workload->tick(20)['action']);
        trafficSame('review_wait', $f->workload->tick(21)['action']);
        trafficSame('awaiting_approval', $f->context->store->get('traffic-4')['status']);
        trafficSame('conflict', $f->context->store->get('traffic-5')['status']);
        trafficSame([], array_values(array_filter($f->calls, static fn(array $call): bool => str_ends_with($call[1], '/approve')
            || str_ends_with($call[1], '/resolve'))));
    } finally { $f->cleanup(); }
};
$tests['Scripted conflict resolution uses the observed source through the normal API'] = static function (): void {
    $f = new TrafficFixture();
    try {
        $f->workload->tick(5);
        trafficSame('conflict', $f->context->store->get('traffic-5')['status']);
        $f->workload->tick(13);
        $f->now += 10;
        trafficSame('scripted_resolution', $f->workload->tick(21)['action']);
        trafficSame('synced', $f->context->store->get('traffic-5')['status']);
        trafficSame(true, in_array(['POST', '/api/pairs/traffic-5/resolve'], $f->calls, true));
    } finally { $f->cleanup(); }
};
$tests['Changed evidence rejects a scripted approval without bypassing governance'] = static function (): void {
    $f = new TrafficFixture();
    try {
        $f->workload->tick(4);
        $f->workload->tick(12);
        [$github] = $f->context->providers();
        $github->edit('91004', ['title' => 'New external evidence']);
        $f->now += 10;
        trafficThrows(static fn() => $f->workload->tick(20), TrafficUnavailable::class);
        trafficSame('conflict', $f->context->store->get('traffic-4')['status']);
        trafficSame(null, $f->context->store->get('traffic-4')['approval']);
    } finally { $f->cleanup(); }
};
$tests['Pre-write failures retain pending intent and normal retry scheduling'] = static function (): void {
    $f = new TrafficFixture();
    try {
        $f->workload->tick(6);
        $pair = $f->context->store->get('traffic-6');
        trafficSame('retry', $pair['status']);
        trafficSame(1, $pair['attempts']);
        trafficSame(true, $pair['next_run'] > time());
        trafficSame(true, $pair['plan'] !== null);
        [, $zendesk] = $f->context->providers();
        trafficSame(0, $zendesk->writes());
        $f->recoverWhenDue();
        trafficSame('synced', $f->context->store->get('traffic-6')['status']);
        trafficSame(1, $zendesk->writes());
    } finally { $f->cleanup(); }
};
$tests['Lost-response recovery acknowledges the committed target without another patch'] = static function (): void {
    $f = new TrafficFixture();
    try {
        $f->workload->tick(7);
        $pair = $f->context->store->get('traffic-7');
        trafficSame('retry', $pair['status']);
        [, $zendesk] = $f->context->providers();
        trafficSame(1, $zendesk->writes());
        $f->restart()->provision();
        trafficSame($pair['plan'], $f->context->store->get('traffic-7')['plan']);
        trafficSame($pair['next_run'], $f->context->store->get('traffic-7')['next_run']);
        $f->recoverWhenDue();
        trafficSame('synced', $f->context->store->get('traffic-7')['status']);
        trafficSame(1, $zendesk->writes());
    } finally { $f->cleanup(); }
};
$tests['Record-specific faults survive patches to other records and can coexist'] = static function (): void {
    $f = new TrafficFixture();
    try {
        [, $zendesk] = $f->context->providers();
        $zendesk->failNext('before', '95006');
        $zendesk->failNext('after', '95007');
        $zendesk->patch('95001', ['title' => 'An unrelated write'], $zendesk->fetch('95001'));
        trafficSame(1, $zendesk->writes());
        trafficThrows(static fn() => $zendesk->patch('95006', ['title' => 'Before-fault target'], $zendesk->fetch('95006')),
            RemoteFailure::class);
        trafficSame(1, $zendesk->writes());
        trafficSame('Report generation intermittently times out', $zendesk->fetch('95006')->fields['title']);
        trafficThrows(static fn() => $zendesk->patch('95007', ['title' => 'After-fault target'], $zendesk->fetch('95007')),
            RemoteFailure::class);
        trafficSame(2, $zendesk->writes());
        trafficSame('After-fault target', $zendesk->fetch('95007')->fields['title']);
        $zendesk->patch('95006', ['title' => 'Before-fault target'], $zendesk->fetch('95006'));
        trafficSame(3, $zendesk->writes());
    } finally { $f->cleanup(); }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
echo count($tests) . ' synthetic traffic checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
