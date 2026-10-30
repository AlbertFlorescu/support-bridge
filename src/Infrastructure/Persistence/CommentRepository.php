<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use Bridge\Comments\DeliveryStatus;
use Bridge\Comments\IssueComment;
use PDO;
use RuntimeException;

/** Append-only source snapshots and delivery evidence on the scoped business connection. */
final readonly class CommentRepository
{
    private PDO $db;
    private TransactionManager $transactions;

    public function __construct(SqliteConnection $connection)
    {
        $this->db = $connection->pdo();
        $this->transactions = new TransactionManager($this->db);
    }

    public function prepare(string $pairId, IssueComment $comment): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM comment_deliveries WHERE pair_id=? AND comment_id=?');
        $query->execute([$pairId, $comment->id]);
        if ($query->fetchColumn() !== false) {
            return false;
        }
        $this->transactions->run(function () use ($pairId, $comment): void {
            $marker = 'Support Bridge comment: ' . bin2hex(random_bytes(32));
            $this->db->prepare('INSERT INTO comment_deliveries '
                . '(pair_id,comment_id,note,marker,source_version) VALUES(?,?,?,?,?)')->execute([
                    $pairId, $comment->id, $comment->note($marker), $marker, $comment->updatedAt,
                ]);
            $this->event($pairId, 'comment_prepared');
        });
        return true;
    }

    public function change(array $delivery, array $values, string $event): void
    {
        $allowed = ['status', 'attempted_at', 'destination_version', 'zendesk_comment_id', 'next_run', 'error'];
        if (
            $values === [] || array_diff(array_keys($values), $allowed) !== []
            || (isset($values['status']) && DeliveryStatus::tryFrom($values['status']) === null)
        ) {
            throw new RuntimeException('Invalid comment delivery transition.');
        }
        $this->transactions->run(function () use ($delivery, $values, $event): void {
            $assignments = array_map(static fn(string $field): string => $field . '=?', array_keys($values));
            $this->db->prepare('UPDATE comment_deliveries SET ' . implode(',', $assignments)
                . ',updated_at=CURRENT_TIMESTAMP WHERE pair_id=? AND comment_id=?')->execute([
                    ...array_values($values), $delivery['pair_id'], $delivery['comment_id'],
                ]);
            $this->event($delivery['pair_id'], $event);
        });
    }

    public function ready(bool $recovery, int $now): array
    {
        $states = $recovery ? "('sending','uncertain')" : "('pending')";
        $query = $this->db->prepare('SELECT * FROM comment_deliveries WHERE status IN ' . $states
            . ' AND next_run<=? ORDER BY updated_at,pair_id,comment_id LIMIT 5');
        $query->execute([$now]);
        return array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function list(int $limit = 50, int $offset = 0): array
    {
        $query = $this->db->prepare('SELECT * FROM comment_deliveries '
            . 'ORDER BY created_at,pair_id,comment_id LIMIT ? OFFSET ?');
        $query->bindValue(1, $limit, PDO::PARAM_INT);
        $query->bindValue(2, $offset, PDO::PARAM_INT);
        $query->execute();
        $streams = $this->db->prepare('SELECT * FROM comment_streams ORDER BY pair_id LIMIT ? OFFSET ?');
        $streams->bindValue(1, $limit, PDO::PARAM_INT);
        $streams->bindValue(2, $offset, PDO::PARAM_INT);
        $streams->execute();
        return ['items' => array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $this->db->query('SELECT COUNT(*) FROM comment_deliveries')->fetchColumn(),
            'limit' => $limit, 'offset' => $offset,
            'streams' => array_map(static fn(array $row): array => array_replace($row, [
                'last_scan' => (int) $row['last_scan'], 'next_run' => (int) $row['next_run'],
            ]), $streams->fetchAll(PDO::FETCH_ASSOC)),
            'stream_total' => (int) $this->db->query('SELECT COUNT(*) FROM comment_streams')->fetchColumn(),
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
            $this->db->prepare("UPDATE comment_deliveries SET status='blocked',error=?,"
                . 'updated_at=CURRENT_TIMESTAMP WHERE pair_id=? AND comment_id=?')->execute([
                    'Internal-only delivery was violated; all comment appends are held for investigation.',
                    $delivery['pair_id'], $delivery['comment_id'],
                ]);
            $this->db->exec("INSERT INTO settings(key,value) VALUES('comments_privacy_hold','1') "
                . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            $this->event($delivery['pair_id'], 'comment_privacy_hold');
        });
    }

    public function stream(string $pairId): array
    {
        $query = $this->db->prepare('SELECT * FROM comment_streams WHERE pair_id=?');
        $query->execute([$pairId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: ['next_run' => 0, 'error' => null, 'last_scan' => 0];
    }

    public function scan(string $pairId, int $now, ?string $error, int $nextRun): void
    {
        $previous = $this->stream($pairId);
        $this->transactions->run(function () use ($pairId, $now, $error, $nextRun, $previous): void {
            $this->db->prepare('INSERT INTO comment_streams(pair_id,last_scan,error,next_run) VALUES(?,?,?,?) '
                . 'ON CONFLICT(pair_id) DO UPDATE SET last_scan=excluded.last_scan,'
                . 'error=excluded.error,next_run=excluded.next_run')->execute([$pairId, $now, $error, $nextRun]);
            if ($error !== $previous['error']) {
                $this->event($pairId, $error === null ? 'comment_stream_restored' : 'comment_stream_failed');
            }
        });
    }

    private function event(string $pairId, string $type): void
    {
        $this->db->prepare('INSERT INTO events(pair_id,type,details) VALUES(?,?,?)')
            ->execute([$pairId, $type, '{}']);
    }

    private function decode(array $row): array
    {
        $row['next_run'] = (int) $row['next_run'];
        $row['attempted_at'] = $row['attempted_at'] === null ? null : (int) $row['attempted_at'];
        return $row;
    }
}
