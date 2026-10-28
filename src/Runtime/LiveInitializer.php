<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use RuntimeException;

/** Prepares an explicitly selected live deployment without fetching or seeding records. */
final readonly class LiveInitializer
{
    public function __construct(private CredentialInitializer $credentials = new CredentialInitializer())
    {
    }

    public function run(): void
    {
        if (getenv('BRIDGE_MODE') !== 'live' || getenv('BRIDGE_LIVE_DEMO_ENABLED') !== '1') {
            throw new RuntimeException('Live bootstrap requires explicit live mode and demo enablement.');
        }

        $configuration = Configuration::fromEnvironment();
        $this->credentials->run();
        // Context creates the bound business and aggregate-metric schemas without provider adapters.
        // Do not initialize fixtures, construct providers, reconcile, link or approve records here.
        new Context($configuration);
        fwrite(
            STDOUT,
            "Live schemas and separate persistent credentials are ready; no records were fetched or linked.\n"
        );
    }
}
