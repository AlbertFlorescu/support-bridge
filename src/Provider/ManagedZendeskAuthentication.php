<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Infrastructure\OAuth\OAuthTokenBundle;
use Bridge\Infrastructure\OAuth\PrivateCredentialDirectory;
use Bridge\RemoteFailure;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Reads a complete, atomically published OAuth credential before each provider request. */
final readonly class ManagedZendeskAuthentication implements ZendeskAuthorization
{
    private const MAX_BYTES = 16384;
    private string $subdomain;
    private Closure $clock;

    public function __construct(private string $path, string $subdomain, ?callable $clock = null)
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('A managed Zendesk credential file is required.');
        }
        $this->subdomain = Identity::subdomain($subdomain);
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    public function authorizationHeader(): string
    {
        $json = $this->read();
        try {
            $bundle = OAuthTokenBundle::fromJson($json, $this->subdomain);
        } catch (RuntimeException | InvalidArgumentException) {
            throw new RemoteFailure('The managed Zendesk credential is invalid.');
        }
        $now = ($this->clock)();
        if (!is_int($now) || !$bundle->usableAt($now)) {
            // No request has been sent. The normal business retry policy may wait for renewal.
            throw new RemoteFailure('The managed Zendesk credential is unavailable or expired.', true, 30);
        }
        return 'Bearer ' . $bundle->token();
    }

    private function read(): string
    {
        clearstatcache(true, $this->path);
        $before = @lstat($this->path);
        if ($before === false) {
            throw new RemoteFailure('The managed Zendesk credential is unavailable or expired.', true, 30);
        }
        if (!$this->ordinaryPrivateFile($before) || is_link($this->path)) {
            throw new RemoteFailure('The managed Zendesk credential must be a private ordinary file.');
        }
        if ($before['size'] > self::MAX_BYTES) {
            throw new RemoteFailure('The managed Zendesk credential exceeds its size limit.');
        }
        try {
            return PrivateCredentialDirectory::readFile($this->path);
        } catch (RuntimeException) {
            throw new RemoteFailure('The managed Zendesk credential could not be read.', true, 30);
        }
    }

    /** @param array<string|int,int> $stat */
    private function ordinaryPrivateFile(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 0077) === 0
            && $stat['nlink'] === 1;
    }
}
