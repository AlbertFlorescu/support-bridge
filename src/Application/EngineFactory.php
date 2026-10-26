<?php

declare(strict_types=1);

namespace Bridge\Application;

use Bridge\Engine;
use Bridge\Store;
use Closure;

final class EngineFactory
{
    private readonly Closure $logger;

    public function __construct(
        private readonly Store $store,
        private readonly ProviderFactory $providers,
        callable $logger
    ) {
        $this->logger = Closure::fromCallable($logger);
    }

    public function create(): Engine
    {
        [
            $github,
            $zendesk,
        ] = $this->providers->create();

        return new Engine($this->store, $github, $zendesk, $this->logger);
    }
}
