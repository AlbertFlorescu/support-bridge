<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\HttpClient;
use Bridge\Provider\GitHub;
use Bridge\Provider\Local;
use Bridge\Provider\ManagedZendeskAuthentication;
use Bridge\Provider\Provider;
use Bridge\Provider\Zendesk;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Provider\ZendeskAuthorization;
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
        $authentication = getenv('BRIDGE_ZENDESK_AUTH');
        $authentication = $authentication === false ? 'api-token' : $authentication;
        $email = getenv('BRIDGE_ZENDESK_EMAIL');
        $email = $email === false ? '' : $email;

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
                    $email,
                    $this->zendeskCredential($authentication),
                    $authentication
                ),
            ];
        } catch (InvalidArgumentException) {
            throw new ConfigurationFailure('Provider configuration is invalid. Check the deployment environment.');
        }
    }

    private function zendeskCredential(string $authentication): string|ManagedZendeskAuthentication
    {
        $bundle = getenv('BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE');
        if ($bundle === false || $bundle === '') {
            return Configuration::required('BRIDGE_ZENDESK_TOKEN');
        }
        if (
            $authentication !== 'oauth'
            || (getenv('BRIDGE_ZENDESK_TOKEN') !== false && getenv('BRIDGE_ZENDESK_TOKEN') !== '')
            || (getenv('BRIDGE_ZENDESK_TOKEN_FILE') !== false && getenv('BRIDGE_ZENDESK_TOKEN_FILE') !== '')
        ) {
            throw new ConfigurationFailure('Configure managed Zendesk OAuth or one static credential source.');
        }
        return new ManagedZendeskAuthentication($bundle, $this->configuration->subdomain);
    }

    public function zendeskAuthorization(): ZendeskAuthorization
    {
        $mode = getenv('BRIDGE_ZENDESK_AUTH');
        $mode = $mode === false ? 'api-token' : $mode;
        $credential = $this->zendeskCredential($mode);
        return is_string($credential)
            ? new ZendeskAuthentication(getenv('BRIDGE_ZENDESK_EMAIL') ?: '', $credential, $mode)
            : $credential;
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
