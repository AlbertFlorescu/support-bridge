<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\EngineFactory;

final readonly class ApprovePlanHandler
{
    public function __construct(private EngineFactory $engines)
    {
    }

    public function handle(ApprovePlan $command): array
    {
        $this->engines->create()->approve($command->id, $command->planHash, $command->reason);

        return [
            'id'     => $command->id,
            'status' => 'new',
        ];
    }
}
