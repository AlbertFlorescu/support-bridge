<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Store;

final readonly class GetMonitoringSnapshotHandler
{
    public function __construct(private Store $store)
    {
    }

    public function handle(GetMonitoringSnapshot $query): array
    {
        return $this->store->monitoringSnapshot();
    }
}
