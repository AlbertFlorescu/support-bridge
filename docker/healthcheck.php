<?php

declare(strict_types=1);

use Bridge\Runtime\HealthCheck;

require dirname(__DIR__) . '/bootstrap.php';

exit((new HealthCheck())->check($argv[1] ?? ''));
