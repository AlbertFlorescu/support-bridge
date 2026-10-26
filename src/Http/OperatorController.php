<?php

declare(strict_types=1);

namespace Bridge\Http;

use Bridge\Application\Context;
use Bridge\Application\Command\ApprovePlan;
use Bridge\Application\Command\LinkPair;
use Bridge\Application\Command\Reconcile;
use Bridge\Application\Command\ResolveConflict;
use Bridge\Application\Command\RetryPair;
use Bridge\Application\Query\GetMetrics;
use Bridge\Application\Query\InspectPair;
use Bridge\Application\Query\ListPairs;

final class OperatorController
{
    public function dispatch(Request $request, array $route, Context $context): array
    {
        if (!in_array($route['name'], ['list'], true) && $request->query !== []) {
            throw new HttpFailure(422, 'invalid_query', 'This endpoint does not accept query parameters.');
        }
        return match ($route['name']) {
            'list' => $this->listing($request, $context),
            'inspect' =>  $context->ask(new InspectPair($route['id'])),
            'metrics' => $context->ask(new GetMetrics()),
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
        return $context->ask(new ListPairs($limit, $offset));
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (
            !is_string($value) || !ctype_digit($value) || strlen($value) > 7 || (int) $value < $minimum
            || (int) $value > $maximum
        ) {
            throw new HttpFailure(422, 'invalid_query', 'Pagination values are outside the documented bounds.');
        }
        return (int) $value;
    }

    private function link(Request $request, Context $context): array
    {
        $body = $request->json(['id', 'github_id', 'zendesk_id']);
        $context->handle(new LinkPair($body['id'], $body['github_id'], $body['zendesk_id']));
        return [
            'id'     => $body['id'],
            'status' => 'new',
        ];
    }

    private function sync(Request $request, Context $context): array
    {
        $request->json([]);
        return $context->handle(new Reconcile());
    }

    private function resolve(Request $request, Context $context, string $id): array
    {
        $body = $request->json(['source']);
        $context->handle(new ResolveConflict($id, $body['source']));
        return [
            'id'     => $id,
            'status' => 'new',
        ];
    }

    private function retry(Request $request, Context $context, string $id): array
    {
        $request->json([]);
        $context->handle(new RetryPair($id));
        return [
            'id'     => $id,
            'status' => 'new',
        ];
    }

    private function approve(Request $request, Context $context, string $id): array
    {
        $body = $request->json(['plan_hash', 'reason']);
        $context->handle(new ApprovePlan($id, $body['plan_hash'], $body['reason']));
        return [
            'id'     => $id,
            'status' => 'new',
        ];
    }
}
