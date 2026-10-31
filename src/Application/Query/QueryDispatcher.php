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
        private GetMetricsHandler $metrics,
        private ?ListEscalationsHandler $escalations = null,
        private ?ListCommentDeliveriesHandler $comments = null,
        private ?GetMonitoringSnapshotHandler $monitoring = null,
        private ?GetLinkedPairTableHandler $table = null,
        private ?ListNoteDeliveriesHandler $notes = null
    ) {
    }

    public function ask(Query $query): array
    {
        return match (true) {
            $query instanceof ListPairs => $this->listing->handle($query),
            $query instanceof InspectPair => $this->inspection->handle($query),
            $query instanceof GetStatus => $this->status->handle($query),
            $query instanceof GetMetrics => $this->metrics->handle($query),
            $query instanceof ListEscalations && $this->escalations !== null => $this->escalations->handle($query),
            $query instanceof ListCommentDeliveries && $this->comments !== null => $this->comments->handle($query),
            $query instanceof GetMonitoringSnapshot && $this->monitoring !== null => $this->monitoring->handle($query),
            $query instanceof GetLinkedPairTable && $this->table !== null => $this->table->handle($query),
            $query instanceof ListNoteDeliveries && $this->notes !== null => $this->notes->handle($query),
            default => throw new InvalidArgumentException('Unsupported application query.'),
        };
    }
}
