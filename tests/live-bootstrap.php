<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Runtime\CredentialInitializer;
use Bridge\Runtime\Initializer;
use Bridge\Runtime\LiveInitializer;

final class LiveBootstrapFixture
{
    public readonly string $directory;
    public readonly string $home;
    public readonly array $paths;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/bridge-live-bootstrap-' . bin2hex(random_bytes(8));
        $this->home = $this->directory . '/state';
        foreach (['operator', 'metrics', 'grafana'] as $name) {
            mkdir($this->directory . '/credentials/' . $name, 0700, true);
        }
        $this->paths = [
            'operator' => $this->directory . '/credentials/operator/token',
            'metrics' => $this->directory . '/credentials/metrics/token',
            'grafana-password' => $this->directory . '/credentials/grafana/password',
            'grafana-key' => $this->directory . '/credentials/grafana/secret-key',
        ];
    }

    public function credentials(): CredentialInitializer
    {
        return new CredentialInitializer(...array_values($this->paths));
    }

    public function configureLive(): void
    {
        putenv('BRIDGE_MODE=live');
        putenv('BRIDGE_LIVE_DEMO_ENABLED=1');
        putenv('BRIDGE_HOME=' . $this->home);
        putenv('BRIDGE_GITHUB_REPOSITORY=example/live-demo');
        putenv('BRIDGE_ZENDESK_SUBDOMAIN=example');
    }

    public function contents(): array
    {
        return array_map(file_get_contents(...), $this->paths);
    }

    public function cleanup(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }
}

function liveBootstrapCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function liveBootstrapReject(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected bootstrap rejection.');
}

$environmentNames = [
    'BRIDGE_MODE',
    'BRIDGE_HOME',
    'BRIDGE_LIVE_DEMO_ENABLED',
    'BRIDGE_GITHUB_REPOSITORY',
    'BRIDGE_ZENDESK_SUBDOMAIN',
    'BRIDGE_ZENDESK_EMAIL',
];
foreach (['BRIDGE_API_TOKEN', 'BRIDGE_METRICS_TOKEN', 'BRIDGE_GITHUB_TOKEN', 'BRIDGE_ZENDESK_TOKEN'] as $name) {
    $environmentNames[] = $name;
    $environmentNames[] = $name . '_FILE';
}
$environment = [];
foreach ($environmentNames as $name) {
    $environment[$name] = getenv($name);
}
$fixtures = [];
$fixture = static function () use (&$fixtures): LiveBootstrapFixture {
    $instance = new LiveBootstrapFixture();
    $fixtures[] = $instance;
    return $instance;
};

$tests = [];
$tests['Credential initialization preserves valid bytes and read-only reader permissions'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->credentials()->run();
    $before = $state->contents();
    foreach ($state->paths as $name => $path) {
        $value = trim($before[$name]);
        liveBootstrapCheck(strlen($value) === 64 && ctype_xdigit($value), 'Generated credential is not strong hex.');
        clearstatcache(true, $path);
        liveBootstrapCheck((fileperms($path) & 0777) === 0444, 'Credential reader permissions changed.');
    }
    liveBootstrapCheck($before['operator'] !== $before['metrics'], 'Operator and metrics authority overlaps.');
    $state->credentials()->run();
    liveBootstrapCheck($state->contents() === $before, 'Repeated bootstrap rotated credentials.');
};
$tests['Invalid existing credentials are rejected without replacement'] = static function () use ($fixture): void {
    foreach (['', 'short', str_repeat('x', 4097), str_repeat('x', 40) . "\nunsafe-header"] as $value) {
        $state = $fixture();
        file_put_contents($state->paths['operator'], $value);
        liveBootstrapReject($state->credentials()->run(...));
        liveBootstrapCheck(file_get_contents($state->paths['operator']) === $value, 'Invalid credential was replaced.');
    }
};
$tests['Credential initialization rejects symbolic links and non-file paths'] = static function () use ($fixture): void {
    $state = $fixture();
    $target = $state->directory . '/original';
    $original = str_repeat('a', 64) . "\n";
    file_put_contents($target, $original);
    symlink($target, $state->paths['operator']);
    liveBootstrapReject($state->credentials()->run(...));
    liveBootstrapCheck(file_get_contents($target) === $original, 'Credential symlink target changed.');

    $directory = $fixture();
    mkdir($directory->paths['operator'], 0700);
    liveBootstrapReject($directory->credentials()->run(...));
    liveBootstrapCheck(!file_exists($directory->paths['metrics']), 'Invalid path allowed later credential effects.');
};
$tests['Equal operator and metrics values fail before live state is opened'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->configureLive();
    $value = str_repeat('b', 64) . "\n";
    file_put_contents($state->paths['operator'], $value);
    file_put_contents($state->paths['metrics'], $value);
    liveBootstrapReject((new LiveInitializer($state->credentials()))->run(...));
    liveBootstrapCheck(!is_dir($state->home), 'Equal credentials opened live state.');
    liveBootstrapCheck(file_get_contents($state->paths['operator']) === $value, 'Operator credential rotated.');
    liveBootstrapCheck(file_get_contents($state->paths['metrics']) === $value, 'Metrics credential rotated.');
};
$tests['Live bootstrap requires explicit mode and exact opt-in before any effects'] = static function () use ($fixture): void {
    foreach ([[false, '1'], ['local', '1'], ['live', false], ['live', 'true'], ['live', '0']] as [$mode, $enabled]) {
        $state = $fixture();
        $state->configureLive();
        putenv($mode === false ? 'BRIDGE_MODE' : 'BRIDGE_MODE=' . $mode);
        putenv($enabled === false ? 'BRIDGE_LIVE_DEMO_ENABLED' : 'BRIDGE_LIVE_DEMO_ENABLED=' . $enabled);
        liveBootstrapReject((new LiveInitializer($state->credentials()))->run(...));
        liveBootstrapCheck(!file_exists($state->home), 'Rejected live bootstrap opened state.');
        foreach ($state->paths as $path) {
            liveBootstrapCheck(!file_exists($path), 'Rejected live bootstrap generated credentials.');
        }
    }
};
$tests['Live identity validation precedes credential and state creation'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->configureLive();
    putenv('BRIDGE_GITHUB_REPOSITORY=invalid/repository/identity');
    liveBootstrapReject((new LiveInitializer($state->credentials()))->run(...));
    liveBootstrapCheck(!is_dir($state->home), 'Invalid live identity opened state.');
    liveBootstrapCheck(!file_exists($state->paths['operator']), 'Invalid identity generated credentials.');
};
$tests['Live bootstrap creates schemas without provider credentials or fixture records and preserves pending work'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->configureLive();
    // No vendor tokens, token files or Zendesk email are configured in this process.
    (new LiveInitializer($state->credentials()))->run();
    liveBootstrapCheck(is_file($state->home . '/live.sqlite'), 'Business schema was not created.');
    liveBootstrapCheck(is_file($state->home . '/metrics.sqlite'), 'Metric schema was not created.');
    foreach (['local.sqlite', 'github.json', 'zendesk.json', 'traffic-owner.json'] as $filename) {
        liveBootstrapCheck(!file_exists($state->home . '/' . $filename), 'Live bootstrap seeded local data.');
    }
    $context = new Context(Configuration::fromEnvironment());
    liveBootstrapCheck($context->store->all() === [], 'Live bootstrap created a link.');
    $credentials = $state->contents();

    $context->store->addPair('retained', '19', '123');
    $before = ['title' => 'Synthetic case', 'state' => 'open'];
    $plan = [
        'target' => ['title' => 'Synthetic case', 'state' => 'closed'],
        'before' => ['github' => $before, 'zendesk' => $before],
        'versions' => ['github' => 'github-v1', 'zendesk' => 'zendesk-v1'],
    ];
    $context->store->update('retained', [
        'status' => 'awaiting_approval',
        'plan' => $plan,
        'error' => 'state_change_requires_approval',
    ]);
    $pending = $context->store->get('retained');
    (new LiveInitializer($state->credentials()))->run();
    liveBootstrapCheck($context->store->get('retained') === $pending, 'Bootstrap changed pending evidence.');
    liveBootstrapCheck($state->contents() === $credentials, 'Live bootstrap rotated credentials.');
    liveBootstrapCheck(!is_file($state->home . '/github.json'), 'Repeated live bootstrap created providers.');
};
$tests['Local initialization still rejects live mode and preserves an existing local fixture'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->configureLive();
    liveBootstrapReject((new Initializer($state->credentials()))->run(...));
    liveBootstrapCheck(!file_exists($state->paths['operator']), 'Local guard generated live credentials.');
    liveBootstrapCheck(!is_dir($state->home), 'Local guard opened live state.');

    putenv('BRIDGE_MODE=local');
    (new Initializer($state->credentials()))->run();
    $context = new Context(Configuration::fromEnvironment());
    liveBootstrapCheck(count($context->store->all()) === 1, 'Local fixture link was not initialized.');
    $context->providers()[0]->edit('101', ['title' => 'Keep this existing local edit']);
    $source = file_get_contents($state->home . '/github.json');
    $credentials = $state->contents();
    (new Initializer($state->credentials()))->run();
    liveBootstrapCheck(file_get_contents($state->home . '/github.json') === $source, 'Local fixture was reseeded.');
    liveBootstrapCheck($state->contents() === $credentials, 'Local reinitialization rotated credentials.');
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        foreach ($environmentNames as $variable) {
            putenv($variable);
        }
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            ++$failed;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
} finally {
    foreach ($environment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    foreach ($fixtures as $state) {
        $state->cleanup();
    }
}

echo count($tests) . ' live bootstrap checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
