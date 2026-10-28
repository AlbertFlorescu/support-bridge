<?php

declare(strict_types=1);

namespace Bridge\Simulation\Replay;

use Bridge\Provider\Identity;
use RuntimeException;

/** Appends captured checkpoints atomically; it never imports them into application state. */
final readonly class EvidenceArchive
{
    private const MAX_BYTES = 2_097_152;

    public function __construct(private string $path)
    {
    }

    public function append(array $run, array $checkpoint): void
    {
        if (!is_string($run['zendesk_subdomain'] ?? null)) {
            throw new RuntimeException('Replay identity requires a Zendesk tenant.');
        }
        $run['zendesk_subdomain'] = Identity::subdomain($run['zendesk_subdomain']);
        if (is_link($this->path) || is_link($this->path . '.lock')) {
            throw new RuntimeException('Replay files must not be symbolic links.');
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Replay output requires an existing writable directory.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot acquire the replay archive lock.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Cannot acquire the replay archive lock.');
        }
        $temporary = null;
        try {
            $document = ['version' => 1, 'run' => $run, 'checkpoints' => []];
            if (is_file($this->path)) {
                if (filesize($this->path) > self::MAX_BYTES) {
                    throw new RuntimeException('Replay archive is too large.');
                }
                $existing = (string) file_get_contents($this->path, false, null, 0, self::MAX_BYTES + 1);
                if (strlen($existing) > self::MAX_BYTES) {
                    throw new RuntimeException('Replay archive is too large.');
                }
                $document = json_decode($existing, true, 128, JSON_THROW_ON_ERROR);
                if (($document['version'] ?? null) !== 1 || !is_array($document['checkpoints'] ?? null)) {
                    throw new RuntimeException('Replay archive is incompatible.');
                }
                foreach (['id', 'mode', 'repository', 'zendesk_subdomain'] as $identity) {
                    if (($document['run'][$identity] ?? null) !== ($run[$identity] ?? null)) {
                        throw new RuntimeException('A replay cannot combine different run identities.');
                    }
                }
            }
            if (count($document['checkpoints']) >= 100) {
                throw new RuntimeException('Replay checkpoint limit reached.');
            }
            $checkpoint['id'] = 'checkpoint-' . (count($document['checkpoints']) + 1);
            $document['checkpoints'][] = $checkpoint;
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::MAX_BYTES) {
                throw new RuntimeException('Replay archive is too large.');
            }
            $temporary = tempnam($directory, '.replay-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot prepare the replay archive.');
            }
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot write replay evidence.');
            }
            if (!rename($temporary, $this->path)) {
                throw new RuntimeException('Cannot publish replay evidence.');
            }
            $temporary = null;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
