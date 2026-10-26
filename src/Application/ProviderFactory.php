<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\HttpClient;
use Bridge\Provider\GitHub;
use Bridge\Provider\Local;
use Bridge\Provider\Provider;
use Bridge\Provider\Zendesk;
use InvalidArgumentException;

final readonly class ProviderFactory
{
    public function __construct(private Configuration $configuration)
    {
    }

    /** @return array{Provider, Provider} */
    public function create(): array
    {
        if ($this->configuration->mode === 'local') {
            return $this->createLocal();
        }

        $http = new HttpClient();

        try {
            return [
                new GitHub(
                    $http,
                    $this->configuration->repository,
                    Configuration::required('BRIDGE_GITHUB_TOKEN')
                ),
                new Zendesk(
                    $http,
                    $this->configuration->subdomain,
                    Configuration::required('BRIDGE_ZENDESK_EMAIL'),
                    Configuration::required('BRIDGE_ZENDESK_TOKEN')
                ),
            ];
        } catch (InvalidArgumentException) {
            throw new ConfigurationFailure('Provider configuration is invalid. Check the deployment environment.');
        }
    }

    /** @return array{Local, Local} */
    public function createLocal(): array
    {
        if ($this->configuration->mode !== 'local') {
            throw new ConfigurationFailure('Local fixtures require local mode.');
        }

        return [
            new Local($this->configuration->home . '/github.json'),
            new Local($this->configuration->home . '/zendesk.json'),
        ];
    }
}
