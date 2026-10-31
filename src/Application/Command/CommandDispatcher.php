<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use InvalidArgumentException;

/** An explicit use-case map; no reflection, middleware or runtime handler discovery. */
final readonly class CommandDispatcher
{
    public function __construct(
        private InitializeLocalStateHandler $initialize,
        private LinkPairHandler $link,
        private ReconcileHandler $reconcile,
        private ResolveConflictHandler $resolve,
        private ApprovePlanHandler $approve,
        private RetryPairHandler $retry,
        private EditLocalRecordHandler $edit,
        private ?IntakeEscalationsHandler $intake = null,
        private ?SyncGitHubCommentsHandler $comments = null,
        private ?SyncZendeskNotesHandler $notes = null
    ) {
    }

    public function handle(Command $command): array
    {
        return match (true) {
            $command instanceof InitializeLocalState => $this->initialize->handle($command),
            $command instanceof LinkPair => $this->link->handle($command),
            $command instanceof Reconcile => $this->reconcile->handle($command),
            $command instanceof ResolveConflict => $this->resolve->handle($command),
            $command instanceof ApprovePlan => $this->approve->handle($command),
            $command instanceof RetryPair => $this->retry->handle($command),
            $command instanceof EditLocalRecord => $this->edit->handle($command),
            $command instanceof IntakeEscalations && $this->intake !== null => $this->intake->handle($command),
            $command instanceof SyncGitHubComments && $this->comments !== null => $this->comments->handle($command),
            $command instanceof SyncZendeskNotes && $this->notes !== null => $this->notes->handle($command),
            default => throw new InvalidArgumentException('Unsupported application command.'),
        };
    }
}
