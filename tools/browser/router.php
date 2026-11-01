<?php

/** Synthetic browser fixture; this router is never served by the application. */

declare(strict_types=1);

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Http\Api;
use Bridge\Http\Review\Configuration as ReviewConfiguration;
use Bridge\Http\Review\Controller;
use Bridge\Http\Review\GrafanaSession;
use Bridge\Http\StaticAssets;
use Bridge\Http\WebApplication;

require dirname(__DIR__, 2) . '/bootstrap.php';

$home = getenv('BRIDGE_NATIVE_FORM_TEST_HOME') ?: '';
if (getenv('BRIDGE_NATIVE_FORM_TEST') !== '1' || !str_starts_with(basename($home), 'support-bridge-native-browser-')) {
    throw new RuntimeException('This fixture requires an isolated local test directory.');
}
$factory = static fn (): Context => new Context(new Configuration('local', $home, 'local/demo', 'local'));
$token = 'native-browser-fixture-operator-0123456789abcdef';
$metrics = 'native-browser-fixture-metrics-fedcba9876543210';
$review = new Controller(
    $factory,
    $token,
    $metrics,
    new ReviewConfiguration(
        true,
        ['test-reviewer'],
        'native_test_grafana_session',
        getenv('BRIDGE_NATIVE_FORM_ORIGIN')
    ),
    new GrafanaSession(static fn (): array => [
        'status' => 200,
        'body' => '{"login":"test-reviewer","isGrafanaAdmin":true}',
        'cookies' => [],
    ])
);
(new WebApplication(new Api($factory, $token, $metrics), new StaticAssets(dirname(__DIR__, 2)), $review))->run();
