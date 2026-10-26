<?php

declare(strict_types=1);

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Http\Api;
use Bridge\Http\StaticAssets;
use Bridge\Http\WebApplication;

require dirname(__DIR__) . '/bootstrap.php';

$api = new Api(
    static fn (): Context => new Context(Configuration::fromEnvironment()),
    getenv('BRIDGE_API_TOKEN') ?: '',
    getenv('BRIDGE_METRICS_TOKEN') ?: ''
);
(new WebApplication($api, new StaticAssets(dirname(__DIR__))))->run();
