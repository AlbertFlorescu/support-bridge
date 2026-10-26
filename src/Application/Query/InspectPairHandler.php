<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Domain\GovernancePolicy;
use Bridge\Store;

final readonly class InspectPairHandler
{
    public function __construct(private Store $store, private GovernancePolicy $governance)
    {
    }

    public function handle(InspectPair $query): array
    {
        $pair = $this->store->get($query->id);

        return [
            'pair'          => $pair,
            'recent_events' => $this->store->events($query->id),
            'governance'    => $this->governance->review($pair),
        ];
    }
}
