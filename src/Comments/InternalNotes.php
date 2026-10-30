<?php

declare(strict_types=1);

namespace Bridge\Comments;

interface InternalNotes
{
    /** Return the unique internal note ID, or fail if absence cannot be established. */
    public function find(string $ticketId, string $marker): ?string;

    public function append(string $ticketId, string $body, string $expectedVersion): void;
}
