<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use PDO;
use RuntimeException;

/** One explicitly owned connection for one canonical business database. */
final class SqliteConnection
{
    private readonly PDO $pdo;
    private readonly string $path;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create state directory.');
        }

        $canonicalDirectory = realpath($directory);
        if ($canonicalDirectory === false) {
            throw new RuntimeException('Cannot resolve state directory.');
        }

        $this->path = realpath($path) ?: $canonicalDirectory . DIRECTORY_SEPARATOR . basename($path);
        $this->pdo = new PDO(
            'sqlite:' . $this->path,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->path;
    }
}
