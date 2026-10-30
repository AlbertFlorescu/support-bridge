<?php

declare(strict_types=1);

namespace Bridge\Observability;

use Bridge\Comments\DeliveryStatus;
use RuntimeException;

final class CommentMetrics
{
    public function render(array $counts, int $streamErrors, bool $privacyHold = false): array
    {
        $expected = array_column(DeliveryStatus::cases(), 'value');
        $keys = array_keys($counts);
        sort($expected);
        sort($keys);
        if ($keys !== $expected || $streamErrors < 0) {
            throw new RuntimeException('Comment metric state is incomplete.');
        }
        $lines = [
            '# HELP support_bridge_comment_deliveries Durable GitHub comment deliveries by current state.',
            '# TYPE support_bridge_comment_deliveries gauge',
        ];
        foreach (DeliveryStatus::cases() as $state) {
            $count = $counts[$state->value];
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException('Comment metric count is invalid.');
            }
            $lines[] = 'support_bridge_comment_deliveries{status="' . $state->value . '"} ' . $count;
        }
        $lines[] = '# HELP support_bridge_comment_stream_errors Linked issues whose comment discovery failed.';
        $lines[] = '# TYPE support_bridge_comment_stream_errors gauge';
        $lines[] = 'support_bridge_comment_stream_errors ' . $streamErrors;
        $lines[] = '# HELP support_bridge_comment_privacy_hold Internal-only evidence failure holds all note delivery.';
        $lines[] = '# TYPE support_bridge_comment_privacy_hold gauge';
        $lines[] = 'support_bridge_comment_privacy_hold ' . (int) $privacyHold;
        return $lines;
    }
}
