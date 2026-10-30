<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\ConfigurationFailure;
use Bridge\Application\ProviderFactory;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Provider\ManagedZendeskAuthentication;
use Bridge\Provider\Zendesk;
use Bridge\Record;
use Bridge\RemoteFailure;
use Bridge\Runtime\SecretLoader;

function rotationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rotationReject(callable $operation, string $class = RemoteFailure::class): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }
        throw new RuntimeException('Unexpected credential rotation rejection type.');
    }
    throw new RuntimeException('Expected credential rotation rejection.');
}

function rotationTicket(string $subject = 'Synthetic case'): HttpResponse
{
    return new HttpResponse(200, [], ['ticket' => [
        'id' => 42,
        'subject' => $subject,
        'status' => 'open',
        'updated_at' => '2026-10-30T12:00:00Z',
    ]]);
}

function rotationBundle(int $issuedAt, string $token = 'synthetic-first-token', array $changes = []): array
{
    return array_replace([
        'version' => 1,
        'subdomain' => 'example',
        'client_id_hash' => hash('sha256', 'synthetic-client'),
        'scope' => 'tickets:read tickets:write',
        'access_token' => $token,
        'issued_at' => $issuedAt,
        'expires_at' => $issuedAt + 14400,
        'renew_at' => $issuedAt + 14100,
    ], $changes);
}

function rotationPublish(string $path, array $bundle): void
{
    $temporary = dirname($path) . '/.test-' . bin2hex(random_bytes(6));
    file_put_contents($temporary, json_encode($bundle, JSON_THROW_ON_ERROR));
    chmod($temporary, 0400);
    if (!rename($temporary, $path)) {
        throw new RuntimeException('Synthetic credential publication failed.');
    }
}

function rotationWithDirectory(callable $test): void
{
    $directory = sys_get_temp_dir() . '/bridge-token-rotation-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $test($directory, $directory . '/zendesk-oauth.json');
    } finally {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

$variables = [
    'BRIDGE_API_TOKEN', 'BRIDGE_API_TOKEN_FILE',
    'BRIDGE_METRICS_TOKEN', 'BRIDGE_METRICS_TOKEN_FILE',
    'BRIDGE_GITHUB_TOKEN', 'BRIDGE_GITHUB_TOKEN_FILE',
    'BRIDGE_ZENDESK_TOKEN', 'BRIDGE_ZENDESK_TOKEN_FILE',
    'BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE', 'BRIDGE_ZENDESK_AUTH', 'BRIDGE_ZENDESK_EMAIL',
];
$environment = [];
foreach ($variables as $variable) {
    $environment[$variable] = getenv($variable);
}

$tests = [];
$tests['One provider reads each atomically rotated token for GET and safe PUT'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        rotationPublish($path, rotationBundle($now));
        $requests = [];
        $http = new HttpClient(static function ($method, $url, $headers, $json) use (&$requests): HttpResponse {
            $requests[] = compact('method', 'headers', 'json');
            return rotationTicket($method === 'PUT' ? 'Changed summary' : 'Synthetic case');
        });
        $source = new ManagedZendeskAuthentication($path, 'example', static fn(): int => $now);
        $provider = new Zendesk($http, 'example', '', $source, 'oauth');
        $provider->fetch('42');
        rotationPublish($path, rotationBundle($now, 'synthetic-second-token'));
        $record = $provider->fetch('42');
        rotationPublish($path, rotationBundle($now, 'synthetic-third-token'));
        $provider->patch('42', ['title' => 'Changed summary'], $record);
        rotationCheck(array_column($requests, 'method') === ['GET', 'GET', 'PUT'], 'Request count or methods changed.');
        rotationCheck(array_map(static fn(array $r): string => $r['headers']['Authorization'], $requests) === [
            'Bearer synthetic-first-token', 'Bearer synthetic-second-token', 'Bearer synthetic-third-token',
        ], 'The existing provider retained a stale token.');
        rotationCheck($requests[2]['json'] === ['ticket' => [
            'safe_update' => true,
            'updated_stamp' => $record->version,
            'subject' => 'Changed summary',
        ]], 'Credential rotation changed the safe patch contract.');
    });
};
$tests['Missing, future or expired credentials fail retryably before provider transport'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        $calls = 0;
        $http = new HttpClient(static function () use (&$calls): HttpResponse {
            ++$calls;
            return rotationTicket();
        });
        $source = new ManagedZendeskAuthentication($path, 'example', static function () use (&$now): int {
            return $now;
        });
        $provider = new Zendesk($http, 'example', '', $source, 'oauth');
        $missing = rotationReject(static fn() => $provider->fetch('42'));
        rotationCheck($missing->retryable, 'Missing token is not retryable.');
        rotationPublish($path, rotationBundle($now));
        $now += 14400;
        $expired = rotationReject(static fn() => $provider->fetch('42'));
        rotationCheck($expired->retryable, 'The exact expiration boundary remained usable.');
        $expected = new Record(['title' => 'Original', 'state' => 'open'], 'observed-version');
        $expiredWrite = rotationReject(static fn() => $provider->patch('42', ['title' => 'Changed'], $expected));
        rotationCheck($expiredWrite->retryable, 'Expiration immediately before a PUT was not blocked.');
        rotationPublish($path, rotationBundle($now + 1));
        $future = rotationReject(static fn() => $provider->fetch('42'));
        rotationCheck($future->retryable, 'A future-issued token is not blocked.');
        rotationCheck($calls === 0, 'An unavailable credential reached provider transport.');
    });
};
$tests['Invalid identity, scope and credential data fail closed without an old-token fallback'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        $calls = 0;
        $http = new HttpClient(static function () use (&$calls): HttpResponse {
            ++$calls;
            return rotationTicket();
        });
        $provider = new Zendesk($http, 'example', '', new ManagedZendeskAuthentication(
            $path,
            'example',
            static fn(): int => $now
        ), 'oauth');
        rotationPublish($path, rotationBundle($now));
        $provider->fetch('42');
        foreach ([
            ['subdomain' => 'wrong-tenant'],
            ['scope' => 'tickets:read tickets:write users:read'],
            ['scope' => 'tickets:read'],
            ['version' => 2],
            ['client_id_hash' => 'private-marker'],
            ['access_token' => "private-marker\r\n"],
            ['expires_at' => $now + 14401],
            ['renew_at' => $now + 14400],
            ['unexpected' => 'private-marker'],
        ] as $changes) {
            rotationPublish($path, rotationBundle($now, 'synthetic-private-marker', $changes));
            $error = rotationReject(static fn() => $provider->fetch('42'));
            rotationCheck(!$error->retryable, 'Invalid managed metadata was accepted as a usable token.');
            rotationCheck(!str_contains($error->getMessage(), 'private-marker'), 'Credential data escaped in an error.');
        }
        chmod($path, 0600);
        file_put_contents($path, '{private-marker');
        $error = rotationReject(static fn() => $provider->fetch('42'));
        rotationCheck(!str_contains($error->getMessage(), 'private-marker'), 'Malformed JSON escaped in an error.');
        rotationCheck($calls === 1, 'Invalid metadata fell back to the previously successful token.');
    });
};
$tests['Unsafe or oversized managed files fail before transport'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        $source = new ManagedZendeskAuthentication($path, 'example', static fn(): int => $now);
        rotationPublish($path, rotationBundle($now));
        chmod($path, 0644);
        rotationCheck(!rotationReject($source->authorizationHeader(...))->retryable, 'Public credential accepted.');
        chmod($path, 0400);
        link($path, $directory . '/hardlink');
        rotationCheck(!rotationReject($source->authorizationHeader(...))->retryable, 'Hard-linked token accepted.');
        unlink($directory . '/hardlink');
        rename($path, $directory . '/original');
        symlink($directory . '/original', $path);
        rotationCheck(!rotationReject($source->authorizationHeader(...))->retryable, 'Symlink token accepted.');
        unlink($path);
        mkdir($path, 0700);
        rotationCheck(!rotationReject($source->authorizationHeader(...))->retryable, 'Directory accepted as token.');
        rmdir($path);
        file_put_contents($path, str_repeat('x', 16385));
        chmod($path, 0400);
        rotationCheck(!rotationReject($source->authorizationHeader(...))->retryable, 'Oversized token accepted.');
    });
};
$tests['A provider 401 does not trigger renewal or an inline PUT replay'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        rotationPublish($path, rotationBundle($now));
        $calls = 0;
        $http = new HttpClient(static function () use (&$calls): HttpResponse {
            ++$calls;
            return new HttpResponse(401, [], ['error' => 'synthetic-private-marker']);
        });
        $provider = new Zendesk($http, 'example', '', new ManagedZendeskAuthentication(
            $path,
            'example',
            static fn(): int => $now
        ), 'oauth');
        $expected = new Record(['title' => 'Original', 'state' => 'open'], 'observed-version');
        $error = rotationReject(static fn() => $provider->patch('42', ['title' => 'Changed'], $expected));
        rotationCheck($calls === 1 && !$error->retryable, 'A rejected PUT was silently replayed or reclassified.');
        rotationCheck(!str_contains($error->getMessage(), 'private-marker'), 'Upstream error body escaped.');
    });
};
$tests['Unknown PUT outcomes remain single attempts for durable engine recovery'] = static function (): void {
    rotationWithDirectory(static function (string $directory, string $path): void {
        $now = 1793360000;
        rotationPublish($path, rotationBundle($now));
        $calls = 0;
        $http = new HttpClient(static function () use (&$calls): HttpResponse {
            ++$calls;
            throw new RuntimeException('synthetic-private-marker');
        });
        $provider = new Zendesk($http, 'example', '', new ManagedZendeskAuthentication(
            $path,
            'example',
            static fn(): int => $now
        ), 'oauth');
        $expected = new Record(['title' => 'Original', 'state' => 'open'], 'observed-version');
        $error = rotationReject(static fn() => $provider->patch('42', ['title' => 'Changed'], $expected));
        rotationCheck($calls === 1 && $error->retryable, 'Unknown write outcomes were retried inside authentication.');
        rotationCheck(!str_contains($error->getMessage(), 'private-marker'), 'Transport details escaped.');
    });
};
$tests['Managed composition requires OAuth and one credential source without reading the bundle'] = static function (): void {
    putenv('BRIDGE_GITHUB_TOKEN=synthetic-github-token');
    putenv('BRIDGE_ZENDESK_AUTH=oauth');
    putenv('BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE=/not-issued-yet/zendesk-oauth.json');
    $factory = new ProviderFactory(new Configuration('live', '/unused', 'example/demo', 'example'));
    rotationCheck($factory->create()[1] instanceof Zendesk, 'Managed provider construction read an unissued token.');
    foreach (['BRIDGE_ZENDESK_TOKEN', 'BRIDGE_ZENDESK_TOKEN_FILE'] as $name) {
        putenv($name . '=synthetic-private-marker');
        $error = rotationReject($factory->create(...), ConfigurationFailure::class);
        rotationCheck(!str_contains($error->getMessage(), 'private-marker'), 'Configuration disclosed static input.');
        putenv($name);
    }
    putenv('BRIDGE_ZENDESK_AUTH=api-token');
    rotationReject($factory->create(...), ConfigurationFailure::class);
};
$tests['Secret loading preserves managed file identity and rejects mixed static configuration'] = static function (): void {
    putenv('BRIDGE_ZENDESK_AUTH=oauth');
    putenv('BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE=/not-issued-yet/zendesk-oauth.json');
    (new SecretLoader())->load();
    rotationCheck(getenv('BRIDGE_ZENDESK_TOKEN') === false, 'Managed token was copied into process environment.');
    foreach (['BRIDGE_ZENDESK_TOKEN', 'BRIDGE_ZENDESK_TOKEN_FILE'] as $name) {
        putenv($name . '=synthetic-private-marker');
        rotationReject(static fn() => (new SecretLoader())->load(), RuntimeException::class);
        putenv($name);
    }
    putenv('BRIDGE_ZENDESK_AUTH=api-token');
    rotationReject(static fn() => (new SecretLoader())->load(), RuntimeException::class);
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        foreach ($variables as $variable) {
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
    foreach ($environment as $variable => $value) {
        putenv($value === false ? $variable : $variable . '=' . $value);
    }
}
echo count($tests) . ' Zendesk token rotation checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
