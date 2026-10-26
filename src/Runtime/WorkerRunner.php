<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Context;
use Bridge\Application\Command\Reconcile;
use Bridge\Exception\WorkerBusy;
use Bridge\Infrastructure\Logging\JsonLineWriter;

final readonly class WorkerRunner
{
    public function __construct(
        private Context $context,
        private int $interval,
        private StopSignal $stop,
        private HeartbeatWriter $heartbeat,
        private JsonLineWriter $output
    ) {
    }

    public function run(): void
    {
        while ($this->stop->running()) {
            try {
                $outcome = $this->context->handle(new Reconcile());
            } catch (WorkerBusy) {
                $outcome = ['worker_busy' => true];
            }
            ($this->output)(['event' => 'worker_sweep', 'outcome' => $outcome]);
            $this->heartbeat->write(['observed_at' => time(), 'interval_seconds' => $this->interval]);
            $this->stop->wait($this->interval);
        }
    }
}
