<?php

declare(strict_types=1);

namespace Bridge;

use Bridge\Contract\PairRepository;
use Bridge\Domain\GovernancePolicy;
use Bridge\Domain\MergePolicy;
use Bridge\Domain\RetryPolicy;
use Bridge\Exception\StateConflict;
use Bridge\Exception\ValidationFailure;
use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Provider\Provider;

/** Application service: coordinate durable intent, remote writes, and recovery. */
final class Engine
{
    private readonly \Closure $clock;
    private readonly \Closure $jitter;
    private readonly \Closure $logger;
    /** @var array{github: Provider, zendesk: Provider} */
    private readonly array $providers;
    private readonly MergePolicy $mergePolicy;
    private readonly RetryPolicy $retryPolicy;
    private readonly GovernancePolicy $governancePolicy;

    public function __construct(
        private readonly PairRepository $store,
        Provider $github,
        Provider $zendesk,
        ?callable $logger = null,
        ?callable $clock = null,
        ?callable $jitter = null
    ) {
        $this->providers = [
            'github'  => $github,
            'zendesk' => $zendesk,
        ];
        $this->mergePolicy = new MergePolicy();
        $this->retryPolicy = new RetryPolicy();
        $this->governancePolicy = new GovernancePolicy();
        $this->clock = \Closure::fromCallable($clock ?? time(...));
        $this->jitter = \Closure::fromCallable($jitter ?? static fn (int $max): int => random_int(0, $max));
        $this->logger = \Closure::fromCallable($logger ?? new JsonLineWriter());
    }

    /** A bounded sweep; scheduling belongs to cron/systemd rather than an immortal PHP request. */
    public function run(): array
    {
        return $this->store->withExclusiveLock(function (): array {
            $counts = array_fill_keys(['synced', 'retry', 'conflict', 'dead', 'awaiting_approval', 'skipped'], 0);
            foreach ($this->store->all() as $pair) {
                if (!$this->isEligible($pair)) {
                    ++$counts['skipped'];
                    continue;
                }
                ++$counts[$this->processPair($pair)];
            }
            return $counts;
        });
    }

    public function resolve(string $id, string $source): void
    {
        if (!isset($this->providers[$source])) {
            throw new ValidationFailure('Resolution source must be github or zendesk.');
        }
        $this->store->withExclusiveLock(function () use ($id, $source): void {
            $pair = $this->store->get($id);
            if ($pair['status'] !== 'conflict') {
                throw new StateConflict('Only a quarantined conflict can be resolved.');
            }
            $records = $this->read($pair);
            if (
                $this->observedFields($records) !== $pair['conflict']['observed']
                || $this->observedVersions($records) !== $pair['conflict']['versions']
            ) {
                $this->quarantine($pair, $records, 'changed_since_conflict', MergePolicy::FIELDS);
                throw new StateConflict('Records changed since the conflict was inspected. ' .
                    'Inspect again before resolving.');
            }
            $plan = $this->createPlan($records[$source]->fields, $records);
            $this->store->event($id, 'resolution_selected', ['source' => $source]);
            $this->store->update($id, [
                'plan'     => $plan,
                'approval' => null,
                'status'   => 'new',
                'conflict' => null,
                'attempts' => 0,
                'next_run' => 0,
                'error'    => null,
            ]);
        });
    }

    /** Explicit operator attestation for one reviewed plan; this never performs a remote write. */
    public function approve(string $id, string $planHash, string $reason): void
    {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1 || trim($reason) === '' || strlen($reason) > 500
            || preg_match('//u', $reason) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason) === 1
        ) {
            throw new ValidationFailure('Approval requires the inspected plan hash and a ' .
                'nonempty UTF-8 rationale of at most 500 bytes.');
        }
        $this->store->withExclusiveLock(function () use ($id, $planHash, $reason): void {
            $pair = $this->store->get($id);
            $review = $this->governancePolicy->review($pair);
            if (
                $pair['status'] !== 'awaiting_approval' || !$review['requires_approval']
                || $review['plan_hash'] !== $planHash
            ) {
                throw new StateConflict('Inspect the current awaiting-approval plan before approving it.');
            }
            $records = $this->read($pair);
            if (
                $this->observedFields($records) !== $pair['plan']['before']
                || $this->observedVersions($records) !== $pair['plan']['versions']
            ) {
                $this->quarantine($pair, $records, 'changed_since_approval_request', MergePolicy::FIELDS);
                throw new StateConflict('Records changed since the approval plan was prepared. ' .
                    'Inspect and resolve the refreshed conflict.');
            }
            $this->assertWritable($records, $pair['plan']);
            $approval = [
                'policy_version' => GovernancePolicy::VERSION,
                'plan_hash'      => $planHash,
                'reason'         => $reason,
                'principal'      => 'shared-operator',
                'approved_at'    => ($this->clock)(),
            ];
            // Audit first: failure or death before authorization persistence cannot enable an effect.
            $this->store->event($id, 'approval_granted', $approval);
            $this->store->update($id, [
                'approval' => $approval,
                'status'   => 'new',
                'error'    => null,
                'attempts' => 0,
                'next_run' => 0,
            ]);
        });
    }

    public function retry(string $id): void
    {
        $this->store->withExclusiveLock(function () use ($id): void {
            $pair = $this->store->get($id);
            if (!in_array($pair['status'], ['dead', 'retry'], true)) {
                throw new StateConflict('Retry applies only to retry/dead pairs. Resolve conflicts explicitly.');
            }
            $this->store->event($id, 'retry_requested');
            $this->store->update($id, ['status' => 'new', 'attempts' => 0, 'next_run' => 0, 'error' => null]);
        });
    }

    private function isEligible(array $pair): bool
    {
        return !in_array($pair['status'], ['conflict', 'dead', 'awaiting_approval'], true)
            && $pair['next_run'] <= ($this->clock)();
    }

    /** Remote failures are isolated to a pair; storage/programming errors still fail the sweep visibly. */
    private function processPair(array $pair): string
    {
        $started = hrtime(true);
        try {
            $this->reconcile($pair);
        } catch (RemoteConflict) {
            $this->recordFailure($pair, new RemoteFailure('Concurrent remote update; inspect again on retry.', true));
        } catch (RemoteFailure $error) {
            $this->recordFailure($pair, $error);
        }
        $current = $this->store->get($pair['id']);
        ($this->logger)([
            'event'       => 'reconcile',
            'pair'        => $pair['id'],
            'status'      => $current['status'],
            'attempts'    => $current['attempts'],
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]);
        return $current['status'];
    }

    private function reconcile(array $pair): void
    {
        $records = $this->read($pair);
        $plan = $pair['plan'];
        if ($plan === null) {
            $decision = $this->mergePolicy->decide(
                $pair['baseline'],
                $records['github']->fields,
                $records['zendesk']->fields
            );
            if ($decision->hasConflict()) {
                $reason = $pair['baseline'] === null ? 'initial_mismatch' : 'divergent_edits';
                $this->quarantine($pair, $records, $reason, $decision->conflictingFields);
                return;
            }
            $target = $decision->target;
            if ($records['github']->fields === $target && $records['zendesk']->fields === $target) {
                $this->complete($pair['id'], $target);
                return;
            }
            $plan = $this->createPlan($target, $records);
            // This durable intent precedes every possible remote write, including explicit resolutions.
            $this->store->update($pair['id'], ['plan' => $plan, 'approval' => null]);
            $pair['plan'] = $plan;
            $pair['approval'] = null;
        } else {
            $this->governancePolicy->validatePlan($plan);
            if (!$this->isSafeToResume($records, $plan)) {
                $this->quarantine($pair, $records, 'changed_during_recovery', MergePolicy::FIELDS);
                return;
            }
        }

        // A fully achieved target needs acknowledgement, never permission for an absent effect.
        if ($records['github']->fields === $plan['target'] && $records['zendesk']->fields === $plan['target']) {
            $this->complete($pair['id'], $plan['target']);
            return;
        }
        $this->assertWritable($records, $plan);
        $review = $this->governancePolicy->review($pair);
        if ($review['requires_approval'] && !$review['approved']) {
            $this->store->update($pair['id'], [
                'status'   => 'awaiting_approval',
                'approval' => null,
                'error'    => 'state_change_requires_approval',
                'next_run' => 0,
            ]);
            $this->store->event($pair['id'], 'approval_requested', [
                'policy_version' => $review['policy_version'],
                'plan_hash'      => $review['plan_hash'],
            ]);
            return;
        }
        if ($review['approved'] && !$this->approvalSourcesUnchanged($records, $plan)) {
            $this->quarantine($pair, $records, 'changed_since_approval', MergePolicy::FIELDS);
            return;
        }
        $this->store->event($pair['id'], 'governance_allowed', [
            'policy_version' => $review['policy_version'],
            'plan_hash'      => $review['plan_hash'],
            'authorization'  => $review['requires_approval'] ? 'operator_approval' : 'linked_title_workflow',
        ]);
        $this->applyPlan($pair, $records, $plan);
        $verified = $this->read($pair);
        if ($verified['github']->fields !== $plan['target'] || $verified['zendesk']->fields !== $plan['target']) {
            $this->quarantine($pair, $verified, 'verification_mismatch', MergePolicy::FIELDS);
            return;
        }
        $this->complete($pair['id'], $plan['target']);
    }

    private function applyPlan(array $pair, array $records, array $plan): void
    {
        foreach ($this->providers as $side => $provider) {
            $changes = array_filter($plan['target'], static fn (
                $value,
                $key
            ): bool => $records[$side]->fields[$key] !== $value, ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $this->store->event($pair['id'], 'write_started', ['side' => $side, 'fields' => array_keys($changes)]);
                $provider->patch($pair[$side . '_id'], $changes, $records[$side]);
                $this->store->event($pair['id'], 'write_returned', ['side' => $side]);
            }
        }
    }

    private function complete(string $id, array $target): void
    {
        $this->store->update($id, [
            'baseline'     => $target,
            'plan'         => null,
            'conflict'     => null,
            'approval'     => null,
            'status'       => 'synced',
            'attempts'     => 0,
            'next_run'     => 0,
            'error'        => null,
            'last_success' => ($this->clock)(),
        ]);
        $this->store->event($id, 'converged');
    }

    private function read(array $pair): array
    {
        return [
            'github'  => $this->providers['github']->fetch($pair['github_id']),
            'zendesk' => $this->providers['zendesk']->fetch($pair['zendesk_id']),
        ];
    }

    private function observedFields(array $records): array
    {
        return array_map(static fn (Record $record): array => $record->fields, $records);
    }

    private function observedVersions(array $records): array
    {
        return array_map(static fn (Record $record): string => $record->version, $records);
    }

    /** Keep the durable wire shape stable so existing retry work survives code upgrades. */
    private function createPlan(array $target, array $records): array
    {
        return [
            'target'   => $target,
            'before'   => $this->observedFields($records),
            'versions' => $this->observedVersions($records),
        ];
    }

    /** Each field can still be its observed preimage or our intended value, never an unrelated edit. */
    private function isSafeToResume(array $records, array $plan): bool
    {
        foreach ($records as $side => $record) {
            // A revert to the preimage after a timed-out commit is still a new edit. Do not replay over it.
            // If the destination already equals the target, acknowledging it needs no write or version match.
            if ($record->fields !== $plan['target'] && $record->version !== $plan['versions'][$side]) {
                return false;
            }
            foreach ($plan['target'] as $field => $value) {
                if ($record->fields[$field] !== $value && $record->fields[$field] !== $plan['before'][$side][$field]) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Original sources are never written by this plan, so their version must remain reviewed. */
    private function approvalSourcesUnchanged(array $records, array $plan): bool
    {
        foreach ($records as $side => $record) {
            if ($plan['before'][$side] === $plan['target'] && $record->version !== $plan['versions'][$side]) {
                return false;
            }
        }
        return true;
    }

    /** Check every destination before any partial effect or request for an impossible approval. */
    private function assertWritable(array $records, array $plan): void
    {
        foreach ($records as $record) {
            if ($record->fields !== $plan['target'] && !$record->writable) {
                throw new RemoteFailure('Destination is immutable; closed Zendesk tickets cannot be edited.');
            }
        }
    }

    private function quarantine(array $pair, array $records, string $reason, array $fields): void
    {
        $this->store->update($pair['id'], [
            'status'   => 'conflict',
            'approval' => null,
            'conflict' => [
                'reason'   => $reason,
                'fields'   => $fields,
                'observed' => $this->observedFields($records),
                'versions' => $this->observedVersions($records),
            ],
            'error'    => $reason,
            'next_run' => 0,
        ]);
        $this->store->event($pair['id'], 'conflict', ['reason' => $reason, 'fields' => $fields]);
    }

    private function recordFailure(array $pair, RemoteFailure $error): void
    {
        $jitter = ($this->jitter)($this->retryPolicy->jitterLimit($pair['attempts']));
        $decision = $this->retryPolicy->afterFailure(
            $pair['attempts'],
            $error->retryable,
            $error->retryAfter,
            ($this->clock)(),
            $jitter
        );
        $this->store->update($pair['id'], [
            'status'   => $decision->status(),
            'attempts' => $decision->attempts,
            'next_run' => $decision->nextRun,
            'error'    => $error->getMessage(),
        ]);
        $this->store->event(
            $pair['id'],
            $decision->willRetry ? 'retry_scheduled' : 'dead',
            [
                'attempts'      => $decision->attempts,
                'delay_seconds' => $decision->delaySeconds,
                'reason'        => $error->getMessage(),
            ]
        );
    }
}
