<?php

declare(strict_types=1);

namespace Bridge\Http;

final class StaticAssets
{
    public function __construct(private readonly string $root)
    {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET') {
            return null;
        }
        $assets = [
            '/'                                       => [
                '/public/docs.html',
                'text/html; charset=utf-8',
            ],
            '/docs'                                   => [
                '/public/docs.html',
                'text/html; charset=utf-8',
            ],
            '/openapi.json'                           => [
                '/docs/api/openapi.json',
                'application/json',
            ],
            '/assets/swagger-ui/swagger-ui.css'       => [
                '/public/assets/swagger-ui/swagger-ui.css',
                'text/css',
            ],
            '/assets/swagger-ui/swagger-ui-bundle.js' => [
                '/public/assets/swagger-ui/swagger-ui-bundle.js',
                'text/javascript',
            ],
            '/assets/swagger-ui/initializer.js'       => [
                '/public/assets/swagger-ui/initializer.js',
                'text/javascript',
            ],
        ];
        if (!isset($assets[$request->path])) {
            return null;
        }
        [
            $path,
            $type,
        ] = $assets[$request->path];
        $content = file_get_contents($this->root . $path);
        if ($content === false) {
            throw new \RuntimeException('A public asset is unavailable.');
        }
        return new Response(200, $content, ['Content-Type' => $type]);
    }
}
