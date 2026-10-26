<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Store;

final readonly class ListPairsHandler
{
    private const SUMMARY_FIELDS = [
        'id',
        'github_id',
        'zendesk_id',
        'status',
        'attempts',
        'next_run',
        'error',
        'last_success',
    ];

    public function __construct(private Store $store)
    {
    }

    public function handle(ListPairs $query): array
    {
        $pairs = $this->store->all();
        $page = array_slice($pairs, $query->offset, $query->limit);
        $fields = array_flip(self::SUMMARY_FIELDS);

        return [
            'items'  => array_map(static fn (array $pair): array => array_intersect_key($pair, $fields), $page),
            'total'  => count($pairs),
            'limit'  => $query->limit,
            'offset' => $query->offset,
        ];
    }
}
