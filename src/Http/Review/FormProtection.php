<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

use Bridge\Http\HttpFailure;

/** Short-lived form proof binds session, action, exact pair and reviewed evidence without storing a new session. */
final class FormProtection
{
    private readonly \Closure $clock;

    public function __construct(private readonly string $secret, ?callable $clock = null)
    {
        if (strlen($secret) < 32 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
            throw new HttpFailure(503, 'review_not_configured', 'Browser review is not configured.');
        }
        $this->clock = \Closure::fromCallable($clock ?? time(...));
    }

    public function issue(string $cookie, string $pair, string $hash, string $action = 'approve'): string
    {
        $prefix = (($this->clock)() + 600) . '.' . bin2hex(random_bytes(16));
        return $prefix . '.' . $this->signature($prefix, $cookie, $pair, $hash, $action);
    }

    public function verify(string $proof, string $cookie, string $pair, string $hash, string $action = 'approve'): void
    {
        $parts = explode('.', $proof);
        $now = ($this->clock)();
        if (
            count($parts) !== 3 || preg_match('/^[0-9]{1,12}$/D', $parts[0]) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $parts[1]) !== 1
            || (int) $parts[0] < $now || (int) $parts[0] > $now + 600
            || !hash_equals($this->signature($parts[0] . '.' . $parts[1], $cookie, $pair, $hash, $action), $parts[2])
        ) {
            throw new HttpFailure(
                403,
                'invalid_review_form',
                'This review form expired or changed. Refresh and review again.'
            );
        }
    }

    private function signature(string $prefix, string $cookie, string $pair, string $hash, string $action): string
    {
        return hash_hmac('sha256', json_encode([
            'browser-review-v1', $prefix, hash('sha256', $cookie), $pair, $hash, $action,
        ], JSON_THROW_ON_ERROR), $this->secret);
    }
}
