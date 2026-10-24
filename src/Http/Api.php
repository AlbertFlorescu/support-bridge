<?php
declare(strict_types=1);

namespace Bridge\Http;

use Bridge\Application\Context;
use Bridge\Application\ConfigurationFailure;
use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Exception\WorkerBusy;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;

/** An HTTP adapter, not a second implementation of reconciliation or operator commands. */
final class Api
{
    private readonly \Closure $contextFactory;

    public function __construct(callable $contextFactory, private readonly string $token, private readonly string $metricsToken = '')
    {
        $this->contextFactory = \Closure::fromCallable($contextFactory);
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(12));
        $context = null;
        try {
            if ($request->path === '/health') {
                $this->method($request, 'GET');
                return new Response(200, ['status' => 'ok', 'service' => 'support-bridge', 'version' => '1.3.0'], ['X-Request-Id' => $requestId]);
            }
            if ($request->path === '/metrics') {
                return $this->exportMetrics($request, $requestId);
            }
            $route = $this->route($request);
            $this->authenticate($request);
            $context = ($this->contextFactory)();
            $body = $this->dispatch($request, $route, $context);
            return new Response($route['name'] === 'link' ? 201 : 200, $body,
                ['X-Request-Id' => $requestId] + ($route['name'] === 'link' ? ['Location' => '/api/pairs/' . $body['id']] : []));
        } catch (HttpFailure $e) {
            return $this->error($e->status, $e->errorCode, $e->getMessage(), $requestId, $e->headers);
        } catch (NotFound) {
            return $this->error(404, 'not_found', 'The linked pair does not exist.', $requestId);
        } catch (WorkerBusy) {
            return $this->error(409, 'worker_busy', 'A reconciliation or operator command is already running.', $requestId, ['Retry-After' => '1']);
        } catch (StateConflict | RemoteConflict) {
            return $this->error(409, 'state_conflict', 'The command conflicts with current state. Inspect the pair before trying again.', $requestId);
        } catch (ConfigurationFailure) {
            return $this->error(503, 'service_not_configured', 'The service environment is incomplete or invalid.', $requestId);
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'validation_failed', 'Configuration or operation values are invalid. Check the documented contract.', $requestId);
        } catch (RemoteFailure $e) {
            return $this->error($e->retryable ? 503 : 502, 'upstream_failure', 'The provider request failed. The operator command was not completed; inspect current state.', $requestId);
        } catch (\Throwable) {
            return $this->error(500, 'internal_error', 'The request could not be completed. Consult service logs using the request ID.', $requestId);
        }
    }

    private function route(Request $request): array
    {
        $routes = [
            '/api/pairs' => ['GET' => 'list', 'POST' => 'link'],
            '/api/metrics' => ['GET' => 'metrics'],
            '/api/sync' => ['POST' => 'sync'],
        ];
        if (isset($routes[$request->path])) {
            $methods = $routes[$request->path];
            if (!isset($methods[$request->method])) {
                throw new HttpFailure(405, 'method_not_allowed', 'This HTTP method is not supported.', ['Allow' => implode(', ', array_keys($methods))]);
            }
            return ['name' => $methods[$request->method]];
        }
        if (preg_match('~^/api/pairs/([A-Za-z0-9][A-Za-z0-9_-]{0,63})(?:/(resolve|retry|approve))?$~D', $request->path, $matches)) {
            $operation = $matches[2] ?? 'inspect';
            $this->method($request, $operation === 'inspect' ? 'GET' : 'POST');
            return ['name' => $operation, 'id' => $matches[1]];
        }
        throw new HttpFailure(404, 'route_not_found', 'The endpoint does not exist.');
    }

    private function authenticate(Request $request): void
    {
        if ($this->tokensOverlap()) {
            throw new HttpFailure(503, 'api_not_configured', 'Configure distinct operator and metrics bearer tokens.');
        }
        $this->authenticateBearer($request, $this->token, 'api_not_configured', 'Configure a strong BRIDGE_API_TOKEN before using the operator API.');
    }

    private function authenticateBearer(Request $request, string $token, string $configurationCode, string $configurationMessage): void
    {
        if (strlen($token) < 32 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new HttpFailure(503, $configurationCode, $configurationMessage);
        }
        $header = $request->header('Authorization');
        if (!preg_match('/^Bearer (\S+)$/iD', $header, $match) || !hash_equals($token, $match[1])) {
            throw new HttpFailure(401, 'unauthorized', 'A valid bearer token is required.', ['WWW-Authenticate' => 'Bearer']);
        }
    }

    private function tokensOverlap(): bool
    {
        return $this->metricsToken !== '' && hash_equals($this->token, $this->metricsToken);
    }

    private function exportMetrics(Request $request, string $requestId): Response
    {
        $this->method($request, 'GET');
        if ($this->tokensOverlap()) {
            throw new HttpFailure(503, 'metrics_not_configured', 'Configure distinct operator and metrics bearer tokens.');
        }
        $this->authenticateBearer($request, $this->metricsToken, 'metrics_not_configured',
            'Configure a strong BRIDGE_METRICS_TOKEN before using the metrics exporter.');
        if ($request->query !== []) {
            throw new HttpFailure(422, 'invalid_query', 'The metrics exporter does not accept query parameters.');
        }
        try {
            $context = ($this->contextFactory)();
            $body = $context->telemetry->render($context->store->metrics());
        } catch (\Throwable) {
            // A successful scrape must never conceal failed application or metrics storage.
            throw new HttpFailure(503, 'metrics_unavailable', 'Metrics storage is unavailable.');
        }
        return new Response(200, $body, ['X-Request-Id' => $requestId,
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function dispatch(Request $request, array $route, Context $context): array
    {
        if (!in_array($route['name'], ['list'], true) && $request->query !== []) {
            throw new HttpFailure(422, 'invalid_query', 'This endpoint does not accept query parameters.');
        }
        return match ($route['name']) {
            'list' => $this->listing($request, $context),
            'inspect' => $context->inspect($route['id']),
            'metrics' => $context->store->metrics() + ['observability' => $context->telemetry->status()],
            'link' => $this->link($request, $context),
            'sync' => $this->sync($request, $context),
            'resolve' => $this->resolve($request, $context, $route['id']),
            'retry' => $this->retry($request, $context, $route['id']),
            'approve' => $this->approve($request, $context, $route['id']),
        };
    }

    private function listing(Request $request, Context $context): array
    {
        if (array_diff(array_keys($request->query), ['limit', 'offset']) !== []) {
            throw new HttpFailure(422, 'invalid_query', 'Use only limit and offset query parameters.');
        }
        $limit = $this->integer($request->query['limit'] ?? '50', 1, 100);
        $offset = $this->integer($request->query['offset'] ?? '0', 0, 1_000_000);
        return $context->listPairs($limit, $offset);
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 7 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new HttpFailure(422, 'invalid_query', 'Pagination values are outside the documented bounds.');
        }
        return (int) $value;
    }

    private function link(Request $request, Context $context): array
    {
        $body = $request->json(['id', 'github_id', 'zendesk_id']);
        $context->link($body['id'], $body['github_id'], $body['zendesk_id']);
        return ['id' => $body['id'], 'status' => 'new'];
    }

    private function sync(Request $request, Context $context): array
    {
        $request->json([]);
        return $context->engine()->run();
    }

    private function resolve(Request $request, Context $context, string $id): array
    {
        $body = $request->json(['source']);
        $context->engine()->resolve($id, $body['source']);
        return ['id' => $id, 'status' => 'new'];
    }

    private function retry(Request $request, Context $context, string $id): array
    {
        $request->json([]);
        $context->engine()->retry($id);
        return ['id' => $id, 'status' => 'new'];
    }

    private function approve(Request $request, Context $context, string $id): array
    {
        $body = $request->json(['plan_hash', 'reason']);
        $context->engine()->approve($id, $body['plan_hash'], $body['reason']);
        return ['id' => $id, 'status' => 'new'];
    }

    private function method(Request $request, string $method): void
    {
        if ($request->method !== $method) {
            throw new HttpFailure(405, 'method_not_allowed', 'This HTTP method is not supported.', ['Allow' => $method]);
        }
    }

    private function error(int $status, string $code, string $message, string $requestId, array $headers = []): Response
    {
        file_put_contents('php://stderr', json_encode(['event' => 'http_error', 'request_id' => $requestId, 'code' => $code, 'status' => $status]) . PHP_EOL);
        return new Response($status, ['error' => ['code' => $code, 'message' => $message, 'requestId' => $requestId]],
            ['X-Request-Id' => $requestId] + $headers);
    }
}
