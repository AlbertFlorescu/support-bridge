<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Infrastructure\OAuth\OAuthClientCredentials;
use Bridge\Infrastructure\OAuth\PrivateCredentialDirectory;
use Bridge\Provider\Identity;
use RuntimeException;

final readonly class OAuthClientCredentialImporter
{
    private string $subdomain;

    public function __construct(private string $directory, string $subdomain)
    {
        $this->subdomain = Identity::subdomain($subdomain);
    }

    /** @param resource $input @return array{status:string,files:int,changed:int} */
    public function run($input): array
    {
        if (getenv('BRIDGE_MODE') !== 'live' || getenv('BRIDGE_LIVE_DEMO_ENABLED') !== '1') {
            throw new RuntimeException('OAuth client import requires explicit live deployment enablement.');
        }
        if (!is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new RuntimeException('OAuth client input must be a stream.');
        }
        $json = stream_get_contents($input, 16385);
        if ($json === false || strlen($json) > 16384) {
            throw new RuntimeException('OAuth client input exceeds its bounds.');
        }
        $client = OAuthClientCredentials::fromJson($json, $this->subdomain);
        $files = new PrivateCredentialDirectory($this->directory);
        return $files->withLock(static function () use ($client, $files): array {
            $existing = $files->read('client.json');
            $value = $client->toJson();
            $changed = $existing === null || !hash_equals($existing, $value);
            if ($changed) {
                $files->replace('client.json', $value);
            }
            return ['status' => 'imported', 'files' => 1, 'changed' => $changed ? 1 : 0];
        });
    }
}
