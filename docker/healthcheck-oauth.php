<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Runtime\ZendeskTokenRenewer;

try {
    exit(ZendeskTokenRenewer::renewalFromEnvironment()->healthy() ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
