<?php
declare(strict_types=1);

namespace Bridge\Exception;

/** The requested operation conflicts with current durable or observed remote state. */
final class StateConflict extends \RuntimeException
{
}
