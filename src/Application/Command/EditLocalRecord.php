<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

final readonly class EditLocalRecord implements Command
{
    public function __construct(
        public string $side,
        public string $id,
        public string $field,
        public string $value
    ) {
    }
}
