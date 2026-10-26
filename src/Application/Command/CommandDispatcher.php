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
        private EditLocalRecordHandler $edit
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
            default => throw new InvalidArgumentException('Unsupported application command.'),
        };
    }
}
