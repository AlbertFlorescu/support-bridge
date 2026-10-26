<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Logging;

final readonly class JsonLineWriter
{
    public function __construct(private string $stream = 'php://stderr')
    {
    }

    public function __invoke(array $entry): void
    {
        file_put_contents($this->stream, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
