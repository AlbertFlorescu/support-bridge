<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\OAuth;

use Bridge\Provider\Identity;
use Closure;
use RuntimeException;
use Throwable;

final readonly class ZendeskTokenRenewal
{
    public const BUNDLE = 'zendesk-oauth.json';
    public const HEARTBEAT = 'zendesk-oauth-heartbeat.json';
    private PrivateCredentialDirectory $files;
    private ZendeskTokenIssuer $issuer;
    private Closure $clock;
    private string $subdomain;

    public function __construct(
        string $subdomain,
        private string $clientFile,
        string $directory,
        ?ZendeskTokenIssuer $issuer = null,
        ?callable $clock = null
    ) {
        $this->subdomain = Identity::subdomain($subdomain);
        $this->files = new PrivateCredentialDirectory($directory);
        $this->issuer = $issuer ?? new ZendeskTokenIssuer();
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    /** @return array{status:string,expires_at:int,renew_at:int,next_check_in_seconds:int} */
    public function tick(): array
    {
        $client = $this->client();
        return $this->files->withLock(function () use ($client): array {
            $now = $this->now();
            $bundle = $this->bundle($client);
            $status = 'current';
            if ($bundle === null || !$bundle->usableAt($now) || $now >= $bundle->renewAt) {
                // Start the lifetime before network latency; never extend a token by the response duration.
                $bundle = $this->issuer->issue($client, $now);
                if (!$bundle->usableAt($this->now())) {
                    throw new RuntimeException('The issued OAuth credential is already unusable.');
                }
                $this->files->replace(self::BUNDLE, $bundle->toJson());
                $status = 'renewed';
            }
            $this->heartbeat($bundle, $status);
            return [
                'status' => $status,
                'expires_at' => $bundle->expiresAt,
                'renew_at' => $bundle->renewAt,
                'next_check_in_seconds' => min(30, max(1, $bundle->renewAt - $this->now())),
            ];
        });
    }

    /** Records loop progress without fabricating a replacement credential after failure. */
    public function recordFailure(): void
    {
        $client = $this->client();
        $this->files->withLock(function () use ($client): void {
            $bundle = $this->bundle($client);
            if ($bundle !== null) {
                $this->heartbeat($bundle, 'renewal_failed');
            }
        });
    }

    public function healthy(): bool
    {
        try {
            $bundle = $this->bundle($this->client());
            $heartbeat = $this->files->read(self::HEARTBEAT);
            $now = $this->now();
            if ($bundle === null || !$bundle->usableAt($now) || $heartbeat === null) {
                return false;
            }
            $state = json_decode($heartbeat, true, 8, JSON_THROW_ON_ERROR);
            return is_array($state) && ($state['version'] ?? null) === 1
                && is_int($state['checked_at'] ?? null) && $state['checked_at'] <= $now
                && $state['checked_at'] >= $now - 90
                && is_string($state['bundle_sha256'] ?? null)
                && hash_equals(hash('sha256', $bundle->toJson()), $state['bundle_sha256']);
        } catch (Throwable) {
            return false;
        }
    }

    private function client(): OAuthClientCredentials
    {
        return OAuthClientCredentials::fromJson(
            PrivateCredentialDirectory::readFile($this->clientFile),
            $this->subdomain
        );
    }

    private function bundle(OAuthClientCredentials $client): ?OAuthTokenBundle
    {
        $json = $this->files->read(self::BUNDLE);
        return $json === null ? null : OAuthTokenBundle::fromJson($json, $this->subdomain, $client->clientIdHash());
    }

    private function heartbeat(OAuthTokenBundle $bundle, string $status): void
    {
        $this->files->replace(self::HEARTBEAT, json_encode([
            'version' => 1,
            'checked_at' => $this->now(),
            'status' => $status,
            'bundle_sha256' => hash('sha256', $bundle->toJson()),
        ], JSON_THROW_ON_ERROR));
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new RuntimeException('The OAuth renewal clock is invalid.');
        }
        return $now;
    }
}
