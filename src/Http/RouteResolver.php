<?php

declare(strict_types=1);

namespace Bridge\Http;

final class RouteResolver
{
    public function resolve(Request $request): array
    {
        $routes = [
            '/api/pairs'   => [
                'GET'  => 'list',
                'POST' => 'link',
            ],
            '/api/metrics' => ['GET' => 'metrics'],
            '/api/sync'    => ['POST' => 'sync'],
            '/api/intake' => ['POST' => 'intake'],
            '/api/escalations' => ['GET' => 'escalations'],
            '/api/notes/sync' => ['POST' => 'sync_notes'],
            '/api/note-deliveries' => ['GET' => 'note_deliveries'],
            '/api/comments/sync' => ['POST' => 'sync_comments'],
            '/api/comment-deliveries' => ['GET' => 'comment_deliveries'],
        ];
        if (isset($routes[$request->path])) {
            $methods = $routes[$request->path];
            if (!isset($methods[$request->method])) {
                throw new HttpFailure(
                    405,
                    'method_not_allowed',
                    'This HTTP method is not supported.',
                    ['Allow' => implode(', ', array_keys($methods))]
                );
            }
            return ['name' => $methods[$request->method]];
        }
        if (
            preg_match(
                '~^/api/pairs/([A-Za-z0-9][A-Za-z0-9_-]{0,63})(?:/(resolve|retry|approve))?$~D',
                $request->path,
                $matches
            )
        ) {
            $operation = $matches[2] ?? 'inspect';
            $this->requireMethod($request, $operation === 'inspect' ? 'GET' : 'POST');
            return [
                'name' => $operation,
                'id'   => $matches[1],
            ];
        }
        throw new HttpFailure(404, 'route_not_found', 'The endpoint does not exist.');
    }

    public function requireMethod(Request $request, string $method): void
    {
        if ($request->method !== $method) {
            throw new HttpFailure(
                405,
                'method_not_allowed',
                'This HTTP method is not supported.',
                ['Allow' => $method]
            );
        }
    }
}
