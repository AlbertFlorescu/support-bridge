<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Exception\WorkerBusy;

umask(0077);
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });

try {
    $interval = getenv('BRIDGE_SWEEP_INTERVAL') ?: '60';
    if (!ctype_digit($interval) || (int) $interval < 1 || (int) $interval > 3600) {
        throw new RuntimeException('Invalid sweep interval.');
    }
    $context = new Context(Configuration::fromEnvironment());
    while ($running) {
        try {
            $outcome = $context->engine()->run();
        } catch (WorkerBusy) {
            // An operator sweep uses the same canonical lock; contention is not a failed plan.
            $outcome = ['worker_busy' => true];
        }
        fwrite(STDOUT, json_encode(['event' => 'worker_sweep', 'outcome' => $outcome], JSON_THROW_ON_ERROR) . PHP_EOL);
        $heartbeat = $context->configuration->home . '/worker-heartbeat.json';
        $temporary = $heartbeat . '.tmp';
        $body = json_encode(['observed_at' => time(), 'interval_seconds' => (int) $interval], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $body) !== strlen($body) || !rename($temporary, $heartbeat)) {
            throw new RuntimeException('Cannot update worker heartbeat.');
        }
        if ($running) {
            sleep((int) $interval);
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Worker failed. Check deployment settings, local storage and service logs.\n");
    exit(1);
}
