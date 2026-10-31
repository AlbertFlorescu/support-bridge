<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use Bridge\Comments\CommentDirection;
use Bridge\Comments\DeliveryStatus;
use Bridge\Comments\SourceComment;
use PDO;
use RuntimeException;

/** Direction-specific immutable ledgers share one connection and the existing business lock. */
final readonly class CommentRepository
{
    private PDO $db;
    private TransactionManager $transactions;

    public function __construct(
        SqliteConnection $connection,
        public CommentDirection $direction = CommentDirection::GitHubToZendesk
    ) {
        $this->db = $connection->pdo();
        $this->transactions = new TransactionManager($this->db);
    }

    public function prepare(string $pairId, SourceComment $comment): bool
    {
        if ($this->direction === CommentDirection::ZendeskToGitHub) {
            $stream = $this->stream($pairId);
            if ($stream['initialized_at'] === null) {
                throw new RuntimeException('Reverse comment discovery needs an initialized starting point.');
            }
            if (in_array($comment->sourceId(), $this->excluded($stream), true)) {
                return false;
            }
        }
        $table = $this->direction->table();
        $query = $this->db->prepare('SELECT 1 FROM ' . $table . ' WHERE pair_id=? AND comment_id=?');
        $query->execute([$pairId, $comment->sourceId()]);
        if ($query->fetchColumn() !== false) {
            return false;
        }
        $this->transactions->run(function () use ($table, $pairId, $comment): void {
            $marker = $this->direction->markerPrefix() . bin2hex(random_bytes(32));
            $note = $comment->note($marker);
            if (strlen($note) > 62_000) {
                throw new RuntimeException('Formatted comment exceeds the supported delivery bound.');
            }
            $this->db->prepare('INSERT INTO ' . $table
                . ' (pair_id,comment_id,note,marker,source_version) VALUES(?,?,?,?,?)')->execute([
                    $pairId, $comment->sourceId(), $note, $marker, $comment->sourceVersion(),
                ]);
            $this->event($pairId, 'prepared');
        });
        return true;
    }

    public function knowsOrigin(string $pairId, string $sourceId, string $marker): bool
    {
        $opposite = $this->direction->opposite();
        $query = $this->db->prepare('SELECT 1 FROM ' . $opposite->table()
            . ' WHERE pair_id=? AND (' . $opposite->destinationColumn() . '=? OR marker=?) LIMIT 1');
        $query->execute([$pairId, $sourceId, $marker]);
        return $query->fetchColumn() !== false;
    }

    /** Record all pre-existing IDs, including public comments; no historical row is marked delivered. */
    public function initialize(string $pairId, array $ids, int $now): void
    {
        if (
            $this->direction !== CommentDirection::ZendeskToGitHub
            || $this->stream($pairId)['initialized_at'] !== null
        ) {
            throw new RuntimeException('The reverse starting point cannot be reset.');
        }
        $this->validateExcluded($ids);
        $this->transactions->run(function () use ($pairId, $ids, $now): void {
            $this->db->prepare('INSERT INTO note_streams(pair_id,initialized_at,excluded_ids) VALUES(?,?,?) '
                . 'ON CONFLICT(pair_id) DO UPDATE SET initialized_at=excluded.initialized_at,'
                . 'excluded_ids=excluded.excluded_ids')->execute([
                    $pairId, $now, json_encode($ids, JSON_THROW_ON_ERROR),
                ]);
            $this->event($pairId, 'stream_initialized');
        });
    }

    public function excludedIds(string $pairId): array
    {
        return $this->excluded($this->stream($pairId));
    }

    public function change(array $delivery, array $values, string $event): void
    {
        $allowed = ['status', 'attempted_at', 'destination_version',
            $this->direction->destinationColumn(), 'next_run', 'error'];
        if (
            $values === [] || array_diff(array_keys($values), $allowed) !== []
            || (isset($values['status']) && DeliveryStatus::tryFrom($values['status']) === null)
        ) {
            throw new RuntimeException('Invalid comment delivery transition.');
        }
        $this->transactions->run(function () use ($delivery, $values, $event): void {
            $assignments = array_map(static fn(string $field): string => $field . '=?', array_keys($values));
            $this->db->prepare('UPDATE ' . $this->direction->table() . ' SET ' . implode(',', $assignments)
                . ',updated_at=CURRENT_TIMESTAMP WHERE pair_id=? AND comment_id=?')->execute([
                    ...array_values($values), $delivery['pair_id'], $delivery['comment_id'],
                ]);
            $this->event($delivery['pair_id'], $event);
        });
    }

    public function ready(bool $recovery, int $now): array
    {
        $states = $recovery ? "('sending','uncertain')" : "('pending')";
        $query = $this->db->prepare('SELECT * FROM ' . $this->direction->table() . ' WHERE status IN ' . $states
            . ' AND next_run<=? ORDER BY updated_at,pair_id,comment_id LIMIT 5');
        $query->execute([$now]);
        return array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function list(int $limit = 50, int $offset = 0): array
    {
        $query = $this->db->prepare('SELECT * FROM ' . $this->direction->table()
            . ' ORDER BY created_at,pair_id,comment_id LIMIT ? OFFSET ?');
        $query->bindValue(1, $limit, PDO::PARAM_INT);
        $query->bindValue(2, $offset, PDO::PARAM_INT);
        $query->execute();
        $streams = $this->db->prepare('SELECT * FROM ' . $this->direction->streamTable()
            . ' ORDER BY pair_id LIMIT ? OFFSET ?');
        $streams->bindValue(1, $limit, PDO::PARAM_INT);
        $streams->bindValue(2, $offset, PDO::PARAM_INT);
        $streams->execute();
        return ['items' => array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->direction->table())->fetchColumn(),
            'limit' => $limit, 'offset' => $offset,
            'streams' => array_map($this->streamView(...), $streams->fetchAll(PDO::FETCH_ASSOC)),
            'stream_total' => (int) $this->db
                ->query('SELECT COUNT(*) FROM ' . $this->direction->streamTable())->fetchColumn(),
            'privacy_hold' => $this->held()];
    }

    public function held(): bool
    {
        return $this->db->query("SELECT value FROM settings WHERE key='comments_privacy_hold'")
            ->fetchColumn() === '1';
    }

    public function hold(array $delivery): void
    {
        $this->transactions->run(function () use ($delivery): void {
            $this->db->prepare('UPDATE ' . $this->direction->table() . " SET status='blocked',error=?,"
                . 'updated_at=CURRENT_TIMESTAMP WHERE pair_id=? AND comment_id=?')->execute([
                    'Internal-only delivery was violated; all comment appends are held for investigation.',
                    $delivery['pair_id'], $delivery['comment_id'],
                ]);
            $this->db->exec("INSERT INTO settings(key,value) VALUES('comments_privacy_hold','1') "
                . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            $this->event($delivery['pair_id'], 'privacy_hold');
        });
    }

    public function stream(string $pairId): array
    {
        $query = $this->db->prepare('SELECT * FROM ' . $this->direction->streamTable() . ' WHERE pair_id=?');
        $query->execute([$pairId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [
            'next_run' => 0, 'error' => null, 'last_scan' => 0, 'initialized_at' => null, 'excluded_ids' => null,
        ];
    }

    public function scan(string $pairId, int $now, ?string $error, int $nextRun): void
    {
        $previous = $this->stream($pairId);
        $this->transactions->run(function () use ($pairId, $now, $error, $nextRun, $previous): void {
            $this->db->prepare('INSERT INTO ' . $this->direction->streamTable()
                . '(pair_id,last_scan,error,next_run) VALUES(?,?,?,?) '
                . 'ON CONFLICT(pair_id) DO UPDATE SET last_scan=excluded.last_scan,'
                . 'error=excluded.error,next_run=excluded.next_run')->execute([$pairId, $now, $error, $nextRun]);
            if ($error !== $previous['error']) {
                $this->event($pairId, $error === null ? 'stream_restored' : 'stream_failed');
            }
        });
    }

    private function streamView(array $row): array
    {
        $row['last_scan'] = (int) $row['last_scan'];
        $row['next_run'] = (int) $row['next_run'];
        if ($this->direction === CommentDirection::ZendeskToGitHub) {
            $row['initialized_at'] = $row['initialized_at'] === null ? null : (int) $row['initialized_at'];
            $row['excluded_count'] = count($this->excluded($row));
            unset($row['excluded_ids']);
        }
        return $row;
    }

    private function excluded(array $stream): array
    {
        $ids = $stream['excluded_ids'] === null
            ? [] : json_decode($stream['excluded_ids'], true, 16, JSON_THROW_ON_ERROR);
        $this->validateExcluded($ids);
        return $ids;
    }

    private function validateExcluded(array $ids): void
    {
        if (!array_is_list($ids) || count($ids) > 500) {
            throw new RuntimeException('Reverse starting-point evidence is invalid.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
                throw new RuntimeException('Reverse starting-point evidence is invalid.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('Reverse starting-point evidence is invalid.');
        }
    }

    private function event(string $pairId, string $suffix): void
    {
        $this->db->prepare('INSERT INTO events(pair_id,type,details) VALUES(?,?,?)')
            ->execute([$pairId, $this->direction->event($suffix), '{}']);
    }

    private function decode(array $row): array
    {
        $row['next_run'] = (int) $row['next_run'];
        $row['attempted_at'] = $row['attempted_at'] === null ? null : (int) $row['attempted_at'];
        return $row;
    }
}
