<?php

declare(strict_types=1);

namespace Bridge\Observability;

/** All stored links, with numeric vendor identifiers and no free-form content labels. */
final class LinkedPairMetrics
{
    private const METRICS = [
        'status' => 'Stored workflow state: 1 new, 2 synced, 3 awaiting approval, '
            . '4 conflict, 5 retry, 6 dead, 7 unknown.',
        'resolution' => 'Last verified baseline resolution: 0 unverified, 1 open, 2 closed.',
        'pending_plan' => 'Whether this linked pair has a durable write plan.',
        'attempts' => 'Persisted failed field-reconciliation attempts.',
        'last_success_timestamp_seconds' => 'Last observed convergence as Unix seconds; zero means never.',
        'notes_delivered' => 'GitHub comment deliveries with an observed internal Zendesk note.',
        'notes_waiting' => 'GitHub comment deliveries pending, sending, uncertain or blocked.',
        'notes_to_github_delivered' => 'New Zendesk internal notes observed as GitHub comments.',
        'notes_to_github_waiting' => 'Reverse note deliveries pending, sending, uncertain or blocked.',
    ];

    public function __construct(private readonly LinkedPairProjection $projection = new LinkedPairProjection())
    {
    }

    /** @param list<array> $pairs Read-only repository projection, not provider responses. */
    public function render(array $pairs): array
    {
        $rows = $this->projection->rows($pairs);
        $lines = [
            '# HELP support_bridge_linked_pairs Actual stored ticket and issue mappings.',
            '# TYPE support_bridge_linked_pairs gauge',
            'support_bridge_linked_pairs ' . count($rows),
        ];
        foreach (self::METRICS as $field => $help) {
            $name = 'support_bridge_linked_pair_' . $field;
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' gauge';
            foreach ($rows as $issue => $row) {
                $labels = 'github_issue="' . $issue . '"';
                if ($field === 'status') {
                    $labels .= ',zendesk_ticket="' . $row['ticket'] . '"';
                }
                $lines[] = $name . '{' . $labels . '} ' . $row['values'][$field];
            }
        }
        return $lines;
    }
}
