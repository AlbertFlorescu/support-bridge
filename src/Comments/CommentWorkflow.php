<?php

declare(strict_types=1);

namespace Bridge\Comments;

use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Provider\Provider;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;
use Bridge\Store;
use Closure;

/** A committed append attempt is recovered by observation, never a blind second append. */
final readonly class CommentWorkflow
{
    private Closure $clock;

    public function __construct(
        private Store $store,
        private CommentRepository $comments,
        private CommentSource $source,
        private InternalNotes $notes,
        private Provider $github,
        private Provider $zendesk,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    public function run(): array
    {
        return $this->store->withExclusiveLock(function (): array {
            $outcome = ['discovered' => 0, 'delivered' => 0, 'waiting' => 0, 'unavailable' => 0,
                'held' => $this->comments->held()];
            if ($outcome['held']) {
                return $outcome;
            }
            foreach ($this->store->all() as $pair) {
                if ((int) $this->comments->stream($pair['id'])['next_run'] > ($this->clock)()) {
                    continue;
                }
                try {
                    $source = $this->source->list($pair['github_id']);
                } catch (RemoteFailure $error) {
                    $this->comments->scan(
                        $pair['id'],
                        ($this->clock)(),
                        'GitHub comment discovery is unavailable or incomplete.',
                        $this->due($error)
                    );
                    ++$outcome['unavailable'];
                    continue;
                }
                foreach ($source as $comment) {
                    $outcome['discovered'] += (int) $this->comments->prepare($pair['id'], $comment);
                }
                $this->comments->scan($pair['id'], ($this->clock)(), null, 0);
            }
            foreach ([true, false] as $recovery) {
                foreach ($this->comments->ready($recovery, ($this->clock)()) as $delivery) {
                    $this->process($delivery, $outcome);
                    if ($outcome['held']) {
                        break 2;
                    }
                }
            }
            return $outcome;
        });
    }

    private function process(array $delivery, array &$outcome): void
    {
        $pair = $this->store->get($delivery['pair_id']);
        try {
            $found = $this->notes->find($pair['zendesk_id'], $delivery['marker']);
            if ($found !== null) {
                $this->delivered($delivery, $found, $outcome);
                return;
            }
            if ($delivery['attempted_at'] !== null) {
                $this->defer($delivery, 'uncertain', 'Note outcome is unconfirmed; no append was repeated.');
                ++$outcome['waiting'];
                return;
            }
            if (
                $pair['status'] !== 'synced' || $pair['plan'] !== null || $pair['conflict'] !== null
                || $pair['approval'] !== null || $pair['baseline'] === null
            ) {
                $this->defer($delivery, 'pending', 'Waiting for a converged pair without pending review work.');
                ++$outcome['waiting'];
                return;
            }
            $github = $this->github->fetch($pair['github_id']);
            $zendesk = $this->zendesk->fetch($pair['zendesk_id']);
            if ($github->fields !== $pair['baseline'] || $zendesk->fields !== $pair['baseline']) {
                $this->defer($delivery, 'pending', 'Fresh canonical fields differ; reconciliation must run first.');
                ++$outcome['waiting'];
                return;
            }
            if (!$zendesk->writable) {
                $this->comments->change($delivery, ['status' => 'blocked',
                    'error' => 'The Zendesk ticket is immutable; no follow-up was created.'], 'comment_blocked');
                ++$outcome['waiting'];
                return;
            }
        } catch (UnsafeNoteEvidence) {
            $this->privacyHold($delivery, $outcome);
            return;
        } catch (RemoteFailure $error) {
            $this->defer(
                $delivery,
                $delivery['attempted_at'] === null ? 'pending' : 'uncertain',
                'Note preflight or recovery lookup is unavailable.',
                $this->due($error)
            );
            ++$outcome['unavailable'];
            return;
        }

        $this->comments->change($delivery, ['status' => 'sending', 'attempted_at' => ($this->clock)(),
            'destination_version' => $zendesk->version, 'error' => null], 'comment_send_started');
        try {
            $this->notes->append($pair['zendesk_id'], $delivery['note'], $zendesk->version);
            $found = $this->notes->find($pair['zendesk_id'], $delivery['marker']);
            if ($found === null) {
                $this->defer($delivery, 'uncertain', 'Zendesk has not exposed the delivered internal note yet.');
                ++$outcome['waiting'];
                return;
            }
        } catch (UnsafeNoteEvidence) {
            $this->privacyHold($delivery, $outcome);
            return;
        } catch (RemoteFailure | RemoteConflict $error) {
            $this->defer(
                $delivery,
                'uncertain',
                'Append outcome is unconfirmed; recovery will only search for its marker.',
                $error instanceof RemoteFailure ? $this->due($error) : null
            );
            ++$outcome['waiting'];
            return;
        }
        $this->delivered($delivery, $found, $outcome);
    }

    private function delivered(array $delivery, string $noteId, array &$outcome): void
    {
        $this->comments->change($delivery, ['status' => 'delivered', 'zendesk_comment_id' => $noteId,
            'error' => null], 'comment_delivered');
        ++$outcome['delivered'];
    }

    private function privacyHold(array $delivery, array &$outcome): void
    {
        $this->comments->hold($delivery);
        $outcome['held'] = true;
        ++$outcome['waiting'];
    }

    private function defer(array $delivery, string $status, string $error, ?int $next = null): void
    {
        $this->comments->change($delivery, ['status' => $status, 'error' => $error,
            'next_run' => $next ?? (($this->clock)() + 60)], 'comment_deferred');
    }

    private function due(RemoteFailure $error): int
    {
        $now = ($this->clock)();
        return $now + min(PHP_INT_MAX - $now, max(60, $error->retryAfter ?? 0));
    }
}
