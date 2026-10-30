<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Infrastructure\Persistence\CommentRepository;

final readonly class ListCommentDeliveriesHandler
{
    public function __construct(private CommentRepository $comments)
    {
    }

    public function handle(ListCommentDeliveries $query): array
    {
        return $this->comments->list($query->limit, $query->offset);
    }
}
