<?php

declare(strict_types=1);

namespace Bridge\Intake;

use Bridge\Record;
use InvalidArgumentException;

final readonly class EscalationTicket
{
    /** @param list<string> $tags */
    public function __construct(
        public string $id,
        public Record $record,
        public array $tags,
        public ?string $description = null
    ) {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) {
            throw new InvalidArgumentException('An escalation requires a positive ticket ID.');
        }
        if (
            $description !== null && (
            strlen($description) > 30_000 || !preg_match('//u', $description) || str_contains($description, "\0")
            )
        ) {
            throw new InvalidArgumentException('The initial description must be UTF-8 text of at most 30000 bytes.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || strlen($tag) > 200) {
                throw new InvalidArgumentException('An escalation ticket has invalid tags.');
            }
        }
    }

    public function eligible(string $tag): bool
    {
        return $this->record->fields['state'] === 'open' && in_array($tag, $this->tags, true);
    }

    public function snapshot(): array
    {
        $snapshot = ['fields' => $this->record->fields, 'version' => $this->record->version];
        if ($this->description !== null) {
            $snapshot['initial_description'] = $this->description;
        }
        return $snapshot;
    }
}
