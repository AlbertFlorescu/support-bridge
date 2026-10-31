<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Application\Command\ApprovePlanHandler;
use Bridge\Application\Command\Command;
use Bridge\Application\Command\CommandDispatcher;
use Bridge\Application\Command\EditLocalRecordHandler;
use Bridge\Application\Command\InitializeLocalState;
use Bridge\Application\Command\InitializeLocalStateHandler;
use Bridge\Application\Command\LinkPair;
use Bridge\Application\Command\LinkPairHandler;
use Bridge\Application\Command\ReconcileHandler;
use Bridge\Application\Command\ResolveConflictHandler;
use Bridge\Application\Command\RetryPairHandler;
use Bridge\Application\Query\GetMetricsHandler;
use Bridge\Application\Query\GetStatusHandler;
use Bridge\Application\Query\InspectPair;
use Bridge\Application\Query\InspectPairHandler;
use Bridge\Application\Query\ListPairs;
use Bridge\Application\Query\ListPairsHandler;
use Bridge\Application\Query\Query;
use Bridge\Application\Query\QueryDispatcher;
use Bridge\Domain\GovernancePolicy;
use Bridge\Engine;
use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Infrastructure\Logging\ReconciliationLogger;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Infrastructure\Persistence\EscalationRepository;
use Bridge\Application\Command\IntakeEscalationsHandler;
use Bridge\Application\Query\ListEscalationsHandler;
use Bridge\Observability\Prometheus;
use Bridge\Store;
use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Application\Command\SyncGitHubCommentsHandler;
use Bridge\Application\Query\ListCommentDeliveriesHandler;
use Bridge\Application\Query\GetMonitoringSnapshotHandler;
use Bridge\Application\Query\GetLinkedPairTableHandler;
use Bridge\Observability\LinkedPairTable;
use Bridge\Comments\CommentDirection;
use Bridge\Application\Command\SyncZendeskNotesHandler;
use Bridge\Application\Query\ListNoteDeliveriesHandler;

/** Per-deployment composition root; adapters share the same explicit use cases. */
final class Context
{
    public readonly Store $store;
    public readonly Prometheus $telemetry;
    private readonly ProviderFactory $providerFactory;
    private readonly EngineFactory $engineFactory;
    private readonly CommandDispatcher $commands;
    private readonly QueryDispatcher $queries;

    public function __construct(
        public readonly Configuration $configuration,
        ?callable $intakeGateways = null,
        ?callable $commentGateways = null,
        ?callable $noteGateways = null
    ) {
        $connection = new SqliteConnection($configuration->home . '/' . $configuration->mode . '.sqlite');
        $this->store = new Store($connection);
        $this->store->bindIdentity([
            'mode'       => $configuration->mode,
            'repository' => strtolower($configuration->repository),
            'subdomain'  => strtolower($configuration->subdomain),
        ]);
        $this->telemetry = new Prometheus($configuration->home . '/metrics.sqlite');
        $this->providerFactory = new ProviderFactory($configuration);
        $escalations = new EscalationRepository($connection);
        $comments = new CommentRepository($connection);
        $commentFactory = new CommentFactory(
            $configuration,
            $this->store,
            $comments,
            $this->providerFactory,
            $commentGateways
        );
        $notes = new CommentRepository($connection, CommentDirection::ZendeskToGitHub);
        $noteFactory = new CommentFactory(
            $configuration,
            $this->store,
            $notes,
            $this->providerFactory,
            $noteGateways,
            CommentDirection::ZendeskToGitHub
        );
        $intakeFactory = new IntakeFactory(
            $configuration,
            $this->store,
            $escalations,
            $this->providerFactory,
            $intakeGateways
        );
        $this->engineFactory = new EngineFactory(
            $this->store,
            $this->providerFactory,
            new ReconciliationLogger(new JsonLineWriter(), $this->telemetry)
        );
        $this->commands = new CommandDispatcher(
            new InitializeLocalStateHandler($configuration, $this->store, $this->providerFactory),
            new LinkPairHandler($this->store),
            new ReconcileHandler($this->engineFactory),
            new ResolveConflictHandler($this->engineFactory),
            new ApprovePlanHandler($this->engineFactory),
            new RetryPairHandler($this->engineFactory),
            new EditLocalRecordHandler($configuration, $this->store, $this->providerFactory),
            new IntakeEscalationsHandler($intakeFactory),
            new SyncGitHubCommentsHandler($commentFactory),
            new SyncZendeskNotesHandler($noteFactory)
        );
        $listing = new ListPairsHandler($this->store);
        $this->queries = new QueryDispatcher(
            $listing,
            new InspectPairHandler($this->store, new GovernancePolicy()),
            new GetStatusHandler($listing),
            new GetMetricsHandler($this->store, $this->telemetry),
            new ListEscalationsHandler($escalations),
            new ListCommentDeliveriesHandler($comments),
            new GetMonitoringSnapshotHandler($this->store),
            new GetLinkedPairTableHandler($this->store, new LinkedPairTable($configuration)),
            new ListNoteDeliveriesHandler($notes)
        );
    }

    public function handle(Command $command): array
    {
        return $this->commands->handle($command);
    }

    public function ask(Query $query): array
    {
        return $this->queries->ask($query);
    }

    public function initialize(): void
    {
        $this->handle(new InitializeLocalState());
    }

    public function link(string $id, string $githubId, string $zendeskId): void
    {
        $this->handle(new LinkPair($id, $githubId, $zendeskId));
    }

    public function engine(): Engine
    {
        return $this->engineFactory->create();
    }

    public function listPairs(int $limit = 50, int $offset = 0): array
    {
        return $this->ask(new ListPairs($limit, $offset));
    }

    public function inspect(string $id): array
    {
        return $this->ask(new InspectPair($id));
    }

    public function providers(): array
    {
        return $this->providerFactory->create();
    }
}
