<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\OAuth;

use Bridge\Provider\Identity;
use Bridge\Provider\ZendeskAuthentication;
use RuntimeException;
use SensitiveParameter;

final readonly class OAuthTokenBundle
{
    public const SCOPE = 'tickets:read tickets:write';
    public const LIFETIME = 14400;

    private function __construct(
        public string $subdomain,
        public string $clientIdHash,
        #[SensitiveParameter] private string $accessToken,
        public int $issuedAt,
        public int $expiresAt,
        public int $renewAt
    ) {
    }

    public static function fromResponse(
        #[SensitiveParameter] array $response,
        OAuthClientCredentials $client,
        int $startedAt
    ): self {
        $token = $response['access_token'] ?? null;
        $type = $response['token_type'] ?? null;
        $scope = $response['scope'] ?? null;
        $lifetime = $response['expires_in'] ?? null;
        $scopes = is_string($scope) ? explode(' ', trim($scope)) : [];
        sort($scopes);
        if (
            !is_string($token) || !is_string($type) || strtolower($type) !== 'bearer'
            || $scopes !== ['tickets:read', 'tickets:write']
            || !is_int($lifetime) || $lifetime < 300 || $lifetime > self::LIFETIME || $startedAt < 1
        ) {
            throw new RuntimeException('The OAuth response did not match the requested scope and lifetime.');
        }
        self::validateToken($token);
        return new self(
            $client->subdomain,
            $client->clientIdHash(),
            $token,
            $startedAt,
            $startedAt + $lifetime,
            $startedAt + $lifetime - min(300, intdiv($lifetime, 10))
        );
    }

    public static function fromJson(
        #[SensitiveParameter] string $json,
        string $expectedSubdomain,
        ?string $expectedClientIdHash = null
    ): self {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The managed OAuth credential is invalid.');
        }
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        if (
            $keys !== [
            'access_token', 'client_id_hash', 'expires_at', 'issued_at', 'renew_at', 'scope', 'subdomain', 'version',
            ]
        ) {
            throw new RuntimeException('The managed OAuth credential fields are invalid.');
        }
        if (!is_string($value['subdomain'])) {
            throw new RuntimeException('The managed OAuth credential identity is invalid.');
        }
        try {
            $subdomain = Identity::subdomain($value['subdomain']);
            $expectedSubdomain = Identity::subdomain($expectedSubdomain);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('The managed OAuth credential identity is invalid.');
        }
        if (
            $value['version'] !== 1 || $subdomain !== $expectedSubdomain
            || $value['scope'] !== self::SCOPE
            || !is_string($value['client_id_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $value['client_id_hash'])
            || ($expectedClientIdHash !== null && !hash_equals($expectedClientIdHash, $value['client_id_hash']))
            || !is_string($value['access_token'])
            || !is_int($value['issued_at']) || !is_int($value['expires_at']) || !is_int($value['renew_at'])
        ) {
            throw new RuntimeException('The managed OAuth credential identity or format is invalid.');
        }
        $lifetime = $value['expires_at'] - $value['issued_at'];
        if (
            $value['issued_at'] < 1 || $lifetime < 300 || $lifetime > self::LIFETIME
            || $value['renew_at'] !== $value['expires_at'] - min(300, intdiv($lifetime, 10))
        ) {
            throw new RuntimeException('The managed OAuth credential timing is invalid.');
        }
        self::validateToken($value['access_token']);
        return new self(
            $subdomain,
            $value['client_id_hash'],
            $value['access_token'],
            $value['issued_at'],
            $value['expires_at'],
            $value['renew_at']
        );
    }

    public function token(): string
    {
        return $this->accessToken;
    }

    public function usableAt(int $now): bool
    {
        return $this->issuedAt <= $now && $now < $this->expiresAt;
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => 1,
            'subdomain' => $this->subdomain,
            'client_id_hash' => $this->clientIdHash,
            'scope' => self::SCOPE,
            'access_token' => $this->accessToken,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'renew_at' => $this->renewAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function validateToken(#[SensitiveParameter] string $token): void
    {
        if ($token === '' || strlen($token) > 4096 || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new RuntimeException('The managed OAuth access credential is invalid.');
        }
        try {
            new ZendeskAuthentication('', $token, 'oauth');
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('The managed OAuth access credential is invalid.');
        }
    }
}
