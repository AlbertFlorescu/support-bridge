<?php

declare(strict_types=1);

namespace Bridge\Http;

use Bridge\Application\Context;
use Closure;
use Throwable;

/** Authenticate and route HTTP requests; use-case decisions stay in the application. */
final class Api
{
    private readonly Closure $contextFactory;
    private readonly BearerAuthentication $authentication;
    private readonly RouteResolver $routes;
    private readonly OperatorController $operations;
    private readonly MonitoringController $monitoring;
    private readonly ErrorResponder $errors;

    public function __construct(callable $contextFactory, string $token, string $metricsToken = '')
    {
        $this->contextFactory = Closure::fromCallable($contextFactory);
        $this->authentication = new BearerAuthentication($token, $metricsToken);
        $this->routes = new RouteResolver();
        $this->operations = new OperatorController();
        $this->monitoring = new MonitoringController($contextFactory, $this->authentication, $this->routes);
        $this->errors = new ErrorResponder();
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(12));
        try {
            if ($request->path === '/health') {
                $this->routes->requireMethod($request, 'GET');
                return new Response(200, [
                    'status'  => 'ok',
                    'service' => 'support-bridge',
                    'version' => '1.8.0',
                ], ['X-Request-Id' => $requestId]);
            }
            if (in_array($request->path, ['/metrics', '/monitoring/pairs'], true)) {
                return $this->monitoring->handle($request, $requestId);
            }
            $route = $this->routes->resolve($request);
            $this->authentication->operator($request);
            $context = ($this->contextFactory)();
            $body = $this->operations->dispatch($request, $route, $context);
            $headers = ['X-Request-Id' => $requestId];
            if ($route['name'] === 'link') {
                $headers['Location'] = '/api/pairs/' . $body['id'];
            }
            return new Response($route['name'] === 'link' ? 201 : 200, $body, $headers);
        } catch (Throwable $error) {
            return $this->errors->fromException($error, $requestId);
        }
    }
}
