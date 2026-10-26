<?php

declare(strict_types=1);

namespace Bridge\Console;

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Application\Command\ApprovePlan;
use Bridge\Application\Command\EditLocalRecord;
use Bridge\Application\Command\InitializeLocalState;
use Bridge\Application\Command\LinkPair;
use Bridge\Application\Command\Reconcile;
use Bridge\Application\Command\ResolveConflict;
use Bridge\Application\Command\RetryPair;
use Bridge\Application\Query\GetMetrics;
use Bridge\Application\Query\GetStatus;
use Bridge\Application\Query\InspectPair;
use PDOException;
use Throwable;

final readonly class Application
{
    private const HELP = <<<'TEXT'
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

    public function __construct(
        private ArgumentParser $parser = new ArgumentParser(),
        private JsonOutput $output = new JsonOutput()
    ) {
    }

    public function run(array $arguments): int
    {
        umask(0077);
        try {
            $input = $this->parser->parse($arguments);
            if ($input->command === 'help') {
                $this->output->text(self::HELP);
                return 0;
            }
            $context = new Context(Configuration::fromEnvironment($input->mode, $input->home));
            $values = $input->values;
            switch ($input->command) {
                case 'init':
                    $this->output->result($context->handle(new InitializeLocalState()));
                    break;
                case 'link':
                    $context->handle(new LinkPair(...$values));
                    $this->output->result([
                        'linked' => $values[0],
                        'next'   => 'sync; inspect and explicitly resolve any initial mismatch',
                    ]);
                    break;
                case 'status':
                    $this->output->result($context->ask(new GetStatus()));
                    break;
                case 'metrics':
                    $this->output->result($context->ask(new GetMetrics()));
                    break;
                case 'inspect':
                    $this->output->result($context->ask(new InspectPair($values[0])));
                    break;
                case 'local-edit':
                    $this->output->result($context->handle(new EditLocalRecord(...$values)));
                    break;
                case 'sync':
                    $this->output->result($context->handle(new Reconcile()));
                    return $this->sweepExitCode($context->ask(new GetMetrics())['pairs']);
                case 'resolve':
                    $context->handle(new ResolveConflict(...$values));
                    $this->output->result(['queued' => $values[0], 'next' => 'sync']);
                    break;
                case 'retry':
                    $context->handle(new RetryPair($values[0]));
                    $this->output->result(['queued' => $values[0], 'next' => 'sync']);
                    break;
                case 'approve':
                    $context->handle(new ApprovePlan(...$values));
                    $this->output->result(['approved' => $values[0], 'next' => 'sync']);
                    break;
            }
            return 0;
        } catch (PDOException) {
            $this->output->error('Database operation failed. Check state directory permissions ' .
                'and SQLite availability.');
        } catch (Throwable $error) {
            $this->output->error($error->getMessage());
        }
        return 1;
    }

    private function sweepExitCode(array $statuses): int
    {
        if ($statuses['dead'] + $statuses['conflict'] + $statuses['awaiting_approval'] > 0) {
            return 2;
        }
        return $statuses['retry'] > 0 ? 3 : 0;
    }
}
