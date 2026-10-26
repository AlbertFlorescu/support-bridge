<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use InvalidArgumentException;

final readonly class QueryDispatcher
{
    public function __construct(
        private ListPairsHandler $listing,
        private InspectPairHandler $inspection,
        private GetStatusHandler $status,
        private GetMetricsHandler $metrics
    ) {
    }

    public function ask(Query $query): array
    {
        return match (true) {
            $query instanceof ListPairs => $this->listing->handle($query),
            $query instanceof InspectPair => $this->inspection->handle($query),
            $query instanceof GetStatus => $this->status->handle($query),
            $query instanceof GetMetrics => $this->metrics->handle($query),
            default => throw new InvalidArgumentException('Unsupported application query.'),
        };
    }
}
