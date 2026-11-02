<?php

declare(strict_types=1);

namespace Bridge\Domain;

use Bridge\Exception\StateConflict;

/** Identity of the complete conflict evidence shown before an operator source decision. */
final class ConflictSnapshot
{
    public static function hash(array $pair): string
    {
        if ($pair['status'] !== 'conflict' || !is_array($pair['conflict'])) {
            throw new StateConflict('Only a quarantined conflict can be resolved.');
        }
        return hash('sha256', json_encode([
            'conflict-review-v1',
            $pair['id'],
            $pair['github_id'],
            $pair['zendesk_id'],
            $pair['baseline'],
            $pair['conflict'],
        ], JSON_THROW_ON_ERROR));
    }
}
