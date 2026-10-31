<?php

declare(strict_types=1);

namespace Bridge\Observability;

use Bridge\Comments\DeliveryStatus;
use Bridge\Comments\CommentDirection;
use RuntimeException;

final class CommentMetrics
{
    public function __construct(private readonly CommentDirection $direction = CommentDirection::GitHubToZendesk)
    {
    }

    public function render(array $counts, int $streamErrors, bool $privacyHold = false, ?int $initialized = null): array
    {
        $expected = array_column(DeliveryStatus::cases(), 'value');
        $keys = array_keys($counts);
        sort($expected);
        sort($keys);
        if ($keys !== $expected || $streamErrors < 0) {
            throw new RuntimeException('Comment metric state is incomplete.');
        }
        $prefix = $this->direction === CommentDirection::GitHubToZendesk
            ? 'support_bridge_comment' : 'support_bridge_note';
        $lines = [
            '# HELP ' . $prefix . '_deliveries Durable channel deliveries by current state.',
            '# TYPE ' . $prefix . '_deliveries gauge',
        ];
        foreach (DeliveryStatus::cases() as $state) {
            $count = $counts[$state->value];
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException('Comment metric count is invalid.');
            }
            $lines[] = $prefix . '_deliveries{status="' . $state->value . '"} ' . $count;
        }
        $lines[] = '# HELP ' . $prefix . '_stream_errors Linked records whose channel discovery failed.';
        $lines[] = '# TYPE ' . $prefix . '_stream_errors gauge';
        $lines[] = $prefix . '_stream_errors ' . $streamErrors;
        if ($this->direction === CommentDirection::GitHubToZendesk) {
            $lines[] = '# HELP support_bridge_comment_privacy_hold Visibility evidence failure holds both channels.';
            $lines[] = '# TYPE support_bridge_comment_privacy_hold gauge';
            $lines[] = 'support_bridge_comment_privacy_hold ' . (int) $privacyHold;
        } else {
            if ($initialized === null || $initialized < 0) {
                throw new RuntimeException('Reverse stream initialization state is unavailable.');
            }
            $lines[] = '# HELP support_bridge_note_streams_initialized Linked streams with a durable new-note cutover.';
            $lines[] = '# TYPE support_bridge_note_streams_initialized gauge';
            $lines[] = 'support_bridge_note_streams_initialized ' . $initialized;
        }
        return $lines;
    }
}
