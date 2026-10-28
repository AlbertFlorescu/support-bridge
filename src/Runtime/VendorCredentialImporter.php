<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use JsonException;
use RuntimeException;
use SensitiveParameter;
use stdClass;

/** Explicit deployment import; no provider, business store or operator authority. */
final readonly class VendorCredentialImporter
{
    private const NAMES = ['github-token', 'zendesk-token'];
    private const MAX_INPUT_BYTES = 16384;

    public function __construct(private string $directory = '/var/lib/support-bridge')
    {
    }

    /** @param resource $input @return array{status:string,files:int,changed:int} */
    public function run($input): array
    {
        if (getenv('BRIDGE_MODE') !== 'live' || getenv('BRIDGE_LIVE_DEMO_ENABLED') !== '1') {
            throw new RuntimeException('Vendor credential import requires explicit live demo enablement.');
        }
        if (!is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new RuntimeException('Vendor credential input must be a stream.');
        }
        $body = stream_get_contents($input, self::MAX_INPUT_BYTES + 1);
        if ($body === false || strlen($body) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Vendor credential input exceeds its limit.');
        }
        $values = $this->values($body);
        $this->preflightDirectory();
        $this->existingValues();
        $this->preflightFile($this->directory . '/.import.lock', false);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700)) {
            throw new RuntimeException('The private credential directory could not be created.');
        }
        if (!@chmod($this->directory, 0700)) {
            throw new RuntimeException('The private credential directory could not be protected.');
        }

        $lock = $this->lock();
        try {
            // Concurrent importers must recheck both files after acquiring their deployment lock.
            $existing = $this->existingValues();
            $changed = 0;
            foreach (self::NAMES as $name) {
                $path = $this->directory . '/' . $name;
                if (isset($existing[$name]) && hash_equals($existing[$name], $values[$name])) {
                    if (!@chmod($path, 0400)) {
                        throw new RuntimeException('An existing credential could not be protected.');
                    }
                    continue;
                }
                $this->replace($path, $values[$name]);
                ++$changed;
            }
            return ['status' => 'imported', 'files' => count(self::NAMES), 'changed' => $changed];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,string> */
    private function values(#[SensitiveParameter] string $body): array
    {
        try {
            $decoded = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Vendor credential input must be a JSON object.');
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('Vendor credential input must be a JSON object.');
        }
        $values = get_object_vars($decoded);
        $names = array_keys($values);
        sort($names);
        if ($names !== self::NAMES) {
            throw new RuntimeException('Supply only the two documented vendor access tokens.');
        }
        foreach ($values as $value) {
            $this->validateValue($value);
        }
        return $values;
    }

    private function validateValue(#[SensitiveParameter] mixed $value): void
    {
        if (
            !is_string($value) || $value === '' || strlen($value) > 4096
            || preg_match('/[^\x21-\x7e]/', $value) !== 0
        ) {
            throw new RuntimeException('A vendor access token has an invalid format.');
        }
    }

    private function preflightDirectory(): void
    {
        clearstatcache(true, $this->directory);
        if (
            $this->directory === '' || str_contains($this->directory, "\0")
            || is_link($this->directory)
            || (file_exists($this->directory) && !is_dir($this->directory))
        ) {
            throw new RuntimeException('The credential directory must be an ordinary directory.');
        }
        if (is_dir($this->directory) && !is_writable($this->directory)) {
            throw new RuntimeException('The credential directory must be writable by its importer.');
        }
    }

    /** @return array<string,string> */
    private function existingValues(): array
    {
        $values = [];
        foreach (self::NAMES as $name) {
            $path = $this->directory . '/' . $name;
            $this->preflightFile($path);
            if (!file_exists($path)) {
                continue;
            }
            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('An existing vendor credential could not be read.');
            }
            try {
                $bytes = stream_get_contents($stream, 4098);
            } finally {
                fclose($stream);
            }
            if ($bytes === false || strlen($bytes) > 4097) {
                throw new RuntimeException('An existing vendor credential exceeds its limit.');
            }
            $value = trim($bytes);
            $this->validateValue($value);
            $values[$name] = $value;
        }
        return $values;
    }

    private function preflightFile(string $path, bool $readable = true): void
    {
        clearstatcache(true, $path);
        if (
            is_link($path) || (file_exists($path) && (
                !is_file($path) || ($readable && !is_readable($path)) || (stat($path)['nlink'] ?? 0) !== 1
            ))
        ) {
            throw new RuntimeException('Credential paths must be ordinary private files.');
        }
    }

    /** @return resource */
    private function lock()
    {
        $path = $this->directory . '/.import.lock';
        $this->preflightFile($path, false);
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'c');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new RuntimeException('The credential import lock could not be opened.');
        }
        if (!@chmod($path, 0600) || !flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);
            throw new RuntimeException('The credential import is unavailable or already running.');
        }
        return $stream;
    }

    private function replace(string $path, #[SensitiveParameter] string $value): void
    {
        $temporary = $this->directory . '/.vendor-secret-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try {
            $stream = @fopen($temporary, 'x');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new RuntimeException('A private credential temporary file could not be created.');
        }
        try {
            if (
                fwrite($stream, $value) !== strlen($value) || !fflush($stream) || !fsync($stream)
                || !@chmod($temporary, 0400)
            ) {
                throw new RuntimeException('A vendor credential could not be persisted.');
            }
            fclose($stream);
            $stream = null;
            $this->preflightFile($path);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('A vendor credential could not be replaced.');
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
