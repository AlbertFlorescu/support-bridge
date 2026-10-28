<?php

declare(strict_types=1);

namespace Bridge\Simulation\Replay;

use Bridge\Domain\GovernancePolicy;

/** Only workflow evidence crosses into a portable replay; credentials and free-form audit text do not. */
final class EvidenceSanitizer
{
    private const REASONS = [
        'initial_mismatch', 'divergent_edits', 'changed_since_conflict', 'changed_since_approval_request',
        'changed_during_recovery', 'changed_since_approval', 'verification_mismatch',
        'state_change_requires_approval',
    ];

    public function pair(array $pair): array
    {
        $result = array_intersect_key($pair, array_flip([
            'id', 'github_id', 'zendesk_id', 'status', 'attempts', 'next_run',
        ]));
        $error = $pair['error'] ?? null;
        $result['error'] = $error === null ? null : ($this->code($error) ?? 'details_omitted');
        $result['baseline'] = $this->fields($pair['baseline'] ?? null);
        $plan = $pair['plan'] ?? null;
        $result['plan'] = $plan === null ? null : [
            'target' => $this->fields($plan['target']),
            'before' => $this->sides($plan['before']),
            'versions' => $this->versions($plan['versions']),
        ];
        $conflict = $pair['conflict'] ?? null;
        $result['conflict'] = $conflict === null ? null : [
            'reason' => $this->code($conflict['reason'] ?? null),
            'fields' => array_values(array_intersect($conflict['fields'] ?? [], ['title', 'state'])),
            'observed' => $this->sides($conflict['observed']),
            'versions' => $this->versions($conflict['versions']),
        ];
        $approval = $pair['approval'] ?? null;
        $result['approval'] = $approval === null ? null : array_intersect_key($approval, array_flip([
            'plan_hash', 'policy_version', 'principal', 'approved_at',
        ]));
        return $result;
    }

    public function events(array $events): array
    {
        $result = [];
        // Store events arrive newest first by insertion ID. Reverse that order before
        // discarding IDs so events within the same timestamp retain their causal order.
        foreach (array_reverse(array_slice($events, 0, 30)) as $event) {
            $details = $event['details'] ?? [];
            if (is_string($details)) {
                $details = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
            }
            $details = is_array($details) ? $details : [];
            $allowed = [];
            foreach (['side', 'source'] as $key) {
                if (in_array($details[$key] ?? null, ['github', 'zendesk'], true)) {
                    $allowed[$key] = $details[$key];
                }
            }
            if (is_array($details['fields'] ?? null)) {
                $allowed['fields'] = array_values(array_intersect($details['fields'], ['title', 'state']));
            }
            if (is_string($details['plan_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $details['plan_hash'])) {
                $allowed['plan_hash'] = $details['plan_hash'];
            }
            if (($details['policy_version'] ?? null) === GovernancePolicy::VERSION) {
                $allowed['policy_version'] = GovernancePolicy::VERSION;
            }
            if (in_array($details['authorization'] ?? null, ['operator_approval', 'linked_title_workflow'], true)) {
                $allowed['authorization'] = $details['authorization'];
            }
            foreach (['attempts', 'next_run', 'delay_seconds'] as $key) {
                if (is_int($details[$key] ?? null) && $details[$key] >= 0) {
                    $allowed[$key] = $details[$key];
                }
            }
            if (isset($details['reason']) && $this->code($details['reason']) !== null) {
                $allowed['reason'] = $details['reason'];
            }
            $result[] = [
                'type' => $event['type'],
                'created_at' => $event['created_at'],
                'details' => $allowed,
            ];
        }
        return $result;
    }

    private function fields(?array $fields): ?array
    {
        return $fields === null ? null : array_intersect_key($fields, ['title' => true, 'state' => true]);
    }

    private function sides(array $sides): array
    {
        return ['github' => $this->fields($sides['github']), 'zendesk' => $this->fields($sides['zendesk'])];
    }

    private function versions(array $versions): array
    {
        return array_intersect_key($versions, ['github' => true, 'zendesk' => true]);
    }

    private function code(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::REASONS, true) ? $value : null;
    }
}
