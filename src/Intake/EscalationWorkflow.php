<?php

declare(strict_types=1);

namespace Bridge\Intake;

use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Infrastructure\Persistence\EscalationRepository;
use Bridge\RemoteFailure;
use Bridge\Store;
use Closure;
use InvalidArgumentException;

/** Explicit-tag intake is deterministic. Unknown creation outcomes never authorize another POST. */
final readonly class EscalationWorkflow
{
    private Closure $clock;

    public function __construct(
        private Store $store,
        private EscalationRepository $escalations,
        private TicketSource $source,
        private EngineeringIssues $issues,
        private string $tag,
        ?callable $clock = null
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $tag)) {
            throw new InvalidArgumentException('Invalid engineering escalation tag.');
        }
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    public function run(): array
    {
        return $this->store->withExclusiveLock(function (): array {
            $outcome = ['discovered' => 0, 'linked' => 0, 'waiting' => 0, 'skipped' => 0,
                'truncated' => false, 'deferred' => false];
            if ($this->escalations->pauseUntil() > ($this->clock)()) {
                $outcome['deferred'] = true;
                return $outcome;
            }
            $page = $this->escalations->page();
            try {
                $discovery = $this->source->discover($this->tag, $page);
            } catch (RemoteFailure $error) {
                $this->escalations->pauseUntil($this->retryAt($error));
                throw $error;
            }
            $outcome['truncated'] = $page === 20 && $discovery['has_more'];
            foreach ($discovery['tickets'] as $ticket) {
                if ($ticket->eligible($this->tag) && $this->store->findByZendesk($ticket->id) === null) {
                    $outcome['discovered'] += (int) $this->escalations->prepare($ticket);
                }
            }
            $this->escalations->page($discovery['has_more'] && $page < 20 ? $page + 1 : 1);
            foreach ([true, false] as $recovery) {
                foreach ($this->escalations->ready($recovery, ($this->clock)()) as $intent) {
                    $this->process($intent, $outcome);
                    if ($outcome['deferred']) {
                        break 2;
                    }
                }
            }
            return $outcome;
        });
    }

    private function process(array $intent, array &$outcome): void
    {
        $ticketId = $intent['ticket_id'];
        $existing = $this->store->findByZendesk($ticketId);
        if ($existing !== null) {
            if ($intent['github_id'] !== null && $intent['github_id'] !== $existing['github_id']) {
                $this->escalations->change($ticketId, ['status' => 'blocked',
                    'error' => 'The ticket was linked to another engineering issue.'], 'escalation_blocked');
                ++$outcome['waiting'];
                return;
            }
            $this->escalations->change($ticketId, ['status' => 'linked',
                'github_id' => $existing['github_id'], 'error' => null], 'escalation_link_recovered');
            ++$outcome['linked'];
            return;
        }
        if ($intent['github_id'] !== null) {
            $this->link($intent, $intent['github_id'], $outcome);
            return;
        }
        try {
            $found = $this->issues->find($intent['marker']);
            if ($found !== null) {
                $this->rememberAndLink($intent, $found, $outcome);
                return;
            }
            if ($intent['attempted_at'] !== null) {
                $this->wait($intent, 'Issue creation is unconfirmed; no additional create request was sent.');
                ++$outcome['waiting'];
                return;
            }
            $ticket = $this->source->fetch($ticketId);
            if ($ticket->id !== $ticketId) {
                throw new RemoteFailure('The escalation source returned another ticket.');
            }
            if (!$ticket->eligible($this->tag)) {
                $this->escalations->change(
                    $ticketId,
                    ['status' => 'withdrawn', 'error' => null],
                    'escalation_withdrawn'
                );
                ++$outcome['skipped'];
                return;
            }
            if ($ticket->description === null) {
                throw new RemoteFailure('The initial Zendesk description must be read before issue creation.');
            }
            try {
                $this->store->get($intent['pair_id']);
                $this->escalations->change($ticketId, ['status' => 'blocked',
                    'error' => 'The generated pair name is already in use.'], 'escalation_blocked');
                ++$outcome['waiting'];
                return;
            } catch (NotFound) {
                // The canonical lock prevents another local link until this operation finishes.
            }
        } catch (RemoteFailure $error) {
            $next = $this->retryAt($error);
            $this->escalations->pauseUntil($next);
            $this->escalations->change($ticketId, [
                'error' => 'Escalation preflight is unavailable; no create request was sent.',
                'next_run' => $next,
            ], 'escalation_preflight_failed');
            $outcome['deferred'] = true;
            ++$outcome['waiting'];
            return;
        }

        // Audit and durable evidence commit before the first potentially consequential request.
        $this->escalations->change($ticketId, [
            'status' => 'creating', 'snapshot' => $ticket->snapshot(),
            'attempted_at' => ($this->clock)(), 'error' => null,
        ], 'escalation_create_started');
        try {
            $githubId = $this->issues->create($ticket, $intent['marker']);
        } catch (RemoteFailure $error) {
            $next = $this->retryAt($error);
            $this->wait(
                $intent,
                'GitHub creation outcome is uncertain; recovery will search before any decision.',
                $next
            );
            $this->escalations->pauseUntil($next);
            $outcome['deferred'] = true;
            ++$outcome['waiting'];
            return;
        }
        $this->rememberAndLink($intent, $githubId, $outcome);
    }

    private function rememberAndLink(array $intent, string $githubId, array &$outcome): void
    {
        $this->escalations->change(
            $intent['ticket_id'],
            ['github_id' => $githubId, 'error' => null],
            'escalation_issue_observed'
        );
        $this->link($intent, $githubId, $outcome);
    }

    private function link(array $intent, string $githubId, array &$outcome): void
    {
        try {
            $this->store->addPair($intent['pair_id'], $githubId, $intent['ticket_id']);
        } catch (StateConflict) {
            $this->escalations->change(
                $intent['ticket_id'],
                ['status' => 'blocked',
                'github_id' => $githubId, 'error' => 'The observed issue or generated pair is already linked.'],
                'escalation_blocked'
            );
            ++$outcome['waiting'];
            return;
        }
        $this->escalations->change($intent['ticket_id'], ['status' => 'linked',
            'github_id' => $githubId, 'error' => null], 'escalation_linked');
        ++$outcome['linked'];
    }

    private function wait(array $intent, string $error, ?int $next = null): void
    {
        $this->escalations->change($intent['ticket_id'], [
            'status' => 'uncertain', 'error' => $error, 'next_run' => $next ?? (($this->clock)() + 60),
        ], 'escalation_creation_unconfirmed');
    }

    private function retryAt(RemoteFailure $error): int
    {
        $now = ($this->clock)();
        return $now + min(PHP_INT_MAX - $now, max(60, $error->retryAfter ?? 0));
    }
}
