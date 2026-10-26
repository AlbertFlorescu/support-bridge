<?php

declare(strict_types=1);

namespace Bridge\Domain;

/** Pure three-way merge of the deliberately small canonical model. */
final class MergePolicy
{
    public const FIELDS = [
        'title',
        'state',
    ];

    /** Inputs are canonical fields already validated at the provider boundary. */
    public function decide(?array $baseline, array $github, array $zendesk): MergeDecision
    {
        $target = [];
        $conflicts = [];
        foreach (self::FIELDS as $field) {
            $left = $github[$field];
            $right = $zendesk[$field];
            $base = $baseline[$field] ?? null;
            if ($left === $right) {
                $target[$field] = $left;
            } elseif ($base !== null && $left === $base) {
                $target[$field] = $right;
            } elseif ($base !== null && $right === $base) {
                $target[$field] = $left;
            } else {
                $conflicts[] = $field;
            }
        }
        return new MergeDecision($conflicts === [] ? $target : null, $conflicts);
    }
}
