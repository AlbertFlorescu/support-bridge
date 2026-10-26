<?php

declare(strict_types=1);

namespace Bridge\Application\Command;

final readonly class LinkPair implements Command
{
    public function __construct(
        public string $id,
        public string $githubId,
        public string $zendeskId
    ) {
    }
}
