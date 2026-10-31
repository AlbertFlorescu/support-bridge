<?php

declare(strict_types=1);

namespace Bridge\Comments;

/** Reverse delivery needs a complete cutover and fresh internal-only source evidence. */
interface InternalNoteSource extends CommentSource
{
    /** @return list<string> All existing comment IDs, including public and initial comments. */
    public function baselineIds(string $ticketId): array;

    /** @return list<SourceComment> Eligible notes excluding all IDs observed at cutover. */
    public function listNew(string $ticketId, array $excludedIds): array;

    public function current(string $ticketId, string $commentId): ?SourceComment;
}
