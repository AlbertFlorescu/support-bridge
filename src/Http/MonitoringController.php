<?php

declare(strict_types=1);

namespace Bridge\Http;

use Bridge\Application\Query\GetMonitoringSnapshot;
use Bridge\Application\Query\GetLinkedPairTable;

final class MonitoringController
{
    private readonly \Closure $contextFactory;

    public function __construct(
        callable $contextFactory,
        private readonly BearerAuthentication $authentication,
        private readonly RouteResolver $routes
    ) {
        $this->contextFactory = \Closure::fromCallable($contextFactory);
    }

    public function handle(Request $request, string $requestId): Response
    {
        $tableRequest = $request->path === '/monitoring/pairs';
        $this->routes->requireMethod($request, 'GET');
        $this->authentication->metrics($request);
        if ($request->query !== []) {
            throw new HttpFailure(422, 'invalid_query', 'Monitoring reads do not accept query parameters.');
        }
        try {
            $context = ($this->contextFactory)();
            if ($tableRequest) {
                $body = $context->ask(new GetLinkedPairTable());
            } else {
                $snapshot = $context->ask(new GetMonitoringSnapshot());
                $body = $context->telemetry->render($snapshot['state'], $snapshot['linked_pairs']);
            }
        } catch (\Throwable) {
            // A successful scrape must never conceal failed application or metrics storage.
            if ($tableRequest) {
                throw new HttpFailure(503, 'monitoring_unavailable', 'Monitoring evidence is unavailable.');
            }
            throw new HttpFailure(503, 'metrics_unavailable', 'Metrics storage is unavailable.');
        }
        return new Response(200, $body, [
            'X-Request-Id' => $requestId,
            'Content-Type' => is_string($body)
                ? 'text/plain; version=0.0.4; charset=utf-8' : 'application/json; charset=utf-8',
        ]);
    }
}
