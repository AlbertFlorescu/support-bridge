<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;

umask(0077);

try {
    if ((getenv('BRIDGE_MODE') ?: 'local') !== 'local') {
        throw new RuntimeException('The automatic bootstrap is for local synthetic data only.');
    }
    $paths = ['/run/secrets/operator/token', '/run/secrets/metrics/token',
        '/run/secrets/grafana/password', '/run/secrets/grafana/secret-key'];
    foreach ($paths as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Secret files must be ordinary files.');
        }
        if (!file_exists($path)) {
            $stream = fopen($path, 'x');
            if ($stream === false) {
                throw new RuntimeException('Cannot create a credential.');
            }
            try {
                $value = bin2hex(random_bytes(32)) . "\n";
                if (fwrite($stream, $value) !== strlen($value) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException('Cannot persist a credential.');
                }
            } finally {
                fclose($stream);
            }
        }
        $value = trim((string) file_get_contents($path));
        if (strlen($value) < 32 || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new RuntimeException('An existing credential is invalid; refusing to replace it.');
        }
        // Different containers run with different UIDs. Separate read-only mounts define access.
        if (!chmod($path, 0444)) {
            throw new RuntimeException('Cannot set credential permissions.');
        }
    }
    if (hash_equals(trim((string) file_get_contents($paths[0])), trim((string) file_get_contents($paths[1])))) {
        throw new RuntimeException('Operator and metrics credentials must differ.');
    }
    $context = new Context(Configuration::fromEnvironment());
    // Repeated Compose tool invocations must not contend with a running sweep just
    // to discover that the existing linked state needs no bootstrap operation.
    if ($context->store->all() === []) {
        $context->initialize();
    }
    fwrite(STDOUT, "Local state and separate persistent credentials are ready.\n");
} catch (Throwable) {
    fwrite(STDERR, "Initialization failed. Check volume ownership, existing credentials and local state; no existing data was reset.\n");
    exit(1);
}
