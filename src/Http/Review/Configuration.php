<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

use Bridge\Application\ConfigurationFailure;

/** Explicit local browser authority; monitoring access alone never enables review. */
final readonly class Configuration
{
    public function __construct(
        public bool $enabled = false,
        public array $logins = [],
        public string $cookieName = 'support-bridge-live-demo_grafana_session',
        public string $origin = 'http://127.0.0.1:1988',
        public string $grafanaOrigin = 'http://127.0.0.1:1990'
    ) {
        if (!$enabled) {
            return;
        }
        if (
            $logins === [] || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $cookieName) !== 1
            || !$this->localOrigin($origin) || !$this->localOrigin($grafanaOrigin)
        ) {
            throw new ConfigurationFailure('Browser review requires explicit local origins and reviewer logins.');
        }
        foreach ($logins as $login) {
            if (!is_string($login) || preg_match('/^[A-Za-z0-9_.@-]{1,100}$/D', $login) !== 1) {
                throw new ConfigurationFailure('Browser reviewer logins are invalid.');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $logins = array_values(array_filter(array_map('trim', explode(',', getenv('BRIDGE_REVIEW_LOGINS') ?: ''))));
        return new self(
            getenv('BRIDGE_REVIEW_ENABLED') === '1',
            $logins,
            getenv('BRIDGE_REVIEW_GRAFANA_COOKIE') ?: 'support-bridge-live-demo_grafana_session',
            getenv('BRIDGE_REVIEW_ORIGIN') ?: 'http://127.0.0.1:1988',
            getenv('BRIDGE_REVIEW_GRAFANA_ORIGIN') ?: 'http://127.0.0.1:1990'
        );
    }

    private function localOrigin(string $value): bool
    {
        return preg_match('~^http://127\.0\.0\.1:([1-9][0-9]{0,4})$~D', $value, $match) === 1
            && (int) $match[1] <= 65535;
    }
}
