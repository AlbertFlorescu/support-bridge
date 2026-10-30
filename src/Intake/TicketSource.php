<?php

declare(strict_types=1);

namespace Bridge\Intake;

interface TicketSource
{
    /** @return array{tickets:list<EscalationTicket>,has_more:bool} */
    public function discover(string $tag, int $page): array;

    /** Fetch fresh eligibility and the supported initial description; null is metadata-only. */
    public function fetch(string $id): EscalationTicket;
}
