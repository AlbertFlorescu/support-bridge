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
        $bundle = getenv('BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE');
        if ($bundle !== false && $bundle !== '') {
            if (
                getenv('BRIDGE_ZENDESK_AUTH') !== 'oauth'
                || (getenv('BRIDGE_ZENDESK_TOKEN') !== false && getenv('BRIDGE_ZENDESK_TOKEN') !== '')
                || (getenv('BRIDGE_ZENDESK_TOKEN_FILE') !== false && getenv('BRIDGE_ZENDESK_TOKEN_FILE') !== '')
            ) {
                throw new RuntimeException('Configure managed Zendesk OAuth or one static credential source.');
            }
            // The provider reads this file per request. Queries and startup need no issued token.
        }
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
