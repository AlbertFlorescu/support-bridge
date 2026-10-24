<?php
declare(strict_types=1);

// Serve only this public directory. Never use the repository root as the document root.
require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Http\Api;
use Bridge\Http\Request;

umask(0077);
ini_set('display_errors', '0');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

$assets = [
    '/' => [__DIR__ . '/docs.html', 'text/html; charset=utf-8'],
    '/docs' => [__DIR__ . '/docs.html', 'text/html; charset=utf-8'],
    '/openapi.json' => [dirname(__DIR__) . '/docs/api/openapi.json', 'application/json'],
    '/assets/swagger-ui/swagger-ui.css' => [__DIR__ . '/assets/swagger-ui/swagger-ui.css', 'text/css'],
    '/assets/swagger-ui/swagger-ui-bundle.js' => [__DIR__ . '/assets/swagger-ui/swagger-ui-bundle.js', 'text/javascript'],
    '/assets/swagger-ui/initializer.js' => [__DIR__ . '/assets/swagger-ui/initializer.js', 'text/javascript'],
];
if (isset($assets[$path]) && $method === 'GET') {
    header('Content-Type: ' . $assets[$path][1]);
    header('Cache-Control: no-store');
    readfile($assets[$path][0]);
    return;
}

$api = new Api(static fn(): Context => new Context(Configuration::fromEnvironment()),
    getenv('BRIDGE_API_TOKEN') ?: '', getenv('BRIDGE_METRICS_TOKEN') ?: '');
$request = new Request($method, $path, function_exists('getallheaders') ? getallheaders() : [],
    file_get_contents('php://input', false, null, 0, 16_385) ?: '', $_GET);
$api->handle($request)->send();
