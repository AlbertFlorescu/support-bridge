#!/usr/bin/env php
<?php

declare(strict_types=1);

use Bridge\Console\Application;

require dirname(__DIR__) . '/bootstrap.php';

exit((new Application())->run(array_slice($argv, 1)));
