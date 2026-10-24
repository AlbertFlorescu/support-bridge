<?php
declare(strict_types=1);

namespace Bridge\Contract;

/** Durable pair state used by reconciliation; implementations own their locking mechanism. */
interface PairRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed> */
    public function get(string $id): array;

    /** Persist one atomic state transition before returning. */
    public function update(string $id, array $values): void;

    public function event(string $id, string $type, array $details = []): void;

    /** Run a worker or operator action exclusively against this repository. */
    public function withExclusiveLock(callable $operation): mixed;
}
