<?php

declare(strict_types=1);

namespace Bridge\Observability;

use RuntimeException;

/** Shared numeric evidence and one-to-one identity validation for monitoring readers. */
final class LinkedPairProjection
{
    private const STATUSES = [
        'new' => 1, 'synced' => 2, 'awaiting_approval' => 3,
        'conflict' => 4, 'retry' => 5, 'dead' => 6,
    ];

    /** @param list<array> $pairs */
    public function rows(array $pairs): array
    {
        $rows = [];
        $tickets = [];
        foreach ($pairs as $pair) {
            $issue = is_array($pair) ? ($pair['github_id'] ?? null) : null;
            $ticket = is_array($pair) ? ($pair['zendesk_id'] ?? null) : null;
            if (
                !is_string($issue) || !preg_match('/^[1-9][0-9]{0,15}$/D', $issue)
                || !is_string($ticket) || !preg_match('/^[1-9][0-9]{0,15}$/D', $ticket)
                || isset($rows[$issue]) || isset($tickets[$ticket])
            ) {
                throw new RuntimeException('Linked pair metric state is invalid.');
            }
            $rows[$issue] = ['ticket' => $ticket, 'subject' => $pair['baseline']['title'] ?? null,
                'values' => $this->values($pair)];
            $tickets[$ticket] = true;
        }
        return $rows;
    }

    private function values(array $pair): array
    {
        $baseline = $pair['baseline'] ?? null;
        if (
            $baseline !== null
            && (!is_array($baseline) || !in_array($baseline['state'] ?? null, ['open', 'closed'], true))
        ) {
            throw new RuntimeException('Linked pair metric state is invalid.');
        }
        $pending = $pair['pending_plan'] ?? null;
        if (!in_array($pending, [0, 1], true)) {
            throw new RuntimeException('Linked pair metric state is invalid.');
        }
        $status = $pair['status'] ?? null;
        return [
            'status' => is_string($status) ? (self::STATUSES[$status] ?? 7) : 7,
            'resolution' => $baseline === null ? 0 : ($baseline['state'] === 'open' ? 1 : 2),
            'pending_plan' => $pending,
            'attempts' => $this->nonnegative($pair['attempts'] ?? -1),
            'last_success_timestamp_seconds' => $this->nonnegative($pair['last_success'] ?? null),
            'notes_delivered' => $this->nonnegative($pair['notes_delivered'] ?? -1),
            'notes_waiting' => $this->nonnegative($pair['notes_waiting'] ?? -1),
            'notes_to_github_delivered' => $this->nonnegative($pair['notes_to_github_delivered'] ?? -1),
            'notes_to_github_waiting' => $this->nonnegative($pair['notes_to_github_waiting'] ?? -1),
        ];
    }

    private function nonnegative(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Linked pair metric state is invalid.');
        }
        return $value;
    }
}
