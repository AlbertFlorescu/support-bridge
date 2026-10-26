<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Observability\Prometheus;
use Bridge\Store;

final readonly class GetMetricsHandler
{
    public function __construct(private Store $store, private Prometheus $telemetry)
    {
    }

    public function handle(GetMetrics $query): array
    {
        return $this->store->metrics() + ['observability' => $this->telemetry->status()];
    }
}
