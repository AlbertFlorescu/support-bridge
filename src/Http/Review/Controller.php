<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

use Bridge\Application\Command\ApprovePlan;
use Bridge\Application\Command\ResolveConflict;
use Bridge\Application\Context;
use Bridge\Application\Query\GetStatus;
use Bridge\Application\Query\InspectPair;
use Bridge\Exception\NotFound;
use Bridge\Http\ErrorResponder;
use Bridge\Http\HttpFailure;
use Bridge\Http\Request;
use Bridge\Http\Response;

/** Browser adapter for the existing operator decisions; never reconciles or writes vendors. */
final class Controller
{
    private readonly \Closure $contextFactory;
    private readonly GrafanaSession $authentication;

    public function __construct(
        callable $contextFactory,
        private readonly string $token,
        private readonly string $metricsToken = '',
        private readonly ?Configuration $configuration = null,
        ?GrafanaSession $authentication = null,
        private readonly ?FormProtection $protection = null
    ) {
        $this->contextFactory = \Closure::fromCallable($contextFactory);
        $this->authentication = $authentication ?? new GrafanaSession();
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(12));
        $configuration = new Configuration();
        $session = null;
        try {
            $configuration = $this->configuration ?? Configuration::fromEnvironment();
            if (!$configuration->enabled || $this->token === $this->metricsToken) {
                throw new HttpFailure(
                    503,
                    'review_not_configured',
                    'Browser review is not enabled for this deployment.'
                );
            }
            $protection = $this->protection ?? new FormProtection($this->token);
            $route = $this->route($request);
            if (
                $request->header('Host') !== parse_url($configuration->origin, PHP_URL_HOST) . ':' .
                    parse_url($configuration->origin, PHP_URL_PORT) || $request->query !== []
            ) {
                throw new HttpFailure(403, 'invalid_review_origin', 'Use the configured local review address.');
            }
            $session = $this->authentication->authenticate($request, $configuration);
            $context = ($this->contextFactory)();
            $page = new Page($configuration, $context->configuration);
            if ($route['name'] === 'list') {
                $body = $page->listing($context->ask(new GetStatus()), $session->login);
            } elseif ($route['name'] === 'link') {
                $id = $this->linkedPair($context, $route['github'], $route['zendesk']);
                return $this->response(303, '', $requestId, $configuration, $session, [
                    'Location' => '/review/pairs/' . rawurlencode($id),
                ]);
            } elseif (in_array($route['name'], ['approve', 'resolve'], true)) {
                $this->requireSubmissionOrigin($request, $configuration);
                if ($route['name'] === 'approve') {
                    $this->approve($request, $context, $route['id'], $session, $protection);
                } else {
                    $this->resolve($request, $context, $route['id'], $session, $protection);
                }
                return $this->response(303, '', $requestId, $configuration, $session, [
                    'Location' => '/review/pairs/' . rawurlencode($route['id']),
                ]);
            } else {
                $body = $page->inspection($context->ask(new InspectPair($route['id'])), $session, $protection);
            }
            return $this->response(200, $body, $requestId, $configuration, $session);
        } catch (\Throwable $error) {
            $response = (new ErrorResponder())->fromException($error, $requestId);
            return $this->response(
                $response->status,
                (new Page($configuration))->error($response->body['error']['message'], $requestId),
                $requestId,
                $configuration,
                $session,
                $response->headers
            );
        }
    }

    private function route(Request $request): array
    {
        $route = null;
        if ($request->path === '/review') {
            $route = ['name' => 'list'];
        } elseif (preg_match('~^/review/links/([1-9][0-9]{0,15})/([1-9][0-9]{0,15})$~D', $request->path, $m)) {
            $route = ['name' => 'link', 'github' => $m[1], 'zendesk' => $m[2]];
        } elseif (
            preg_match(
                '~^/review/pairs/([A-Za-z0-9][A-Za-z0-9_-]{0,63})(?:/(approve|resolve))?$~D',
                $request->path,
                $m
            )
        ) {
            $route = ['name' => $m[2] ?? 'inspect', 'id' => $m[1]];
        }
        if ($route === null) {
            throw new HttpFailure(404, 'route_not_found', 'This review page does not exist.');
        }
        $method = in_array($route['name'], ['approve', 'resolve'], true) ? 'POST' : 'GET';
        if ($request->method !== $method) {
            throw new HttpFailure(
                405,
                'method_not_allowed',
                'This HTTP method is not supported.',
                ['Allow' => $method]
            );
        }
        return $route;
    }

    private function linkedPair(Context $context, string $github, string $zendesk): string
    {
        foreach ($context->ask(new GetStatus()) as $pair) {
            if ($pair['github_id'] === $github && $pair['zendesk_id'] === $zendesk) {
                return $pair['id'];
            }
        }
        throw new NotFound('The numeric pair is not linked.');
    }

    private function requireSubmissionOrigin(Request $request, Configuration $configuration): void
    {
        if (
            $request->header('Origin') !== $configuration->origin
            || !in_array($request->header('Sec-Fetch-Site'), ['', 'same-origin'], true)
        ) {
            throw new HttpFailure(403, 'invalid_review_origin', 'Submit decisions from the review page itself.');
        }
    }

    private function approve(
        Request $request,
        Context $context,
        string $id,
        Session $session,
        FormProtection $protection
    ): void {
        $body = $this->form($request, ['plan_hash', 'csrf', 'reason', 'confirm']);
        $protection->verify($body['csrf'], $session->incoming, $id, $body['plan_hash']);
        if ($body['confirm'] !== 'yes') {
            throw new HttpFailure(
                422,
                'review_confirmation_required',
                'Confirm that you reviewed the complete plan.'
            );
        }
        $context->handle(new ApprovePlan($id, $body['plan_hash'], $body['reason']));
    }

    private function resolve(
        Request $request,
        Context $context,
        string $id,
        Session $session,
        FormProtection $protection
    ): void {
        $body = $this->form($request, ['conflict_hash', 'source', 'csrf', 'confirm']);
        if (!in_array($body['source'], ['github', 'zendesk'], true)) {
            throw new HttpFailure(422, 'invalid_resolution_source', 'Choose GitHub or Zendesk.');
        }
        $protection->verify(
            $body['csrf'],
            $session->incoming,
            $id,
            $body['conflict_hash'],
            'resolve:' . $body['source']
        );
        if ($body['confirm'] !== 'yes') {
            throw new HttpFailure(422, 'review_confirmation_required', 'Confirm the complete source choice.');
        }
        $context->handle(new ResolveConflict($id, $body['source'], $body['conflict_hash']));
    }

    private function form(Request $request, array $required): array
    {
        if (strlen($request->body) > 16384) {
            throw new HttpFailure(413, 'body_too_large', 'Review forms are limited to 16 KiB.');
        }
        $type = strtolower(trim(explode(';', $request->header('Content-Type'))[0]));
        if ($type !== 'application/x-www-form-urlencoded') {
            throw new HttpFailure(415, 'unsupported_media_type', 'Submit the review form.');
        }
        $fields = [];
        foreach (explode('&', $request->body) as $part) {
            $entry = explode('=', $part, 2);
            $name = urldecode($entry[0]);
            if (count($entry) !== 2 || array_key_exists($name, $fields)) {
                throw new HttpFailure(422, 'invalid_review_fields', 'Use the exact review form fields once.');
            }
            $fields[$name] = urldecode($entry[1]);
        }
        if (array_diff(array_keys($fields), $required) !== [] || array_diff($required, array_keys($fields)) !== []) {
            throw new HttpFailure(422, 'invalid_review_fields', 'Use the exact review form fields.');
        }
        return $fields;
    }

    private function response(
        int $status,
        string $body,
        string $requestId,
        Configuration $configuration,
        ?Session $session,
        array $headers = []
    ): Response {
        return new Response($status, $body, array_replace([
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Request-Id' => $requestId,
            // Native form navigation must retain Origin for the strict submission guard.
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; frame-ancestors 'none'; " .
                "base-uri 'none'; form-action 'self'",
        ], $headers, $session?->headers($configuration) ?? []));
    }
}
