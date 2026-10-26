<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use RuntimeException;

final class SecretLoader
{
    private const NAMES = [
        'BRIDGE_API_TOKEN',
        'BRIDGE_METRICS_TOKEN',
        'BRIDGE_GITHUB_TOKEN',
        'BRIDGE_ZENDESK_TOKEN',
    ];

    public function load(): void
    {
        foreach (self::NAMES as $name) {
            $path = getenv($name . '_FILE');
            if ($path === false || $path === '') {
                continue;
            }
            if (getenv($name) !== false && getenv($name) !== '') {
                throw new RuntimeException('Configure a secret value or its file, not both.');
            }
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('A configured secret file is unavailable.');
            }
            $value = trim((string) file_get_contents($path));
            if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new RuntimeException('A configured secret file contains an invalid value.');
            }
            putenv($name . '=' . $value);
        }
    }
}
