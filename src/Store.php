<?php
declare(strict_types=1);

namespace Bridge;

use Bridge\Contract\PairRepository;
use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Exception\ValidationFailure;
use Bridge\Exception\WorkerBusy;
use PDO;
use PDOException;

/** SQLite adapter for durable reconciliation state; no provider or merge decisions belong here. */
final class Store implements PairRepository
{
    private readonly PDO $db;
    private readonly string $path;

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create state directory.');
        }
        $this->path = realpath($path) ?: realpath(dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
        $this->db = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;');
        $this->db->exec("CREATE TABLE IF NOT EXISTS pairs (
            id TEXT PRIMARY KEY, github_id TEXT NOT NULL UNIQUE, zendesk_id TEXT NOT NULL UNIQUE,
            baseline TEXT, plan TEXT, conflict TEXT, approval TEXT, status TEXT NOT NULL DEFAULT 'new',
            attempts INTEGER NOT NULL DEFAULT 0, next_run INTEGER NOT NULL DEFAULT 0,
            error TEXT, last_success INTEGER
        ); CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, pair_id TEXT NOT NULL,
            type TEXT NOT NULL, details TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        ); CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);");
        $this->migrateApproval();
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
            throw new StateConflict('This state database belongs to different providers. Use a separate --home directory.');
        }
    }

    public function addPair(string $id, string $githubId, string $zendeskId): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $id)
            || !preg_match('/^[1-9][0-9]{0,15}$/D', $githubId) || !preg_match('/^[1-9][0-9]{0,15}$/D', $zendeskId)) {
            throw new ValidationFailure('Use a short pair name and positive numeric issue/ticket IDs.');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('INSERT INTO pairs(id, github_id, zendesk_id) VALUES (?, ?, ?)');
            $q->execute([$id, $githubId, $zendeskId]);
            $this->event($id, 'linked');
            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
        return array_map($this->decode(...), $this->db->query('SELECT * FROM pairs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function update(string $id, array $values): void
    {
        $allowed = ['baseline', 'plan', 'conflict', 'approval', 'status', 'attempts', 'next_run', 'error', 'last_success'];
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
        $q = $this->db->prepare('SELECT type, details, created_at FROM events WHERE pair_id = ? ORDER BY id DESC LIMIT 30');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function metrics(): array
    {
        $counts = array_fill_keys(['new', 'synced', 'retry', 'conflict', 'dead', 'awaiting_approval'], 0);
        foreach ($this->db->query('SELECT status, COUNT(*) AS total FROM pairs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        return ['pairs' => $counts, 'pending_plans' => (int) $this->db->query('SELECT COUNT(*) FROM pairs WHERE plan IS NOT NULL')->fetchColumn(),
            'last_success' => $this->db->query('SELECT MAX(last_success) FROM pairs')->fetchColumn() ?: null];
    }

    /** @return resource */
    public function lock()
    {
        $handle = fopen($this->path . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new WorkerBusy('Another worker is using this database. Try again after it finishes.');
        }
        return $handle;
    }

    public function withExclusiveLock(callable $operation): mixed
    {
        $lock = $this->lock();
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

    /** Additive, idempotent upgrade: old pending plans remain intact and start without approval. */
    private function migrateApproval(): void
    {
        $hasApproval = fn(): bool => in_array('approval', array_column($this->db->query('PRAGMA table_info(pairs)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        if ($hasApproval()) {
            return;
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // Recheck after the write lock: another process may have migrated while we waited.
            if (!$hasApproval()) {
                $this->db->exec('ALTER TABLE pairs ADD COLUMN approval TEXT');
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
