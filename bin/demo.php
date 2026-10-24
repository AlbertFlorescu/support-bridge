#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Engine;
use Bridge\Domain\GovernancePolicy;
use Bridge\Provider\Local;
use Bridge\Store;

umask(0077);
$home = dirname(__DIR__) . '/var/demo-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$quiet = static function (array $entry): void {};
$jitter = static fn(int $max): int => 0;
$fields = ['title' => 'CSV export fails for large reports', 'state' => 'open'];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Demo verification failed: ' . $message);
    }
    echo 'PASS  ' . $message . PHP_EOL;
}

try {
    echo "Support Bridge | local reliability demo | no network calls\n\n";
    $github = new Local($home . '/github.json');
    $zendesk = new Local($home . '/zendesk.json');
    $github->seed(['101' => $fields]);
    $zendesk->seed(['501' => $fields]);
    $store = new Store($home . '/local.sqlite');
    $store->bindIdentity(['mode' => 'local', 'repository' => 'local/demo', 'subdomain' => 'local']);
    $store->addPair('escalation-1', '101', '501');
    $engine = new Engine($store, $github, $zendesk, $quiet, $clock, $jitter);
    $engine->run();
    check($store->get('escalation-1')['status'] === 'synced', 'Equal linked records establish a common baseline.');

    $zendesk->edit('501', ['title' => 'CSV export fails above 100k rows']);
    $engine->run();
    check($github->fetch('101')->fields === $zendesk->fetch('501')->fields, 'Support edits reach engineering; only the title is patched.');

    $github->edit('101', ['title' => 'CSV export fix verified above 100k rows']);
    $zendesk->edit('501', ['state' => 'closed']);
    $engine->run();
    check($store->get('escalation-1')['status'] === 'awaiting_approval'
        && $github->fetch('101')->fields['state'] === 'open'
        && $zendesk->fetch('501')->fields['title'] === 'CSV export fails above 100k rows',
        'A deterministic governance gate holds every effect of a state-change plan for operator approval.');
    $review = (new GovernancePolicy())->review($store->get('escalation-1'));
    $engine->approve('escalation-1', $review['plan_hash'], 'Reviewed the merged escalation and resolution state.');
    $engine->run();
    check($github->fetch('101')->fields === ['title' => 'CSV export fix verified above 100k rows', 'state' => 'closed']
        && $github->fetch('101')->fields === $zendesk->fetch('501')->fields,
        'Independent edits to title and state merge without discarding either change.');

    $github->edit('101', ['state' => 'open']);
    $zendesk->failNext('after');
    $engine->run();
    $review = (new GovernancePolicy())->review($store->get('escalation-1'));
    $engine->approve('escalation-1', $review['plan_hash'], 'Customer verification failed; approve reopening the escalation.');
    $engine->run();
    $writes = $zendesk->writes();
    check($store->get('escalation-1')['status'] === 'retry' && $store->get('escalation-1')['plan'] !== null,
        'Lost response after commit leaves durable recovery work.');
    unset($engine, $store, $github, $zendesk);
    $now += 10;
    $github = new Local($home . '/github.json');
    $zendesk = new Local($home . '/zendesk.json');
    $store = new Store($home . '/local.sqlite');
    $engine = new Engine($store, $github, $zendesk, $quiet, $clock, $jitter);
    $engine->run();
    check($store->get('escalation-1')['status'] === 'synced' && $zendesk->writes() === $writes,
        'A restarted worker reads the committed result and does not send a duplicate write.');

    $github->edit('101', ['title' => 'Engineering: export fix ready']);
    $zendesk->edit('501', ['title' => 'Support: customer still sees failure']);
    $engine->run();
    check($store->get('escalation-1')['status'] === 'conflict', 'Conflicting edits are quarantined for operator review.');
    $engine->resolve('escalation-1', 'github');
    $engine->run();
    check($github->fetch('101')->fields === $zendesk->fetch('501')->fields && $store->get('escalation-1')['status'] === 'synced',
        'An explicit, fresh source choice restores convergence.');
    $before = $github->writes() + $zendesk->writes();
    $engine->run();
    check($github->writes() + $zendesk->writes() === $before, 'A repeated reconciliation performs zero writes.');

    echo "\nInspect the retained demo state:\nphp bin/bridge.php inspect escalation-1 --home=" . $home . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
