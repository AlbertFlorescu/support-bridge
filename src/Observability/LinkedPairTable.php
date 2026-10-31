<?php

declare(strict_types=1);

namespace Bridge\Observability;

use Bridge\Application\Configuration;
use Bridge\Provider\Identity;
use RuntimeException;

/** Current record context for authenticated Grafana viewers, outside metric labels/history. */
final readonly class LinkedPairTable
{
    public function __construct(
        private Configuration $configuration,
        private LinkedPairProjection $projection = new LinkedPairProjection()
    ) {
    }

    /** @param list<array> $pairs */
    public function project(array $pairs): array
    {
        $repository = $this->configuration->mode === 'live'
            ? Identity::repository($this->configuration->repository) : null;
        $tenant = $this->configuration->mode === 'live'
            ? Identity::subdomain($this->configuration->subdomain) : null;
        $items = [];
        foreach ($this->projection->rows($pairs) as $issue => $row) {
            $subject = $row['subject'];
            if (
                $subject !== null && (!is_string($subject) || trim($subject) === ''
                || strlen($subject) > 500 || preg_match('//u', $subject) !== 1)
            ) {
                throw new RuntimeException('Linked pair table evidence is invalid.');
            }
            $values = $row['values'];
            if ($values['last_success_timestamp_seconds'] > intdiv(PHP_INT_MAX, 1000)) {
                throw new RuntimeException('Linked pair table evidence is invalid.');
            }
            $items[] = [
                'zendesk_ticket' => $row['ticket'],
                'github_issue' => (string) $issue,
                'subject' => $subject,
                'status' => $values['status'],
                'resolution' => $values['resolution'],
                'pending_plan' => $values['pending_plan'],
                'attempts' => $values['attempts'],
                'notes_delivered' => $values['notes_delivered'],
                'notes_waiting' => $values['notes_waiting'],
                'notes_to_github_delivered' => $values['notes_to_github_delivered'],
                'notes_to_github_waiting' => $values['notes_to_github_waiting'],
                'last_success_ms' => $values['last_success_timestamp_seconds'] * 1000,
                'zendesk_url' => $tenant === null ? null
                    : 'https://' . $tenant . '.zendesk.com/agent/tickets/' . $row['ticket'],
                'github_url' => $repository === null ? null
                    : 'https://github.com/' . $repository . '/issues/' . $issue,
            ];
        }
        return ['items' => $items];
    }
}
