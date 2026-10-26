<?php

declare(strict_types=1);

namespace Bridge\Runtime;

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\Simulation\OperatorClient;
use Bridge\Simulation\TrafficSettings;
use Bridge\Simulation\Workload;
use RuntimeException;
use Throwable;

final class ContainerApplication
{
    public function run(array $arguments): int
    {
        umask(0077);
        $role = $arguments[0] ?? 'api';
        try {
            (new SecretLoader())->load();
            $extra = array_slice($arguments, 1);
            if ($role !== 'cli' && $extra !== []) {
                throw new RuntimeException('Only the cli role accepts additional arguments.');
            }
            switch ($role) {
                case 'init':
                    (new Initializer())->run();
                    return 0;
                case 'worker':
                    $this->worker()->run();
                    return 0;
                case 'traffic':
                    $this->traffic()->run();
                    return 0;
                default:
                    $command = match ($role) {
                        'api' => [
                            PHP_BINARY,
                            '-d',
                            'display_errors=0',
                            '-S',
                            '0.0.0.0:8080',
                            '-t',
                            '/app/public',
                            '/app/public/router.php',
                        ],
                        'cli' => [
                            PHP_BINARY,
                            '/app/bin/bridge.php',
                            ...$extra,
                        ],
                        'verify' => [
                            PHP_BINARY,
                            '/app/docker/check.php',
                        ],
                        'smoke' => [
                            PHP_BINARY,
                            '/app/docker/verify-stack.php',
                        ],
                        default => throw new RuntimeException('Unknown container role.'),
                    };
                    pcntl_exec(array_shift($command), $command);
                    throw new RuntimeException('Cannot start the container process.');
            }
        } catch (Throwable) {
            file_put_contents(
                'php://stderr',
                "Container configuration or startup failed. Check the role, secret files and deployment settings.\n"
            );
            return 1;
        }
    }

    private function worker(): WorkerRunner
    {
        $interval = getenv('BRIDGE_SWEEP_INTERVAL') ?: '60';
        if (!ctype_digit($interval) || (int) $interval < 1 || (int) $interval > 3600) {
            throw new RuntimeException('Invalid sweep interval.');
        }
        $context = new Context(Configuration::fromEnvironment());
        return new WorkerRunner(
            $context,
            (int) $interval,
            new StopSignal(),
            new HeartbeatWriter($context->configuration->home . '/worker-heartbeat.json'),
            new JsonLineWriter('php://stdout')
        );
    }

    private function traffic(): TrafficRunner
    {
        $settings = TrafficSettings::fromEnvironment();
        $context = new Context(Configuration::fromEnvironment());
        $client = new OperatorClient(Configuration::required('BRIDGE_API_TOKEN'));
        return new TrafficRunner(
            $context,
            $settings,
            new Workload($context, $client->request(...), $settings->review),
            new StopSignal(),
            new HeartbeatWriter('/tmp/traffic-heartbeat.json'),
            new JsonLineWriter('php://stdout')
        );
    }
}
