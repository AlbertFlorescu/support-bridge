<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\Configuration;
use Bridge\Application\ProviderFactory;
use Bridge\Store;
use RuntimeException;

final readonly class InitializeLocalStateHandler
{
    public function __construct(
        private Configuration $configuration,
        private Store $store,
        private ProviderFactory $providers
    ) {
    }

    public function handle(InitializeLocalState $command): array
    {
        $this->store->withExclusiveLock(function (): void {
            if ($this->configuration->mode !== 'local' || $this->store->all() !== []) {
                return;
            }

            $home = $this->configuration->home;

            if (file_exists($home . '/github.json') || file_exists($home . '/zendesk.json')) {
                throw new RuntimeException('Fixture files already exist. Use a fresh state directory.');
            }

            [
                $github,
                $zendesk,
            ] = $this->providers->createLocal();
            $sample = [
                'title' => 'CSV export fails for large reports',
                'state' => 'open',
            ];
            $github->seed(['101' => $sample]);
            $zendesk->seed(['501' => $sample]);
            $this->store->addPair('escalation-1', '101', '501');
        });

        return [
            'mode'        => $this->configuration->mode,
            'initialized' => true,
            'next'        => 'sync',
        ];
    }
}
