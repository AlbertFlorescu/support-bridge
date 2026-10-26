<?php

declare(strict_types=1);

namespace Bridge;

/** A deliberately small common model. Provider-specific fields never cross the boundary. */
final readonly class Record
{
    /** @param array{title: string, state: string} $fields */
    public function __construct(public array $fields, public string $version, public bool $writable = true)
    {
        if (
            array_keys($fields) !== [
                'title',
                'state',
            ] || trim($fields['title']) === ''
            || strlen($fields['title']) > 500 || !in_array($fields['state'], ['open', 'closed'], true)
        ) {
            throw new \InvalidArgumentException(
                'Record requires a nonempty title (at most 500 bytes) and open/closed state.'
            );
        }
    }
}
