<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\OAuth;

use Bridge\Provider\Identity;
use RuntimeException;
use SensitiveParameter;

final readonly class OAuthClientCredentials
{
    public string $subdomain;

    public function __construct(
        string $subdomain,
        #[SensitiveParameter] private string $clientId,
        #[SensitiveParameter] private string $clientSecret
    ) {
        try {
            $this->subdomain = Identity::subdomain($subdomain);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('The OAuth client identity is invalid.');
        }
        foreach ([$clientId, $clientSecret] as $value) {
            if ($value === '' || strlen($value) > 4096 || preg_match('/[^\x21-\x7e]/', $value)) {
                throw new RuntimeException('The OAuth client credential format is invalid.');
            }
        }
    }

    public static function fromJson(#[SensitiveParameter] string $json, string $expectedSubdomain): self
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The OAuth client file is invalid.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('The OAuth client file is invalid.');
        }
        $keys = array_keys($decoded);
        sort($keys);
        if ($keys !== ['client_id', 'client_secret', 'subdomain']) {
            throw new RuntimeException('Supply only the documented OAuth client fields.');
        }
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('The OAuth client fields must be strings.');
            }
        }
        $client = new self($decoded['subdomain'], $decoded['client_id'], $decoded['client_secret']);
        try {
            $expectedSubdomain = Identity::subdomain($expectedSubdomain);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('The expected OAuth client identity is invalid.');
        }
        if (!hash_equals($expectedSubdomain, $client->subdomain)) {
            throw new RuntimeException('The OAuth client belongs to another deployment identity.');
        }
        return $client;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function clientIdHash(): string
    {
        return hash('sha256', $this->clientId);
    }

    public function toJson(): string
    {
        return json_encode([
            'subdomain' => $this->subdomain,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
