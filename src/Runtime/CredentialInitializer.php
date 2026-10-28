<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use RuntimeException;

/** Generates missing deployment credentials without rotating existing values. */
final readonly class CredentialInitializer
{
    public function __construct(
        private string $operatorPath = '/run/secrets/operator/token',
        private string $metricsPath = '/run/secrets/metrics/token',
        private string $grafanaPasswordPath = '/run/secrets/grafana/password',
        private string $grafanaKeyPath = '/run/secrets/grafana/secret-key'
    ) {
    }

    public function run(): void
    {
        $paths = [$this->operatorPath, $this->metricsPath, $this->grafanaPasswordPath, $this->grafanaKeyPath];
        foreach ($paths as $path) {
            if (is_link($path)) {
                throw new RuntimeException('Secret files must be ordinary files.');
            }
            if (!file_exists($path)) {
                $this->create($path);
            }
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Secret files must be readable ordinary files.');
            }
            $value = trim((string) @file_get_contents($path));
            if (strlen($value) < 32 || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new RuntimeException('An existing credential is invalid; refusing to replace it.');
            }
            // Different container UIDs read only their separately mounted credential volumes.
            if (!chmod($path, 0444)) {
                throw new RuntimeException('Cannot set credential permissions.');
            }
        }

        if (
            hash_equals(
                trim((string) file_get_contents($this->operatorPath)),
                trim((string) file_get_contents($this->metricsPath))
            )
        ) {
            throw new RuntimeException('Operator and metrics credentials must differ.');
        }
    }

    private function create(string $path): void
    {
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create a credential.');
        }
        try {
            $value = bin2hex(random_bytes(32)) . "\n";
            if (fwrite($stream, $value) !== strlen($value) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('Cannot persist a credential.');
            }
        } finally {
            fclose($stream);
        }
    }
}
