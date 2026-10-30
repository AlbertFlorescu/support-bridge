<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Comments\CommentSource;
use Bridge\Comments\CommentWorkflow;
use Bridge\Comments\InternalNotes;
use Bridge\HttpClient;
use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Provider\GitHubIssueComments;
use Bridge\Provider\Provider;
use Bridge\Provider\ZendeskInternalNotes;
use Bridge\Store;
use Closure;

final readonly class CommentFactory
{
    private ?Closure $gateways;

    public function __construct(
        private Configuration $configuration,
        private Store $store,
        private CommentRepository $comments,
        private ProviderFactory $providers,
        ?callable $gateways = null
    ) {
        $this->gateways = $gateways === null ? null : Closure::fromCallable($gateways);
    }

    public static function enabled(): bool
    {
        return getenv('BRIDGE_GITHUB_COMMENTS_ENABLED') === '1';
    }

    public function create(): CommentWorkflow
    {
        if ($this->gateways !== null) {
            [$source, $notes, $github, $zendesk] = ($this->gateways)();
        } else {
            if ($this->configuration->mode !== 'live' || !self::enabled()) {
                throw new ConfigurationFailure(
                    'GitHub internal-note delivery must be explicitly enabled in live mode.'
                );
            }
            $http = new HttpClient();
            $source = new GitHubIssueComments(
                $http,
                $this->configuration->repository,
                Configuration::required('BRIDGE_GITHUB_TOKEN')
            );
            $notes = new ZendeskInternalNotes(
                $http,
                $this->configuration->subdomain,
                $this->providers->zendeskAuthorization()
            );
            [$github, $zendesk] = $this->providers->create();
        }
        if (
            !$source instanceof CommentSource || !$notes instanceof InternalNotes
            || !$github instanceof Provider || !$zendesk instanceof Provider
        ) {
            throw new ConfigurationFailure('Invalid comment capability configuration.');
        }
        return new CommentWorkflow($this->store, $this->comments, $source, $notes, $github, $zendesk);
    }
}
