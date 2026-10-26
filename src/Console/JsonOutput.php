<?php

declare(strict_types=1);

namespace Bridge\Console;

final readonly class JsonOutput
{
    public function __construct(private string $output = 'php://stdout', private string $errors = 'php://stderr')
    {
    }

    public function result(mixed $value): void
    {
        $this->text(json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL);
    }

    public function text(string $text): void
    {
        file_put_contents($this->output, $text);
    }

    public function error(string $message): void
    {
        file_put_contents($this->errors, $message . PHP_EOL);
    }
}
