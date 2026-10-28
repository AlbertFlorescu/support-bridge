<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Command\InitializeLocalState;
use Bridge\Application\Command\Reconcile;
use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Exception\NotFound;
use Bridge\Simulation\Replay\CaptureConsole;
use Bridge\Simulation\Replay\CheckpointCapture;
use Bridge\Simulation\Replay\EvidenceArchive;
use Bridge\Simulation\Replay\EvidenceSanitizer;
use Bridge\Simulation\Replay\PrometheusCapture;

$home = sys_get_temp_dir() . '/bridge-replay-' . bin2hex(random_bytes(6));
mkdir($home, 0700);
$passed = 0;
$failed = 0;
$test = static function (string $name, callable $operation) use (&$passed, &$failed): void {
    try {
        $operation();
        ++$passed;
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
};
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$context = new Context(new Configuration('local', $home, 'local/demo', 'local'));
$context->handle(new InitializeLocalState());
$context->handle(new Reconcile());
[$github, $zendesk] = $context->providers();
$capture = new CheckpointCapture($context);
$run = [
    'id' => 'replay-test', 'mode' => 'local', 'repository' => 'local/demo',
    'zendesk_subdomain' => 'local', 'captured_at' => gmdate('c'),
];

$test('Capture observes current records without reconciling or advancing the baseline', function () use (
    $context, $zendesk, $github, $capture, $assert
): void {
    $baseline = $context->store->get('escalation-1')['baseline'];
    $zendesk->edit('501', ['title' => 'Support observed a larger failing export']);
    $before = $github->writes() + $zendesk->writes();
    $snapshot = $capture->capture('escalation-1', 'Source edit', 'Support changed the subject');
    $assert($snapshot['records']['zendesk']['title'] === 'Support observed a larger failing export');
    $assert($snapshot['records']['github']['title'] === $baseline['title']);
    $assert($snapshot['pair']['baseline'] === $baseline);
    $assert($snapshot['records']['github']['kind'] === 'local' && !isset($snapshot['records']['github']['url']));
    $assert($github->writes() + $zendesk->writes() === $before);
});

$test('Captured state-change review retains the whole pending plan and grants no approval', function () use (
    $context, $zendesk, $github, $capture, $assert
): void {
    $context->handle(new Reconcile());
    $zendesk->edit('501', ['state' => 'closed']);
    $context->handle(new Reconcile());
    $before = $github->writes() + $zendesk->writes();
    $snapshot = $capture->capture('escalation-1', 'Review boundary', 'Waiting for the operator');
    $assert($snapshot['pair']['status'] === 'awaiting_approval');
    $assert($snapshot['governance']['requires_approval'] && !$snapshot['governance']['approved']);
    $assert($snapshot['pair']['plan']['target']['state'] === 'closed');
    $assert($snapshot['pair']['approval'] === null && $snapshot['records']['github']['state'] === 'open');
    $assert($github->writes() + $zendesk->writes() === $before);
});

$test('Portable evidence excludes secret fields and arbitrary audit rationale', function () use ($assert): void {
    $sanitizer = new EvidenceSanitizer();
    $pair = [
        'id' => 'case', 'status' => 'new', 'token' => 'PRIVATE_SENTINEL', 'baseline' => null,
        'approval' => ['plan_hash' => str_repeat('a', 64), 'reason' => 'PRIVATE_SENTINEL'],
    ];
    $events = [['type' => 'approval_granted', 'created_at' => '2026-10-27 00:00:00', 'details' => [
        'reason' => 'PRIVATE_SENTINEL', 'token' => 'PRIVATE_SENTINEL',
        'side' => 'PRIVATE_SENTINEL', 'fields' => ['title', 'PRIVATE_SENTINEL'],
    ]]];
    $json = json_encode([$sanitizer->pair($pair), $sanitizer->events($events)], JSON_THROW_ON_ERROR);
    $assert(!str_contains($json, 'PRIVATE_SENTINEL'));
    $assert(str_contains($json, 'title'));
});

$test('Filtered errors remain visibly present while current fixed reason codes survive', function () use (
    $capture, $assert
): void {
    $pair = $capture->capture('escalation-1', 'Held', 'Observe error visibility')['pair'];
    $sanitizer = new EvidenceSanitizer();
    $pair['error'] = 'PRIVATE_SENTINEL';
    $sanitized = $sanitizer->pair($pair);
    $assert($sanitized['error'] === 'details_omitted');
    $assert(!str_contains(json_encode($sanitized, JSON_THROW_ON_ERROR), 'PRIVATE_SENTINEL'));
    foreach (['changed_since_conflict', 'changed_since_approval_request'] as $reason) {
        $pair['error'] = $reason;
        $assert($sanitizer->pair($pair)['error'] === $reason);
        $events = $sanitizer->events([[
            'type' => 'conflict', 'created_at' => '2026-10-27 00:00:00', 'details' => ['reason' => $reason],
        ]]);
        $assert($events[0]['details']['reason'] === $reason);
    }
    $pair['error'] = null;
    $assert($sanitizer->pair($pair)['error'] === null);
});

$test('Captured events preserve insertion order within the same second and allow only fixed sources', function () use (
    $assert
): void {
    $events = array_map(static fn (string $type): array => [
        'type' => $type, 'created_at' => '2026-10-27 00:00:00',
        'details' => ['source' => $type === 'resolution_selected' ? 'zendesk' : 'PRIVATE_SENTINEL'],
    ], ['converged', 'write_returned', 'write_started', 'resolution_selected']);
    $result = (new EvidenceSanitizer())->events($events);
    $assert(array_column($result, 'type') === ['resolution_selected', 'write_started', 'write_returned', 'converged']);
    $assert($result[0]['details']['source'] === 'zendesk');
    $assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'PRIVATE_SENTINEL'));
});

$test('Capture labels and operation bounds match the renderer UTF-8 byte contract', function () use (
    $capture, $assert
): void {
    $snapshot = $capture->capture('escalation-1', str_repeat('é', 100), str_repeat('o', 500));
    $assert(strlen($snapshot['label']) === 200 && strlen($snapshot['operation']) === 500);
    foreach ([[str_repeat('l', 201), 'Observe'], [str_repeat('é', 101), 'Observe'], ['Observe', str_repeat('o', 501)]] as [$label, $operation]) {
        try {
            $capture->capture('missing', $label, $operation);
            throw new LogicException('Oversized capture text was accepted');
        } catch (InvalidArgumentException) {
            $assert(true);
        }
    }
});

$test('Archive appends immutable ordered checkpoints and rejects a changed run identity', function () use (
    $home, $run, $capture, $assert
): void {
    $archive = new EvidenceArchive($home . '/run.json');
    $checkpoint = $capture->capture('escalation-1', 'Held', 'Observed pending review');
    $archive->append($run, $checkpoint);
    $archive->append($run, $checkpoint);
    $before = file_get_contents($home . '/run.json');
    $document = json_decode($before, true, 128, JSON_THROW_ON_ERROR);
    $assert(array_column($document['checkpoints'], 'id') === ['checkpoint-1', 'checkpoint-2']);
    foreach (['mode' => 'live', 'repository' => 'another/repository', 'zendesk_subdomain' => 'another-tenant'] as $key => $value) {
        try {
            $archive->append(array_replace($run, [$key => $value]), $checkpoint);
            throw new LogicException('A mixed archive was accepted');
        } catch (RuntimeException $error) {
            $assert(!$error instanceof LogicException);
        }
        $assert(file_get_contents($home . '/run.json') === $before);
    }
    $assert($document['run']['zendesk_subdomain'] === 'local');
    $unbound = $document;
    unset($unbound['run']['zendesk_subdomain']);
    file_put_contents($home . '/unbound.json', json_encode($unbound, JSON_THROW_ON_ERROR));
    try {
        (new EvidenceArchive($home . '/unbound.json'))->append($run, $checkpoint);
        throw new LogicException('An unbound existing archive was accepted');
    } catch (RuntimeException $error) {
        $assert(!$error instanceof LogicException);
    }
    $assert(file_get_contents($home . '/run.json') === $before);
});

$test('Corrupt and symbolic-link archives fail without replacing earlier evidence', function () use (
    $home, $run, $assert
): void {
    file_put_contents($home . '/corrupt.json', '{broken');
    try {
        (new EvidenceArchive($home . '/corrupt.json'))->append($run, []);
        throw new LogicException('Corrupt evidence was replaced');
    } catch (JsonException) {
        $assert(file_get_contents($home . '/corrupt.json') === '{broken');
    }
    symlink($home . '/corrupt.json', $home . '/linked.json');
    try {
        (new EvidenceArchive($home . '/linked.json'))->append($run, []);
        throw new LogicException('A link was accepted');
    } catch (RuntimeException $error) {
        $assert(!$error instanceof LogicException);
    }
    $assert(file_get_contents($home . '/corrupt.json') === '{broken');
});

$test('A missing pair fails before provider credentials are needed', function () use ($home, $assert): void {
    $live = new Context(new Configuration('live', $home, 'owner/repository', 'tenant'));
    try {
        (new CheckpointCapture($live))->capture('missing', 'Missing', 'Observe only');
        throw new LogicException('Missing pair was accepted');
    } catch (NotFound) {
        $assert(true);
    }
});

$test('Monitoring capture rejects public, credential-bearing and query-bearing origins', function () use ($assert): void {
    foreach (['http://example.com', 'http://user:secret@127.0.0.1', 'http://127.0.0.1/?token=secret'] as $url) {
        try {
            new PrometheusCapture($url);
            throw new LogicException('Unsafe monitoring URL was accepted');
        } catch (InvalidArgumentException) {
            $assert(true);
        }
    }
});

$test('Compose monitoring origins are query transports only and never browser metadata', function () use (
    $home, $assert
): void {
    new PrometheusCapture('http://prometheus:9090');
    PrometheusCapture::validateUrl('http://prometheus:9090', true);
    foreach (['prometheus-url', 'grafana-url'] as $option) {
        $target = $home . '/unsafe-' . $option;
        ob_start();
        $code = (new CaptureConsole())->run([
            'case', '--home=' . $target, '--output=' . $home . '/unsafe.json',
            '--run=unsafe-test', '--label=Observe', '--operation=Observe', '--' . $option . '=http://prometheus:9090',
        ]);
        ob_end_clean();
        $assert($code === 1 && !file_exists($target));
    }
    foreach (['http://127.0.0.1:9092', 'http://localhost:3002', 'http://[::1]:9092/'] as $url) {
        PrometheusCapture::validateUrl($url);
    }
});

$test('Unavailable monitoring is absent rather than fabricated zero observations', function () use ($assert): void {
    $assert((new PrometheusCapture('http://127.0.0.1:1'))->capture() === []);
});

$test('Invalid console arguments fail before creating application state', function () use ($home, $assert): void {
    $target = $home . '/must-not-exist';
    ob_start();
    $code = (new CaptureConsole())->run(['case', '--home=' . $target, '--unknown=value']);
    ob_end_clean();
    $assert($code === 1 && !file_exists($target));
});

unset($capture, $github, $zendesk, $context);
foreach (glob($home . '/*') ?: [] as $path) {
    if (is_file($path) || is_link($path)) {
        unlink($path);
    }
}
rmdir($home);
echo "$passed replay checks; $failed failed.\n";
exit($failed === 0 ? 0 : 1);
