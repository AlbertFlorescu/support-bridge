<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Store;

final readonly class LinkPairHandler
{
    public function __construct(private Store $store)
    {
    }

    public function handle(LinkPair $command): array
    {
        $this->store->withExclusiveLock(
            fn () => $this->store->addPair($command->id, $command->githubId, $command->zendeskId)
        );

        return [
            'id'     => $command->id,
            'status' => 'new',
        ];
    }
}
