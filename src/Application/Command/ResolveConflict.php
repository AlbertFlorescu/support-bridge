<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

final readonly class ResolveConflict implements Command
{
    public function __construct(
        public string $id,
        public string $source
    ) {
    }
}
