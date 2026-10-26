<?php

declare(strict_types=1);

namespace Bridge\Domain;

/** A conflict never exposes a partial target that a caller might accidentally apply. */
final readonly class MergeDecision
{
    /** @param null|array{title: string, state: string} $target @param list<string> $conflictingFields */
    public function __construct(public ?array $target, public array $conflictingFields = [])
    {
        if (($target === null) !== ($conflictingFields !== [])) {
            throw new \LogicException('A merge decision must contain either a complete target or conflicts.');
        }
    }

    public function hasConflict(): bool
    {
        return $this->conflictingFields !== [];
    }
}
