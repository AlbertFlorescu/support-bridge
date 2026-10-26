<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

final readonly class InspectPair implements Query
{
    public function __construct(
        public string $id
    ) {
    }
}
