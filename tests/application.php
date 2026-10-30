<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Command\ApprovePlan;
use Bridge\Application\Command\Command;
use Bridge\Application\Command\EditLocalRecord;
use Bridge\Application\Command\InitializeLocalState;
use Bridge\Application\Command\LinkPair;
use Bridge\Application\Command\Reconcile;
use Bridge\Application\Command\ResolveConflict;
use Bridge\Application\Command\RetryPair;
use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Application\Query\GetMetrics;
use Bridge\Application\Query\GetLinkedPairTable;
use Bridge\Application\Query\GetStatus;
use Bridge\Application\Query\InspectPair;
use Bridge\Application\Query\ListPairs;
use Bridge\Application\Query\Query;
use Bridge\Exception\StateConflict;
use Bridge\Exception\WorkerBusy;

final class ApplicationFixture
{
    public readonly string $home;
    public Context $context;

    public function __construct(string $mode = 'local')
    {
        $this->home = sys_get_temp_dir() . '/bridge-application-' . bin2hex(random_bytes(8));
        $this->context = new Context(new Configuration(
            $mode,
            $this->home,
            $mode === 'live' ? 'example/escalations' : 'local/demo',
            $mode === 'live' ? 'example' : 'local'
        ));
    }

    public function initialize(): void
    {
        $this->context->handle(new InitializeLocalState());
        $this->context->handle(new Reconcile());
    }

    public function cleanup(): void
    {
        foreach (glob($this->home . '/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->home);
    }
}

function applicationSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function applicationThrows(callable $operation, string $exception): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $exception) {
            return;
        }

        throw $error;
    }

    throw new RuntimeException('Expected rejection by ' . $exception);
}

$tests = [];
$tests['Command handlers initialize and link through the shared application boundary'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        applicationSame(
            ['mode' => 'local', 'initialized' => true, 'next' => 'sync'],
            $fixture->context->handle(new InitializeLocalState())
        );
        applicationSame(
            ['id' => 'second', 'status' => 'new'],
            $fixture->context->handle(new LinkPair('second', '102', '502'))
        );
        $pairs = $fixture->context->ask(new ListPairs(1, 1));
        applicationSame(2, $pairs['total']);
        applicationSame('second', $pairs['items'][0]['id']);
        applicationSame(false, array_key_exists('baseline', $pairs['items'][0]));
        applicationSame(false, array_key_exists('plan', $pairs['items'][0]));
    } finally {
        $fixture->cleanup();
    }
};

$tests['Queries remain read-only and work while the command lock is held'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $before = [
            $fixture->context->store->all(),
            $fixture->context->store->events('escalation-1'),
            hash_file('sha256', $fixture->home . '/github.json'),
            hash_file('sha256', $fixture->home . '/zendesk.json'),
        ];
        $lock = $fixture->context->store->lock();

        try {
            applicationSame(1, $fixture->context->ask(new ListPairs())['total']);
            applicationSame('synced', $fixture->context->ask(new GetStatus())[0]['status']);
            applicationSame(
                'escalation-state-v1',
                $fixture->context->ask(new InspectPair('escalation-1'))['governance']['policy_version']
            );
            applicationSame(1, $fixture->context->ask(new GetMetrics())['pairs']['synced']);
            $table = $fixture->context->ask(new GetLinkedPairTable());
            applicationSame(1, count($table['items']));
            applicationSame('101', $table['items'][0]['github_issue']);
            applicationSame('CSV export fails for large reports', $table['items'][0]['subject']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        applicationSame($before, [
            $fixture->context->store->all(),
            $fixture->context->store->events('escalation-1'),
            hash_file('sha256', $fixture->home . '/github.json'),
            hash_file('sha256', $fixture->home . '/zendesk.json'),
        ]);
    } finally {
        $fixture->cleanup();
    }
};

$tests['Live read queries and initialization do not construct credential-dependent providers'] = static function (): void {
    $fixture = new ApplicationFixture('live');

    try {
        applicationSame(0, $fixture->context->ask(new ListPairs())['total']);
        applicationSame([], $fixture->context->ask(new GetStatus()));
        applicationSame(0, $fixture->context->ask(new GetMetrics())['pairs']['synced']);
        applicationSame(['items' => []], $fixture->context->ask(new GetLinkedPairTable()));
        applicationSame('live', $fixture->context->handle(new InitializeLocalState())['mode']);
        applicationSame(false, file_exists($fixture->home . '/github.json'));
        applicationSame(false, file_exists($fixture->home . '/zendesk.json'));
        applicationThrows(
            static fn () => $fixture->context->handle(new EditLocalRecord('github', '101', 'title', 'Changed')),
            InvalidArgumentException::class
        );
    } finally {
        $fixture->cleanup();
    }
};

$tests['State-change commands retain exact-plan review and approval does not execute writes'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $fixture->context->handle(new EditLocalRecord('zendesk', '501', 'state', 'closed'));
        applicationSame(1, $fixture->context->handle(new Reconcile())['awaiting_approval']);
        $inspection = $fixture->context->ask(new InspectPair('escalation-1'));
        applicationSame(null, $inspection['pair']['approval']);
        applicationSame(true, $inspection['governance']['requires_approval']);
        [$github] = $fixture->context->providers();
        applicationSame('open', $github->fetch('101')->fields['state']);
        $fixture->context->handle(new ApprovePlan(
            'escalation-1',
            $inspection['governance']['plan_hash'],
            'Reviewed this synthetic resolution.'
        ));
        applicationSame('open', $github->fetch('101')->fields['state']);
        applicationSame(1, $fixture->context->handle(new Reconcile())['synced']);
        applicationSame('closed', $github->fetch('101')->fields['state']);
    } finally {
        $fixture->cleanup();
    }
};

$tests['Source selection commands cannot grant approval for their state effects'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $fixture->context->handle(new EditLocalRecord('github', '101', 'title', 'Engineering summary'));
        $fixture->context->handle(new EditLocalRecord('github', '101', 'state', 'closed'));
        $fixture->context->handle(new EditLocalRecord('zendesk', '501', 'title', 'Support summary'));
        applicationSame(1, $fixture->context->handle(new Reconcile())['conflict']);
        $fixture->context->handle(new ResolveConflict('escalation-1', 'github'));
        applicationSame(null, $fixture->context->ask(new InspectPair('escalation-1'))['pair']['approval']);
        applicationSame(1, $fixture->context->handle(new Reconcile())['awaiting_approval']);
        applicationThrows(
            static fn () => $fixture->context->handle(new RetryPair('escalation-1')),
            StateConflict::class
        );
    } finally {
        $fixture->cleanup();
    }
};

$tests['A restarted application preserves the pending plan and legacy facade results'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $fixture->context->handle(new EditLocalRecord('zendesk', '501', 'state', 'closed'));
        $fixture->context->handle(new Reconcile());
        $before = $fixture->context->inspect('escalation-1');
        $fixture->context = new Context($fixture->context->configuration);
        $fixture->context->initialize();
        applicationSame($before, $fixture->context->ask(new InspectPair('escalation-1')));
        applicationSame($fixture->context->listPairs(), $fixture->context->ask(new ListPairs()));
        applicationSame($fixture->context->inspect('escalation-1'), $fixture->context->ask(new InspectPair('escalation-1')));
        $fixture->context->link('second', '102', '502');
        applicationSame(2, $fixture->context->ask(new ListPairs())['total']);
    } finally {
        $fixture->cleanup();
    }
};

$tests['Local edits and link commands share the canonical command lock'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $lock = $fixture->context->store->lock();

        try {
            applicationThrows(
                static fn () => $fixture->context->handle(new EditLocalRecord('github', '101', 'title', 'Blocked')),
                WorkerBusy::class
            );
            applicationThrows(
                static fn () => $fixture->context->handle(new LinkPair('second', '102', '502')),
                WorkerBusy::class
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        applicationSame('CSV export fails for large reports', $fixture->context->providers()[0]->fetch('101')->fields['title']);
        applicationSame(1, $fixture->context->ask(new ListPairs())['total']);
    } finally {
        $fixture->cleanup();
    }
};

$tests['Unsupported use cases fail without changing business state'] = static function (): void {
    $fixture = new ApplicationFixture();

    try {
        $fixture->initialize();
        $before = $fixture->context->store->all();
        applicationThrows(
            static fn () => $fixture->context->handle(new class implements Command {
            }),
            InvalidArgumentException::class
        );
        applicationThrows(
            static fn () => $fixture->context->ask(new class implements Query {
            }),
            InvalidArgumentException::class
        );
        applicationSame($before, $fixture->context->store->all());
    } finally {
        $fixture->cleanup();
    }
};

$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}

echo count($tests) . ' application use-case checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
