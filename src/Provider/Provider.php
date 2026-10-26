<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Record;

interface Provider
{
    public function fetch(string $id): Record;

    /** Patch only changed canonical fields, guarded by the latest observed record where supported. */
    public function patch(string $id, array $changes, Record $expected): void;
}
