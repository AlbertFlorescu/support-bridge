<?php

declare(strict_types=1);

namespace Bridge\Simulation\Replay;

use Bridge\Application\Context;
use Bridge\Application\Query\InspectPair;
use Bridge\Record;
use InvalidArgumentException;

/** Explicit observation command: provider reads are kept outside the local query handlers. */
final readonly class CheckpointCapture
{
    public function __construct(
        private Context $context,
        private EvidenceSanitizer $sanitizer = new EvidenceSanitizer()
    ) {
    }

    public function capture(string $pairId, string $label, string $operation): array
    {
        foreach ([[$label, 200], [$operation, 500]] as [$text, $maximum]) {
            if (trim($text) === '' || strlen($text) > $maximum || preg_match('//u', $text) !== 1) {
                throw new InvalidArgumentException('Checkpoint labels require bounded UTF-8 text.');
            }
        }
        return $this->context->store->withExclusiveLock(function () use ($pairId, $label, $operation): array {
            // Resolve the pair before constructing providers or looking up vendor credentials.
            $inspection = $this->context->ask(new InspectPair($pairId));
            $pair = $inspection['pair'];
            [$github, $zendesk] = $this->context->providers();
            $records = [
                'github' => $this->record('github', $pair['github_id'], $github->fetch($pair['github_id'])),
                'zendesk' => $this->record('zendesk', $pair['zendesk_id'], $zendesk->fetch($pair['zendesk_id'])),
            ];
            return [
                'label' => $label,
                'operation' => $operation,
                'observed_at' => gmdate('c'),
                'pair' => $this->sanitizer->pair($pair),
                'records' => $records,
                'governance' => $inspection['governance'],
                'events' => $this->sanitizer->events($inspection['recent_events']),
                'metrics' => [],
            ];
        });
    }

    private function record(string $side, string $id, Record $record): array
    {
        $configuration = $this->context->configuration;
        $result = [
            'kind' => $configuration->mode,
            'id' => $id,
            'title' => $record->fields['title'],
            'state' => $record->fields['state'],
            'version' => $record->version,
            'writable' => $record->writable,
        ];
        if ($configuration->mode === 'live') {
            $result['url'] = $side === 'github'
                ? 'https://github.com/' . $configuration->repository . '/issues/' . rawurlencode($id)
                : 'https://' . $configuration->subdomain . '.zendesk.com/agent/tickets/' . rawurlencode($id);
        }
        return $result;
    }
}
