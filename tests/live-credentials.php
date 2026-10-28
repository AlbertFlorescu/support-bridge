<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Runtime\VendorCredentialImporter;

final class VendorCredentialFixture
{
    public readonly string $root;
    public readonly string $directory;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/bridge-vendor-credentials-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->directory = $this->root . '/vendor';
    }

    public function run(array|string $values = ['github-token' => 'synthetic-github-token', 'zendesk-token' => 'synthetic-zendesk-token']): array
    {
        $input = fopen('php://temp', 'w+');
        fwrite($input, is_array($values) ? json_encode($values, JSON_THROW_ON_ERROR) : $values);
        rewind($input);
        try {
            return (new VendorCredentialImporter($this->directory))->run($input);
        } finally {
            fclose($input);
        }
    }

    public function cleanup(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($entry->getPathname(), 0700);
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }
}

function vendorCredentialCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function vendorCredentialReject(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected credential import rejection.');
}

$environment = ['BRIDGE_MODE' => getenv('BRIDGE_MODE'), 'BRIDGE_LIVE_DEMO_ENABLED' => getenv('BRIDGE_LIVE_DEMO_ENABLED')];
$fixtures = [];
$fixture = static function () use (&$fixtures): VendorCredentialFixture {
    $instance = new VendorCredentialFixture();
    $fixtures[] = $instance;
    return $instance;
};
$tests = [];
$tests['Import requires exact live opt-in before reading input or creating files'] = static function () use ($fixture): void {
    foreach ([[false, '1'], ['local', '1'], ['live', false], ['live', 'true'], ['live', '0']] as [$mode, $enabled]) {
        putenv($mode === false ? 'BRIDGE_MODE' : 'BRIDGE_MODE=' . $mode);
        putenv($enabled === false ? 'BRIDGE_LIVE_DEMO_ENABLED' : 'BRIDGE_LIVE_DEMO_ENABLED=' . $enabled);
        $state = $fixture();
        vendorCredentialReject(static fn() => (new VendorCredentialImporter($state->directory))->run(null));
        vendorCredentialCheck(!file_exists($state->directory), 'Rejected import created a directory.');
    }
};
$tests['Both bounded token values and the exact JSON object shape are validated before effects'] = static function () use ($fixture): void {
    foreach ([
        '', 'not-json', '[]', '{}', 'null',
        ['github-token' => 'synthetic-github-token'],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => 'synthetic-zendesk-token', 'operator-token' => 'unwanted'],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => 123],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => ''],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => "invalid\nheader"],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => 'contains space'],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => "invalid\x7f"],
        ['github-token' => 'synthetic-github-token', 'zendesk-token' => str_repeat('t', 4097)],
        str_repeat(' ', 16385),
    ] as $values) {
        $state = $fixture();
        vendorCredentialReject(static fn() => $state->run($values));
        vendorCredentialCheck(!file_exists($state->directory), 'Invalid input created credential state.');
    }
};
$tests['Successful import creates only private access token files and safe metadata'] = static function () use ($fixture): void {
    $state = $fixture();
    ob_start();
    try {
        $result = $state->run();
    } finally {
        $output = ob_get_clean();
    }
    vendorCredentialCheck($result === ['status' => 'imported', 'files' => 2, 'changed' => 2], 'Import metadata changed.');
    vendorCredentialCheck($output === '', 'Import class wrote credential output.');
    vendorCredentialCheck((fileperms($state->directory) & 0777) === 0700, 'Credential directory is not private.');
    foreach (['github-token' => 'synthetic-github-token', 'zendesk-token' => 'synthetic-zendesk-token'] as $name => $value) {
        $path = $state->directory . '/' . $name;
        vendorCredentialCheck(file_get_contents($path) === $value && (fileperms($path) & 0777) === 0400, 'Credential value or permissions differ.');
    }
    $names = array_values(array_diff(scandir($state->directory), ['.', '..']));
    vendorCredentialCheck($names === ['.import.lock', 'github-token', 'zendesk-token'], 'Import created unrelated state.');
};
$tests['Repeated import preserves unchanged credential bytes and inodes'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->run();
    $path = $state->directory . '/github-token';
    chmod($path, 0600);
    file_put_contents($path, "synthetic-github-token\n");
    chmod($path, 0400);
    $before = [file_get_contents($path), fileinode($path)];
    $result = $state->run();
    clearstatcache(true, $path);
    vendorCredentialCheck($result['changed'] === 0, 'Matching credentials were replaced.');
    vendorCredentialCheck([file_get_contents($path), fileinode($path)] === $before, 'Matching credential bytes or inode changed.');
};
$tests['Manual rotation replaces only the changed token and leaves no temporary credential files'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->run();
    $githubPath = $state->directory . '/github-token';
    $githubInode = fileinode($githubPath);
    $zendeskPath = $state->directory . '/zendesk-token';
    $oldInode = fileinode($zendeskPath);
    $result = $state->run(['github-token' => 'synthetic-github-token', 'zendesk-token' => 'synthetic-rotated-zendesk-token']);
    clearstatcache();
    vendorCredentialCheck($result['changed'] === 1 && fileinode($githubPath) === $githubInode, 'Rotation changed the wrong credential.');
    vendorCredentialCheck(fileinode($zendeskPath) !== $oldInode && file_get_contents($zendeskPath) === 'synthetic-rotated-zendesk-token', 'Rotation did not replace the credential.');
    vendorCredentialCheck((fileperms($zendeskPath) & 0777) === 0400 && glob($state->directory . '/.vendor-secret-*') === [], 'Rotation leaked temporary credential files.');
};
$tests['Directory links and non-directory targets are rejected without following them'] = static function () use ($fixture): void {
    foreach (['link', 'file'] as $kind) {
        $state = $fixture();
        if ($kind === 'link') {
            $target = $state->root . '/outside';
            mkdir($target, 0700);
            symlink($target, $state->directory);
        } else {
            file_put_contents($state->directory, 'keep this unrelated file');
        }
        vendorCredentialReject($state->run(...));
        vendorCredentialCheck($kind === 'link' ? scandir($target) === ['.', '..'] : file_get_contents($state->directory) === 'keep this unrelated file', 'Rejected directory target was changed.');
    }
};
$tests['Invalid second credential paths prevent any first credential write'] = static function () use ($fixture): void {
    foreach (['link', 'directory', 'hardlink'] as $kind) {
        $state = $fixture();
        mkdir($state->directory, 0700);
        $target = $state->root . '/outside';
        file_put_contents($target, 'synthetic-outside-token');
        $path = $state->directory . '/zendesk-token';
        match ($kind) {
            'link' => symlink($target, $path),
            'directory' => mkdir($path, 0700),
            'hardlink' => link($target, $path),
        };
        vendorCredentialReject($state->run(...));
        vendorCredentialCheck(!file_exists($state->directory . '/github-token'), 'Invalid second path permitted the first write.');
        vendorCredentialCheck(file_get_contents($target) === 'synthetic-outside-token', 'An outside file changed.');
    }
};
$tests['Malformed existing credentials stop rotation before either existing value is replaced'] = static function () use ($fixture): void {
    $state = $fixture();
    $state->run();
    chmod($state->directory . '/zendesk-token', 0600);
    file_put_contents($state->directory . '/zendesk-token', "invalid\nheader");
    chmod($state->directory . '/zendesk-token', 0400);
    vendorCredentialReject(static fn() => $state->run(['github-token' => 'synthetic-rotated-github', 'zendesk-token' => 'synthetic-rotated-zendesk']));
    vendorCredentialCheck(file_get_contents($state->directory . '/github-token') === 'synthetic-github-token', 'Invalid existing second value permitted the first replacement.');
};
$tests['The import lock rejects concurrent writers and symbolic lock paths'] = static function () use ($fixture): void {
    $state = $fixture();
    mkdir($state->directory, 0700);
    $lock = fopen($state->directory . '/.import.lock', 'x');
    flock($lock, LOCK_EX);
    try {
        vendorCredentialReject($state->run(...));
        vendorCredentialCheck(!file_exists($state->directory . '/github-token'), 'Concurrent import wrote a credential.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    unlink($state->directory . '/.import.lock');
    $outside = $state->root . '/outside-lock';
    file_put_contents($outside, 'keep');
    symlink($outside, $state->directory . '/.import.lock');
    vendorCredentialReject($state->run(...));
    vendorCredentialCheck(file_get_contents($outside) === 'keep', 'Import followed the lock link.');
};
$tests['The standalone command rejects malformed input with sanitized output'] = static function (): void {
    $marker = 'synthetic-private-marker-should-never-appear';
    $input = json_encode(['github-token' => $marker, 'zendesk-token' => 123], JSON_THROW_ON_ERROR);
    $pipes = [];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/docker/import-vendor-credentials.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    vendorCredentialCheck(is_resource($process), 'Could not start importer command.');
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    vendorCredentialCheck($code === 1 && $output === '' && str_contains($error, 'Vendor credential import failed.'), 'Importer failure contract changed.');
    vendorCredentialCheck(!str_contains($error, $marker) && !str_contains($error, $input), 'Credential input reached error output.');
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        putenv('BRIDGE_MODE=live');
        putenv('BRIDGE_LIVE_DEMO_ENABLED=1');
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
echo count($tests) . ' live credential import checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
