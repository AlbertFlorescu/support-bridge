<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Throwable;

final class HealthCheck
{
    public function check(string $role): int
    {
        try {
            if ($role === 'api') {
                $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
                $body = @file_get_contents('http://127.0.0.1:8080/health', false, $context);
                $data = $body === false ? null : json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                return ($data['status'] ?? '') === 'ok' ? 0 : 1;
            }
            if ($role === 'worker') {
                $path = (getenv('BRIDGE_HOME') ?: '/var/lib/support-bridge') . '/worker-heartbeat.json';
                $body = @file_get_contents($path);
                $data = $body === false ? null : json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                $interval = $data['interval_seconds'] ?? null;
                $observed = $data['observed_at'] ?? null;
                return is_int($interval) && $interval >= 1 && $interval <= 3600 && is_int($observed)
                    && $observed <= time() && time() - $observed <= $interval + 30 ? 0 : 1;
            }
        } catch (Throwable) {
            // Health responses contain no credential values or state snapshots.
        }
        return 1;
    }
}
