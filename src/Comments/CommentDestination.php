<?php

declare(strict_types=1);

namespace Bridge\Comments;

interface CommentDestination
{
    /** Require a complete bounded lookup and one unambiguous destination match. */
    public function find(string $recordId, string $marker): ?string;

    public function append(string $recordId, string $body, string $expectedVersion): void;
}
