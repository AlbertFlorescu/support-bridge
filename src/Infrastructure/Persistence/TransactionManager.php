<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use PDO;
use Throwable;

/** Atomic repository writes/audit events, or a consistent snapshot for read-only operations. */
final readonly class TransactionManager
{
    public function __construct(private PDO $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $operation();
            $this->connection->commit();

            return $result;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $error;
        }
    }
}
