<?php

declare(strict_types=1);

namespace Bridge\Simulation\Replay;

use InvalidArgumentException;
use Throwable;

/** Optional, best-effort aggregate observations; no provider or operator credentials. */
final readonly class PrometheusCapture
{
    public function __construct(private string $baseUrl)
    {
        self::validateUrl($baseUrl, true);
    }

    public static function validateUrl(string $url, bool $allowCompose = false): void
    {
        $parts = parse_url($url);
        $hosts = ['127.0.0.1', 'localhost', '[::1]'];
        if ($allowCompose) {
            $hosts[] = 'prometheus';
        }
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || !in_array($parts['host'] ?? '', $hosts, true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new InvalidArgumentException('Monitoring URLs must use an allowed HTTP origin.');
        }
    }

    public function capture(): array
    {
        try {
            return [
                'up' => $this->scalar('up{job="support_bridge"}'),
                'pairs' => $this->states(),
                'pending_plans' => $this->scalar('sum(support_bridge_pending_plans)'),
                'reconciliations_total' => $this->scalar('sum(support_bridge_reconciliations_total)'),
            ];
        } catch (Throwable) {
            // A failed telemetry read cannot alter or fabricate the captured business outcome.
            return [];
        }
    }

    private function scalar(string $query): float
    {
        $rows = $this->query($query);
        $value = $rows[0]['value'][1] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new \RuntimeException('The monitoring observation is unavailable.');
        }
        return (float) $value;
    }

    private function states(): array
    {
        $states = [];
        foreach ($this->query('sum by(status)(support_bridge_pairs)') as $row) {
            $status = $row['metric']['status'] ?? '';
            $value = $row['value'][1] ?? null;
            if (
                in_array($status, ['new', 'synced', 'retry', 'conflict', 'dead', 'awaiting_approval'], true)
                && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            ) {
                $states[$status] = (float) $value;
            }
        }
        return $states;
    }

    private function query(string $query): array
    {
        $handle = curl_init(rtrim($this->baseUrl, '/') . '/api/v1/query?query=' . rawurlencode($query));
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false || $status !== 200) {
            throw new \RuntimeException('The monitoring observation is unavailable.');
        }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (($data['status'] ?? '') !== 'success' || !is_array($data['data']['result'] ?? null)) {
            throw new \RuntimeException('The monitoring observation is invalid.');
        }
        return $data['data']['result'];
    }
}
