<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\Configuration;
use Bridge\Application\ProviderFactory;
use Bridge\Store;
use InvalidArgumentException;

final readonly class EditLocalRecordHandler
{
    public function __construct(
        private Configuration $configuration,
        private Store $store,
        private ProviderFactory $providers
    ) {
    }

    public function handle(EditLocalRecord $command): array
    {
        if (
            $this->configuration->mode !== 'local'
            || !in_array($command->side, ['github', 'zendesk'], true)
            || !in_array($command->field, ['title', 'state'], true)
        ) {
            throw new InvalidArgumentException('local-edit requires local mode, a valid side and title/state.');
        }

        $this->store->withExclusiveLock(function () use ($command): void {
            [
                $github,
                $zendesk,
            ] = $this->providers->createLocal();
            $provider = $command->side === 'github' ? $github : $zendesk;
            $provider->edit($command->id, [$command->field => $command->value]);
        });

        return [
            'edited' => $command->side,
            'id'     => $command->id,
            'field'  => $command->field,
        ];
    }
}
