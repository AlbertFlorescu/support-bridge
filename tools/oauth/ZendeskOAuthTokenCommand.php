<?php

declare(strict_types=1);

namespace Bridge\Tooling\OAuth;

use Bridge\Provider\Identity;
use Bridge\Provider\ZendeskAuthentication;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Manually issues a bounded, ticket-only token; never performs runtime refresh. */
final readonly class ZendeskOAuthTokenCommand
{
    private const SCOPE = 'tickets:read tickets:write';
    private const LIFETIME = 14400;
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

        $response = $this->request('https://' . $subdomain . '.zendesk.com/oauth/tokens', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => self::SCOPE,
            'expires_in' => self::LIFETIME,
        ]);
        $token = $response['access_token'] ?? null;
        $type = $response['token_type'] ?? null;
        $scope = $response['scope'] ?? null;
        $lifetime = $response['expires_in'] ?? null;
        $scopes = is_string($scope) ? explode(' ', trim($scope)) : [];
        sort($scopes);
        if (
            !is_string($token) || !is_string($type) || strtolower($type) !== 'bearer'
            || $scopes !== ['tickets:read', 'tickets:write']
            || !is_int($lifetime) || $lifetime < 300 || $lifetime > self::LIFETIME
        ) {
            throw new RuntimeException('The token response did not match the requested scope and expiry.');
        }
        try {
            new ZendeskAuthentication('', $token, 'oauth');
        } catch (InvalidArgumentException) {
            throw new RuntimeException('The token response contained an invalid access credential.');
        }
        $this->saveToken($output, $token);

        return [
            'status' => 'saved',
            'scope' => self::SCOPE,
            'expires_in' => $lifetime,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $lifetime),
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

    /** @param array<string,int|string> $payload @return array<string,mixed> */
    private function request(string $url, #[SensitiveParameter] array $payload): array
    {
        try {
            if ($this->transport !== null) {
                return ($this->transport)($url, $payload);
            }
            return $this->curlRequest($url, $payload);
        } catch (Throwable) {
            throw new RuntimeException('Token issuance failed; no access credential was saved.');
        }
    }

    /** @param array<string,int|string> $payload @return array<string,mixed> */
    private function curlRequest(string $url, #[SensitiveParameter] array $payload): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTPS initialization failed.');
        }
        $body = '';
        $headerBytes = 0;
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes): int {
                    $headerBytes += strlen($line);
                    return $headerBytes > 16384 ? 0 : strlen($line);
                },
            ]);
            if (
                curl_exec($handle) === false
                || !in_array(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), [200, 201], true)
            ) {
                throw new RuntimeException('HTTPS token request failed.');
            }
        } finally {
            curl_close($handle);
        }
        $response = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('The token response is invalid.');
        }
        return $response;
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
