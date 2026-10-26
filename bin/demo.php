#!/usr/bin/env php
<?php

declare(strict_types=1);

use Bridge\Simulation\ReliabilityDemo;

require dirname(__DIR__) . '/bootstrap.php';

exit((new ReliabilityDemo(dirname(__DIR__)))->run());
