<?php
declare(strict_types=1);

namespace Bridge;

final class RemoteFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
