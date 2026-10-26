<?php
declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Record;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;

/** Persistent local stand-in for one remote API. Never opens a network connection. */
final class Local implements Provider
{
    public function __construct(private readonly string $path)
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create local fixture directory.');
        }
    }

    public function seed(array $records): void
    {
        $this->access(function (array &$data) use ($records): void {
            if ($data !== []) {
                throw new \RuntimeException('Local fixture already exists; use a fresh state directory.');
            }
            $data = ['records' => [], 'fault' => null, 'writes' => 0];
            foreach ($records as $id => $fields) {
                new Record($fields, '1');
                $data['records'][(string) $id] = ['fields' => $fields, 'version' => 1, 'writable' => true];
            }
        }, true);
    }

    public function fetch(string $id): Record
    {
        return $this->access(function (array &$data) use ($id): Record {
            $r = $data['records'][$id] ?? throw new RemoteFailure('Local record was not found.');
            return new Record($r['fields'], (string) $r['version'], $r['writable']);
        });
    }

    /** Add an owned synthetic record without changing one retained from a previous run. */
    public function ensureRecord(string $id, array $fields, string $owner): void
    {
        if (preg_match('/^[1-9][0-9]{0,15}$/D', $id) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $owner) !== 1) {
            throw new \InvalidArgumentException('Synthetic records require a numeric ID and an ownership marker.');
        }
        new Record($fields, '1');
        $this->access(static function (array &$data) use ($id, $fields, $owner): void {
            if (!isset($data['records']) || !is_array($data['records'])) {
                throw new \RuntimeException('Initialize local fixtures before adding synthetic records.');
            }
            if (isset($data['records'][$id])) {
                if (($data['records'][$id]['owner'] ?? null) !== $owner) {
                    throw new \RuntimeException('A synthetic record ID is already in use.');
                }
                return;
            }
            $data['records'][$id] = ['fields' => $fields, 'version' => 1, 'writable' => true, 'owner' => $owner];
        }, true);
    }

    public function patch(string $id, array $changes, Record $expected): void
    {
        $failure = $this->access(function (array &$data) use ($id, $changes, $expected): ?string {
            $r = $data['records'][$id] ?? throw new RemoteFailure('Local record was not found.');
            if (!$r['writable']) {
                throw new RemoteFailure('Local record is immutable.');
            }
            if ((string) $r['version'] !== $expected->version) {
                throw new RemoteConflict('Local version changed.');
            }
            $fault = $data['record_faults'][$id] ?? $data['fault'];
            if (isset($data['record_faults'][$id])) {
                unset($data['record_faults'][$id]);
            } else {
                $data['fault'] = null;
            }
            if ($fault === 'before') {
                return $fault;
            }
            $fields = array_replace($r['fields'], $changes);
            new Record($fields, (string) ($r['version'] + 1));
            $data['records'][$id]['fields'] = $fields;
            ++$data['records'][$id]['version'];
            ++$data['writes'];
            return $fault;
        }, true);
        if ($failure !== null) {
            throw new RemoteFailure($failure === 'after' ? 'Simulated response lost after remote commit.' : 'Simulated service unavailable.', true);
        }
    }

    public function edit(string $id, array $changes): void
    {
        $this->access(function (array &$data) use ($id, $changes): void {
            $r = $data['records'][$id] ?? throw new RemoteFailure('Local record was not found.');
            $fields = array_replace($r['fields'], $changes);
            new Record($fields, (string) ($r['version'] + 1));
            $data['records'][$id]['fields'] = $fields;
            ++$data['records'][$id]['version'];
        }, true);
    }

    public function failNext(string $when, ?string $id = null): void
    {
        if (!in_array($when, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Fault must be before or after.');
        }
        $this->access(static function (array &$data) use ($when, $id): void {
            if ($id === null) {
                $data['fault'] = $when;
                return;
            }
            if (!isset($data['records'][$id])) {
                throw new \InvalidArgumentException('A targeted fault requires an existing local record.');
            }
            $data['record_faults'][$id] = $when;
        }, true);
    }

    public function writes(): int
    {
        return $this->access(static fn(array $data): int => $data['writes']);
    }

    private function access(callable $action, bool $write = false): mixed
    {
        $f = fopen($this->path, 'c+');
        if ($f === false || !flock($f, $write ? LOCK_EX : LOCK_SH)) {
            throw new \RuntimeException('Cannot lock local fixture.');
        }
        try {
            $text = stream_get_contents($f);
            $data = $text === '' ? [] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            $result = $action($data);
            if ($write) {
                $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                rewind($f);
                if (!ftruncate($f, 0) || fwrite($f, $json) !== strlen($json) || !fflush($f)) {
                    throw new \RuntimeException('Cannot persist local fixture.');
                }
            }
            return $result;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
