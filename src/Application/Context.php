<?php
declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Engine;
use Bridge\Domain\GovernancePolicy;
use Bridge\HttpClient;
use Bridge\Observability\Prometheus;
use Bridge\Provider\GitHub;
use Bridge\Provider\Local;
use Bridge\Provider\Zendesk;
use Bridge\Store;

/** Composition root shared by the CLI and HTTP adapters; no global service locator. */
final class Context
{
    public readonly Store $store;
    public readonly Prometheus $telemetry;

    public function __construct(public readonly Configuration $configuration)
    {
        $this->store = new Store($configuration->home . '/' . $configuration->mode . '.sqlite');
        $this->store->bindIdentity(['mode' => $configuration->mode, 'repository' => strtolower($configuration->repository),
            'subdomain' => strtolower($configuration->subdomain)]);
        $this->telemetry = new Prometheus($configuration->home . '/metrics.sqlite');
    }

    public function initialize(): void
    {
        $this->store->withExclusiveLock(function (): void {
            if ($this->configuration->mode !== 'local' || $this->store->all() !== []) {
                return;
            }
            $home = $this->configuration->home;
            if (file_exists($home . '/github.json') || file_exists($home . '/zendesk.json')) {
                throw new \RuntimeException('Fixture files already exist. Use a fresh state directory.');
            }
            [$github, $zendesk] = $this->providers();
            $sample = ['title' => 'CSV export fails for large reports', 'state' => 'open'];
            $github->seed(['101' => $sample]);
            $zendesk->seed(['501' => $sample]);
            $this->store->addPair('escalation-1', '101', '501');
        });
    }

    public function link(string $id, string $githubId, string $zendeskId): void
    {
        $this->store->withExclusiveLock(fn() => $this->store->addPair($id, $githubId, $zendeskId));
    }

    public function engine(): Engine
    {
        [$github, $zendesk] = $this->providers();
        return new Engine($this->store, $github, $zendesk, function (array $entry): void {
            file_put_contents('php://stderr', json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL);
            ($this->telemetry)($entry);
        });
    }

    public function listPairs(int $limit = 50, int $offset = 0): array
    {
        $pairs = $this->store->all();
        return ['items' => array_map(static fn(array $pair): array => array_intersect_key($pair, array_flip([
            'id', 'github_id', 'zendesk_id', 'status', 'attempts', 'next_run', 'error', 'last_success'])), array_slice($pairs, $offset, $limit)),
            'total' => count($pairs), 'limit' => $limit, 'offset' => $offset];
    }

    public function inspect(string $id): array
    {
        $pair = $this->store->get($id);
        return ['pair' => $pair, 'recent_events' => $this->store->events($id),
            'governance' => (new GovernancePolicy())->review($pair)];
    }

    public function providers(): array
    {
        $configuration = $this->configuration;
        if ($configuration->mode === 'local') {
            return [new Local($configuration->home . '/github.json'), new Local($configuration->home . '/zendesk.json')];
        }
        $http = new HttpClient();
        try {
            return [new GitHub($http, $configuration->repository, Configuration::required('BRIDGE_GITHUB_TOKEN')),
                new Zendesk($http, $configuration->subdomain, Configuration::required('BRIDGE_ZENDESK_EMAIL'), Configuration::required('BRIDGE_ZENDESK_TOKEN'))];
        } catch (\InvalidArgumentException) {
            throw new ConfigurationFailure('Provider configuration is invalid. Check the deployment environment.');
        }
    }
}
