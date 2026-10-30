<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Runtime\OAuthClientCredentialImporter;

umask(0077);
try {
    $result = (new OAuthClientCredentialImporter(
        getenv('BRIDGE_OAUTH_DIRECTORY') ?: '/var/lib/support-bridge',
        getenv('BRIDGE_ZENDESK_SUBDOMAIN') ?: ''
    ))->run(STDIN);
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'OAuth client import failed. Check the private input and deployment identity.' . PHP_EOL);
    exit(1);
}
