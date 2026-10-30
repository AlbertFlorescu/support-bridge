<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use Bridge\Intake\EscalationTicket;
use Bridge\Intake\IntakeStatus;
use PDO;
use RuntimeException;

/** Durable creation intent; uses the same scoped connection as reconciliation. */
final readonly class EscalationRepository
{
    private PDO $db;
    private TransactionManager $transactions;

    public function __construct(SqliteConnection $connection)
    {
        $this->db = $connection->pdo();
        $this->transactions = new TransactionManager($this->db);
    }

    public function prepare(EscalationTicket $ticket): bool
    {
        $existing = $this->find($ticket->id);
        if ($existing !== null && $existing['status'] !== 'withdrawn') {
            return false;
        }
        $this->transactions->run(function () use ($ticket, $existing): void {
            if ($existing === null) {
                $this->db->prepare(
                    'INSERT INTO escalations(ticket_id,pair_id,marker,snapshot) VALUES(?,?,?,?)'
                )->execute([
                    $ticket->id, 'zendesk-' . $ticket->id,
                    '<!-- support-bridge-escalation:' . bin2hex(random_bytes(32)) . ' -->',
                    json_encode($ticket->snapshot(), JSON_THROW_ON_ERROR),
                ]);
            } else {
                $this->db->prepare(
                    "UPDATE escalations SET status='prepared',snapshot=?,error=NULL,next_run=0 WHERE ticket_id=?"
                )->execute([json_encode($ticket->snapshot(), JSON_THROW_ON_ERROR), $ticket->id]);
            }
            $this->event($ticket->id, 'escalation_prepared');
        });
        return true;
    }

    public function find(string $ticketId): ?array
    {
        $query = $this->db->prepare('SELECT * FROM escalations WHERE ticket_id=?');
        $query->execute([$ticketId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decode($row);
    }

    public function change(string $ticketId, array $values, string $event): void
    {
        $allowed = ['status', 'github_id', 'snapshot', 'error', 'next_run', 'attempted_at'];
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new RuntimeException('Invalid escalation transition fields.');
        }
        if (isset($values['status']) && IntakeStatus::tryFrom($values['status']) === null) {
            throw new RuntimeException('Invalid escalation transition state.');
        }
        if (isset($values['snapshot'])) {
            $values['snapshot'] = json_encode($values['snapshot'], JSON_THROW_ON_ERROR);
        }
        $this->transactions->run(function () use ($ticketId, $values, $event): void {
            $assignments = array_map(static fn(string $name): string => $name . '=?', array_keys($values));
            $query = $this->db->prepare('UPDATE escalations SET ' . implode(',', $assignments)
                . ',updated_at=CURRENT_TIMESTAMP WHERE ticket_id=?');
            $query->execute([...array_values($values), $ticketId]);
            $this->event($ticketId, $event);
        });
    }

    public function ready(bool $recovery, int $now): array
    {
        $states = $recovery
            ? "(status IN ('creating','uncertain') OR (status='blocked' AND EXISTS "
                . '(SELECT 1 FROM pairs WHERE pairs.zendesk_id=escalations.ticket_id)))'
            : "status='prepared'";
        $query = $this->db->prepare(
            'SELECT * FROM escalations WHERE ' . $states
            . ' AND next_run<=? ORDER BY updated_at,ticket_id LIMIT 5'
        );
        $query->execute([$now]);
        return array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function list(int $limit = 50, int $offset = 0): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM escalations ORDER BY created_at,ticket_id LIMIT ? OFFSET ?'
        );
        $query->bindValue(1, $limit, PDO::PARAM_INT);
        $query->bindValue(2, $offset, PDO::PARAM_INT);
        $query->execute();
        return [
            'items' => array_map($this->decode(...), $query->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $this->db->query('SELECT COUNT(*) FROM escalations')->fetchColumn(),
            'limit' => $limit, 'offset' => $offset,
        ];
    }

    public function page(?int $value = null): int
    {
        if ($value !== null) {
            $this->db->prepare('INSERT INTO settings(key,value) VALUES(?,?) '
                . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute(['intake_page', (string) $value]);
            return $value;
        }
        $query = $this->db->prepare('SELECT value FROM settings WHERE key=?');
        $query->execute(['intake_page']);
        return max(1, min(20, (int) ($query->fetchColumn() ?: 1)));
    }

    public function pauseUntil(?int $value = null): int
    {
        if ($value !== null) {
            $this->db->prepare(
                'INSERT INTO settings(key,value) VALUES(?,?) '
                . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value'
            )->execute(['intake_pause_until', (string) $value]);
            return $value;
        }
        $query = $this->db->prepare('SELECT value FROM settings WHERE key=?');
        $query->execute(['intake_pause_until']);
        return max(0, (int) ($query->fetchColumn() ?: 0));
    }

    private function event(string $ticketId, string $type): void
    {
        $this->db->prepare('INSERT INTO events(pair_id,type,details) VALUES(?,?,?)')
            ->execute(['zendesk-' . $ticketId, $type, '{}']);
    }

    private function decode(array $row): array
    {
        $row['snapshot'] = json_decode($row['snapshot'], true, 16, JSON_THROW_ON_ERROR);
        $row['next_run'] = (int) $row['next_run'];
        $row['attempted_at'] = $row['attempted_at'] === null ? null : (int) $row['attempted_at'];
        return $row;
    }
}
