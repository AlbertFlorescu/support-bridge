<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeProvider.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Exception\WorkerBusy;
use Bridge\Http\Api;
use Bridge\Http\Request;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Infrastructure\Persistence\EscalationRepository;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Intake\EngineeringIssues;
use Bridge\Intake\EscalationTicket;
use Bridge\Intake\EscalationWorkflow;
use Bridge\Intake\EngineeringIssueBody;
use Bridge\Intake\TicketSource;
use Bridge\Provider\GitHubEscalations;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Provider\ZendeskEscalations;
use Bridge\Record;
use Bridge\RemoteFailure;
use Bridge\Store;

function intakeAssert(bool $condition, string $message = 'Escalation expectation failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function intakeReject(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        intakeAssert($error instanceof $type, 'Unexpected escalation failure type.');
        return;
    }
    throw new RuntimeException('Expected escalation rejection.');
}

final class IntakeSourceFixture implements TicketSource
{
    public array $tickets = [];
    public array $pages = [];
    public bool $withdraw = false;
    public ?string $freshDescription = null;

    public function discover(string $tag, int $page): array
    {
        $this->pages[] = $page;
        return ['tickets' => array_values($this->tickets), 'has_more' => $page === 1];
    }

    public function fetch(string $id): EscalationTicket
    {
        $ticket = $this->tickets[$id];
        if ($this->freshDescription !== null) {
            $ticket = new EscalationTicket($id, $ticket->record, $ticket->tags, $this->freshDescription);
        } elseif ($ticket->description === null) {
            $ticket = new EscalationTicket($id, $ticket->record, $ticket->tags, '');
        }
        return $this->withdraw ? new EscalationTicket($id, $ticket->record, []) : $ticket;
    }
}

final class IntakeIssuesFixture implements EngineeringIssues
{
    public array $created = [];
    public int $posts = 0;
    public bool $loseResponse = false;
    public bool $hide = false;
    public bool $failLookup = false;
    public int $lookupRetryAfter = 0;
    public ?Closure $beforeCreate = null;

    public function find(string $marker): ?string
    {
        if ($this->failLookup) {
            throw new RemoteFailure('Synthetic lookup failure.', true, $this->lookupRetryAfter);
        }
        return $this->hide ? null : ($this->created[$marker] ?? null);
    }

    public function create(EscalationTicket $ticket, string $marker): string
    {
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)($ticket, $marker);
        }
        $id = (string) (100 + ++$this->posts);
        $this->created[$marker] = $id;
        if ($this->loseResponse) {
            throw new RemoteFailure('Synthetic lost create response.', true);
        }
        return $id;
    }
}

function intakeFixture(callable $operation): void
{
    $directory = sys_get_temp_dir() . '/bridge-intake-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $connection = new SqliteConnection($directory . '/state.sqlite');
    $store = new Store($connection);
    $repository = new EscalationRepository($connection);
    $source = new IntakeSourceFixture();
    $source->tickets['5'] = new EscalationTicket(
        '5', new Record(['title' => 'Customer export fails', 'state' => 'open'], 'version-1'),
        ['support_bridge_escalate']
    );
    $issues = new IntakeIssuesFixture();
    $now = time();
    $clock = static function () use (&$now): int {
        return $now;
    };
    $workflow = static fn(): EscalationWorkflow => new EscalationWorkflow(
        $store, $repository, $source, $issues, 'support_bridge_escalate', $clock
    );
    try {
        $operation($store, $repository, $source, $issues, $workflow, $connection, $now, $directory);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

$tests = [];
$tests['tagged source creates one issue and automatically links it'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $issues->beforeCreate = static function ($ticket, $marker) use ($repository): void {
            $intent = $repository->find($ticket->id);
            intakeAssert($intent['status'] === 'creating' && $intent['attempted_at'] !== null);
            intakeAssert($intent['marker'] === $marker && $intent['snapshot']['version'] === 'version-1');
        };
        intakeAssert($workflow()->run()['linked'] === 1);
        intakeAssert($store->get('zendesk-5')['github_id'] === '101');
        intakeAssert($repository->find('5')['status'] === 'linked');
        $workflow()->run();
        intakeAssert($issues->posts === 1 && $source->pages === [1, 2]);
    });
};
$tests['untagged and solved tickets create nothing'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $source->tickets['5'] = new EscalationTicket('5', $source->tickets['5']->record, []);
        $source->tickets['6'] = new EscalationTicket(
            '6', new Record(['title' => 'Already solved', 'state' => 'closed'], 'v2'),
            ['support_bridge_escalate']
        );
        $workflow()->run();
        intakeAssert($issues->posts === 0 && $store->all() === [] && $repository->list()['total'] === 0);
    });
};
$tests['existing manual links remain intact'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $store->addPair('existing', '99', '5');
        $workflow()->run();
        intakeAssert($issues->posts === 0 && count($store->all()) === 1);
        intakeAssert($repository->list()['total'] === 0);
    });
};
$tests['lost create response is recovered without another create'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now): void {
        $issues->loseResponse = true;
        $workflow()->run();
        intakeAssert($repository->find('5')['status'] === 'uncertain' && $store->all() === []);
        $now += 61;
        $workflow()->run();
        intakeAssert($issues->posts === 1 && $repository->find('5')['status'] === 'linked');
    });
};
$tests['absence after an uncertain create never becomes a blind retry'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now): void {
        $issues->loseResponse = true;
        $issues->hide = true;
        $workflow()->run();
        for ($i = 0; $i < 3; ++$i) {
            $now += 61;
            $workflow()->run();
        }
        intakeAssert($issues->posts === 1 && $store->all() === []);
        intakeAssert($repository->find('5')['status'] === 'uncertain');
    });
};
$tests['audit failure before creation prevents the outbound request'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection): void {
        $connection->pdo()->exec("CREATE TRIGGER reject_intake BEFORE INSERT ON events "
            . "WHEN NEW.type='escalation_create_started' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        intakeReject(static fn() => $workflow()->run(), PDOException::class);
        intakeAssert($issues->posts === 0 && $repository->find('5')['attempted_at'] === null);
        $connection->pdo()->exec('DROP TRIGGER reject_intake');
        $workflow()->run();
        intakeAssert($issues->posts === 1);
    });
};
$tests['a crash while linking retains the observed remote ID'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection): void {
        $connection->pdo()->exec("CREATE TRIGGER reject_link BEFORE INSERT ON events "
            . "WHEN NEW.type='linked' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        intakeReject(static fn() => $workflow()->run(), PDOException::class);
        intakeAssert($repository->find('5')['github_id'] === '101' && $store->all() === []);
        $connection->pdo()->exec('DROP TRIGGER reject_link');
        $workflow()->run();
        intakeAssert($issues->posts === 1 && count($store->all()) === 1);
    });
};
$tests['withdrawing the tag before creation stops the request'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $source->withdraw = true;
        $workflow()->run();
        intakeAssert($issues->posts === 0 && $repository->find('5')['status'] === 'withdrawn');
        $source->withdraw = false;
        $workflow()->run();
        intakeAssert($issues->posts === 1);
    });
};
$tests['name conflicts and a busy canonical worker lock prevent creation'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $lock = $store->lock();
        try {
            intakeReject(static fn() => $workflow()->run(), WorkerBusy::class);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $store->addPair('zendesk-5', '77', '8');
        $workflow()->run();
        intakeAssert($issues->posts === 0 && $repository->find('5')['status'] === 'blocked');
    });
};
$tests['preflight outages retry reads without recording a create attempt'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now): void {
        $issues->failLookup = true;
        $workflow()->run();
        intakeAssert($issues->posts === 0 && $repository->find('5')['attempted_at'] === null);
        $issues->failLookup = false;
        $now += 61;
        $workflow()->run();
        intakeAssert($issues->posts === 1);
    });
};
$tests['the creation budget does not discard later discovered tickets'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        for ($id = 6; $id <= 11; ++$id) {
            $source->tickets[(string) $id] = new EscalationTicket(
                (string) $id, $source->tickets['5']->record, ['support_bridge_escalate']
            );
        }
        $workflow()->run();
        intakeAssert($issues->posts === 5 && $repository->list()['total'] === 7);
        $workflow()->run();
        intakeAssert($issues->posts === 7 && count($store->all()) === 7);
    });
};
$tests['vendor cooldown persists across passes without repeating discovery or creation'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now): void {
        $issues->failLookup = true;
        $issues->lookupRetryAfter = 3600;
        intakeAssert($workflow()->run()['deferred']);
        $issues->failLookup = false;
        $now += 3599;
        intakeAssert($workflow()->run()['deferred']);
        intakeAssert(count($source->pages) === 1 && $issues->posts === 0);
        ++$now;
        intakeAssert($workflow()->run()['linked'] === 1 && $issues->posts === 1);
    });
};
$tests['HTTP capabilities use fixed hosts and never create via another endpoint'] = static function (): void {
    $calls = [];
    $http = new HttpClient(static function ($method, $url, $headers, $json) use (&$calls): HttpResponse {
        $calls[] = [$method, $url, $json];
        if ($method === 'POST') {
            return new HttpResponse(201, [], ['number' => 30, 'body' => $json['body']]);
        }
        return new HttpResponse(200, [], []);
    });
    $adapter = new GitHubEscalations($http, 'owner/repo', 'example', 'synthetic-token');
    $marker = '<!-- support-bridge-escalation:' . str_repeat('a', 64) . ' -->';
    intakeAssert($adapter->find($marker) === null);
    $ticket = new EscalationTicket('5', new Record(['title' => 'A problem', 'state' => 'open'], 'v1'), [], '');
    intakeAssert($adapter->create($ticket, $marker) === '30');
    intakeAssert(str_contains($calls[1][2]['body'], 'https://example.zendesk.com/agent/tickets/5'));
    intakeReject(static fn() => $http->request('POST', 'https://example.zendesk.com/oauth/tokens', [], []),
        InvalidArgumentException::class);
    intakeReject(static fn() => $http->request('POST', 'https://api.github.com/repos/../repo/issues', [], [
        'title' => 'Unsafe destination', 'body' => 'Unsupported',
    ]), InvalidArgumentException::class);
};
$tests['duplicate markers and incomplete repository listings never imply safe absence'] = static function (): void {
    $marker = '<!-- support-bridge-escalation:' . str_repeat('b', 64) . ' -->';
    $duplicate = new GitHubEscalations(new HttpClient(static fn() => new HttpResponse(200, [], [
        ['number' => 10, 'body' => $marker], ['number' => 11, 'body' => $marker],
    ])), 'owner/repo', 'example', 'synthetic-token');
    intakeReject(static fn() => $duplicate->find($marker), RemoteFailure::class);
    $bounded = new GitHubEscalations(new HttpClient(static fn() => new HttpResponse(
        200, ['Link' => '<https://api.github.com/next>; rel="next"'], []
    )), 'owner/repo', 'example', 'synthetic-token');
    intakeReject(static fn() => $bounded->find($marker), RemoteFailure::class);
    $malformed = new GitHubEscalations(new HttpClient(static fn() => new HttpResponse(200, [], [
        ['number' => 10],
    ])), 'owner/repo', 'example', 'synthetic-token');
    intakeReject(static fn() => $malformed->find($marker), RemoteFailure::class);
};
$tests['Zendesk discovery uses ticket-scoped listing and filters before reading subjects'] = static function (): void {
    $calls = [];
    $adapter = new ZendeskEscalations(new HttpClient(
        static function ($method, $url, $headers, $json) use (&$calls): HttpResponse {
            $calls[] = $url;
            return new HttpResponse(200, [], ['tickets' => [[
                'id' => 5, 'subject' => 'A problem', 'description' => str_repeat('x', 30_001),
                'updated_at' => 'v1', 'tags' => ['support_bridge_escalate'], 'status' => 'open',
            ], [
                'id' => 6, 'subject' => str_repeat('x', 501), 'tags' => [], 'status' => 'open',
            ]], 'next_page' => null]);
        }
    ), 'example', new ZendeskAuthentication('', 'synthetic-token', 'oauth'));
    $found = $adapter->discover('support_bridge_escalate', 1)['tickets'];
    intakeAssert(count($found) === 1 && $found[0]->description === null);
    intakeAssert(!array_key_exists('initial_description', $found[0]->snapshot()));
    intakeAssert(str_contains($calls[0], '/tickets.json?sort_by=id&sort_order=desc&per_page=50&page=1'));
    intakeReject(static fn() => $adapter->discover('bad tag', 1), InvalidArgumentException::class);
};
$tests['creation includes the complete initial description and an explicit Zendesk link'] = static function (): void {
    $description = "The CSV export fails above 100,000 rows.\nPlease preserve this exact report.";
    $marker = '<!-- support-bridge-escalation:' . str_repeat('d', 64) . ' -->';
    $captured = null;
    $adapter = new GitHubEscalations(new HttpClient(
        static function ($method, $url, $headers, $json) use (&$captured): HttpResponse {
            $captured = $json;
            return new HttpResponse(201, [], ['number' => 30, 'body' => $json['body']]);
        }
    ), 'owner/repo', 'example', 'synthetic-token');
    $ticket = new EscalationTicket('6', new Record(['title' => 'CSV export fails', 'state' => 'open'], 'v1'),
        ['support_bridge_escalate'], $description);
    intakeAssert($adapter->create($ticket, $marker) === '30');
    intakeAssert(array_keys($captured) === ['title', 'body'] && str_contains($captured['body'], $description));
    intakeAssert(str_starts_with($captured['body'],
        'Engineering escalation from [Zendesk ticket 6](https://example.zendesk.com/agent/tickets/6).'));
    intakeAssert(!str_contains($captured['body'], 'Zendesk ticket #6') && str_ends_with($captured['body'], $marker));
};
$tests['source markup and fence characters remain literal description text'] = static function (): void {
    $description = "```\n@someone #6 [a link](https://example.invalid/)\n~~~~\n<!-- a source comment -->";
    $ticket = new EscalationTicket('6', new Record(['title' => 'A problem', 'state' => 'open'], 'v1'), [], $description);
    $marker = '<!-- support-bridge-escalation:' . str_repeat('e', 64) . ' -->';
    $body = (new EngineeringIssueBody('example'))->render($ticket, $marker);
    intakeAssert(preg_match('/\n([`~]{3,})text\n/', $body, $fence) === 1);
    intakeAssert(!str_contains($description, $fence[1]));
    intakeAssert(str_contains($body, $fence[1] . "text\n" . $description . "\n" . $fence[1]));
};
$tests['description byte bounds and empty text are explicit without truncation'] = static function (): void {
    $record = new Record(['title' => 'A problem', 'state' => 'open'], 'v1');
    foreach ([str_repeat('x', 30_001), "bad\0text", "\xff"] as $invalid) {
        intakeReject(static fn() => new EscalationTicket('6', $record, [], $invalid), InvalidArgumentException::class);
    }
    $marker = '<!-- support-bridge-escalation:' . str_repeat('f', 64) . ' -->';
    $formatter = new EngineeringIssueBody('example');
    foreach ([str_repeat('é', 15_000), str_repeat('`', 15_000) . str_repeat('~', 15_000)] as $limit) {
        $body = $formatter->render(new EscalationTicket('6', $record, [], $limit), $marker);
        intakeAssert(str_contains($body, $limit) && strlen($body) < 62_000);
    }
    intakeAssert(str_contains($formatter->render(new EscalationTicket('6', $record, [], ''), $marker),
        'Zendesk supplied no initial description text.'));
    intakeReject(static fn() => $formatter->render(new EscalationTicket('6', $record, []), $marker),
        InvalidArgumentException::class);
};
$tests['fresh initial-description evidence commits before the first create attempt'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $source->freshDescription = 'The original report with its latest source redaction.';
        $issues->beforeCreate = static function ($ticket, $marker) use ($repository, $source): void {
            $snapshot = $repository->find('5')['snapshot'];
            intakeAssert($snapshot['initial_description'] === $source->freshDescription);
            intakeAssert($ticket->description === $source->freshDescription);
        };
        $workflow()->run();
        intakeAssert($issues->posts === 1);
    });
};
$tests['legacy attempted snapshots recover without rewriting history or creating another issue'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now): void {
        $issues->loseResponse = true;
        $workflow()->run();
        $snapshot = $repository->find('5')['snapshot'];
        unset($snapshot['initial_description']);
        $connection->pdo()->prepare('UPDATE escalations SET snapshot=? WHERE ticket_id=?')
            ->execute([json_encode($snapshot, JSON_THROW_ON_ERROR), '5']);
        $source->freshDescription = 'New source text must not rewrite a past attempted snapshot.';
        $now += 61;
        $workflow()->run();
        intakeAssert($issues->posts === 1 && $repository->find('5')['status'] === 'linked');
        intakeAssert($repository->find('5')['snapshot'] === $snapshot);
    });
};
$tests['Zendesk requires a supported first-description field before preparing creation'] = static function (): void {
    $value = ['id' => 6, 'subject' => 'A problem', 'updated_at' => 'v1',
        'tags' => ['support_bridge_escalate'], 'status' => 'open', 'description' => 'Original report'];
    $authorization = new ZendeskAuthentication('', 'synthetic-token', 'oauth');
    $adapter = new ZendeskEscalations(new HttpClient(static fn() => new HttpResponse(200, [], ['ticket' => $value])),
        'example', $authorization);
    intakeAssert($adapter->fetch('6')->description === 'Original report');
    foreach ([null, str_repeat('x', 30_001), "\xff"] as $unsupported) {
        $invalid = array_replace($value, ['description' => $unsupported]);
        $adapter = new ZendeskEscalations(new HttpClient(static fn() => new HttpResponse(200, [], ['ticket' => $invalid])),
            'example', $authorization);
        intakeReject(static fn() => $adapter->fetch('6'), RemoteFailure::class);
    }
    intakeFixture(static function ($store, $repository, $source, $issues): void {
        $metadata = $source->tickets['5'];
        $missing = new class ($metadata) implements TicketSource {
            public function __construct(private EscalationTicket $metadata) {}
            public function discover(string $tag, int $page): array {
                return ['tickets' => [$this->metadata], 'has_more' => false];
            }
            public function fetch(string $id): EscalationTicket { return $this->metadata; }
        };
        $workflow = new EscalationWorkflow($store, $repository, $missing, $issues, 'support_bridge_escalate');
        intakeAssert($workflow->run()['deferred'] && $issues->posts === 0);
        intakeAssert($repository->find('5')['attempted_at'] === null);
    });
};

$failed = 0;
$tests['automatic links retain title synchronization and the resolution approval gate'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $workflow()->run();
        $github = new Bridge\Tests\FakeProvider();
        $zendesk = new Bridge\Tests\FakeProvider();
        $github->seed('101', $source->tickets['5']->record->fields);
        $zendesk->seed('5', $source->tickets['5']->record->fields);
        $engine = new Bridge\Engine($store, $github, $zendesk);
        $engine->run();
        $zendesk->change('5', ['title' => 'Customer clarified the problem']);
        $engine->run();
        intakeAssert($github->fields('101')['title'] === 'Customer clarified the problem');
        $github->change('101', ['state' => 'closed']);
        $engine->run();
        intakeAssert($store->get('zendesk-5')['status'] === 'awaiting_approval');
        intakeAssert($zendesk->fields('5')['state'] === 'open' && $zendesk->writes === []);
    });
};
$tests['API intake authenticates, validates and exposes provider-free query results'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow, $connection, &$now, $home): void {
        $factoryCalls = 0;
        $context = new Context(new Configuration('local', $home, 'local/demo', 'local'),
            static function () use ($source, $issues, &$factoryCalls): array {
                ++$factoryCalls;
                return [$source, $issues];
            });
        $token = 'synthetic-intake-operator-token-0123456789abcdef';
        $api = new Api(static fn() => $context, $token, 'synthetic-intake-metrics-token-0123456789abcdef');
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        intakeAssert($api->handle(new Request('POST', '/api/intake', [], '{}'))->status === 401);
        intakeAssert($api->handle(new Request('GET', '/api/escalations', $headers))->status === 200);
        intakeAssert($factoryCalls === 0 && $issues->posts === 0);
        intakeAssert($api->handle(new Request('POST', '/api/intake', $headers, '{"ticket_id":"5"}'))->status === 422);
        $result = $api->handle(new Request('POST', '/api/intake', $headers, '{}'));
        intakeAssert($result->status === 200 && $result->body['linked'] === 1);
        $listing = $api->handle(new Request('GET', '/api/escalations', $headers));
        intakeAssert($listing->body['total'] === 1 && $listing->body['items'][0]['status'] === 'linked');
        intakeAssert($factoryCalls === 1 && $issues->posts === 1);
        intakeAssert($api->handle(new Request('GET', '/api/escalations', $headers, '', ['unknown' => '1']))->status === 422);
    });
};
$tests['intake metrics expose fixed states and reject incomplete or identifying labels'] = static function (): void {
    intakeFixture(static function ($store, $repository, $source, $issues, $workflow): void {
        $workflow()->run();
        $counts = $store->metrics()['intake'];
        $renderer = new Bridge\Observability\IntakeMetrics();
        $text = implode("\n", $renderer->render($counts));
        intakeAssert(str_contains($text, 'support_bridge_intake_records{status="linked"} 1'));
        intakeAssert(!str_contains($text, 'Customer export fails') && !str_contains($text, 'zendesk-5'));
        unset($counts['prepared']);
        intakeReject(static fn() => $renderer->render($counts), RuntimeException::class);
        $counts['ticket_id'] = 5;
        intakeReject(static fn() => $renderer->render($counts), RuntimeException::class);
    });
};

foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
    }
}
echo count($tests) . ' escalation intake checks; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
