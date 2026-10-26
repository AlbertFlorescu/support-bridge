<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Context;
use Bridge\Exception\WorkerBusy;
use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Simulation\TrafficSettings;
use Bridge\Simulation\TrafficUnavailable;
use Bridge\Simulation\Workload;
use RuntimeException;

final readonly class TrafficRunner
{
    public function __construct(
        private Context $context,
        private TrafficSettings $settings,
        private Workload $workload,
        private StopSignal $stop,
        private HeartbeatWriter $heartbeat,
        private JsonLineWriter $output
    ) {
    }

    public function run(): void
    {
        $lock = fopen(realpath($this->context->configuration->home) . '/traffic.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another traffic generator is already running.');
        }
        try {
            $provisioned = false;
            $ticks = $errors = $sequence = $lastSuccess = 0;
            ($this->output)([
                'event'      => 'traffic_started',
                'interval'   => $this->settings->interval,
                'random_min' => $this->settings->minimum,
                'random_max' => $this->settings->maximum,
                'review'     => $this->settings->review,
                'synthetic'  => true,
            ]);
            while (
                $this->stop->running() && ($this->settings->maxTicks === null
                || $sequence < $this->settings->maxTicks)
            ) {
                ++$sequence;
                try {
                    if (!$provisioned) {
                        $this->workload->provision();
                        $provisioned = true;
                    }
                    $entry = $this->workload->tick($sequence);
                    ++$ticks;
                    $lastSuccess = time();
                    ($this->output)($entry + ['ticks' => $ticks, 'errors' => $errors]);
                } catch (WorkerBusy | TrafficUnavailable) {
                    ++$errors;
                    ($this->output)([
                        'event'    => 'traffic_deferred',
                        'sequence' => $sequence,
                        'ticks'    => $ticks,
                        'errors'   => $errors,
                    ]);
                }
                $heartbeat = [
                    'observed_at'      => time(),
                    'interval_seconds' => $this->settings->maximumDelay(),
                    'ticks'            => $ticks,
                    'errors'           => $errors,
                    'last_success_at'  => $lastSuccess,
                ];
                $this->heartbeat->write($heartbeat);
                if (
                    $this->stop->running() && ($this->settings->maxTicks === null
                    || $sequence < $this->settings->maxTicks)
                ) {
                    $this->stop->wait($this->settings->delay());
                }
            }
            ($this->output)(['event' => 'traffic_stopped', 'ticks' => $ticks, 'errors' => $errors]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
