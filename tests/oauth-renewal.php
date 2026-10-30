<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Infrastructure\OAuth\OAuthClientCredentials;
use Bridge\Infrastructure\OAuth\OAuthTokenBundle;
use Bridge\Infrastructure\OAuth\PrivateCredentialDirectory;
use Bridge\Infrastructure\OAuth\ZendeskTokenIssuer;
use Bridge\Infrastructure\OAuth\ZendeskTokenRenewal;
use Bridge\Provider\ManagedZendeskAuthentication;
use Bridge\Runtime\OAuthClientCredentialImporter;

function renewalCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renewalReject(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected credential renewal rejection.');
}

function renewalResponse(array $changes = []): array
{
    return array_replace([
        'access_token' => 'synthetic-access-token',
        'token_type' => 'bearer',
        'scope' => 'tickets:read tickets:write',
        'expires_in' => 14400,
    ], $changes);
}

function renewalInput(array $values)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, json_encode($values, JSON_THROW_ON_ERROR));
    rewind($stream);
    return $stream;
}

function renewalWithFiles(callable $test): void
{
    $root = sys_get_temp_dir() . '/support-bridge-renewal-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/issuer', 0700);
    mkdir($root . '/vendor', 0700);
    $clientFile = $root . '/issuer/client.json';
    file_put_contents($clientFile, (new OAuthClientCredentials('example', 'synthetic-client', 'synthetic-secret'))->toJson());
    chmod($clientFile, 0400);
    try {
        $test($root, $clientFile, $root . '/vendor');
    } finally {
        $remove = static function (string $directory) use (&$remove): void {
            foreach (scandir($directory) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory . '/' . $name;
                if (is_dir($path) && !is_link($path)) {
                    $remove($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($directory);
        };
        $remove($root);
    }
}

$tests = [];
$tests['First issuance binds identity, scope and four-hour lifetime in one private bundle'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $calls = [];
        $issuer = new ZendeskTokenIssuer(static function ($url, $payload) use (&$calls): array {
            $calls[] = compact('url', 'payload');
            return renewalResponse(['scope' => 'tickets:write tickets:read']);
        });
        $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 1000);
        renewalCheck(!$renewal->healthy(), 'A missing credential was reported healthy.');
        $result = $renewal->tick();
        renewalCheck($result === [
            'status' => 'renewed', 'expires_at' => 15400, 'renew_at' => 15100, 'next_check_in_seconds' => 30,
        ], 'The initial renewal metadata differs.');
        renewalCheck($calls === [[
            'url' => 'https://example.zendesk.com/oauth/tokens',
            'payload' => [
                'grant_type' => 'client_credentials', 'client_id' => 'synthetic-client',
                'client_secret' => 'synthetic-secret', 'scope' => 'tickets:read tickets:write', 'expires_in' => 14400,
            ],
        ]], 'The issuer host, grant or permissions differ.');
        $path = $directory . '/zendesk-oauth.json';
        renewalCheck((fileperms($path) & 0777) === 0400, 'The bundle is not private.');
        $bundle = OAuthTokenBundle::fromJson(file_get_contents($path), 'example', hash('sha256', 'synthetic-client'));
        renewalCheck($bundle->token() === 'synthetic-access-token' && $renewal->healthy(), 'Published credential differs.');
        renewalCheck(!str_contains(json_encode($result), 'synthetic-'), 'Result metadata exposed a credential.');
        renewalCheck(!file_exists($directory . '/github-token'), 'Renewal created a GitHub credential.');
    });
};
$tests['Mixed-case tenant identities round-trip through import, issuance and the managed reader'] = static function (): void {
    renewalWithFiles(static function ($root, $unused, $directory): void {
        $issuerDirectory = $root . '/mixed-issuer';
        $importer = new OAuthClientCredentialImporter($issuerDirectory, 'ExAmPlE');
        $input = renewalInput([
            'subdomain' => 'eXaMpLe', 'client_id' => 'CaseSensitiveClient', 'client_secret' => 'synthetic-secret',
        ]);
        try {
            $importer->run($input);
        } finally {
            fclose($input);
        }
        $clientFile = $issuerDirectory . '/client.json';
        $storedClient = json_decode(file_get_contents($clientFile), true);
        renewalCheck($storedClient['subdomain'] === 'example', 'Imported tenant identity was not normalized.');
        renewalCheck($storedClient['client_id'] === 'CaseSensitiveClient', 'Client identifier case was changed.');
        $url = '';
        $issuer = new ZendeskTokenIssuer(static function ($requestedUrl) use (&$url): array {
            $url = $requestedUrl;
            return renewalResponse();
        });
        $renewal = new ZendeskTokenRenewal('EXAMPLE', $clientFile, $directory, $issuer, static fn() => 1000);
        $renewal->tick();
        $path = $directory . '/zendesk-oauth.json';
        $storedBundle = json_decode(file_get_contents($path), true);
        renewalCheck($storedBundle['subdomain'] === 'example', 'Published tenant identity was not normalized.');
        renewalCheck($url === 'https://example.zendesk.com/oauth/tokens', 'Issuer used a noncanonical hostname.');
        renewalCheck($renewal->healthy(), 'Mixed-case configuration did not produce a healthy renewal.');
        $reader = new ManagedZendeskAuthentication($path, 'ExAmPlE', static fn() => 1000);
        renewalCheck($reader->authorizationHeader() === 'Bearer synthetic-access-token', 'Managed reader rejected the bundle.');
        renewalCheck(
            OAuthTokenBundle::fromJson(file_get_contents($path), 'EXAMPLE')->subdomain === 'example',
            'Bundle lookup did not normalize its expected tenant.'
        );
    });
};
$tests['Not-due checks avoid issuance while due checks publish exactly one replacement'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $now = 1000;
        $calls = 0;
        $issuer = new ZendeskTokenIssuer(static function () use (&$calls): array {
            return renewalResponse(['access_token' => 'synthetic-access-' . ++$calls]);
        });
        // A by-reference clock makes the request and completion observations independently controllable.
        $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static function () use (&$now): int {
            return $now;
        });
        $renewal->tick();
        $initial = file_get_contents($directory . '/zendesk-oauth.json');
        $now = 15099;
        renewalCheck($renewal->tick()['status'] === 'current' && $calls === 1, 'A not-due token was renewed.');
        renewalCheck(file_get_contents($directory . '/zendesk-oauth.json') === $initial, 'A current bundle was rewritten.');
        $now = 15100;
        renewalCheck($renewal->tick()['status'] === 'renewed' && $calls === 2, 'A due token was not renewed once.');
        renewalCheck($renewal->tick()['status'] === 'current' && $calls === 2, 'A replacement was issued twice.');
    });
};
$tests['Token expiry begins at request start and latency cannot extend validity'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $now = 1000;
        $issuer = new ZendeskTokenIssuer(static function () use (&$now): array {
            $now += 47;
            return renewalResponse();
        });
        $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static function () use (&$now): int {
            return $now;
        });
        renewalCheck($renewal->tick()['expires_at'] === 15400, 'Network latency extended the credential lifetime.');
    });
};
$tests['Short vendor lifetimes retain a positive renewal margin and reject expiry exactly'] = static function (): void {
    $client = new OAuthClientCredentials('example', 'synthetic-client', 'synthetic-secret');
    $bundle = OAuthTokenBundle::fromResponse(renewalResponse(['expires_in' => 300]), $client, 1000);
    renewalCheck($bundle->renewAt === 1270 && $bundle->expiresAt === 1300, 'Short-token scheduling differs.');
    renewalCheck(!$bundle->usableAt(999) && $bundle->usableAt(1299) && !$bundle->usableAt(1300), 'Time bounds differ.');
};
$tests['Invalid or overbroad responses preserve the previous usable credential'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $client = new OAuthClientCredentials('example', 'synthetic-client', 'synthetic-secret');
        $old = OAuthTokenBundle::fromResponse(renewalResponse(), $client, 1000)->toJson();
        $files = new PrivateCredentialDirectory($directory);
        $files->withLock(static fn() => $files->replace('zendesk-oauth.json', $old));
        foreach (
            [
            ['scope' => 'read write'], ['scope' => 'tickets:read tickets:write users:read'],
            ['scope' => 'tickets:read'], ['token_type' => 'basic'], ['access_token' => "secret-marker\r\n"],
            ['access_token' => null], ['expires_in' => 299], ['expires_in' => 14401], ['expires_in' => '14400'],
            ] as $changes
        ) {
            $issuer = new ZendeskTokenIssuer(static fn() => renewalResponse($changes));
            $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 15100);
            $error = renewalReject($renewal->tick(...));
            renewalCheck(!str_contains($error->getMessage(), 'secret-marker'), 'A grant error disclosed credentials.');
            renewalCheck(file_get_contents($directory . '/zendesk-oauth.json') === $old, 'A bad grant replaced a token.');
            $renewal->recordFailure();
            renewalCheck($renewal->healthy(), 'A recent failed renewal invalidated a still-usable token.');
        }
    });
};
$tests['Transport failure retains the token and cannot fabricate post-expiry health'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $client = new OAuthClientCredentials('example', 'synthetic-client', 'synthetic-secret');
        $old = OAuthTokenBundle::fromResponse(renewalResponse(), $client, 1000)->toJson();
        $files = new PrivateCredentialDirectory($directory);
        $files->withLock(static fn() => $files->replace('zendesk-oauth.json', $old));
        $issuer = new ZendeskTokenIssuer(static function (): array {
            throw new RuntimeException('synthetic-secret private-failure-marker');
        });
        $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 15400);
        $error = renewalReject($renewal->tick(...));
        renewalCheck(!str_contains($error->getMessage(), 'private-failure-marker'), 'Transport error exposed input.');
        $renewal->recordFailure();
        renewalCheck(!$renewal->healthy(), 'An expired token was reported healthy.');
        renewalCheck(file_get_contents($directory . '/zendesk-oauth.json') === $old, 'A failure destroyed prior evidence.');
    });
};
$tests['Existing tenant or client mismatches fail before token transport'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $calls = 0;
        $issuer = new ZendeskTokenIssuer(static function () use (&$calls): array {
            ++$calls;
            return renewalResponse();
        });
        $files = new PrivateCredentialDirectory($directory);
        foreach ([['other', 'synthetic-client'], ['example', 'other-client']] as [$tenant, $clientId]) {
            $bundle = OAuthTokenBundle::fromResponse(renewalResponse(), new OAuthClientCredentials(
                $tenant,
                $clientId,
                'synthetic-secret'
            ), 1000)->toJson();
            $files->withLock(static fn() => $files->replace('zendesk-oauth.json', $bundle));
            renewalReject((new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 15100))->tick(...));
            renewalCheck(file_get_contents($directory . '/zendesk-oauth.json') === $bundle, 'Identity evidence was overwritten.');
        }
        renewalCheck($calls === 0, 'Identity mismatch reached the token endpoint.');
    });
};
$tests['The shared credential lock prevents concurrent issuance and publication'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $calls = 0;
        $issuer = new ZendeskTokenIssuer(static function () use (&$calls): array {
            ++$calls;
            return renewalResponse();
        });
        $lock = fopen($directory . '/.import.lock', 'c');
        chmod($directory . '/.import.lock', 0600);
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            renewalReject((new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 1000))->tick(...));
            renewalCheck($calls === 0 && !file_exists($directory . '/zendesk-oauth.json'), 'A busy writer lock was bypassed.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    });
};
$tests['Atomic replacement leaves existing readers complete and no temporary secret files'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $client = new OAuthClientCredentials('example', 'synthetic-client', 'synthetic-secret');
        $old = OAuthTokenBundle::fromResponse(renewalResponse(), $client, 1000)->toJson();
        $files = new PrivateCredentialDirectory($directory);
        $files->withLock(static fn() => $files->replace('zendesk-oauth.json', $old));
        $reader = fopen($directory . '/zendesk-oauth.json', 'rb');
        try {
            $issuer = new ZendeskTokenIssuer(static fn() => renewalResponse(['access_token' => 'replacement-token']));
            (new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer, static fn() => 15100))->tick();
            renewalCheck(stream_get_contents($reader) === $old, 'An already-open reader saw partial publication.');
            $new = OAuthTokenBundle::fromJson(file_get_contents($directory . '/zendesk-oauth.json'), 'example');
            renewalCheck($new->token() === 'replacement-token', 'A new reader did not see the complete replacement.');
            renewalCheck(glob($directory . '/.oauth-*') === [], 'Temporary credentials remain.');
        } finally {
            fclose($reader);
        }
    });
};
$tests['Health requires recent loop progress bound to the current usable bundle'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $now = 1000;
        $renewal = new ZendeskTokenRenewal('example', $clientFile, $directory, new ZendeskTokenIssuer(
            static fn() => renewalResponse()
        ), static function () use (&$now): int {
            return $now;
        });
        $renewal->tick();
        $now = 1091;
        renewalCheck(!$renewal->healthy(), 'A stalled renewal loop remained healthy.');
        $renewal->tick();
        renewalCheck($renewal->healthy(), 'A current loop heartbeat did not restore health.');
        $files = new PrivateCredentialDirectory($directory);
        $files->withLock(static fn() => $files->replace('zendesk-oauth-heartbeat.json', json_encode([
            'version' => 1, 'checked_at' => 1092, 'bundle_sha256' => str_repeat('a', 64),
        ])));
        renewalCheck(!$renewal->healthy(), 'A future or unrelated heartbeat was accepted.');
    });
};
$tests['Unsafe credential and output paths are rejected before transport'] = static function (): void {
    renewalWithFiles(static function ($root, $clientFile, $directory): void {
        $calls = 0;
        $issuer = new ZendeskTokenIssuer(static function () use (&$calls): array {
            ++$calls;
            return renewalResponse();
        });
        symlink($clientFile, $root . '/client-link');
        renewalReject((new ZendeskTokenRenewal('example', $root . '/client-link', $directory, $issuer))->tick(...));
        chmod($clientFile, 0644);
        renewalReject((new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer))->tick(...));
        chmod($clientFile, 0400);
        symlink($clientFile, $directory . '/zendesk-oauth.json');
        renewalReject((new ZendeskTokenRenewal('example', $clientFile, $directory, $issuer))->tick(...));
        renewalCheck($calls === 0, 'An unsafe filesystem path reached transport.');
    });
};
$tests['Bundle timing, unknown fields, permissions and client identity fail closed'] = static function (): void {
    $bundle = OAuthTokenBundle::fromResponse(renewalResponse(), new OAuthClientCredentials(
        'example',
        'synthetic-client',
        'synthetic-secret'
    ), 1000);
    foreach (
        [
        ['scope' => 'read write'], ['version' => 2], ['renew_at' => 15400], ['issued_at' => 0],
        ['expires_at' => 1001], ['expires_at' => 15401], ['client_id_hash' => 'invalid'], ['extra' => true],
        ] as $changes
    ) {
        renewalReject(static fn() => OAuthTokenBundle::fromJson(json_encode(array_replace(
            json_decode($bundle->toJson(), true),
            $changes
        )), 'example'));
    }
    renewalReject(static fn() => OAuthTokenBundle::fromJson($bundle->toJson(), 'other'));
    renewalReject(static fn() => OAuthClientCredentials::fromJson('{"subdomain":"other"}', 'example'));
};
$tests['OAuth client import is guarded, exact-field, private and idempotent'] = static function (): void {
    renewalWithFiles(static function ($root): void {
        $directory = $root . '/new-issuer';
        $importer = new OAuthClientCredentialImporter($directory, 'example');
        $values = ['subdomain' => 'example', 'client_id' => 'new-client', 'client_secret' => 'new-secret'];
        $input = renewalInput($values);
        try {
            renewalCheck($importer->run($input)['changed'] === 1, 'First issuer import did not publish.');
            rewind($input);
            renewalCheck($importer->run($input)['changed'] === 0, 'Identical issuer import rewrote credentials.');
            renewalCheck((fileperms($directory) & 0777) === 0700, 'Issuer directory is not private.');
            renewalCheck((fileperms($directory . '/client.json') & 0777) === 0400, 'Issuer file is not private.');
            $before = file_get_contents($directory . '/client.json');
            foreach ([['scope' => 'read write'], ['subdomain' => 'other'], ['client_secret' => 12]] as $change) {
                $invalid = renewalInput(array_replace($values, $change));
                try {
                    renewalReject(static fn() => $importer->run($invalid));
                } finally {
                    fclose($invalid);
                }
            }
            renewalCheck(file_get_contents($directory . '/client.json') === $before, 'Rejected issuer input changed credentials.');
            putenv('BRIDGE_LIVE_DEMO_ENABLED=0');
            rewind($input);
            renewalReject(static fn() => $importer->run($input));
            putenv('BRIDGE_LIVE_DEMO_ENABLED=1');
        } finally {
            fclose($input);
        }
    });
};

$environment = ['BRIDGE_MODE' => getenv('BRIDGE_MODE'), 'BRIDGE_LIVE_DEMO_ENABLED' => getenv('BRIDGE_LIVE_DEMO_ENABLED')];
putenv('BRIDGE_MODE=live');
putenv('BRIDGE_LIVE_DEMO_ENABLED=1');
$failed = 0;
try {
    foreach ($tests as $name => $test) {
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
echo count($tests) . ' OAuth renewal checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
