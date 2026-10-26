<?php

declare(strict_types=1);

namespace Bridge\Simulation;

/** A transient observation failure; the next iteration must inspect before another command. */
final class TrafficUnavailable extends \RuntimeException
{
}
