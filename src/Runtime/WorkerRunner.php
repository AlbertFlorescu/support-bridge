<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Context;
use Bridge\Application\Command\Reconcile;
use Bridge\Application\Command\IntakeEscalations;
use Bridge\Application\IntakeFactory;
use Bridge\Application\CommentFactory;
use Bridge\Application\Command\SyncGitHubComments;
use Bridge\Application\Command\SyncZendeskNotes;
use Bridge\Comments\CommentDirection;
use Bridge\RemoteFailure;
use Bridge\Exception\WorkerBusy;
use Bridge\Infrastructure\Logging\JsonLineWriter;

final readonly class WorkerRunner
{
    public function __construct(
        private Context $context,
        private int $interval,
        private StopSignal $stop,
        private HeartbeatWriter $heartbeat,
        private JsonLineWriter $output
    ) {
    }

    public function run(): void
    {
        while ($this->stop->running()) {
            try {
                if (IntakeFactory::enabled()) {
                    try {
                        $intake = $this->context->handle(new IntakeEscalations());
                        ($this->output)(['event' => 'escalation_intake', 'outcome' => $intake]);
                    } catch (RemoteFailure) {
                        ($this->output)(['event' => 'escalation_intake_unavailable']);
                    }
                }
                $outcome = $this->context->handle(new Reconcile());
                if (CommentFactory::enabled()) {
                    $comments = $this->context->handle(new SyncGitHubComments());
                    ($this->output)(['event' => 'comment_sync', 'outcome' => $comments]);
                }
                if (CommentFactory::enabled(CommentDirection::ZendeskToGitHub)) {
                    $notes = $this->context->handle(new SyncZendeskNotes());
                    ($this->output)(['event' => 'note_sync', 'outcome' => $notes]);
                }
            } catch (WorkerBusy) {
                $outcome = ['worker_busy' => true];
            }
            ($this->output)(['event' => 'worker_sweep', 'outcome' => $outcome]);
            $this->heartbeat->write(['observed_at' => time(), 'interval_seconds' => $this->interval]);
            $this->stop->wait($this->interval);
        }
    }
}
