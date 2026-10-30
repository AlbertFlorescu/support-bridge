<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Record;
use Bridge\RemoteFailure;

/** Zendesk Support tickets, with safe updates and a deliberately lossy common state. */
final class Zendesk implements Provider
{
    private readonly string $subdomain;
    private readonly ZendeskAuthorization $authorization;

    public function __construct(
        private readonly HttpClient $http,
        string $subdomain,
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string|ZendeskAuthorization $token,
        string $authentication = 'api-token'
    ) {
        $this->subdomain = Identity::subdomain($subdomain);
        if ($token instanceof ZendeskAuthorization && $authentication !== 'oauth') {
            throw new \InvalidArgumentException('Managed Zendesk credentials require OAuth authentication.');
        }
        $this->authorization = $token instanceof ZendeskAuthorization
            ? $token
            : new ZendeskAuthentication($email, $token, $authentication);
    }

    public function fetch(string $id): Record
    {
        return $this->record($this->http->request('GET', $this->url($id), $this->headers()));
    }

    public function patch(string $id, array $changes, Record $expected): void
    {
        if (array_diff(array_keys($changes), ['title', 'state']) !== []) {
            throw new \InvalidArgumentException('Only title and state may be synchronized.');
        }
        new Record(array_replace($expected->fields, $changes), $expected->version);
        if ($changes === []) {
            return;
        }
        if (!$expected->writable) {
            throw new RemoteFailure('Closed Zendesk tickets cannot be updated; link a follow-up ticket.');
        }
        if ($expected->version === '') {
            throw new \InvalidArgumentException('Zendesk safe updates require an observed updated_at timestamp.');
        }
        $ticket = [
            'safe_update'   => true,
            'updated_stamp' => $expected->version,
        ];
        if (isset($changes['title']) && $changes['title'] !== $expected->fields['title']) {
            $ticket['subject'] = $changes['title'];
        }
        if (isset($changes['state']) && $changes['state'] !== $expected->fields['state']) {
            $ticket['status'] = $changes['state'] === 'closed' ? 'solved' : 'open';
        }
        if (count($ticket) > 2) {
            // Omitting status on a subject-only update preserves new/pending/hold/custom statuses.
            $this->record($this->http->request('PUT', $this->url($id), $this->headers(), ['ticket' => $ticket]));
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Authorization' => $this->authorization->authorizationHeader(),
            'Accept' => 'application/json',
        ];
    }

    private function url(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('Zendesk ticket ID must be a positive integer.');
        }
        return 'https://' . $this->subdomain . '.zendesk.com/api/v2/tickets/' . $id . '.json';
    }

    private function record(HttpResponse $response): Record
    {
        $response->assertSuccess('Zendesk');
        $ticket = $response->body['ticket'] ?? null;
        if (
            !is_array($ticket) || !is_string($ticket['subject'] ?? null)
            || !is_string($ticket['updated_at'] ?? null) || $ticket['updated_at'] === ''
            || !in_array($ticket['status'] ?? null, ['new', 'open', 'pending', 'hold', 'solved', 'closed'], true)
        ) {
            throw new RemoteFailure('Zendesk returned an invalid ticket representation.');
        }
        $state = in_array($ticket['status'], ['solved', 'closed'], true) ? 'closed' : 'open';
        try {
            return new Record(
                [
                    'title' => $ticket['subject'],
                    'state' => $state,
                ],
                $ticket['updated_at'],
                $ticket['status'] !== 'closed'
            );
        } catch (\InvalidArgumentException) {
            throw new RemoteFailure('Zendesk returned an invalid ticket representation.');
        }
    }
}
