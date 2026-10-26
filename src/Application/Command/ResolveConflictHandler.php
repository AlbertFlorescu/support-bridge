<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\EngineFactory;

final readonly class ResolveConflictHandler
{
    public function __construct(private EngineFactory $engines)
    {
    }

    public function handle(ResolveConflict $command): array
    {
        $this->engines->create()->resolve($command->id, $command->source);

        return [
            'id'     => $command->id,
            'status' => 'new',
        ];
    }
}
