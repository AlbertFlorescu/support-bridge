<?php

declare(strict_types=1);

namespace Bridge\Comments;

use InvalidArgumentException;

/** First observed source content; later edits never rewrite an existing internal note. */
final readonly class IssueComment implements SourceComment
{
    public function __construct(
        public string $id,
        public string $body,
        public string $author,
        public string $createdAt,
        public string $updatedAt,
        public string $url
    ) {
        if (
            !preg_match('/^[1-9][0-9]{0,15}$/D', $id)
            || trim($body) === '' || strlen($body) > 60_000 || !preg_match('//u', $body)
            || str_contains($body, "\0")
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}(?:\[bot\])?$/D', $author)
            || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $createdAt)
            || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $updatedAt)
            || !preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/'
                . '[1-9][0-9]{0,15}#issuecomment-' . preg_quote($id, '~') . '$~D', $url)
        ) {
            throw new InvalidArgumentException('Unsupported GitHub comment representation.');
        }
    }

    public function sourceId(): string
    {
        return $this->id;
    }

    public function sourceVersion(): string
    {
        return $this->updatedAt;
    }

    public function sourceBody(): string
    {
        return $this->body;
    }

    public function note(string $marker): string
    {
        if (!preg_match('/^Support Bridge comment: [a-f0-9]{64}$/D', $marker)) {
            throw new InvalidArgumentException('Invalid comment delivery marker.');
        }
        return $marker . "\nGitHub comment by @" . $this->author . ' at ' . $this->createdAt
            . "\n" . $this->url . "\n\n" . $this->body;
    }
}
