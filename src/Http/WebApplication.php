<?php

declare(strict_types=1);

namespace Bridge\Http;

final class WebApplication
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' " .
            "'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; " .
                "frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
    ];

    public function __construct(private readonly Api $api, private readonly StaticAssets $assets)
    {
    }

    public function run(): void
    {
        umask(0077);
        ini_set('display_errors', '0');
        $request = new Request(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            function_exists('getallheaders') ? getallheaders() : [],
            file_get_contents('php://input', false, null, 0, 16_385) ?: '',
            $_GET
        );
        $response = $this->assets->handle($request) ?? $this->api->handle($request);
        (new Response($response->status, $response->body, self::SECURITY_HEADERS + $response->headers))->send();
    }
}
