<?php
declare(strict_types=1);

namespace Bridge\Tests;

use Bridge\Provider\Provider;
use Bridge\Record;
use Bridge\RemoteConflict;

/** An in-memory remote with optimistic versions and before/after-commit fault injection. */
final class FakeProvider implements Provider
{
    /** @var array<string, array{fields: array, version: int, writable: bool}> */
    private array $records = [];
    /** @var list<array{id: string, changes: array}> */
    public array $writes = [];
    /** @var list<string> */
    public array $reads = [];
    /** @var list<array{error: \Throwable, afterCommit: bool}> */
    private array $patchFailures = [];
    /** @var list<\Throwable> */
    private array $fetchFailures = [];
    public ?\Closure $beforePatch = null;

    public function seed(string $id, array $fields, bool $writable = true): void
    {
        $this->records[$id] = ['fields' => $fields, 'version' => 1, 'writable' => $writable];
    }

    /** A user edit does not count as an integration write. */
    public function change(string $id, array $changes): void
    {
        $this->records[$id]['fields'] = array_replace($this->records[$id]['fields'], $changes);
        ++$this->records[$id]['version'];
    }

    public function fields(string $id): array
    {
        return $this->records[$id]['fields'];
    }

    public function failNextPatch(\Throwable $error, bool $afterCommit = false): void
    {
        $this->patchFailures[] = ['error' => $error, 'afterCommit' => $afterCommit];
    }

    public function failNextFetch(\Throwable $error): void
    {
        $this->fetchFailures[] = $error;
    }

    public function fetch(string $id): Record
    {
        $this->reads[] = $id;
        if ($this->fetchFailures !== []) {
            throw array_shift($this->fetchFailures);
        }
        $record = $this->records[$id] ?? throw new \RuntimeException('Unknown fake record: ' . $id);
        return new Record($record['fields'], (string) $record['version'], $record['writable']);
    }

    public function patch(string $id, array $changes, Record $expected): void
    {
        if ($this->beforePatch !== null) {
            $hook = $this->beforePatch;
            $this->beforePatch = null;
            $hook($this, $id, $changes);
        }
        $record = $this->records[$id];
        if ((string) $record['version'] !== $expected->version) {
            throw new RemoteConflict('Remote changed after read.');
        }
        $failure = array_shift($this->patchFailures);
        if ($failure !== null && !$failure['afterCommit']) {
            throw $failure['error'];
        }
        $this->change($id, $changes);
        $this->writes[] = ['id' => $id, 'changes' => $changes];
        if ($failure !== null) {
            throw $failure['error'];
        }
    }
}
