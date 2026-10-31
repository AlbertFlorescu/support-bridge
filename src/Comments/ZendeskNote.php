<?php

declare(strict_types=1);

namespace Bridge\Comments;

use InvalidArgumentException;

final readonly class ZendeskNote implements SourceComment
{
    public function __construct(
        public string $id,
        public string $body,
        public string $authorId,
        public ?string $authorName,
        public string $createdAt,
        public string $url
    ) {
        if (
            !preg_match('/^[1-9][0-9]{0,18}$/D', $id)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $authorId)
            || trim($body) === '' || strlen($body) > 62_000 || !preg_match('//u', $body)
            || str_contains($body, "\0")
            || ($authorName !== null && (trim($authorName) === '' || strlen($authorName) > 500
                || !preg_match('//u', $authorName) || preg_match('/[\x00-\x1f\x7f]/', $authorName)))
            || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $createdAt)
            || !preg_match('~^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
                . '\.zendesk\.com/agent/tickets/[1-9][0-9]{0,15}$~D', $url)
        ) {
            throw new InvalidArgumentException('Unsupported Zendesk internal note representation.');
        }
    }

    public function sourceId(): string
    {
        return $this->id;
    }

    public function sourceVersion(): string
    {
        return $this->createdAt;
    }

    public function sourceBody(): string
    {
        return $this->body;
    }

    public function note(string $marker): string
    {
        if (!preg_match('/^Support Bridge note: [a-f0-9]{64}$/D', $marker)) {
            throw new InvalidArgumentException('Invalid internal-note delivery marker.');
        }
        $text = 'Author: ' . ($this->authorName ?? ('Zendesk user #' . $this->authorId))
            . "\n\n" . $this->body;
        preg_match_all('/`+/', $text, $runs);
        $length = $runs[0] === [] ? 0 : max(array_map(strlen(...), $runs[0]));
        $fence = str_repeat('`', max(3, $length + 1));
        $note = $marker . "\nZendesk internal note #" . $this->id . ' by user #' . $this->authorId
            . ' at ' . $this->createdAt . "\n" . $this->url . "\n\n"
            . $fence . "text\n" . $text . "\n" . $fence;
        if (strlen($note) > 60_000) {
            throw new InvalidArgumentException('Formatted Zendesk note exceeds the GitHub comment bound.');
        }
        return $note;
    }
}
