<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Infrastructure\OAuth\ZendeskTokenRenewal;
use RuntimeException;
use Throwable;

final readonly class ZendeskTokenRenewer
{
    public function __construct(
        private ZendeskTokenRenewal $renewal,
        private StopSignal $stop,
        private JsonLineWriter $log
    ) {
    }

    public static function renewalFromEnvironment(): ZendeskTokenRenewal
    {
        if (getenv('BRIDGE_MODE') !== 'live' || getenv('BRIDGE_LIVE_DEMO_ENABLED') !== '1') {
            throw new RuntimeException('OAuth renewal requires explicit live deployment enablement.');
        }
        $subdomain = getenv('BRIDGE_ZENDESK_SUBDOMAIN');
        if ($subdomain === false || $subdomain === '') {
            throw new RuntimeException('OAuth renewal requires its deployment identity.');
        }
        return new ZendeskTokenRenewal(
            $subdomain,
            getenv('BRIDGE_OAUTH_CLIENT_FILE') ?: '/run/secrets/oauth/client.json',
            getenv('BRIDGE_OAUTH_DIRECTORY') ?: '/var/lib/support-bridge'
        );
    }

    public function run(): void
    {
        $failures = 0;
        while ($this->stop->running()) {
            try {
                $result = $this->renewal->tick();
                $failures = 0;
                if ($result['status'] === 'renewed') {
                    ($this->log)([
                        'event' => 'zendesk_token_renewed',
                        'expires_at' => $result['expires_at'],
                        'renew_at' => $result['renew_at'],
                    ]);
                }
                $delay = $result['next_check_in_seconds'];
            } catch (Throwable) {
                $failures = min(5, $failures + 1);
                $delay = min(60, 5 * (2 ** ($failures - 1)));
                try {
                    $this->renewal->recordFailure();
                } catch (Throwable) {
                    // An absent heartbeat must remain visibly unhealthy, never a fabricated success.
                }
                ($this->log)(['event' => 'zendesk_token_renewal_failed', 'retry_in_seconds' => $delay]);
            }
            $this->stop->wait($delay);
        }
        ($this->log)(['event' => 'zendesk_token_renewer_stopped']);
    }
}
