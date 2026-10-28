<?php

declare(strict_types=1);

namespace Bridge\Simulation\Replay;

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Runtime\SecretLoader;
use InvalidArgumentException;
use Throwable;

final class CaptureConsole
{
    public function run(array $arguments): int
    {
        if ($arguments === ['--help'] || $arguments === []) {
            echo "Usage: capture-demo.php PAIR --output=FILE --run=ID --label=TEXT --operation=TEXT\n";
            echo "Optional: --mode=local|live --home=DIR --prometheus-url=ORIGIN --grafana-url=ORIGIN\n";
            echo "Use --prometheus-query-url=ORIGIN when its internal query address differs from the browser URL.\n";
            echo "Captures observations only; it never synchronizes, approves or selects a source.\n";
            return 0;
        }
        try {
            umask(0077);
            $pair = array_shift($arguments);
            $options = $this->options($arguments);
            foreach (['output', 'run', 'label', 'operation'] as $required) {
                if (!isset($options[$required]) || trim($options[$required]) === '') {
                    throw new InvalidArgumentException('Required capture option missing.');
                }
            }
            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $options['run']) !== 1) {
                throw new InvalidArgumentException('Invalid capture run identity.');
            }
            foreach (['prometheus-url', 'grafana-url', 'prometheus-query-url'] as $key) {
                if (isset($options[$key])) {
                    PrometheusCapture::validateUrl($options[$key], $key === 'prometheus-query-url');
                }
            }
            (new SecretLoader())->load();
            $configuration = Configuration::fromEnvironment($options['mode'] ?? null, $options['home'] ?? null);
            $context = new Context($configuration);
            $checkpoint = (new CheckpointCapture($context))->capture($pair, $options['label'], $options['operation']);
            $queryUrl = $options['prometheus-query-url'] ?? $options['prometheus-url'] ?? null;
            if ($queryUrl !== null) {
                $checkpoint['metrics'] = (new PrometheusCapture($queryUrl))->capture();
            }
            $monitoring = [];
            foreach (['prometheus', 'grafana'] as $service) {
                if (isset($options[$service . '-url'])) {
                    $monitoring[$service . '_url'] = $options[$service . '-url'];
                }
            }
            (new EvidenceArchive($options['output']))->append([
                'id' => $options['run'],
                'mode' => $configuration->mode,
                'captured_at' => $checkpoint['observed_at'],
                'repository' => $configuration->repository,
                'zendesk_subdomain' => $configuration->subdomain,
                'monitoring' => $monitoring,
            ], $checkpoint);
            echo json_encode([
                'captured' => true,
                'pair' => $pair,
                'status' => $checkpoint['pair']['status'],
                'observed_at' => $checkpoint['observed_at'],
            ], JSON_THROW_ON_ERROR) . "\n";
            return 0;
        } catch (Throwable) {
            fwrite(STDERR, "Checkpoint capture failed. Verify the selected state, credentials, options and output.\n");
            return 1;
        }
    }

    private function options(array $arguments): array
    {
        $allowed = [
            'output', 'run', 'label', 'operation', 'mode', 'home',
            'prometheus-url', 'grafana-url', 'prometheus-query-url',
        ];
        $result = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/sD', $argument, $match) !== 1) {
                throw new InvalidArgumentException('Invalid capture option.');
            }
            if (!in_array($match[1], $allowed, true) || array_key_exists($match[1], $result)) {
                throw new InvalidArgumentException('Unknown or duplicate capture option.');
            }
            $result[$match[1]] = $match[2];
        }
        return $result;
    }
}
