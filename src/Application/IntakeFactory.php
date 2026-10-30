<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\HttpClient;
use Bridge\Infrastructure\Persistence\EscalationRepository;
use Bridge\Intake\EngineeringIssues;
use Bridge\Intake\EscalationWorkflow;
use Bridge\Intake\TicketSource;
use Bridge\Provider\GitHubEscalations;
use Bridge\Provider\ZendeskEscalations;
use Bridge\Store;
use Closure;

/** Builds vendor capabilities only for an explicitly invoked intake command. */
final readonly class IntakeFactory
{
    private ?Closure $gateways;

    public function __construct(
        private Configuration $configuration,
        private Store $store,
        private EscalationRepository $escalations,
        private ProviderFactory $providers,
        ?callable $gateways = null
    ) {
        $this->gateways = $gateways === null ? null : Closure::fromCallable($gateways);
    }

    public static function enabled(): bool
    {
        return getenv('BRIDGE_ZENDESK_INTAKE_ENABLED') === '1';
    }

    public function create(): EscalationWorkflow
    {
        $tag = getenv('BRIDGE_ZENDESK_ESCALATION_TAG') ?: 'support_bridge_escalate';
        if ($this->gateways !== null) {
            [$source, $issues] = ($this->gateways)();
        } else {
            if ($this->configuration->mode !== 'live' || !self::enabled()) {
                throw new ConfigurationFailure('Zendesk escalation intake must be explicitly enabled in live mode.');
            }
            $http = new HttpClient();
            $source = new ZendeskEscalations(
                $http,
                $this->configuration->subdomain,
                $this->providers->zendeskAuthorization()
            );
            $issues = new GitHubEscalations(
                $http,
                $this->configuration->repository,
                $this->configuration->subdomain,
                Configuration::required('BRIDGE_GITHUB_TOKEN')
            );
        }
        if (!$source instanceof TicketSource || !$issues instanceof EngineeringIssues) {
            throw new ConfigurationFailure('Invalid escalation capability configuration.');
        }
        return new EscalationWorkflow($this->store, $this->escalations, $source, $issues, $tag);
    }
}
