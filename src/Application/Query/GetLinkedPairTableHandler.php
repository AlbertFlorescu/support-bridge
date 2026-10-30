<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Observability\LinkedPairTable;
use Bridge\Store;

/** Build the provider-free monitoring view through the shared application query boundary. */
final readonly class GetLinkedPairTableHandler
{
    public function __construct(private Store $store, private LinkedPairTable $table)
    {
    }

    public function handle(GetLinkedPairTable $query): array
    {
        return $this->table->project($this->store->monitoringSnapshot()['linked_pairs']);
    }
}
