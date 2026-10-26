<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Exception\WorkerBusy;
use Bridge\Simulation\OperatorClient;
use Bridge\Simulation\TrafficSettings;
use Bridge\Simulation\TrafficUnavailable;
use Bridge\Simulation\Workload;

umask(0077);
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
$lock = null;

try {
    $settings = TrafficSettings::fromEnvironment();
    $context = new Context(Configuration::fromEnvironment());
    $lock = fopen(realpath($context->configuration->home) . '/traffic.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another traffic generator is already running.');
    }
    $client = new OperatorClient(Configuration::required('BRIDGE_API_TOKEN'));
    $workload = new Workload($context, $client->request(...), $settings->review);
    $provisioned = false;
    $ticks = $errors = $sequence = $lastSuccess = 0;
    fwrite(STDOUT, json_encode(['event' => 'traffic_started', 'interval' => $settings->interval,
        'random_min' => $settings->minimum, 'random_max' => $settings->maximum,
        'review' => $settings->review, 'synthetic' => true], JSON_THROW_ON_ERROR) . PHP_EOL);
    while ($running && ($settings->maxTicks === null || $sequence < $settings->maxTicks)) {
        ++$sequence;
        try {
            if (!$provisioned) {
                $workload->provision();
                $provisioned = true;
            }
            $entry = $workload->tick($sequence);
            ++$ticks;
            $lastSuccess = time();
            fwrite(STDOUT, json_encode($entry + ['ticks' => $ticks, 'errors' => $errors], JSON_THROW_ON_ERROR) . PHP_EOL);
        } catch (WorkerBusy | TrafficUnavailable) {
            ++$errors;
            fwrite(STDOUT, json_encode(['event' => 'traffic_deferred', 'sequence' => $sequence,
                'ticks' => $ticks, 'errors' => $errors], JSON_THROW_ON_ERROR) . PHP_EOL);
        }
        $heartbeat = ['observed_at' => time(), 'interval_seconds' => $settings->maximumDelay(),
            'ticks' => $ticks, 'errors' => $errors, 'last_success_at' => $lastSuccess];
        $path = '/tmp/traffic-heartbeat.json';
        $json = json_encode($heartbeat, JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.tmp', $json) !== strlen($json) || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Cannot update the traffic heartbeat.');
        }
        if ($running && ($settings->maxTicks === null || $sequence < $settings->maxTicks)) {
            sleep($settings->delay());
        }
    }
    fwrite(STDOUT, json_encode(['event' => 'traffic_stopped', 'ticks' => $ticks, 'errors' => $errors], JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic traffic failed. Check local-only settings, ownership markers, API access and service logs.\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
