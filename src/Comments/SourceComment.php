<?php

declare(strict_types=1);

namespace Bridge\Comments;

/** An immutable source snapshot; formatting cannot grant workflow authority. */
interface SourceComment
{
    public function sourceId(): string;

    public function sourceVersion(): string;

    public function sourceBody(): string;

    public function note(string $marker): string;
}
