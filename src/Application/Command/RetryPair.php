<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

final readonly class RetryPair implements Command
{
    public function __construct(
        public string $id
    ) {
    }
}
