<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/tools/oauth/issue-zendesk-token.php';

use Bridge\Application\Configuration;
use Bridge\Application\ConfigurationFailure;
use Bridge\Application\ProviderFactory;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Provider\GitHub;
use Bridge\Provider\Zendesk;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Tooling\OAuth\ZendeskOAuthTokenCommand;

function oauthCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function oauthReject(callable $operation, string $class = InvalidArgumentException::class): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }
        throw new RuntimeException('Unexpected OAuth rejection type.');
    }
    throw new RuntimeException('Expected OAuth rejection.');
}

function oauthTicket(string $subject = 'Synthetic case'): HttpResponse
{
    return new HttpResponse(200, [], ['ticket' => [
        'id' => 42,
        'subject' => $subject,
        'status' => 'pending',
        'updated_at' => '2026-10-27T12:00:00Z',
    ]]);
}

function oauthWithPrivateFiles(callable $operation): void
{
    $directory = sys_get_temp_dir() . '/support-bridge-oauth-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    foreach (['client-id' => 'synthetic-client-id', 'client-secret' => 'synthetic-client-secret'] as $name => $value) {
        file_put_contents($directory . '/' . $name, $value . "\n");
        chmod($directory . '/' . $name, 0600);
    }
    $arguments = [
        '--subdomain=example',
        '--client-id-file=' . $directory . '/client-id',
        '--client-secret-file=' . $directory . '/client-secret',
        '--output=' . $directory . '/zendesk-token',
    ];
    try {
        $operation($directory, $arguments);
    } finally {
        foreach (scandir($directory) as $name) {
            if ($name !== '.' && $name !== '..') {
                $path = $directory . '/' . $name;
                if (is_dir($path) && !is_link($path)) {
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($directory);
    }
}

function oauthTokenResponse(array $changes = []): array
{
    return array_replace([
        'access_token' => 'synthetic-issued-access-token',
        'token_type' => 'bearer',
        'scope' => 'tickets:read tickets:write',
        'expires_in' => 14400,
    ], $changes);
}

$names = [
    'BRIDGE_GITHUB_TOKEN',
    'BRIDGE_ZENDESK_TOKEN',
    'BRIDGE_ZENDESK_EMAIL',
    'BRIDGE_ZENDESK_AUTH',
];
$environment = [];
foreach ($names as $name) {
    $environment[$name] = getenv($name);
}

$tests = [];
$tests['Zendesk OAuth sends Bearer on GET and safe subject-only PUT without email'] = static function (): void {
    $requests = [];
    $http = new HttpClient(static function ($method, $url, $headers, $json) use (&$requests): HttpResponse {
        $requests[] = compact('method', 'url', 'headers', 'json');
        return oauthTicket($method === 'PUT' ? 'Agreed summary' : 'Synthetic case');
    });
    $provider = new Zendesk($http, 'example', '', 'synthetic-oauth-access-token', 'oauth');
    $record = $provider->fetch('42');
    $provider->patch('42', ['title' => 'Agreed summary'], $record);
    oauthCheck(count($requests) === 2, 'OAuth provider made unexpected requests.');
    foreach ($requests as $request) {
        oauthCheck($request['headers']['Authorization'] === 'Bearer synthetic-oauth-access-token', 'Bearer header differs.');
        oauthCheck($request['url'] === 'https://example.zendesk.com/api/v2/tickets/42.json', 'Zendesk host changed.');
    }
    oauthCheck($requests[0]['method'] === 'GET' && $requests[1]['method'] === 'PUT', 'Provider methods changed.');
    oauthCheck($requests[1]['json'] === ['ticket' => [
        'safe_update' => true,
        'updated_stamp' => $record->version,
        'subject' => 'Agreed summary',
    ]], 'OAuth changed the safe-update field contract.');
};
$tests['Legacy four-argument Zendesk construction retains Basic API-token authentication'] = static function (): void {
    $requests = [];
    $http = new HttpClient(static function ($method, $url, $headers, $json) use (&$requests): HttpResponse {
        $requests[] = compact('method', 'url', 'headers', 'json');
        return oauthTicket();
    });
    $provider = new Zendesk($http, 'example', 'agent@example.invalid', 'legacy-synthetic-token');
    $record = $provider->fetch('42');
    $provider->patch('42', ['title' => 'Changed subject'], $record);
    $expected = 'Basic ' . base64_encode('agent@example.invalid/token:legacy-synthetic-token');
    oauthCheck(count($requests) === 2, 'Legacy provider request count changed.');
    oauthCheck($requests[0]['headers']['Authorization'] === $expected, 'Legacy GET auth changed.');
    oauthCheck($requests[1]['headers']['Authorization'] === $expected, 'Legacy PUT auth changed.');
};
$tests['Authentication modes and malformed tokens fail before transport without credential disclosure'] = static function (): void {
    $calls = 0;
    $http = new HttpClient(static function () use (&$calls): HttpResponse {
        ++$calls;
        return oauthTicket();
    });
    foreach (['', 'oauth2', 'OAUTH', 'mode-private-marker'] as $mode) {
        $error = oauthReject(static fn() => new Zendesk($http, 'example', '', 'token-private-marker', $mode));
        oauthCheck(!str_contains($error->getMessage(), 'private-marker'), 'Authentication error disclosed input.');
    }
    foreach (['', ' ', "unsafe\r\nheader", 'contains space', "\x7f", "token\u{00a0}suffix", "\xff", str_repeat('x', 4097)] as $token) {
        oauthReject(static fn() => new Zendesk($http, 'example', '', $token, 'oauth'));
    }
    oauthCheck($calls === 0, 'Invalid credentials reached transport.');
};
$tests['OAuth does not use email while API-token mode requires a valid address'] = static function (): void {
    oauthCheck(
        (new ZendeskAuthentication('unused invalid email', 'oauth-synthetic', 'oauth'))->authorizationHeader()
            === 'Bearer oauth-synthetic',
        'OAuth depends on an email address.'
    );
    oauthReject(static fn() => new ZendeskAuthentication('', 'legacy-synthetic'));
    oauthReject(static fn() => new ZendeskAuthentication('not-an-address', 'legacy-synthetic', 'api-token'));
};
$tests['Live provider factory supports OAuth without Zendesk email'] = static function (): void {
    putenv('BRIDGE_GITHUB_TOKEN=github-private-marker');
    putenv('BRIDGE_ZENDESK_TOKEN=zendesk-private-marker');
    putenv('BRIDGE_ZENDESK_AUTH=oauth');
    $factory = new ProviderFactory(new Configuration('live', '/unused', 'example/demo', 'example'));
    [$github, $zendesk] = $factory->create();
    oauthCheck($github instanceof GitHub && $zendesk instanceof Zendesk, 'OAuth provider composition failed.');
    oauthCheck(getenv('BRIDGE_ZENDESK_EMAIL') === false, 'Factory required or changed an email.');
};
$tests['Factory defaults remain legacy and invalid modes have sanitized configuration errors'] = static function (): void {
    putenv('BRIDGE_GITHUB_TOKEN=github-private-marker');
    putenv('BRIDGE_ZENDESK_TOKEN=zendesk-private-marker');
    $factory = new ProviderFactory(new Configuration('live', '/unused', 'example/demo', 'example'));
    oauthReject($factory->create(...), ConfigurationFailure::class);
    putenv('BRIDGE_ZENDESK_EMAIL=agent@example.invalid');
    oauthCheck($factory->create()[1] instanceof Zendesk, 'Legacy factory default changed.');
    putenv('BRIDGE_ZENDESK_AUTH=mode-private-marker');
    $error = oauthReject($factory->create(...), ConfigurationFailure::class);
    oauthCheck(!str_contains($error->getMessage(), 'private-marker'), 'Factory error disclosed credentials or mode.');
};
$tests['Manual helper requests only ticket scopes and atomically saves a private credential'] = static function (): void {
    oauthWithPrivateFiles(static function (string $directory, array $arguments): void {
        $calls = [];
        $command = new ZendeskOAuthTokenCommand(static function ($url, $payload) use (&$calls): array {
            $calls[] = compact('url', 'payload');
            return oauthTokenResponse(['scope' => 'tickets:write tickets:read']);
        });
        $metadata = $command->run($arguments);
        oauthCheck($calls === [[
            'url' => 'https://example.zendesk.com/oauth/tokens',
            'payload' => [
                'grant_type' => 'client_credentials',
                'client_id' => 'synthetic-client-id',
                'client_secret' => 'synthetic-client-secret',
                'scope' => 'tickets:read tickets:write',
                'expires_in' => 14400,
            ],
        ]], 'OAuth grant host, scope or lifetime changed.');
        $path = $directory . '/zendesk-token';
        oauthCheck(file_get_contents($path) === "synthetic-issued-access-token\n", 'Token file differs.');
        oauthCheck((fileperms($path) & 0777) === 0600, 'Token permissions are not private.');
        oauthCheck($metadata['status'] === 'saved' && $metadata['expires_in'] === 14400, 'Safe metadata differs.');
        oauthCheck(!str_contains(json_encode($metadata), 'synthetic-'), 'Metadata disclosed credential input.');
        oauthCheck(count(glob($directory . '/.zendesk-token-*')) === 0, 'A temporary credential file remains.');
        $replacement = new ZendeskOAuthTokenCommand(static fn() => oauthTokenResponse(['access_token' => 'replacement-token']));
        $replacement->run($arguments);
        oauthCheck(file_get_contents($path) === "replacement-token\n", 'Manual atomic replacement failed.');
    });
};
$tests['Malformed or overbroad grant responses preserve an existing token and disclose no credentials'] = static function (): void {
    oauthWithPrivateFiles(static function (string $directory, array $arguments): void {
        $path = $directory . '/zendesk-token';
        file_put_contents($path, "existing-private-token\n");
        chmod($path, 0600);
        foreach ([
            ['scope' => 'read write'],
            ['scope' => 'tickets:read tickets:write users:read'],
            ['scope' => 'tickets:read'],
            ['expires_in' => 299],
            ['expires_in' => 14401],
            ['expires_in' => '14400'],
            ['token_type' => 'basic'],
            ['access_token' => "private-marker\r\n"],
            ['access_token' => null],
        ] as $response) {
            $command = new ZendeskOAuthTokenCommand(static fn() => oauthTokenResponse($response));
            $error = oauthReject(static fn() => $command->run($arguments), RuntimeException::class);
            oauthCheck(!str_contains($error->getMessage(), 'private-marker'), 'Response validation leaked input.');
            oauthCheck(file_get_contents($path) === "existing-private-token\n", 'Invalid response replaced a token.');
        }
        $command = new ZendeskOAuthTokenCommand(static function (): array {
            throw new RuntimeException('synthetic-client-secret private-marker');
        });
        $error = oauthReject(static fn() => $command->run($arguments), RuntimeException::class);
        oauthCheck(!str_contains($error->getMessage(), 'private-marker'), 'Transport error leaked a credential.');
        oauthCheck(file_get_contents($path) === "existing-private-token\n", 'Transport failure replaced a token.');
    });
};
$tests['Unsafe credential paths and malformed arguments fail before token transport'] = static function (): void {
    oauthWithPrivateFiles(static function (string $directory, array $arguments): void {
        $calls = 0;
        $command = new ZendeskOAuthTokenCommand(static function () use (&$calls): array {
            ++$calls;
            return oauthTokenResponse();
        });
        foreach ([
            array_slice($arguments, 1),
            [...$arguments, '--scope=read'],
            [...$arguments, '--subdomain=example'],
            array_replace($arguments, [0 => '--subdomain=example.attacker.invalid']),
            array_replace($arguments, [3 => '--output=' . $directory . '/client-secret']),
        ] as $invalid) {
            oauthReject(static fn() => $command->run($invalid));
        }
        chmod($directory . '/client-secret', 0644);
        oauthReject(static fn() => $command->run($arguments));
        chmod($directory . '/client-secret', 0600);
        symlink($directory . '/client-id', $directory . '/zendesk-token');
        oauthReject(static fn() => $command->run($arguments));
        unlink($directory . '/zendesk-token');
        mkdir($directory . '/zendesk-token', 0700);
        oauthReject(static fn() => $command->run($arguments));
        rmdir($directory . '/zendesk-token');
        chmod($directory, 0755);
        oauthReject(static fn() => $command->run($arguments));
        chmod($directory, 0700);
        oauthCheck($calls === 0, 'Invalid inputs reached token transport.');
    });
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        foreach ($names as $variable) {
            putenv($variable);
        }
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            ++$failed;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
} finally {
    foreach ($environment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}

echo count($tests) . ' OAuth authentication checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
