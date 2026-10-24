<?php
declare(strict_types=1);

namespace Bridge\Domain;

/** Pure retry scheduling: randomness and time enter as values, never as side effects. */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 5;
    private const MAX_BACKOFF_SECONDS = 300;

    public function jitterLimit(int $previousAttempts): int
    {
        // Cap before exponentiation so corrupt/very old counters cannot overflow.
        return min(self::MAX_BACKOFF_SECONDS, 2 ** min(9, max(1, $previousAttempts + 1)));
    }

    public function afterFailure(int $previousAttempts, bool $retryable, ?int $retryAfter, int $now, int $jitter): RetryDecision
    {
        $attempts = $previousAttempts + 1;
        if (!$retryable || $attempts >= self::MAX_ATTEMPTS) {
            return new RetryDecision($attempts, false, 0, null);
        }
        $backoff = $this->jitterLimit($previousAttempts);
        $boundedJitter = max(0, min($backoff, $jitter));
        $delay = max($backoff + $boundedJitter, $retryAfter ?? 0);
        return new RetryDecision($attempts, true, $now + $delay, $delay);
    }
}
