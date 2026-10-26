<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

final readonly class ListPairs implements Query
{
    public function __construct(
        public int $limit = 50,
        public int $offset = 0
    ) {
    }
}
