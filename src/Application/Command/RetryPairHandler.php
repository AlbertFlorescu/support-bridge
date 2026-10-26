<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\EngineFactory;

final readonly class RetryPairHandler
{
    public function __construct(private EngineFactory $engines)
    {
    }

    public function handle(RetryPair $command): array
    {
        $this->engines->create()->retry($command->id);

        return [
            'id'     => $command->id,
            'status' => 'new',
        ];
    }
}
