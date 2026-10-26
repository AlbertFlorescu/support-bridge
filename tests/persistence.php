<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Exception\WorkerBusy;
use Bridge\Infrastructure\Persistence\DatabaseLock;
use Bridge\Infrastructure\Persistence\SchemaMigrator;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Infrastructure\Persistence\TransactionManager;
use Bridge\Store;

function persistenceSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}

function persistenceRejects(callable $operation, string $class): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }

        throw $error;
    }

    throw new RuntimeException('Expected the operation to be rejected.');
}

final class PersistenceFixture
{
    public readonly string $directory;
    public readonly SqliteConnection $connection;
    public readonly Store $store;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/bridge-persistence-' . bin2hex(random_bytes(8));
        $this->connection = new SqliteConnection($this->directory . '/business.sqlite');
        $this->store = new Store($this->connection);
    }

    public function cleanup(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }
}

$tests = [];

$tests['Explicit connection scopes retain SQLite durability settings and separate databases'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $database = $fixture->connection->pdo();
        persistenceSame('wal', $database->query('PRAGMA journal_mode')->fetchColumn());
        persistenceSame(2, $database->query('PRAGMA synchronous')->fetchColumn());
        persistenceSame(5000, $database->query('PRAGMA busy_timeout')->fetchColumn());

        $other = new Store(new SqliteConnection($fixture->directory . '/other.sqlite'));
        $fixture->store->addPair('first', '101', '501');
        persistenceSame([], $other->all());
        persistenceSame('first', $fixture->store->all()[0]['id']);
    } finally {
        $fixture->cleanup();
    }
};

$tests['Repositories sharing an injected connection participate in the same transaction'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $other = new Store($fixture->connection);
        $fixture->store->addPair('shared', '101', '501');
        $database = $fixture->connection->pdo();
        $database->beginTransaction();
        $fixture->store->update('shared', ['status' => 'retry', 'attempts' => 3, 'next_run' => 1800000000]);
        $other->event('shared', 'transaction_probe');
        persistenceSame('retry', $other->get('shared')['status']);
        $database->rollBack();

        persistenceSame('new', $other->get('shared')['status']);
        persistenceSame(0, $other->get('shared')['attempts']);
        persistenceSame(['linked'], array_column($fixture->store->events('shared'), 'type'));
    } finally {
        $fixture->cleanup();
    }
};

$tests['Transaction rollback preserves the original failure and leaves the connection usable'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $fixture->store->addPair('rollback', '101', '501');
        $transactions = new TransactionManager($fixture->connection->pdo());
        $failure = new DomainException('The required command step failed.');
        $caught = persistenceRejects(
            static function () use ($fixture, $transactions, $failure): void {
                $transactions->run(static function () use ($fixture, $failure): void {
                    $fixture->store->update('rollback', ['status' => 'dead']);
                    $fixture->store->event('rollback', 'uncommitted');
                    throw $failure;
                });
            },
            DomainException::class,
        );

        persistenceSame($failure, $caught);
        persistenceSame(false, $fixture->connection->pdo()->inTransaction());
        persistenceSame('new', $fixture->store->get('rollback')['status']);
        persistenceSame(['linked'], array_column($fixture->store->events('rollback'), 'type'));
        persistenceSame('committed', $transactions->run(static function () use ($fixture): string {
            $fixture->store->update('rollback', ['status' => 'synced']);

            return 'committed';
        }));
        persistenceSame('synced', $fixture->store->get('rollback')['status']);
    } finally {
        $fixture->cleanup();
    }
};

$tests['A failed required link audit rolls back the pair without masking the storage error'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $fixture->connection->pdo()->exec('DROP TABLE events');
        persistenceRejects(
            static fn() => $fixture->store->addPair('unlogged', '101', '501'),
            PDOException::class,
        );
        persistenceSame([], $fixture->store->all());
        persistenceSame(false, $fixture->connection->pdo()->inTransaction());

        (new SchemaMigrator())->migrate($fixture->connection->pdo());
        $fixture->store->addPair('logged', '101', '501');
        persistenceSame(['linked'], array_column($fixture->store->events('logged'), 'type'));
    } finally {
        $fixture->cleanup();
    }
};

$tests['Canonical database aliases share the lock and callback failures release it'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $lock = new DatabaseLock($fixture->connection);
        $alias = $fixture->directory . '/alias.sqlite';
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('symlink')) {
            persistenceSame(true, symlink($fixture->connection->path(), $alias));
            $otherConnection = new SqliteConnection($alias);
            persistenceSame($fixture->connection->path(), $otherConnection->path());
        } else {
            $otherConnection = new SqliteConnection($fixture->connection->path());
        }

        $otherLock = new DatabaseLock($otherConnection);
        $lock->exclusively(static function () use ($otherLock): void {
            persistenceRejects(static fn() => $otherLock->acquire(), WorkerBusy::class);
        });

        persistenceRejects(
            static fn() => $lock->exclusively(static fn() => throw new RuntimeException('Interrupted operation.')),
            RuntimeException::class,
        );
        persistenceSame('released', $otherLock->exclusively(static fn(): string => 'released'));
    } finally {
        $fixture->cleanup();
    }
};

$tests['Legacy schema migration preserves the exact pending JSON and retry evidence'] = static function (): void {
    $fixture = new PersistenceFixture();
    try {
        $path = $fixture->directory . '/legacy.sqlite';
        $database = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec(<<<'SQL'
            CREATE TABLE pairs (
                id TEXT PRIMARY KEY,
                github_id TEXT NOT NULL UNIQUE,
                zendesk_id TEXT NOT NULL UNIQUE,
                baseline TEXT,
                plan TEXT,
                conflict TEXT,
                status TEXT NOT NULL DEFAULT 'new',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_run INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                last_success INTEGER
            );
            SQL);
        $plan = '{ "target": {"title":"Saved", "state":"closed"}, "before": {}, "versions": {} }';
        $database->prepare('INSERT INTO pairs(id, github_id, zendesk_id, plan, status, attempts, next_run) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['pending', '101', '501', $plan, 'retry', 3, 1800000000]);

        $connection = new SqliteConnection($path);
        $migrations = new SchemaMigrator();
        $migrations->migrate($connection->pdo());
        $migrations->migrate($connection->pdo());
        $row = $connection->pdo()->query('SELECT * FROM pairs')->fetch(PDO::FETCH_ASSOC);
        persistenceSame($plan, $row['plan']);
        persistenceSame('retry', $row['status']);
        persistenceSame(3, $row['attempts']);
        persistenceSame(1800000000, $row['next_run']);
        persistenceSame(null, $row['approval']);
        $columns = array_column($connection->pdo()->query('PRAGMA table_info(pairs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        persistenceSame(1, count(array_filter($columns, static fn(string $name): bool => $name === 'approval')));
    } finally {
        $fixture->cleanup();
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}

echo count($tests) . ' persistence checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
