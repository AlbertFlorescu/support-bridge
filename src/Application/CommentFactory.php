<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Comments\CommentSource;
use Bridge\Comments\CommentDestination;
use Bridge\Comments\CommentDirection;
use Bridge\Comments\InternalNoteSource;
use Bridge\Comments\CommentWorkflow;
use Bridge\HttpClient;
use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Provider\GitHubIssueComments;
use Bridge\Provider\Provider;
use Bridge\Provider\ZendeskInternalNotes;
use Bridge\Provider\ZendeskTicketNotes;
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
        ?callable $gateways = null,
        private CommentDirection $direction = CommentDirection::GitHubToZendesk
    ) {
        $this->gateways = $gateways === null ? null : Closure::fromCallable($gateways);
    }

    public static function enabled(CommentDirection $direction = CommentDirection::GitHubToZendesk): bool
    {
        return getenv($direction === CommentDirection::GitHubToZendesk
            ? 'BRIDGE_GITHUB_COMMENTS_ENABLED' : 'BRIDGE_ZENDESK_NOTES_ENABLED') === '1';
    }

    public function create(): CommentWorkflow
    {
        if ($this->gateways !== null) {
            [$source, $notes, $github, $zendesk] = ($this->gateways)();
        } else {
            if ($this->configuration->mode !== 'live' || !self::enabled($this->direction)) {
                throw new ConfigurationFailure(
                    'This comment channel must be explicitly enabled in live mode.'
                );
            }
            $http = new HttpClient();
            $issues = new GitHubIssueComments(
                $http,
                $this->configuration->repository,
                Configuration::required('BRIDGE_GITHUB_TOKEN')
            );
            $authorization = $this->providers->zendeskAuthorization();
            $source = $this->direction === CommentDirection::GitHubToZendesk ? $issues
                : new ZendeskTicketNotes($http, $this->configuration->subdomain, $authorization);
            $notes = $this->direction === CommentDirection::ZendeskToGitHub ? $issues
                : new ZendeskInternalNotes($http, $this->configuration->subdomain, $authorization);
            [$github, $zendesk] = $this->providers->create();
        }
        if (
            !$source instanceof CommentSource || !$notes instanceof CommentDestination
            || !$github instanceof Provider || !$zendesk instanceof Provider
            || $this->comments->direction !== $this->direction
            || ($this->direction === CommentDirection::ZendeskToGitHub && !$source instanceof InternalNoteSource)
        ) {
            throw new ConfigurationFailure('Invalid comment capability configuration.');
        }
        return new CommentWorkflow($this->store, $this->comments, $source, $notes, $github, $zendesk);
    }
}
