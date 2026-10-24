<?php
declare(strict_types=1);

namespace Bridge\Domain;

final readonly class RetryDecision
{
    public function __construct(
        public int $attempts,
        public bool $willRetry,
        public int $nextRun,
        public ?int $delaySeconds,
    ) {
    }

    public function status(): string
    {
        return $this->willRetry ? 'retry' : 'dead';
    }
}
