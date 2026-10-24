<?php
declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Provider\Identity;

/** Deployment settings are process configuration, never HTTP request parameters. */
final readonly class Configuration
{
    public function __construct(public string $mode, public string $home, public string $repository, public string $subdomain)
    {
        if (!in_array($mode, ['local', 'live'], true) || $home === '') {
            throw new ConfigurationFailure('Mode must be local/live and the state directory must be nonempty.');
        }
        if ($mode === 'live') {
            try {
                Identity::repository($repository);
                Identity::subdomain($subdomain);
            } catch (\InvalidArgumentException) {
                throw new ConfigurationFailure('Provider identities are invalid. Check the deployment environment.');
            }
        }
    }

    public static function fromEnvironment(?string $mode = null, ?string $home = null): self
    {
        $mode ??= getenv('BRIDGE_MODE') ?: 'local';
        $home ??= getenv('BRIDGE_HOME') ?: dirname(__DIR__, 2) . '/var/local';
        return new self($mode, $home,
            $mode === 'live' ? self::required('BRIDGE_GITHUB_REPOSITORY') : 'local/demo',
            $mode === 'live' ? self::required('BRIDGE_ZENDESK_SUBDOMAIN') : 'local');
    }

    public static function required(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new ConfigurationFailure('Missing environment variable: ' . $name);
        }
        return $value;
    }
}
