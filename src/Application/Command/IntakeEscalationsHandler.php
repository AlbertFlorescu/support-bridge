<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\IntakeFactory;

final readonly class IntakeEscalationsHandler
{
    public function __construct(private IntakeFactory $factory)
    {
    }

    public function handle(IntakeEscalations $command): array
    {
        return $this->factory->create()->run();
    }
}
