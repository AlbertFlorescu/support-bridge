<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use Bridge\Exception\WorkerBusy;

/** Serializes operations across processes sharing the same canonical database. */
final readonly class DatabaseLock
{
    public function __construct(private SqliteConnection $connection)
    {
    }

    /** @return resource */
    public function acquire()
    {
        $handle = fopen($this->connection->path() . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new WorkerBusy('Another worker is using this database. Try again after it finishes.');
        }

        return $handle;
    }

    public function exclusively(callable $operation): mixed
    {
        $handle = $this->acquire();
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
