<?php

declare(strict_types=1);

namespace Bridge\Simulation;

final readonly class TrafficSettings
{
    public function __construct(
        public string $interval = '5',
        public int $minimum = 1,
        public int $maximum = 5,
        public string $review = 'scripted',
        public ?int $maxTicks = null
    ) {
        if (
            !in_array($interval, ['1', '5', 'random'], true) || $minimum < 1 || $maximum > 60
            || $minimum > $maximum || !in_array($review, ['scripted', 'manual'], true)
            || ($maxTicks !== null && ($maxTicks < 1 || $maxTicks > 1_000_000))
        ) {
            throw new \InvalidArgumentException('Invalid synthetic traffic settings.');
        }
    }

    public static function fromEnvironment(): self
    {
        if (getenv('BRIDGE_MODE') !== 'local' || getenv('BRIDGE_TRAFFIC_ENABLED') !== '1') {
            throw new \RuntimeException('Traffic requires explicit local mode and synthetic opt-in.');
        }
        $integer = static function (string $name, int $default): int {
            $value = getenv($name);
            if ($value === false || $value === '') {
                return $default;
            }
            if (!ctype_digit($value) || strlen($value) > 7) {
                throw new \InvalidArgumentException('Traffic settings must use bounded positive integers.');
            }
            return (int) $value;
        };
        $maxTicks = getenv('BRIDGE_TRAFFIC_MAX_TICKS');
        return new self(
            getenv('BRIDGE_TRAFFIC_INTERVAL') ?: '5',
            $integer('BRIDGE_TRAFFIC_RANDOM_MIN', 1),
            $integer('BRIDGE_TRAFFIC_RANDOM_MAX', 5),
            getenv('BRIDGE_TRAFFIC_REVIEW') ?: 'scripted',
            $maxTicks === false || $maxTicks === '' ? null : $integer('BRIDGE_TRAFFIC_MAX_TICKS', 1)
        );
    }

    public function delay(): int
    {
        return $this->interval === 'random' ? random_int($this->minimum, $this->maximum) : (int) $this->interval;
    }

    public function maximumDelay(): int
    {
        return $this->interval === 'random' ? $this->maximum : (int) $this->interval;
    }
}
