<?php

declare(strict_types=1);

use Bridge\Runtime\ContainerApplication;

require dirname(__DIR__) . '/bootstrap.php';

exit((new ContainerApplication())->run(['traffic']));
