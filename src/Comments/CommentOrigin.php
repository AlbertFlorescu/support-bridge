<?php

declare(strict_types=1);

namespace Bridge\Comments;

use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\RemoteFailure;

/** Use durable origin evidence, never the author's account, to suppress reflected messages. */
final readonly class CommentOrigin
{
    public function __construct(private CommentRepository $deliveries)
    {
    }

    public function mirrored(string $pairId, SourceComment $comment): bool
    {
        $body = str_replace("\r\n", "\n", $comment->sourceBody());
        $firstLine = explode("\n", $body, 2)[0];
        if ($this->deliveries->knowsOrigin($pairId, $comment->sourceId(), $firstLine)) {
            return true;
        }
        if (
            str_starts_with($firstLine, 'Support Bridge comment:')
            || str_starts_with($firstLine, 'Support Bridge note:')
        ) {
            throw new RemoteFailure('Unrecognized bridge origin; comment discovery requires investigation.');
        }
        return false;
    }
}
