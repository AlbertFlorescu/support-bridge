<?php

declare(strict_types=1);

namespace Bridge\Http;

final class MetricsController
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
        $this->routes->requireMethod($request, 'GET');
        $this->authentication->metrics($request);
        if ($request->query !== []) {
            throw new HttpFailure(422, 'invalid_query', 'The metrics exporter does not accept query parameters.');
        }
        try {
            $context = ($this->contextFactory)();
            $body = $context->telemetry->render($context->store->metrics(), $context->store->all());
        } catch (\Throwable) {
            // A successful scrape must never conceal failed application or metrics storage.
            throw new HttpFailure(503, 'metrics_unavailable', 'Metrics storage is unavailable.');
        }
        return new Response(200, $body, [
            'X-Request-Id' => $requestId,
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
