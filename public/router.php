<?php

declare(strict_types=1);

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Http\Api;
use Bridge\Http\StaticAssets;
use Bridge\Http\WebApplication;

require dirname(__DIR__) . '/bootstrap.php';

$contextFactory = static fn (): Context => new Context(Configuration::fromEnvironment());
$api = new Api(
    $contextFactory,
    getenv('BRIDGE_API_TOKEN') ?: '',
    getenv('BRIDGE_METRICS_TOKEN') ?: ''
);
$review = new \Bridge\Http\Review\Controller(
    $contextFactory,
    getenv('BRIDGE_API_TOKEN') ?: '',
    getenv('BRIDGE_METRICS_TOKEN') ?: ''
);
(new WebApplication($api, new StaticAssets(dirname(__DIR__)), $review))->run();
