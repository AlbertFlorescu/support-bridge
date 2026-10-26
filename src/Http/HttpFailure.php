<?php

declare(strict_types=1);

namespace Bridge\Http;

final class HttpFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
