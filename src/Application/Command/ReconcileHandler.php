<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\EngineFactory;

final readonly class ReconcileHandler
{
    public function __construct(private EngineFactory $engines)
    {
    }

    public function handle(Reconcile $command): array
    {
        return $this->engines->create()->run();
    }
}
