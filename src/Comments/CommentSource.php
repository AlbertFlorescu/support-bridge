<?php

declare(strict_types=1);

namespace Bridge\Comments;

interface CommentSource
{
    /** @return list<SourceComment> Complete bounded eligible comment listing. */
    public function list(string $issueId): array;
}
