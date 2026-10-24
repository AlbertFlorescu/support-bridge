<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Domain\MergeDecision;
use Bridge\Domain\MergePolicy;
use Bridge\Domain\RetryPolicy;

function domainSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

$tests = [];
$tests['Three-way title decisions cover every equality relationship'] = static function (): void {
    $policy = new MergePolicy();
    $base = ['title' => 'Base', 'state' => 'open'];
    $cases = [
        ['Base', 'Base', 'Base'], ['Base', 'Left', 'Left'], ['Base', 'Right', 'Right'],
        ['Left', 'Base', 'Left'], ['Left', 'Left', 'Left'], ['Left', 'Right', null],
        ['Right', 'Base', 'Right'], ['Right', 'Left', null], ['Right', 'Right', 'Right'],
    ];
    foreach ($cases as [$left, $right, $expectedTitle]) {
        $decision = $policy->decide($base, ['title' => $left, 'state' => 'open'], ['title' => $right, 'state' => 'open']);
        domainSame($expectedTitle === null, $decision->hasConflict());
        domainSame($expectedTitle === null ? null : ['title' => $expectedTitle, 'state' => 'open'], $decision->target);
        domainSame($expectedTitle === null ? ['title'] : [], $decision->conflictingFields);
    }
};

$tests['Swapping providers preserves merge decisions for independent edits'] = static function (): void {
    $policy = new MergePolicy();
    $base = ['title' => 'Base', 'state' => 'open'];
    $left = ['title' => 'Customer context', 'state' => 'open'];
    $right = ['title' => 'Base', 'state' => 'closed'];
    $first = $policy->decide($base, $left, $right);
    $swapped = $policy->decide($base, $right, $left);
    domainSame(['title' => 'Customer context', 'state' => 'closed'], $first->target);
    domainSame($first->target, $swapped->target);
    domainSame([], $first->conflictingFields);
};

$tests['Bootstrap accepts equal records and requires a choice for every initial difference'] = static function (): void {
    $policy = new MergePolicy();
    $left = ['title' => 'Engineering', 'state' => 'open'];
    domainSame($left, $policy->decide(null, $left, $left)->target);
    $mismatch = $policy->decide(null, $left, ['title' => 'Support', 'state' => 'closed']);
    domainSame(null, $mismatch->target);
    domainSame(['title', 'state'], $mismatch->conflictingFields);
};

$tests['A conflict exposes no target even when another field could merge'] = static function (): void {
    $decision = (new MergePolicy())->decide(
        ['title' => 'Base', 'state' => 'open'],
        ['title' => 'Engineering', 'state' => 'closed'],
        ['title' => 'Support', 'state' => 'open'],
    );
    domainSame(null, $decision->target);
    domainSame(['title'], $decision->conflictingFields);
    $thrown = false;
    try { new MergeDecision(['title' => 'Partial', 'state' => 'closed'], ['title']); } catch (LogicException) { $thrown = true; }
    domainSame(true, $thrown);
};

$tests['Retry backoff increases through the four permitted automatic retries'] = static function (): void {
    $policy = new RetryPolicy();
    foreach ([2, 4, 8, 16] as $previous => $delay) {
        $decision = $policy->afterFailure($previous, true, null, 1000, 0);
        domainSame($previous + 1, $decision->attempts);
        domainSame('retry', $decision->status());
        domainSame($delay, $decision->delaySeconds);
        domainSame(1000 + $delay, $decision->nextRun);
    }
};

$tests['Retry-After never shortens local backoff and may extend it'] = static function (): void {
    $policy = new RetryPolicy();
    domainSame(1120, $policy->afterFailure(0, true, 120, 1000, 0)->nextRun);
    domainSame(1002, $policy->afterFailure(0, true, 1, 1000, 0)->nextRun);
    domainSame(1004, $policy->afterFailure(0, true, null, 1000, 2)->nextRun);
};

$tests['Permanent and exhausted failures have no scheduled retry'] = static function (): void {
    $policy = new RetryPolicy();
    foreach ([[0, false, 1], [4, true, 5]] as [$previous, $retryable, $attempts]) {
        $decision = $policy->afterFailure($previous, $retryable, 120, 1000, 2);
        domainSame($attempts, $decision->attempts);
        domainSame('dead', $decision->status());
        domainSame(false, $decision->willRetry);
        domainSame(0, $decision->nextRun);
        domainSame(null, $decision->delaySeconds);
    }
};

$tests['Jitter is bounded and counter growth cannot overflow backoff computation'] = static function (): void {
    $policy = new RetryPolicy();
    domainSame(2, $policy->afterFailure(0, true, null, 0, -10)->delaySeconds);
    domainSame(4, $policy->afterFailure(0, true, null, 0, 999)->delaySeconds);
    domainSame(300, $policy->jitterLimit(1_000_000));
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
fwrite(STDOUT, count($tests) . ' pure domain policy tests; ' . $failed . ' failed.' . PHP_EOL);
exit($failed === 0 ? 0 : 1);
