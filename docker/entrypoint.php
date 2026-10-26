<?php
declare(strict_types=1);

umask(0077);

try {
    // Read only explicitly supported deployment secrets. Request data never enters this path.
    foreach (['BRIDGE_API_TOKEN', 'BRIDGE_METRICS_TOKEN', 'BRIDGE_GITHUB_TOKEN', 'BRIDGE_ZENDESK_TOKEN'] as $name) {
        $path = getenv($name . '_FILE');
        if ($path === false || $path === '') {
            continue;
        }
        if (getenv($name) !== false && getenv($name) !== '') {
            throw new RuntimeException('Configure a secret value or its file, not both.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('A configured secret file is unavailable.');
        }
        $value = trim((string) file_get_contents($path));
        if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new RuntimeException('A configured secret file contains an invalid value.');
        }
        putenv($name . '=' . $value);
    }

    $role = $argv[1] ?? 'api';
    $arguments = array_slice($argv, 2);
    $command = match ($role) {
        'api' => [PHP_BINARY, '-d', 'display_errors=0', '-S', '0.0.0.0:8080', '-t', '/app/public', '/app/public/router.php'],
        'cli' => [PHP_BINARY, '/app/bin/bridge.php', ...$arguments],
        'init' => [PHP_BINARY, '/app/docker/initialize.php'],
        'worker' => [PHP_BINARY, '/app/docker/worker.php'],
        'traffic' => [PHP_BINARY, '/app/docker/traffic.php'],
        'verify' => [PHP_BINARY, '/app/docker/check.php'],
        'smoke' => [PHP_BINARY, '/app/docker/verify-stack.php'],
        default => throw new RuntimeException('Unknown container role. Use api, worker, traffic, cli, init, verify or smoke.'),
    };
    if ($role !== 'cli' && $arguments !== []) {
        throw new RuntimeException('Only the cli role accepts additional arguments.');
    }
    // Replace the process so Compose's init can forward termination to the actual service.
    pcntl_exec(array_shift($command), $command);
    throw new RuntimeException('Cannot start the container process.');
} catch (Throwable) {
    fwrite(STDERR, "Container configuration or startup failed. Check the role, secret files and deployment settings.\n");
    exit(1);
}
