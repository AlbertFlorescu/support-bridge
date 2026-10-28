<?php

declare(strict_types=1);

namespace Bridge\Observability;

use RuntimeException;

/** Read-only projection of the three explicitly named demonstration cases. */
final class DemoCaseMetrics
{
    private const CASES = [
        'csv-export'    => 'CSV export',
        'webhook-retry' => 'Webhook delivery',
        'sso-conflict'  => 'SSO sign-in',
    ];
    private const STATUSES = [
        'new'               => 1,
        'synced'            => 2,
        'awaiting_approval' => 3,
        'conflict'          => 4,
        'retry'             => 5,
        'dead'              => 6,
    ];
    private const METRICS = [
        'present'                        => 'Whether this demonstration case has a stored link.',
        'status'                         => 'Stored workflow state: 0 missing, 1 new, 2 synced, ' .
            '3 awaiting approval, 4 conflict, 5 retry, 6 dead, 7 unknown.',
        'resolution'                     => 'Last verified baseline resolution: 0 unverified, 1 open, 2 closed.',
        'pending_plan'                   => 'Whether this case has a durable write plan awaiting convergence.',
        'attempts'                       => 'Persisted failed attempts for this case.',
        'last_success_timestamp_seconds' => 'Last observed convergence as Unix seconds, or zero if never observed.',
    ];

    /** @param list<array> $pairs Actual decoded business-store rows, never provider responses. */
    public function render(array $pairs): array
    {
        $linked = [];
        foreach ($pairs as $pair) {
            $id = is_array($pair) ? ($pair['id'] ?? null) : null;
            if (!is_string($id) || !isset(self::CASES[$id])) {
                continue;
            }
            if (isset($linked[$id])) {
                throw new RuntimeException('Demo case state is invalid.');
            }
            $linked[$id] = $pair;
        }

        $cases = [];
        foreach (self::CASES as $id => $label) {
            $cases[$label] = $this->values($linked[$id] ?? null);
        }

        $lines = [];
        foreach (self::METRICS as $field => $help) {
            $metric = 'support_bridge_demo_case_' . $field;
            $lines[] = '# HELP ' . $metric . ' ' . $help;
            $lines[] = '# TYPE ' . $metric . ' gauge';
            foreach ($cases as $label => $values) {
                $lines[] = $metric . '{case="' . $label . '"} ' . $values[$field];
            }
        }
        return $lines;
    }

    private function values(?array $pair): array
    {
        if ($pair === null) {
            return array_fill_keys(array_keys(self::METRICS), 0);
        }

        $baseline = $pair['baseline'] ?? null;
        if (
            $baseline !== null
            && (!is_array($baseline) || !in_array($baseline['state'] ?? null, ['open', 'closed'], true))
        ) {
            throw new RuntimeException('Demo case state is invalid.');
        }
        $status = $pair['status'] ?? null;
        return [
            'present'                        => 1,
            'status'                         => is_string($status) ? (self::STATUSES[$status] ?? 7) : 7,
            'resolution'                     => $baseline === null ? 0 : ($baseline['state'] === 'open' ? 1 : 2),
            'pending_plan'                   => ($pair['plan'] ?? null) === null ? 0 : 1,
            'attempts'                       => self::nonnegative($pair['attempts'] ?? -1),
            'last_success_timestamp_seconds' => self::nonnegative($pair['last_success'] ?? null),
        ];
    }

    private static function nonnegative(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Demo case state is invalid.');
        }
        return $value;
    }
}
