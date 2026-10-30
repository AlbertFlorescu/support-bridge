<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Comments\InternalNotes;
use Bridge\Comments\UnsafeNoteEvidence;
use Bridge\HttpClient;
use Bridge\RemoteFailure;
use InvalidArgumentException;

/** A separate append capability; ordinary title/state patches cannot publish comments. */
final class ZendeskInternalNotes implements InternalNotes
{
    private readonly string $subdomain;
    private array $comments = [];

    public function __construct(
        private readonly HttpClient $http,
        string $subdomain,
        private readonly ZendeskAuthorization $authorization
    ) {
        $this->subdomain = Identity::subdomain($subdomain);
    }

    public function find(string $ticketId, string $marker): ?string
    {
        if (!preg_match('/^Support Bridge comment: [a-f0-9]{64}$/D', $marker)) {
            throw new InvalidArgumentException('Invalid comment delivery marker.');
        }
        $matches = [];
        foreach ($this->listing($ticketId) as $comment) {
            $body = str_replace("\r\n", "\n", $comment['plain_body'] ?? $comment['body']);
            if ($body === $marker || str_starts_with($body, $marker . "\n")) {
                if ($comment['public'] !== false) {
                    throw new UnsafeNoteEvidence('A delivery marker was observed on a public Zendesk comment.');
                }
                $matches[] = (string) $comment['id'];
            }
        }
        if (count($matches) > 1) {
            throw new RemoteFailure('Several Zendesk notes carry the delivery marker.');
        }
        return $matches[0] ?? null;
    }

    public function append(string $ticketId, string $body, string $expectedVersion): void
    {
        if (
            strlen($body) > 62_000 || !preg_match('//u', $body) || $expectedVersion === ''
            || !preg_match('/^Support Bridge comment: [a-f0-9]{64}\n/', $body)
        ) {
            throw new InvalidArgumentException('Invalid internal note or expected ticket version.');
        }
        // A failed or lost response must not leave an old absence result in this process.
        unset($this->comments[$ticketId]);
        $response = $this->http->request('PUT', $this->url($ticketId), $this->headers(), ['ticket' => [
            'comment' => ['body' => $body, 'public' => false],
            'safe_update' => true, 'updated_stamp' => $expectedVersion,
        ]]);
        $response->assertSuccess('Zendesk');
        if ($response->status !== 200 || (string) ($response->body['ticket']['id'] ?? '') !== $ticketId) {
            throw new RemoteFailure('Zendesk did not confirm the intended note update.');
        }
    }

    private function listing(string $ticketId): array
    {
        if (isset($this->comments[$ticketId])) {
            return $this->comments[$ticketId];
        }
        $comments = [];
        for ($page = 1; $page <= 5; ++$page) {
            $url = substr($this->url($ticketId), 0, -5) . '/comments.json?per_page=100&page=' . $page;
            $response = $this->http->request('GET', $url, $this->headers());
            $response->assertSuccess('Zendesk');
            if (
                !is_array($response->body['comments'] ?? null) || !array_is_list($response->body['comments'])
                || count($response->body['comments']) > 100 || !array_key_exists('next_page', $response->body)
                || (!is_string($response->body['next_page']) && $response->body['next_page'] !== null)
            ) {
                throw new RemoteFailure('Zendesk returned an invalid note listing.');
            }
            foreach ($response->body['comments'] as $comment) {
                if (
                    !is_array($comment) || (!is_int($comment['id'] ?? null) && !is_string($comment['id'] ?? null))
                    || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $comment['id'])
                    || !is_bool($comment['public'] ?? null) || !is_string($comment['body'] ?? null)
                    || (isset($comment['plain_body']) && !is_string($comment['plain_body']))
                ) {
                    throw new RemoteFailure('Zendesk returned an invalid ticket comment.');
                }
                $comments[] = $comment;
            }
            if (empty($response->body['next_page'])) {
                return $this->comments[$ticketId] = $comments;
            }
        }
        throw new RemoteFailure('Zendesk note lookup exceeded its five-page bound.', true, 60);
    }

    private function url(string $ticketId): string
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $ticketId)) {
            throw new InvalidArgumentException('Invalid Zendesk ticket ID.');
        }
        return 'https://' . $this->subdomain . '.zendesk.com/api/v2/tickets/' . $ticketId . '.json';
    }

    private function headers(): array
    {
        return ['Authorization' => $this->authorization->authorizationHeader(), 'Accept' => 'application/json'];
    }
}
