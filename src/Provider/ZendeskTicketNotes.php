<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Comments\InternalNoteSource;
use Bridge\Comments\SourceComment;
use Bridge\Comments\ZendeskNote;
use Bridge\HttpClient;
use Bridge\RemoteFailure;
use InvalidArgumentException;

final readonly class ZendeskTicketNotes implements InternalNoteSource
{
    private string $subdomain;
    private ZendeskCommentHistory $history;

    public function __construct(HttpClient $http, string $subdomain, ZendeskAuthorization $authorization)
    {
        $this->subdomain = Identity::subdomain($subdomain);
        $this->history = new ZendeskCommentHistory($http, $subdomain, $authorization);
    }

    public function baselineIds(string $ticketId): array
    {
        return array_map(
            static fn(array $comment): string => (string) $comment['id'],
            $this->read($ticketId)['comments']
        );
    }

    public function list(string $ticketId): array
    {
        return $this->listNew($ticketId, []);
    }

    public function listNew(string $ticketId, array $excludedIds): array
    {
        $history = $this->read($ticketId);
        $initial = (string) $history['comments'][0]['id'];
        $notes = [];
        foreach ($history['comments'] as $comment) {
            if (
                (string) $comment['id'] !== $initial && $comment['public'] === false
                && !in_array((string) $comment['id'], $excludedIds, true)
            ) {
                $notes[] = $this->note($comment, $history['users'], $ticketId);
            }
        }
        return $notes;
    }

    public function current(string $ticketId, string $commentId): ?SourceComment
    {
        $history = $this->read($ticketId);
        $initial = (string) $history['comments'][0]['id'];
        foreach ($history['comments'] as $comment) {
            if ((string) $comment['id'] === $commentId && $commentId !== $initial && $comment['public'] === false) {
                $note = $this->note($comment, $history['users'], $ticketId);
                try {
                    $note->note('Support Bridge note: ' . str_repeat('a', 64));
                } catch (InvalidArgumentException) {
                    throw new RemoteFailure('Zendesk returned an unsupported current internal note.');
                }
                return $note;
            }
        }
        return null;
    }

    private function read(string $ticketId): array
    {
        $history = $this->history->read($ticketId);
        if ($history['comments'] === []) {
            throw new RemoteFailure('Zendesk did not expose the initial ticket report.');
        }
        return $history;
    }

    private function note(array $comment, array $users, string $ticketId): ZendeskNote
    {
        if (
            (!is_int($comment['author_id'] ?? null) && !is_string($comment['author_id'] ?? null))
            || !is_string($comment['created_at'] ?? null)
        ) {
            throw new RemoteFailure('Zendesk returned an unsupported internal note.');
        }
        try {
            $author = (string) $comment['author_id'];
            $note = new ZendeskNote(
                (string) $comment['id'],
                str_replace("\r\n", "\n", $comment['plain_body'] ?? $comment['body']),
                $author,
                $users[$author] ?? null,
                $comment['created_at'],
                'https://' . $this->subdomain . '.zendesk.com/agent/tickets/' . $ticketId
            );
            return $note;
        } catch (InvalidArgumentException) {
            throw new RemoteFailure('Zendesk returned an unsupported internal note.');
        }
    }
}
