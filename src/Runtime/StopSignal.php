<?php

declare(strict_types=1);

namespace Bridge\Runtime;

final class StopSignal
{
    private bool $running = true;

    public function __construct()
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->running = false;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->running = false;
        });
    }

    public function running(): bool
    {
        return $this->running;
    }

    public function wait(int $seconds): void
    {
        if ($this->running) {
            sleep($seconds);
        }
    }
}
