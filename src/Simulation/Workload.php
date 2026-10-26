<?php

declare(strict_types=1);

namespace Bridge\Simulation;

use Bridge\Application\Context;

/** External actors and scripted reviews for the isolated local traffic stack. */
final class Workload
{
    private readonly \Closure $request;
    private readonly \Closure $clock;
    private array $waitingSince = [];
    private const TOPICS = [
        'CSV export stalls above 100k rows',
        'Webhook deliveries arrive twice',
        'SSO sign-in loops after session expiry',
        'Invoice download returns an empty file',
        'Search misses recently imported accounts',
        'Report generation intermittently times out',
        'Attachment upload loses its response',
        'Dashboard totals refresh slowly',
    ];

    public function __construct(
        private readonly Context $context,
        callable $request,
        private readonly string $review = 'scripted',
        ?callable $clock = null
    ) {
        if ($context->configuration->mode !== 'local' || !in_array($review, ['scripted', 'manual'], true)) {
            throw new \InvalidArgumentException(
                'Synthetic workloads require local providers and a supported review mode.'
            );
        }
        $this->request = \Closure::fromCallable($request);
        $this->clock = \Closure::fromCallable($clock ?? time(...));
    }

    public static function mapping(): array
    {
        $mapping = [];
        for ($i = 1; $i <= 8; ++$i) {
            $mapping['traffic-' . $i] = [
                'github_id'  => (string) (91000 + $i),
                'zendesk_id' => (string) (95000 + $i),
            ];
        }
        return $mapping;
    }

    public function provision(): void
    {
        $mapping = self::mapping();
        $this->context->store->withExclusiveLock(function () use ($mapping): void {
            $path = $this->context->configuration->home . '/traffic-owner.json';
            if (is_link($path)) {
                throw new \RuntimeException('The traffic ownership marker must be an ordinary file.');
            }
            $exists = is_file($path);
            foreach ($this->context->store->all() as $pair) {
                $expected = $mapping[$pair['id']] ?? ($pair['id'] === 'escalation-1'
                    ? ['github_id' => '101', 'zendesk_id' => '501'] : null);
                if (
                    $expected === null || $pair['github_id'] !== $expected['github_id']
                    || $pair['zendesk_id'] !== $expected['zendesk_id'] || (!$exists && isset($mapping[$pair['id']]))
                ) {
                    throw new \RuntimeException('Traffic requires its isolated synthetic link set.');
                }
            }
            if ($exists) {
                $marker = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
                if (
                    !is_array($marker) || ($marker['version'] ?? null) !== 1
                        || ($marker['mapping'] ?? null) !== $mapping
                    || !is_string($marker['owner'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $marker['owner']) !== 1
                ) {
                    throw new \RuntimeException('The traffic ownership marker is invalid.');
                }
            } else {
                $marker = [
                    'version' => 1,
                    'owner'   => bin2hex(random_bytes(16)),
                    'mapping' => $mapping,
                ];
                $stream = fopen($path, 'x');
                if ($stream === false) {
                    throw new \RuntimeException('Cannot create the traffic ownership marker.');
                }
                try {
                    $json = json_encode($marker, JSON_THROW_ON_ERROR);
                    if (fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !fsync($stream)) {
                        throw new \RuntimeException('Cannot persist the traffic ownership marker.');
                    }
                } finally {
                    fclose($stream);
                }
            }
            [
                $github,
                $zendesk,
            ] = $this->context->providers();
            foreach (array_values($mapping) as $index => $ids) {
                $fields = [
                    'title' => self::TOPICS[$index],
                    'state' => 'open',
                ];
                $github->ensureRecord($ids['github_id'], $fields, $marker['owner']);
                $zendesk->ensureRecord($ids['zendesk_id'], $fields, $marker['owner']);
            }
        });
        foreach ($mapping as $id => $ids) {
            $observed = ($this->request)('GET', '/api/pairs/' . $id, []);
            if ($observed['status'] === 404) {
                $this->call('POST', '/api/pairs', ['id' => $id] + $ids, 201);
            } else {
                $this->accepted($observed, 200);
                $pair = $observed['body']['pair'] ?? [];
                if (
                    ($pair['github_id'] ?? null) !== $ids['github_id']
                    || ($pair['zendesk_id'] ?? null) !== $ids['zendesk_id']
                ) {
                    throw new \RuntimeException('The API and traffic storage do not share the expected mappings.');
                }
            }
        }
    }

    public function tick(int $sequence): array
    {
        if ($sequence < 1) {
            throw new \InvalidArgumentException('Traffic sequence must be positive.');
        }
        $index = ($sequence - 1) % 8;
        $id = 'traffic-' . ($index + 1);
        $inspection = $this->call('GET', '/api/pairs/' . $id);
        $pair = $inspection['pair'] ?? throw new \RuntimeException('Traffic inspection has no pair.');
        $status = $pair['status'] ?? '';
        $action = 'baseline';
        if (in_array($status, ['conflict', 'awaiting_approval'], true)) {
            $identity = $status . ':' . hash('sha256', json_encode(
                [
                    $pair['plan'],
                    $pair['conflict'],
                ],
                JSON_THROW_ON_ERROR
            ));
            if (($this->waitingSince[$id]['identity'] ?? null) !== $identity) {
                $this->waitingSince[$id] = [
                    'identity' => $identity,
                    'since'    => ($this->clock)(),
                ];
            }
            $action = 'review_wait';
            if ($this->review === 'scripted' && ($this->clock)() - $this->waitingSince[$id]['since'] >= 10) {
                if ($status === 'conflict') {
                    $this->call('POST', '/api/pairs/' . $id . '/resolve', ['source' => 'github']);
                    $action = 'scripted_resolution';
                } else {
                    $hash = $inspection['governance']['plan_hash'] ?? '';
                    $this->call('POST', '/api/pairs/' . $id . '/approve', [
                        'plan_hash' => $hash,
                        'reason'    => 'Synthetic scripted review of this exact local traffic ' .
                            'plan; no human or compliance approval.',
                    ]);
                    $action = 'scripted_approval';
                }
                unset($this->waitingSince[$id]);
            }
        } elseif ($status === 'synced') {
            unset($this->waitingSince[$id]);
            $action = $this->edit($id, $index, $sequence);
        } elseif ($status === 'retry') {
            $action = 'retry_wait';
        } elseif ($status === 'dead') {
            $action = 'dead_wait';
        } elseif ($status !== 'new') {
            throw new \RuntimeException('Traffic inspection returned an unknown workflow status.');
        }
        $outcome = $this->call('POST', '/api/sync');
        return [
            'event'    => 'traffic_tick',
            'sequence' => $sequence,
            'pair'     => $id,
            'action'   => $action,
            'outcome'  => $outcome,
        ];
    }

    private function edit(string $id, int $index, int $sequence): string
    {
        return $this->context->store->withExclusiveLock(function () use ($id, $index, $sequence): string {
            $pair = $this->context->store->get($id);
            if ($pair['status'] !== 'synced' || $pair['plan'] !== null) {
                return 'state_changed';
            }
            [
                $github,
                $zendesk,
            ] = $this->context->providers();
            $title = self::TOPICS[$index] . ' — update ' . $sequence;
            $nextState = $zendesk->fetch($pair['zendesk_id'])->fields['state'] === 'open' ? 'closed' : 'open';
            switch ($index) {
                case 0:
                    $zendesk->edit($pair['zendesk_id'], ['title' => $title]);
                    return 'support_title';
                case 1:
                    $github->edit($pair['github_id'], ['title' => $title]);
                    return 'engineering_title';
                case 2:
                    $github->edit($pair['github_id'], ['title' => $title]);
                    $zendesk->edit($pair['zendesk_id'], ['state' => $nextState]);
                    return 'independent_merge';
                case 3:
                    $zendesk->edit($pair['zendesk_id'], ['state' => $nextState]);
                    return 'state_change';
                case 4:
                    $github->edit($pair['github_id'], ['title' => $title . ' / engineering']);
                    $zendesk->edit($pair['zendesk_id'], ['title' => $title . ' / support']);
                    return 'conflict_created';
                case 5:
                case 6:
                    $github->edit($pair['github_id'], ['title' => $title]);
                    $zendesk->failNext($index === 5 ? 'before' : 'after', $pair['zendesk_id']);
                    return $index === 5 ? 'transient_failure' : 'lost_response';
                default:
                    return 'no_op';
            }
        });
    }

    private function call(string $method, string $path, array $body = [], int $expected = 200): array
    {
        $response = ($this->request)($method, $path, $body);
        $this->accepted($response, $expected);
        return $response['body'];
    }

    private function accepted(array $response, int $expected): void
    {
        if (($response['status'] ?? null) === 409) {
            throw new TrafficUnavailable('Traffic must reobserve after operator contention or changed evidence.');
        }
        if (($response['status'] ?? null) !== $expected || !is_array($response['body'] ?? null)) {
            throw new \RuntimeException('The operator API rejected a synthetic traffic operation.');
        }
    }
}
