<?php

declare(strict_types=1);

namespace Bridge\Comments;

use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Provider\Provider;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;
use Bridge\Store;
use Closure;
use InvalidArgumentException;

/** Both channels persist before effects and recover an append by observation, never resubmission. */
final readonly class CommentWorkflow
{
    private Closure $clock;
    private CommentDirection $direction;
    private CommentOrigin $origin;

    public function __construct(
        private Store $store,
        private CommentRepository $comments,
        private CommentSource $source,
        private CommentDestination $destination,
        private Provider $github,
        private Provider $zendesk,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
        $this->direction = $comments->direction;
        $this->origin = new CommentOrigin($comments);
        if ($this->direction === CommentDirection::ZendeskToGitHub && !$source instanceof InternalNoteSource) {
            throw new InvalidArgumentException('Reverse delivery needs a verifiable internal-note source.');
        }
    }

    public function run(): array
    {
        return $this->store->withExclusiveLock(function (): array {
            $outcome = ['discovered' => 0, 'delivered' => 0, 'waiting' => 0, 'unavailable' => 0,
                'held' => $this->comments->held()];
            if ($this->direction === CommentDirection::ZendeskToGitHub) {
                $outcome['initialized'] = 0;
            }
            if ($outcome['held']) {
                return $outcome;
            }
            foreach ($this->store->all() as $pair) {
                $stream = $this->comments->stream($pair['id']);
                if ((int) $stream['next_run'] > ($this->clock)()) {
                    continue;
                }
                try {
                    $recordId = $pair[$this->direction->sourceField()];
                    if ($this->source instanceof InternalNoteSource && $stream['initialized_at'] === null) {
                        $this->comments->initialize(
                            $pair['id'],
                            $this->source->baselineIds($recordId),
                            ($this->clock)()
                        );
                        $this->comments->scan($pair['id'], ($this->clock)(), null, 0);
                        ++$outcome['initialized'];
                        continue;
                    }
                    $eligible = [];
                    $observed = $this->source instanceof InternalNoteSource
                        ? $this->source->listNew($recordId, $this->comments->excludedIds($pair['id']))
                        : $this->source->list($recordId);
                    foreach ($observed as $comment) {
                        if (!$this->origin->mirrored($pair['id'], $comment)) {
                            try {
                                $comment->note($this->direction->markerPrefix() . str_repeat('a', 64));
                            } catch (InvalidArgumentException) {
                                throw new RemoteFailure('The formatted source comment exceeds supported bounds.');
                            }
                            $eligible[] = $comment;
                        }
                    }
                } catch (RemoteFailure $error) {
                    $this->comments->scan(
                        $pair['id'],
                        ($this->clock)(),
                        'Comment discovery is unavailable, ambiguous or incomplete.',
                        $this->due($error)
                    );
                    ++$outcome['unavailable'];
                    continue;
                }
                foreach ($eligible as $comment) {
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
        $destinationId = $pair[$this->direction->destinationField()];
        try {
            $found = $this->destination->find($destinationId, $delivery['marker']);
            if ($found !== null) {
                $this->delivered($delivery, $found, $outcome);
                return;
            }
            if ($delivery['attempted_at'] !== null) {
                $this->defer($delivery, 'uncertain', 'Comment outcome is unconfirmed; no append was repeated.');
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
            $target = $this->direction === CommentDirection::GitHubToZendesk ? $zendesk : $github;
            if (!$target->writable) {
                $this->comments->change($delivery, ['status' => 'blocked',
                    'error' => 'The destination is immutable; no follow-up was created.'], 'blocked');
                ++$outcome['waiting'];
                return;
            }
            if ($this->source instanceof InternalNoteSource) {
                $current = $this->source->current($pair['zendesk_id'], $delivery['comment_id']);
                if ($current === null || $current->note($delivery['marker']) !== $delivery['note']) {
                    $this->comments->change(
                        $delivery,
                        ['status' => 'blocked',
                        'error' => 'The source note changed or is no longer eligible; the snapshot was not replaced.'],
                        'blocked'
                    );
                    ++$outcome['waiting'];
                    return;
                }
            }
        } catch (UnsafeNoteEvidence) {
            $this->privacyHold($delivery, $outcome);
            return;
        } catch (RemoteFailure $error) {
            $this->defer(
                $delivery,
                $delivery['attempted_at'] === null ? 'pending' : 'uncertain',
                'Comment preflight or recovery lookup is unavailable.',
                $this->due($error)
            );
            ++$outcome['unavailable'];
            return;
        }

        $this->comments->change($delivery, ['status' => 'sending', 'attempted_at' => ($this->clock)(),
            'destination_version' => $target->version, 'error' => null], 'send_started');
        try {
            $this->destination->append($destinationId, $delivery['note'], $target->version);
            $found = $this->destination->find($destinationId, $delivery['marker']);
            if ($found === null) {
                $this->defer($delivery, 'uncertain', 'The destination has not exposed the delivered comment yet.');
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

    private function delivered(array $delivery, string $commentId, array &$outcome): void
    {
        $this->comments->change($delivery, ['status' => 'delivered',
            $this->direction->destinationColumn() => $commentId, 'error' => null], 'delivered');
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
            'next_run' => $next ?? (($this->clock)() + 60)], 'deferred');
    }

    private function due(RemoteFailure $error): int
    {
        $now = ($this->clock)();
        return $now + min(PHP_INT_MAX - $now, max(60, $error->retryAfter ?? 0));
    }
}
