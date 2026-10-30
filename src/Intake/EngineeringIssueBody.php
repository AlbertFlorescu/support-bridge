<?php

declare(strict_types=1);

namespace Bridge\Intake;

use Bridge\Provider\Identity;
use InvalidArgumentException;

/** Shared creation/maintenance format; source text is inert, without reference expansion. */
final readonly class EngineeringIssueBody
{
    private string $subdomain;

    public function __construct(string $subdomain)
    {
        $this->subdomain = Identity::subdomain($subdomain);
    }

    public function render(EscalationTicket $ticket, string $marker): string
    {
        if ($ticket->description === null) {
            throw new InvalidArgumentException('Read the initial description before creating an engineering issue.');
        }
        if (!preg_match('/^<!-- support-bridge-escalation:[a-f0-9]{64} -->$/D', $marker)) {
            throw new InvalidArgumentException('Invalid escalation marker.');
        }
        $url = 'https://' . $this->subdomain . '.zendesk.com/agent/tickets/' . $ticket->id;
        $description = 'Zendesk supplied no initial description text.';
        if ($ticket->description !== '') {
            $fence = $this->fence($ticket->description);
            $description = $fence . "text\n" . $ticket->description . "\n" . $fence;
        }
        return 'Engineering escalation from [Zendesk ticket ' . $ticket->id . '](' . $url . ").\n\n"
            . "## Initial Zendesk description\n\n" . $description . "\n\n" . $marker;
    }

    private function fence(string $text): string
    {
        $lengths = [];
        foreach (['`', '~'] as $character) {
            preg_match_all('/' . $character . '+/', $text, $runs);
            $lengths[$character] = max([0, ...array_map(strlen(...), $runs[0])]);
        }
        $character = $lengths['`'] <= $lengths['~'] ? '`' : '~';
        return str_repeat($character, max(3, $lengths[$character] + 1));
    }
}
