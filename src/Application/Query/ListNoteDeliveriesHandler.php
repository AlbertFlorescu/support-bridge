<?php

declare(strict_types=1);

namespace Bridge\Application\Query;

use Bridge\Infrastructure\Persistence\CommentRepository;

final readonly class ListNoteDeliveriesHandler
{
    public function __construct(private CommentRepository $notes)
    {
    }

    public function handle(ListNoteDeliveries $query): array
    {
        return $this->notes->list($query->limit, $query->offset);
    }
}
