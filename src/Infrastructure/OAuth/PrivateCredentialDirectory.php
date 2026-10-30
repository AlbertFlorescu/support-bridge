<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\OAuth;

use RuntimeException;
use SensitiveParameter;

/** Private deployment files only; this lock is independent of business reconciliation. */
final readonly class PrivateCredentialDirectory
{
    public function __construct(private string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new RuntimeException('The private credential directory is invalid.');
        }
    }

    public function withLock(callable $operation): mixed
    {
        $this->prepare();
        $path = $this->path('.import.lock');
        self::preflightFile($path);
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'c');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new RuntimeException('The private credential lock is unavailable.');
        }
        try {
            if (!@chmod($path, 0600) || !flock($stream, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The private credential lock is unavailable or busy.');
            }
            return $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    public function read(string $name): ?string
    {
        $path = $this->path($name);
        clearstatcache(true, $path);
        self::preflightFile($path);
        return file_exists($path) ? self::readFile($path) : null;
    }

    public function replace(string $name, #[SensitiveParameter] string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > 16384) {
            throw new RuntimeException('The private credential content exceeds its bounds.');
        }
        $this->prepare();
        $path = $this->path($name);
        self::preflightFile($path);
        $temporary = $this->directory . '/.oauth-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try {
            $stream = @fopen($temporary, 'x');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new RuntimeException('The private credential output is unavailable.');
        }
        try {
            if (
                fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)
                || !@chmod($temporary, 0400)
            ) {
                throw new RuntimeException('The private credential could not be persisted.');
            }
            fclose($stream);
            $stream = null;
            self::preflightFile($path);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('The private credential could not be published.');
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

    public static function readFile(string $path): string
    {
        clearstatcache(true, $path);
        self::preflightFile($path);
        $before = @stat($path);
        if (
            $before === false || !is_readable($path) || ($before['mode'] & 0077) !== 0
            || $before['size'] > 16384
        ) {
            throw new RuntimeException('The private credential file is unavailable or unprotected.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The private credential file is unavailable.');
        }
        try {
            $opened = fstat($stream);
            if (
                $opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
                || ($opened['mode'] & 0077) !== 0 || $opened['nlink'] !== 1
            ) {
                throw new RuntimeException('The private credential file changed during observation.');
            }
            $bytes = stream_get_contents($stream, 16385);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) > 16384) {
            throw new RuntimeException('The private credential file exceeds its bounds.');
        }
        return $bytes;
    }

    private function prepare(): void
    {
        clearstatcache(true, $this->directory);
        if (is_link($this->directory) || (file_exists($this->directory) && !is_dir($this->directory))) {
            throw new RuntimeException('The private credential directory must be ordinary.');
        }
        if ((!is_dir($this->directory) && !@mkdir($this->directory, 0700)) || !@chmod($this->directory, 0700)) {
            throw new RuntimeException('The private credential directory is unavailable.');
        }
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9.][a-zA-Z0-9_.-]{0,63}$/D', $name) || in_array($name, ['.', '..'], true)) {
            throw new RuntimeException('The private credential filename is invalid.');
        }
        return $this->directory . '/' . $name;
    }

    private static function preflightFile(string $path): void
    {
        clearstatcache(true, $path);
        if (
            is_link($path) || (file_exists($path) && (
                !is_file($path) || (@stat($path)['nlink'] ?? 0) !== 1
            ))
        ) {
            throw new RuntimeException('The private credential path must be an ordinary single-link file.');
        }
    }
}
