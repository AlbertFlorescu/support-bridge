<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Record;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;

/** GitHub Issues only; pull requests are intentionally excluded. */
final class GitHub implements Provider
{
    private readonly string $repository;
    /** @var array<string, string> */
    private readonly array $headers;

    public function __construct(private readonly HttpClient $http, string $repository, string $token)
    {
        $repository = Identity::repository($repository);
        if (trim($token) === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('A valid GitHub token is required.');
        }
        $this->repository = $repository;
        $this->headers = [
            'Authorization'        => 'Bearer ' . $token,
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2026-03-10',
        ];
    }

    public function fetch(string $id): Record
    {
        return $this->record($this->http->request('GET', $this->url($id), $this->headers));
    }

    public function patch(string $id, array $changes, Record $expected): void
    {
        $this->validateChanges($changes, $expected);
        if ($changes === []) {
            return;
        }
        if (!$expected->writable) {
            throw new RemoteFailure('GitHub record is not writable.');
        }

        // GitHub documents conditional GETs, not an atomic CAS for Update an issue.
        // This preflight catches observed changes; a human can still edit between GET and PATCH.
        $fresh = $this->fetch($id);
        if ($fresh->fields !== $expected->fields || $fresh->version !== $expected->version) {
            throw new RemoteConflict('GitHub issue changed before the write.');
        }
        $payload = array_filter($changes, static fn (
            mixed $value,
            string $field
        ): bool => $value !== $fresh->fields[$field], ARRAY_FILTER_USE_BOTH);
        if ($payload !== []) {
            $this->record($this->http->request('PATCH', $this->url($id), $this->headers, $payload));
        }
    }

    private function url(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('GitHub issue number must be a positive integer.');
        }
        return 'https://api.github.com/repos/' . $this->repository . '/issues/' . $id;
    }

    private function record(HttpResponse $response): Record
    {
        $response->assertSuccess('GitHub');
        $body = $response->body;
        if (array_key_exists('pull_request', $body)) {
            throw new RemoteFailure('Pull requests are not supported; link a GitHub issue.');
        }
        if (
            !is_string($body['title'] ?? null) || !is_string($body['state'] ?? null)
            || !is_string($body['updated_at'] ?? null) || $body['updated_at'] === ''
        ) {
            throw new RemoteFailure('GitHub returned an invalid issue representation.');
        }
        try {
            return new Record(['title' => $body['title'], 'state' => $body['state']], $body['updated_at']);
        } catch (\InvalidArgumentException) {
            throw new RemoteFailure('GitHub returned an invalid issue representation.');
        }
    }

    private function validateChanges(array $changes, Record $expected): void
    {
        if (array_diff(array_keys($changes), ['title', 'state']) !== []) {
            throw new \InvalidArgumentException('Only title and state may be synchronized.');
        }
        new Record(array_replace($expected->fields, $changes), $expected->version);
    }
}
