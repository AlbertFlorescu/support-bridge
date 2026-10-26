<?php

declare(strict_types=1);

namespace Bridge;

/** A small decoded JSON response. Error bodies are deliberately never logged. */
final readonly class HttpResponse
{
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public int $status, array $headers = [], public array $body = [])
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = trim((string) $value);
        }
        $this->headers = $normalized;
    }

    public function assertSuccess(string $service): void
    {
        if ($this->status >= 200 && $this->status < 300) {
            return;
        }
        if (in_array($this->status, [409, 412], true)) {
            throw new RemoteConflict($service . ' record changed; fetch it again before reconciling.');
        }

        $delay = $this->retryDelay();
        $githubRateLimit = $service === 'GitHub' && $this->status === 403
            && (($this->headers['x-ratelimit-remaining'] ?? null) === '0'
                || $this->retryAfterDelay(time()) !== null
                || (is_string($this->body['message'] ?? null)
                    && str_contains(strtolower($this->body['message']), 'secondary rate limit')));
        $retryable = $githubRateLimit || in_array($this->status, [408, 429], true)
            || ($this->status >= 500 && $this->status <= 599);
        if ($service === 'GitHub' && ($githubRateLimit || $this->status === 429) && $delay === null) {
            $delay = 60; // GitHub's documented minimum for a secondary rate limit without guidance.
        }

        // Never include upstream bodies: they may contain ticket text or credentials.
        throw new RemoteFailure(
            $service . ' returned HTTP ' . $this->status . '.',
            $retryable,
            $retryable ? $delay : null
        );
    }

    /** Honor both limits if a server sends Retry-After and a primary-rate-limit reset. */
    public function retryDelay(?int $now = null): ?int
    {
        $now ??= time();
        $delays = [];
        $retryAfter = $this->retryAfterDelay($now);
        if ($retryAfter !== null) {
            $delays[] = $retryAfter;
        }
        $reset = $this->headers['x-ratelimit-reset'] ?? '';
        if (($this->headers['x-ratelimit-remaining'] ?? null) === '0' && ctype_digit($reset)) {
            $delays[] = max(0, (int) $reset - $now);
        }
        return $delays === [] ? null : max($delays);
    }

    private function retryAfterDelay(int $now): ?int
    {
        $value = $this->headers['retry-after'] ?? '';
        if (ctype_digit($value)) {
            // Limit to an addable integer even if an upstream sends an absurd delta.
            return min((int) $value, PHP_INT_MAX - max(0, $now));
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new \DateTimeZone('UTC'));
        return $date === false ? null : max(0, $date->getTimestamp() - $now);
    }
}
