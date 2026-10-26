<?php

declare(strict_types=1);

namespace Bridge\Console;

final readonly class Arguments
{
    public function __construct(
        public string $command,
        public array $values,
        public ?string $mode,
        public ?string $home
    ) {
    }
}
