<?php

declare(strict_types=1);

namespace Bridge\Http;

final readonly class Response
{
    public function __construct(public int $status, public array|string $body, public array $headers = [])
    {
    }

    public function send(): void
    {
        http_response_code($this->status);
        $explicitContentType = false;
        foreach (array_keys($this->headers) as $name) {
            $explicitContentType = $explicitContentType || strcasecmp($name, 'Content-Type') === 0;
        }
        if (!$explicitContentType) {
            header(is_array($this->body)
                ? 'Content-Type: application/json; charset=utf-8'
                : 'Content-Type: text/plain; charset=utf-8');
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo is_array($this->body)
            ? json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->body;
    }
}
