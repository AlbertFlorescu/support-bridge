<?php

declare(strict_types=1);

namespace Bridge\Domain;

use Bridge\Exception\ValidationFailure;

/** Fixed operational controls; neither model output nor a markdown skill can override them. */
final class GovernancePolicy
{
    public const VERSION = 'escalation-state-v1';

    /** Inspection is pure: no network, credential lookup, clock or approval side effect. */
    public function review(array $pair): array
    {
        $plan = $pair['plan'];
        if ($plan === null) {
            return [
                'policy_version'    => self::VERSION,
                'plan_hash'         => null,
                'requires_approval' => false,
                'approved'          => false,
            ];
        }
        $this->validatePlan($plan);
        // The approval is specific to this linked identity and policy, not merely a target value.
        $hash = hash('sha256', json_encode([
            self::VERSION,
            $pair['id'],
            $pair['github_id'],
            $pair['zendesk_id'],
            $plan['target'],
            $plan['before'],
            $plan['versions'],
        ], JSON_THROW_ON_ERROR));
        $required = false;
        foreach (['github', 'zendesk'] as $side) {
            $required = $required || $plan['before'][$side]['state'] !== $plan['target']['state'];
        }
        $approval = $pair['approval'] ?? null;
        $approved = is_array($approval) && ($approval['policy_version'] ?? null) === self::VERSION
            && ($approval['plan_hash'] ?? null) === $hash && ($approval['principal'] ?? null) === 'shared-operator'
            && is_string($approval['reason'] ?? null) && trim($approval['reason']) !== ''
            && strlen($approval['reason']) <= 500 && preg_match('//u', $approval['reason']) === 1
            && is_int($approval['approved_at'] ?? null) && $approval['approved_at'] >= 0;
        return [
            'policy_version'    => self::VERSION,
            'plan_hash'         => $hash,
            'requires_approval' => $required,
            'approved'          => $approved,
        ];
    }

    /** A capability allowlist enforced in code before any provider effect, including legacy plans. */
    public function validatePlan(array $plan): void
    {
        if (
            array_keys($plan) !== [
                'target',
                'before',
                'versions',
            ]
            || !is_array($plan['target']) || !is_array($plan['before']) || !is_array($plan['versions'])
            || array_keys($plan['before']) !== [
                'github',
                'zendesk',
            ]
                || array_keys($plan['versions']) !== [
                    'github',
                    'zendesk',
                ]
        ) {
            throw new ValidationFailure('The persisted write plan is outside the allowed workflow contract.');
        }
        foreach ([$plan['target'], $plan['before']['github'], $plan['before']['zendesk']] as $fields) {
            if (
                !is_array($fields) || array_keys($fields) !== [
                    'title',
                    'state',
                ]
                || !is_string($fields['title']) || trim($fields['title']) === '' || strlen($fields['title']) > 500
                || preg_match('//u', $fields['title']) !== 1 || !in_array($fields['state'], ['open', 'closed'], true)
            ) {
                throw new ValidationFailure('Only a valid title and open/closed state may cross the write boundary.');
            }
        }
        foreach ($plan['versions'] as $version) {
            if (!is_string($version) || $version === '') {
                throw new ValidationFailure('The write plan requires both observed versions.');
            }
        }
    }
}
