<?php

declare(strict_types=1);

namespace Bridge\Observability;

use Bridge\Intake\IntakeStatus;
use RuntimeException;

/** Fixed state labels only; ticket IDs, titles and creation markers never become metrics. */
final class IntakeMetrics
{
    public function render(array $counts): array
    {
        $expected = array_column(IntakeStatus::cases(), 'value');
        $keys = array_keys($counts);
        sort($expected);
        sort($keys);
        if ($keys !== $expected) {
            throw new RuntimeException('Escalation metric state is incomplete.');
        }
        $lines = [
            '# HELP support_bridge_intake_records Durable Zendesk escalation intents by current state.',
            '# TYPE support_bridge_intake_records gauge',
        ];
        foreach (IntakeStatus::cases() as $state) {
            $count = $counts[$state->value];
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException('Escalation metric count is invalid.');
            }
            $lines[] = 'support_bridge_intake_records{status="' . $state->value . '"} ' . $count;
        }
        return $lines;
    }
}
