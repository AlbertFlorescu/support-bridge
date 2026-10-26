<?php

/** Run the same PHP and OpenAPI gates without any host PHP/Node installation. */

declare(strict_types=1);

foreach (
    [
        [
            PHP_BINARY,
            '/app/vendor/bin/phpcs',
            '--standard=/app/phpcs.xml.dist',
        ],
        [
            PHP_BINARY,
            '/app/bin/check.php',
        ],
        [
            'node',
            '/app/tools/api-docs/check-vendor.cjs',
        ],
        [
            'node',
            '/app/tools/api-docs/validate.cjs',
        ],
    ] as $command
) {
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, '/app');
    if (!is_resource($process)) {
        fwrite(STDERR, "Cannot start the verification process.\n");
        exit(1);
    }
    $code = proc_close($process);
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}
