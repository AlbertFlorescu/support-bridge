<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Exception\ValidationFailure;

final readonly class ListEscalations implements Query
{
    public function __construct(public int $limit = 50, public int $offset = 0)
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new ValidationFailure('Escalation pagination is outside its bounds.');
        }
    }
}
