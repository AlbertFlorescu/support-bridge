<?php

declare(strict_types=1);

namespace Bridge\Tooling\OAuth;

use Bridge\Infrastructure\OAuth\OAuthClientCredentials;
use Bridge\Infrastructure\OAuth\OAuthTokenBundle;
use Bridge\Infrastructure\OAuth\ZendeskTokenIssuer;
use Bridge\Provider\Identity;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/** Explicit manual command over the same narrow issuer used by the renewal service. */
final readonly class ZendeskOAuthTokenCommand
{
    private ?Closure $transport;

    /** @param null|callable(string,array):array $transport Offline testing boundary. */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    /** @param list<string> $arguments @return array<string,int|string> */
    public function run(array $arguments): array
    {
        $options = $this->options($arguments);
        $subdomain = Identity::subdomain($options['subdomain']);
        $clientId = $this->readCredential($options['client-id-file']);
        $clientSecret = $this->readCredential($options['client-secret-file']);
        $output = $this->outputPath($options['output']);
        foreach (['client-id-file', 'client-secret-file'] as $input) {
            if (realpath($options[$input]) === $output) {
                throw new InvalidArgumentException('The token output must differ from the client credential files.');
            }
        }

        $bundle = (new ZendeskTokenIssuer($this->transport))->issue(
            new OAuthClientCredentials($subdomain, $clientId, $clientSecret),
            time()
        );
        $this->saveToken($output, $bundle->token());

        return [
            'status' => 'saved',
            'scope' => OAuthTokenBundle::SCOPE,
            'expires_in' => $bundle->expiresAt - $bundle->issuedAt,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $bundle->expiresAt),
        ];
    }

    /** @param list<string> $arguments @return array<string,string> */
    private function options(array $arguments): array
    {
        $required = ['subdomain', 'client-id-file', 'client-secret-file', 'output'];
        $options = [];
        foreach ($arguments as $argument) {
            if (!preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $match)) {
                throw new InvalidArgumentException('Use the documented named file arguments.');
            }
            if (!in_array($match[1], $required, true) || isset($options[$match[1]])) {
                throw new InvalidArgumentException('An argument is unknown or repeated.');
            }
            $options[$match[1]] = $match[2];
        }
        if (count($options) !== count($required)) {
            throw new InvalidArgumentException('All four named arguments are required.');
        }
        return $options;
    }

    private function readCredential(string $path): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || !is_readable($path) || !$this->privateMode($path)) {
            throw new InvalidArgumentException('Client credentials must be private readable ordinary files.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('A client credential file could not be read.');
        }
        try {
            $bytes = stream_get_contents($stream, 4097);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) > 4096) {
            throw new InvalidArgumentException('A client credential file exceeds the size limit.');
        }
        $credential = trim($bytes);
        if ($credential === '' || preg_match('/[\p{C}\s]/u', $credential) !== 0) {
            throw new InvalidArgumentException('A client credential file contains an invalid value.');
        }
        return $credential;
    }

    private function outputPath(string $path): string
    {
        if (str_contains($path, "\0") || in_array(basename($path), ['', '.', '..'], true)) {
            throw new InvalidArgumentException('The token output path is invalid.');
        }
        $directory = realpath(dirname($path));
        if (
            $directory === false || !is_dir($directory)
            || !is_writable($directory) || !$this->privateMode($directory)
        ) {
            throw new InvalidArgumentException('The token output needs an existing private writable directory.');
        }
        $output = $directory . DIRECTORY_SEPARATOR . basename($path);
        clearstatcache(true, $output);
        if (is_link($output) || (file_exists($output) && (!is_file($output) || !$this->privateMode($output)))) {
            throw new InvalidArgumentException('An existing token output must be a private ordinary file.');
        }
        return $output;
    }

    private function privateMode(string $path): bool
    {
        $mode = @fileperms($path);
        return $mode !== false && ($mode & 0077) === 0;
    }

    private function saveToken(string $output, #[SensitiveParameter] string $token): void
    {
        $temporary = dirname($output) . DIRECTORY_SEPARATOR . '.zendesk-token-' . bin2hex(random_bytes(16));
        $previousMask = umask(0077);
        try {
            $stream = @fopen($temporary, 'x');
        } finally {
            umask($previousMask);
        }
        if ($stream === false) {
            throw new RuntimeException('The private token output could not be created.');
        }
        try {
            $bytes = $token . "\n";
            if (
                !@chmod($temporary, 0600) || !$this->privateMode($temporary)
                || @fwrite($stream, $bytes) !== strlen($bytes) || !@fflush($stream) || !@fsync($stream)
            ) {
                throw new RuntimeException('The private token output could not be persisted.');
            }
            fclose($stream);
            $stream = null;
            $this->outputPath($output);
            if (!@rename($temporary, $output)) {
                throw new RuntimeException('The private token output could not be replaced.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
