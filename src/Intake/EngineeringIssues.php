<?php

declare(strict_types=1);

namespace Bridge\Intake;

interface EngineeringIssues
{
    /** Exact persisted marker matching; incomplete searches must fail, never imply absence. */
    public function find(string $marker): ?string;

    public function create(EscalationTicket $ticket, string $marker): string;
}
