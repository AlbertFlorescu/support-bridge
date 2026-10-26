<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Logging;

use Bridge\Observability\Prometheus;

final readonly class ReconciliationLogger
{
    public function __construct(private JsonLineWriter $writer, private Prometheus $telemetry)
    {
    }

    public function __invoke(array $entry): void
    {
        ($this->writer)($entry);
        ($this->telemetry)($entry);
    }
}
