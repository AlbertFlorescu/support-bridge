<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

/** Incoming cookie binds a submission; a rotated cookie binds the next rendered form. */
final readonly class Session
{
    public function __construct(public string $login, public string $incoming, public string $current)
    {
    }

    public function headers(Configuration $configuration): array
    {
        return $this->incoming === $this->current ? [] : [
            'Set-Cookie' => $configuration->cookieName . '=' . $this->current .
                '; Path=/; HttpOnly; SameSite=Lax',
        ];
    }
}
