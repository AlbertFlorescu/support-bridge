<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use RuntimeException;

final class Initializer
{
    public function __construct(private readonly CredentialInitializer $credentials = new CredentialInitializer())
    {
    }

    public function run(): void
    {
        if ((getenv('BRIDGE_MODE') ?: 'local') !== 'local') {
            throw new RuntimeException('The automatic bootstrap is for local synthetic data only.');
        }
        $this->credentials->run();
        $context = new Context(Configuration::fromEnvironment());
        // Repeated Compose tool invocations must not contend with a running sweep just
        // to discover that the existing linked state needs no bootstrap operation.
        if ($context->store->all() === []) {
            $context->initialize();
        }
        fwrite(STDOUT, "Local state and separate persistent credentials are ready.\n");
    }
}
