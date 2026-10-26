<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

final readonly class GetStatusHandler
{
    public function __construct(private ListPairsHandler $listing)
    {
    }

    public function handle(GetStatus $query): array
    {
        return $this->listing->handle(new ListPairs(PHP_INT_MAX))['items'];
    }
}
