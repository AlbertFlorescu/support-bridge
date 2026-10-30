<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Infrastructure\Persistence\EscalationRepository;

final readonly class ListEscalationsHandler
{
    public function __construct(private EscalationRepository $escalations)
    {
    }

    public function handle(ListEscalations $query): array
    {
        return $this->escalations->list($query->limit, $query->offset);
    }
}
