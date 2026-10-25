#!/usr/bin/env php
<?php
declare(strict_types=1);

/** One local/CI entry point; no Composer or shell-specific syntax required. */
$root = dirname(__DIR__);
chdir($root);
$files = [__DIR__ . '/../bootstrap.php'];
foreach (['src', 'bin', 'tests', 'public', 'docker'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
$run = static function (array $command, bool $quiet = false): void {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch PHP verification.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if (!$quiet || $code !== 0) {
        echo $out;
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code);
    }
};
foreach ($files as $file) {
    $run([PHP_BINARY, '-l', $file], true);
}
echo 'PHP syntax: ' . count($files) . " files passed.\n\n";
foreach (['tests/domain.php', 'tests/run.php', 'tests/governance.php', 'tests/http.php', 'tests/observability.php', 'tests/api.php', 'tests/api-server.php', 'bin/demo.php'] as $script) {
    $run([PHP_BINARY, $root . '/' . $script]);
}
