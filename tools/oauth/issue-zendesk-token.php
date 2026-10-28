<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/ZendeskOAuthTokenCommand.php';

use Bridge\Tooling\OAuth\ZendeskOAuthTokenCommand;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (array_slice($argv, 1) === ['--help']) {
        echo 'Usage: php tools/oauth/issue-zendesk-token.php --subdomain=LABEL --client-id-file=PATH '
            . '--client-secret-file=PATH --output=PATH' . PHP_EOL;
        echo 'Use private ordinary input files and an existing private output directory (POSIX modes 0600/0700).'
            . PHP_EOL;
        exit(0);
    }
    try {
        $metadata = (new ZendeskOAuthTokenCommand())->run(array_slice($argv, 1));
        echo json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable) {
        fwrite(STDERR, 'Token issuance failed. Check the confidential OAuth client, private files and account access.'
            . PHP_EOL);
        exit(1);
    }
}
