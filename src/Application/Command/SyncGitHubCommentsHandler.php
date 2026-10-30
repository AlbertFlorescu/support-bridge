<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

use Bridge\Application\CommentFactory;

final readonly class SyncGitHubCommentsHandler
{
    public function __construct(private CommentFactory $factory)
    {
    }

    public function handle(SyncGitHubComments $command): array
    {
        return $this->factory->create()->run();
    }
}
