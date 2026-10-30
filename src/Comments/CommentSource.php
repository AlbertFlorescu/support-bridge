<?php

declare(strict_types=1);

namespace Bridge\Comments;

interface CommentSource
{
    /** @return list<IssueComment> Complete bounded issue-comment listing. */
    public function list(string $issueId): array;
}
