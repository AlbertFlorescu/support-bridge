<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\CommentFactory;

final readonly class SyncZendeskNotesHandler
{
    public function __construct(private CommentFactory $factory)
    {
    }

    public function handle(SyncZendeskNotes $command): array
    {
        return $this->factory->create()->run();
    }
}
