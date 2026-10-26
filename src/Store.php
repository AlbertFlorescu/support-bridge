<?php

declare(strict_types=1);

namespace Bridge;

use Bridge\Contract\PairRepository;
use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Exception\ValidationFailure;
use Bridge\Infrastructure\Persistence\DatabaseLock;
use Bridge\Infrastructure\Persistence\SchemaMigrator;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Infrastructure\Persistence\TransactionManager;
use PDO;
use PDOException;

/** SQLite adapter for durable reconciliation state; no provider or merge decisions belong here. */
final class Store implements PairRepository
{
    private readonly PDO $db;
    private readonly DatabaseLock $lock;
    private readonly TransactionManager $transactions;

    public function __construct(string|SqliteConnection $connection)
    {
        $connection = is_string($connection) ? new SqliteConnection($connection) : $connection;
        $this->db = $connection->pdo();
        $this->lock = new DatabaseLock($connection);
        $this->transactions = new TransactionManager($this->db);

        (new SchemaMigrator())->migrate($this->db);
    }

    /** A database belongs to one repository/account pair; changing env must never retarget old links. */
    public function bindIdentity(array $identity): void
    {
        ksort($identity);
        $value = json_encode($identity, JSON_THROW_ON_ERROR);
        $this->db->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES (?, ?)')->execute(['identity', $value]);
        $q = $this->db->prepare('SELECT value FROM settings WHERE key = ?');
        $q->execute(['identity']);
        if ($q->fetchColumn() !== $value) {
            throw new StateConflict('This state database belongs to different providers. Use a ' .
                'separate --home directory.');
        }
    }

    public function addPair(string $id, string $githubId, string $zendeskId): void
    {
        if (
            !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $id)
            || !preg_match('/^[1-9][0-9]{0,15}$/D', $githubId) || !preg_match('/^[1-9][0-9]{0,15}$/D', $zendeskId)
        ) {
            throw new ValidationFailure('Use a short pair name and positive numeric issue/ticket IDs.');
        }
        try {
            $this->transactions->run(function () use ($id, $githubId, $zendeskId): void {
                $q = $this->db->prepare('INSERT INTO pairs(id, github_id, zendesk_id) VALUES (?, ?, ?)');
                $q->execute([$id, $githubId, $zendeskId]);
                $this->event($id, 'linked');
            });
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new StateConflict('The pair name or one of its remote records is already linked.', 0, $error);
            }
            throw $error;
        }
    }

    public function get(string $id): array
    {
        $q = $this->db->prepare('SELECT * FROM pairs WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFound('Unknown pair: ' . $id);
        }
        return $this->decode($row);
    }

    public function all(): array
    {
        return array_map(
            $this->decode(...),
            $this->db->query('SELECT * FROM pairs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function update(string $id, array $values): void
    {
        $allowed = [
            'baseline',
            'plan',
            'conflict',
            'approval',
            'status',
            'attempts',
            'next_run',
            'error',
            'last_success',
        ];
        if ($values === [] || array_diff(array_keys($values), $allowed) !== []) {
            throw new ValidationFailure('Invalid state update.');
        }
        $assignments = [];
        $params = [];
        foreach ($values as $key => $value) {
            $assignments[] = $key . ' = ?';
            $params[] = in_array($key, ['baseline', 'plan', 'conflict', 'approval'], true) && $value !== null
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $value;
        }
        $params[] = $id;
        $q = $this->db->prepare('UPDATE pairs SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $q->execute($params);
        if ($q->rowCount() === 0) {
            throw new NotFound('Unknown pair: ' . $id);
        }
    }

    public function event(string $id, string $type, array $details = []): void
    {
        $this->db->prepare('INSERT INTO events(pair_id, type, details) VALUES (?, ?, ?)')
            ->execute([$id, $type, json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public function events(string $id): array
    {
        $q = $this->db->prepare('SELECT type, details, created_at FROM events WHERE pair_id = ? ' .
            'ORDER BY id DESC LIMIT 30');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function metrics(): array
    {
        $counts = array_fill_keys(['new', 'synced', 'retry', 'conflict', 'dead', 'awaiting_approval'], 0);
        $rows = $this->db
            ->query('SELECT status, COUNT(*) AS total FROM pairs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        return [
            'pairs'         => $counts,
            'pending_plans' => (int) $this->db
            ->query('SELECT COUNT(*) FROM pairs WHERE plan IS NOT NULL')->fetchColumn(),
            'last_success'  => $this->db->query('SELECT MAX(last_success) FROM pairs')->fetchColumn() ?: null,
        ];
    }

    /** @return resource */
    public function lock()
    {
        return $this->lock->acquire();
    }

    public function withExclusiveLock(callable $operation): mixed
    {
        return $this->lock->exclusively($operation);
    }

    private function decode(array $row): array
    {
        foreach (['baseline', 'plan', 'conflict', 'approval'] as $field) {
            $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR);
        }
        $row['attempts'] = (int) $row['attempts'];
        $row['next_run'] = (int) $row['next_run'];
        return $row;
    }
}
