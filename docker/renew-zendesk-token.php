<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Runtime\StopSignal;
use Bridge\Runtime\ZendeskTokenRenewer;

umask(0077);
try {
    (new ZendeskTokenRenewer(
        ZendeskTokenRenewer::renewalFromEnvironment(),
        new StopSignal(),
        new JsonLineWriter('php://stdout')
    ))->run();
} catch (Throwable) {
    fwrite(STDERR, 'OAuth renewal configuration is unavailable. Check the deployment and private files.' . PHP_EOL);
    exit(1);
}
