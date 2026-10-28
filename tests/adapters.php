<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Console\Application;
use Bridge\Console\ArgumentParser;
use Bridge\Console\JsonOutput;
use Bridge\Runtime\ContainerApplication;
use Bridge\Runtime\HeartbeatWriter;
use Bridge\Runtime\HealthCheck;
use Bridge\Runtime\SecretLoader;
use Bridge\Runtime\StopSignal;

function adapterCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function adapterReject(callable $action): void
{
    try { $action(); } catch (RuntimeException | InvalidArgumentException) { return; }
    throw new RuntimeException('Expected rejection.');
}
$home = sys_get_temp_dir() . '/bridge-adapters-' . bin2hex(random_bytes(8));
mkdir($home, 0700);
$environmentNames = ['BRIDGE_MODE', 'BRIDGE_HOME'];
foreach (['BRIDGE_API_TOKEN', 'BRIDGE_METRICS_TOKEN', 'BRIDGE_GITHUB_TOKEN', 'BRIDGE_ZENDESK_TOKEN'] as $name) {
    $environmentNames[] = $name; $environmentNames[] = $name . '_FILE';
}
$environment = [];
foreach ($environmentNames as $name) { $environment[$name] = getenv($name); putenv($name); }
$tests = [];
$tests['Console parsing rejects invalid input before opening application state'] = static function (): void {
    $parser = new ArgumentParser();
    $args = $parser->parse(['inspect', '--mode=local', 'escalation-1', '--home=/tmp/isolated']);
    adapterCheck($args->values === ['escalation-1'] && $args->mode === 'local' && $args->home === '/tmp/isolated', 'Option parsing changed.');
    adapterReject(static fn() => $parser->parse(['sync', 'unexpected']));
    adapterReject(static fn() => $parser->parse(['--unknown=value']));
};
$tests['Console help needs no storage and command output retains its JSON contract'] = static function () use ($home): void {
    $output = $home . '/output'; $errors = $home . '/errors'; $state = $home . '/console';
    $app = new Application(new ArgumentParser(), new JsonOutput($output, $errors));
    adapterCheck($app->run(['help', '--home=' . $state]) === 0 && !is_dir($state), 'Help opened state.');
    adapterCheck(str_contains(file_get_contents($output), 'local-edit'), 'Help is incomplete.');
    adapterCheck($app->run(['init', '--home=' . $state]) === 0, 'Initialization failed.');
    adapterCheck(json_decode(file_get_contents($output), true)['initialized'] === true, 'Init JSON changed.');
    adapterCheck($app->run(['sync', '--home=' . $state]) === 0, 'Equal records did not converge.');
    adapterCheck($app->run(['local-edit', 'zendesk', '501', 'state', 'closed', '--home=' . $state]) === 0, 'Source edit failed.');
    adapterCheck($app->run(['sync', '--home=' . $state]) === 2, 'Approval wait lost its exit code.');
    $context = new Context(Configuration::fromEnvironment('local', $state));
    $pair = $context->store->get('escalation-1');
    adapterCheck($pair['status'] === 'awaiting_approval' && $pair['approval'] === null, 'CLI bypassed review.');
    adapterCheck($app->run(['unknown', '--home=' . $state]) === 1, 'Invalid command exit changed.');
};
$tests['Console retry exit code and operator-attention precedence reflect durable state'] = static function () use ($home): void {
    $state = $home . '/exit'; $context = new Context(Configuration::fromEnvironment('local', $state));
    $context->initialize(); $context->engine()->run(); [$github, $zendesk] = $context->providers();
    $github->edit('101', ['title' => 'Retry this title']); $zendesk->failNext('before', '501');
    $app = new Application(new ArgumentParser(), new JsonOutput($home . '/exit-output', $home . '/exit-error'));
    adapterCheck($app->run(['sync', '--home=' . $state]) === 3, 'Pending retries lost their exit code.');
    $pair = $context->store->get('escalation-1');
    adapterCheck($pair['status'] === 'retry' && $pair['plan'] !== null, 'Retry intent was discarded.');
    $fields = ['title' => 'Second escalation', 'state' => 'open']; $owner = str_repeat('a', 32);
    $github->ensureRecord('102', $fields, $owner); $zendesk->ensureRecord('502', $fields, $owner);
    $context->link('second', '102', '502'); $context->engine()->run();
    $zendesk->edit('502', ['state' => 'closed']);
    adapterCheck($app->run(['sync', '--home=' . $state]) === 2, 'Operator attention must take precedence.');
};
$tests['Console local edits respect the canonical command lock'] = static function () use ($home): void {
    $state = $home . '/locked'; $context = new Context(Configuration::fromEnvironment('local', $state));
    $context->initialize(); $lock = $context->store->lock();
    try {
        $app = new Application(new ArgumentParser(), new JsonOutput($home . '/lock-output', $home . '/lock-error'));
        adapterCheck($app->run(['local-edit', 'github', '101', 'title', 'Blocked', '--home=' . $state]) === 1, 'Edit bypassed lock.');
        adapterCheck($context->providers()[0]->fetch('101')->fields['title'] !== 'Blocked', 'A blocked edit changed source data.');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
};
$tests['Secret loading accepts one configured source and rejects conflicting or unsafe values'] = static function () use ($home): void {
    $path = $home . '/token'; file_put_contents($path, str_repeat('t', 40) . "\n");
    putenv('BRIDGE_API_TOKEN_FILE=' . $path);
    try {
        (new SecretLoader())->load();
        adapterCheck(getenv('BRIDGE_API_TOKEN') === str_repeat('t', 40), 'Secret file was not loaded.');
        adapterReject(static fn() => (new SecretLoader())->load());
        putenv('BRIDGE_API_TOKEN'); file_put_contents($path, "invalid\nheader");
        adapterReject(static fn() => (new SecretLoader())->load());
    } finally { putenv('BRIDGE_API_TOKEN'); putenv('BRIDGE_API_TOKEN_FILE'); }
};
$tests['Native CLI loads file credentials once after parsing and rejects ambiguous sources before state'] = static function () use ($home): void {
    $path = $home . '/native-token'; file_put_contents($path, str_repeat('n', 40));
    $state = $home . '/native-file-state'; $blocked = $home . '/ambiguous-file-state';
    $app = new Application(new ArgumentParser(), new JsonOutput($home . '/native-output', $home . '/native-error'));
    putenv('BRIDGE_API_TOKEN_FILE=' . $path);
    try {
        adapterCheck($app->run(['help']) === 0 && getenv('BRIDGE_API_TOKEN') === false, 'Help loaded a credential.');
        adapterCheck($app->run(['init', '--mode=local', '--home=' . $state]) === 0, 'Native CLI did not load its file.');
        adapterCheck(getenv('BRIDGE_API_TOKEN') === str_repeat('n', 40), 'Native credential value differs.');
        putenv('BRIDGE_API_TOKEN=' . str_repeat('x', 40));
        adapterCheck($app->run(['init', '--mode=local', '--home=' . $blocked]) === 1 && !file_exists($blocked), 'Ambiguous credentials opened state.');
        adapterCheck(!str_contains(file_get_contents($home . '/native-error'), str_repeat('x', 40)), 'A credential reached the error output.');
    } finally { putenv('BRIDGE_API_TOKEN'); putenv('BRIDGE_API_TOKEN_FILE'); }
};
$tests['Shared heartbeat checks reject stale or future progress and preserve atomic writes'] = static function () use ($home): void {
    putenv('BRIDGE_HOME=' . $home);
    try {
        $writer = new HeartbeatWriter($home . '/worker-heartbeat.json'); $health = new HealthCheck();
        $writer->write(['observed_at' => time(), 'interval_seconds' => 5]);
        adapterCheck($health->check('worker') === 0 && !file_exists($home . '/worker-heartbeat.json.tmp'), 'Fresh heartbeat rejected.');
        $writer->write(['observed_at' => time() - 100, 'interval_seconds' => 5]);
        adapterCheck($health->check('worker') === 1, 'Stale progress accepted.');
        $writer->write(['observed_at' => time() + 100, 'interval_seconds' => 5]);
        adapterCheck($health->check('worker') === 1 && $health->check('unknown') === 1, 'Invalid health accepted.');
    } finally { putenv('BRIDGE_HOME'); }
};
$tests['Invalid and retired container roles are rejected before creating business state'] = static function () use ($home): void {
    $path = $home . '/must-not-exist'; putenv('BRIDGE_HOME=' . $path);
    try {
        adapterCheck((new ContainerApplication())->run(['worker', 'extra']) === 1, 'Extra role arguments accepted.');
        putenv('BRIDGE_MODE=live');
        adapterCheck((new ContainerApplication())->run(['traffic']) === 1, 'Retired role was accepted.');
        adapterCheck((new HealthCheck())->check('traffic') === 1, 'Retired role passed its health check.');
        adapterCheck(!file_exists($path), 'Invalid startup opened business state.');
    } finally { putenv('BRIDGE_HOME'); putenv('BRIDGE_MODE'); }
};
$tests['Stop signal interrupts a runner without terminating its in-flight operation'] = static function (): void {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) { return; }
    $stop = new StopSignal(); adapterCheck($stop->running(), 'Runner starts stopped.');
    posix_kill(getmypid(), SIGTERM); adapterCheck(!$stop->running(), 'SIGTERM did not stop scheduling.');
};
$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (Throwable $error) { ++$failed; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
    }
} finally {
    foreach ($environment as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($home);
}
echo count($tests) . ' console/runtime checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
