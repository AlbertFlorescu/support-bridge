<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use RuntimeException;

final readonly class HeartbeatWriter
{
    public function __construct(private string $path)
    {
    }

    public function write(array $state): void
    {
        $body = json_encode($state, JSON_THROW_ON_ERROR);
        if (
            file_put_contents($this->path . '.tmp', $body) !== strlen($body) || !rename(
                $this->path . '.tmp',
                $this->path
            )
        ) {
            throw new RuntimeException('Cannot update the service heartbeat.');
        }
    }
}
