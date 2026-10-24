#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;

umask(0077);

function usage(): void
{
    echo <<<'TEXT'
Support Bridge — plain PHP, GitHub Issues ↔ Zendesk Tickets

php bin/bridge.php COMMAND [ARGUMENTS] [--home=var/local] [--mode=local|live]

  init                                 Initialize local sample or an empty live database
  link PAIR GITHUB_ISSUE ZENDESK_TICKET  Opt an existing pair into synchronization
  sync                                 Reconcile all eligible links once
  status                               Show link status, retries and last success
  inspect PAIR                         Show baseline, pending plan, conflict and audit
  resolve PAIR github|zendesk           Choose a fresh observed side; next sync applies it
  approve PAIR PLAN_HASH REASON         Approve one inspected state-change plan; next sync applies it
  retry PAIR                           Requeue retry/dead work after fixing its cause
  metrics                              State counters and Prometheus storage status
  local-edit github|zendesk ID FIELD VALUE   Simulate title/state edit in local mode

Local mode needs no credentials. Live mode requires BRIDGE_GITHUB_REPOSITORY,
BRIDGE_GITHUB_TOKEN, BRIDGE_ZENDESK_SUBDOMAIN, BRIDGE_ZENDESK_EMAIL,
BRIDGE_ZENDESK_TOKEN. See README.md. API reference: /docs on the local HTTP server.

TEXT;
}

function output(mixed $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$context = null;
try {
    $mode = null;
    $home = null;
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--mode=')) {
            $mode = substr($arg, 7);
        } elseif (str_starts_with($arg, '--home=')) {
            $home = substr($arg, 7);
        } elseif (str_starts_with($arg, '--')) {
            throw new InvalidArgumentException('Unknown option. Use help for syntax.');
        } else {
            $args[] = $arg;
        }
    }
    $command = array_shift($args) ?? 'help';
    if ($command === 'help') {
        usage();
        exit(0);
    }
    $arity = ['init' => 0, 'link' => 3, 'sync' => 0, 'status' => 0, 'inspect' => 1,
        'resolve' => 2, 'approve' => 3, 'retry' => 1, 'metrics' => 0, 'local-edit' => 4];
    if (!isset($arity[$command]) || count($args) !== $arity[$command]) {
        throw new InvalidArgumentException('Invalid command or arguments. Run: php bin/bridge.php help');
    }
    $context = new Context(Configuration::fromEnvironment($mode, $home));
    switch ($command) {
        case 'init':
            $context->initialize();
            output(['mode' => $context->configuration->mode, 'initialized' => true, 'next' => 'sync']);
            break;
        case 'link':
            $context->link(...$args);
            output(['linked' => $args[0], 'next' => 'sync; inspect and explicitly resolve any initial mismatch']);
            break;
        case 'status':
            output($context->listPairs(PHP_INT_MAX)['items']);
            break;
        case 'metrics':
            output($context->store->metrics() + ['observability' => $context->telemetry->status()]);
            break;
        case 'inspect':
            output($context->inspect($args[0]));
            break;
        case 'local-edit':
            if ($context->configuration->mode !== 'local' || !in_array($args[0], ['github', 'zendesk'], true)
                || !in_array($args[2], ['title', 'state'], true)) {
                throw new InvalidArgumentException('local-edit requires local mode, a valid side and title/state.');
            }
            [$github, $zendesk] = $context->providers();
            ($args[0] === 'github' ? $github : $zendesk)->edit($args[1], [$args[2] => $args[3]]);
            output(['edited' => $args[0], 'id' => $args[1], 'field' => $args[2]]);
            break;
        case 'sync':
            output($context->engine()->run());
            $statuses = $context->store->metrics()['pairs'];
            exit(($statuses['dead'] + $statuses['conflict'] + $statuses['awaiting_approval']) > 0 ? 2 : ($statuses['retry'] > 0 ? 3 : 0));
        case 'resolve':
            $context->engine()->resolve(...$args);
            output(['queued' => $args[0], 'next' => 'sync']);
            break;
        case 'retry':
            $context->engine()->retry($args[0]);
            output(['queued' => $args[0], 'next' => 'sync']);
            break;
        case 'approve':
            $context->engine()->approve(...$args);
            output(['approved' => $args[0], 'next' => 'sync']);
            break;
    }
} catch (PDOException) {
    fwrite(STDERR, "Database operation failed. Check state directory permissions and SQLite availability.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
