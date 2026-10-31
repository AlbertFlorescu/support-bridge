<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeProvider.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Application\Query\ListNoteDeliveries;
use Bridge\Comments\ZendeskNote;
use Bridge\Http\Api;
use Bridge\Http\Request;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Provider\ZendeskTicketNotes;
use Bridge\RemoteFailure;

function conversationCheck(bool $value): void
{
    if (!$value) {
        throw new RuntimeException('Conversation expectation failed.');
    }
}

function conversationReject(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        conversationCheck($error instanceof $type);
        return;
    }
    throw new RuntimeException('Expected conversation rejection.');
}

require __DIR__ . '/ConversationFixture.php';

function conversationFixture(callable $operation): void
{
    $fixture = new ConversationFixture();
    try {
        $operation($fixture);
    } finally {
        $fixture->close();
    }
}

$tests = [];
$tests['first complete scan establishes new-only cutover without posting existing private or public comments'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->reply();
        $f->reply('1001', 'A public customer response', true);
        $result = $f->runReverse();
        conversationCheck($result['initialized'] === 1 && $result['discovered'] === 0 && $f->posts === 0);
        $stream = $f->reverse->list()['streams'][0];
        conversationCheck($stream['initialized_at'] !== null && $stream['excluded_count'] === 3);
        conversationCheck(!array_key_exists('excluded_ids', $stream));
        $f->zendeskComments[2]['public'] = false;
        $f->runReverse();
        conversationCheck($f->posts === 0 && $f->reverse->list()['total'] === 0);
    });
};
$tests['new private notes preserve name ID source link and literal text while excluding customer replies and attachments'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->reply('1001', 'Public customer response', true);
        $f->zendeskComments[1]['attachments'] = [['url' => 'https://untrusted.invalid/private-file']];
        $result = $f->runReverse();
        $row = $f->reverse->list()['items'][0];
        conversationCheck($result['delivered'] === 1 && $f->posts === 1);
        conversationCheck(str_contains($row['note'], 'Escalation Engineer') && str_contains($row['note'], 'user #42'));
        conversationCheck(str_contains($row['note'], 'https://example.zendesk.com/agent/tickets/501'));
        conversationCheck(str_contains($row['note'], '120,000 rows') && !str_contains($row['note'], 'untrusted.invalid'));
        conversationCheck($row['github_comment_id'] === '700' && $row['source_version'] === '2026-10-30T10:00:00Z');
    });
};
$tests['question and answer round trip never reflects integration messages or duplicates ordinary comments'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->question();
        conversationCheck($f->runForward()['delivered'] === 1);
        conversationCheck($f->runReverse()['discovered'] === 0);
        $f->reply();
        conversationCheck($f->runReverse()['delivered'] === 1);
        for ($i = 0; $i < 4; ++$i) {
            $f->runForward();
            $f->runReverse();
        }
        conversationCheck($f->puts === 1 && $f->posts === 1);
        conversationCheck($f->forward->list()['total'] === 1 && $f->reverse->list()['total'] === 1);
    });
};
$tests['copied destination IDs stay excluded after their markers are edited away on either side'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->question();
        $f->runForward();
        $f->zendeskComments[1]['body'] = $f->zendeskComments[1]['plain_body'] = 'Edited copied engineering note';
        $f->runReverse();
        conversationCheck($f->posts === 0);
        $f->reply();
        $f->runReverse();
        $f->githubComments[1]['body'] = 'Edited copied escalation reply';
        $f->runForward();
        conversationCheck($f->puts === 1 && $f->posts === 1);
    });
};
$tests['lost reverse response is observed after restart without a second POST or forward echo'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->losePost = true;
        $f->runReverse();
        conversationCheck($f->reverse->list()['items'][0]['status'] === 'uncertain');
        $f->runForward();
        conversationCheck($f->puts === 0);
        $f->losePost = false;
        $f->now += 61;
        $f->runReverse();
        conversationCheck($f->posts === 1 && $f->reverse->list()['items'][0]['status'] === 'delivered');
    });
};
$tests['an invisible accepted GitHub comment stays uncertain without repeated writes'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->losePost = $f->hidePosts = true;
        $f->runReverse();
        for ($i = 0; $i < 3; ++$i) {
            $f->now += 61;
            $f->runReverse();
        }
        conversationCheck($f->posts === 1 && $f->reverse->list()['items'][0]['status'] === 'uncertain');
    });
};
$tests['an unacknowledged forward append is suppressed by its durable marker before its destination ID is saved'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->question();
        $f->losePut = true;
        $f->runForward();
        conversationCheck($f->forward->list()['items'][0]['zendesk_comment_id'] === null);
        $f->runReverse();
        conversationCheck($f->puts === 1 && $f->posts === 0);
    });
};
$tests['reverse append audit failure prevents every outbound effect'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->connection->pdo()->exec("CREATE TRIGGER reject_reverse BEFORE INSERT ON events "
            . "WHEN NEW.type='note_send_started' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        conversationReject($f->runReverse(...), PDOException::class);
        conversationCheck($f->posts === 0 && $f->reverse->list()['items'][0]['attempted_at'] === null);
    });
};
$tests['failed local acknowledgement leaves sending evidence and recovers without an echo or resend'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->connection->pdo()->exec("CREATE TRIGGER reject_reverse_ack BEFORE INSERT ON events "
            . "WHEN NEW.type='note_delivered' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        conversationReject($f->runReverse(...), PDOException::class);
        conversationCheck($f->posts === 1 && $f->reverse->list()['items'][0]['status'] === 'sending');
        $f->runForward();
        conversationCheck($f->puts === 0);
        $f->connection->pdo()->exec('DROP TRIGGER reject_reverse_ack');
        $f->runReverse();
        conversationCheck($f->posts === 1 && $f->reverse->list()['items'][0]['status'] === 'delivered');
    });
};
$tests['state review conflict and retries hold replies without modifying plans or approvals'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        foreach (['awaiting_approval', 'conflict', 'retry', 'dead', 'new'] as $state) {
            $plan = ['target' => ['title' => 'Reviewed', 'state' => 'closed']];
            $approval = ['existing_evidence' => true];
            $f->store->update('case', ['status' => $state, 'plan' => $plan, 'approval' => $approval]);
            $f->runReverse();
            conversationCheck($f->posts === 0 && $f->store->get('case')['plan'] === $plan
                && $f->store->get('case')['approval'] === $approval);
            $f->now += 61;
        }
        $f->store->update('case', ['status' => 'synced', 'plan' => null, 'approval' => null]);
        $f->runReverse();
        conversationCheck($f->posts === 1);
    });
};
$tests['edited deleted or newly public pending notes never publish their stale private snapshots'] = static function (): void {
    foreach (['edited', 'deleted', 'public'] as $change) {
        conversationFixture(static function (ConversationFixture $f) use ($change): void {
            $f->runReverse();
            $f->reply();
            $f->store->update('case', ['status' => 'conflict']);
            $f->runReverse();
            $saved = $f->reverse->list()['items'][0]['note'];
            if ($change === 'deleted') {
                array_pop($f->zendeskComments);
            } elseif ($change === 'public') {
                $f->zendeskComments[1]['public'] = true;
            } else {
                $f->zendeskComments[1]['body'] = $f->zendeskComments[1]['plain_body'] = 'Changed source content';
            }
            $f->store->update('case', ['status' => 'synced']);
            $f->now += 61;
            $f->runReverse();
            $row = $f->reverse->list()['items'][0];
            conversationCheck($f->posts === 0 && $row['status'] === 'blocked' && $row['note'] === $saved);
        });
    }
};
$tests['failed initial scan or cutover audit cannot invent a starting point'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->historyFailure = true;
        conversationCheck($f->runReverse()['unavailable'] === 1);
        conversationCheck($f->reverse->list()['streams'][0]['initialized_at'] === null);
        $f->historyFailure = false;
        $f->now += 3601;
        $f->connection->pdo()->exec("CREATE TRIGGER reject_cutover BEFORE INSERT ON events "
            . "WHEN NEW.type='note_stream_initialized' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        conversationReject($f->runReverse(...), PDOException::class);
        conversationCheck($f->reverse->list()['streams'][0]['initialized_at'] === null && $f->posts === 0);
    });
};
$tests['cutover survives restart and pause-resume without replaying old discussions'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->reply();
        $f->runReverse();
        $cutover = $f->reverse->list()['streams'][0]['initialized_at'];
        $f->reply('1001', 'New information during the pause');
        $f->now += 3600;
        conversationCheck($f->runReverse()['initialized'] === 0 && $f->posts === 1);
        conversationCheck($f->reverse->list()['streams'][0]['initialized_at'] === $cutover);
        conversationReject(static fn() => $f->reverse->initialize('case', [], $f->now), RuntimeException::class);
    });
};
$tests['unsupported historical notes remain excluded without blocking supported new replies'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->reply('1000', str_repeat('x', 70_000));
        $f->runReverse();
        $f->reply('1001', 'A supported new internal reply');
        $f->runReverse();
        conversationCheck($f->posts === 1);
    });
};
$tests['unknown reserved origin markers stop discovery instead of starting an echo'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply('1000', 'Support Bridge comment: ' . str_repeat('c', 64) . "\nUnknown origin");
        conversationCheck($f->runReverse()['unavailable'] === 1 && $f->posts === 0);
        $f->question('Support Bridge note: ' . str_repeat('d', 64) . "\nUnknown origin");
        conversationCheck($f->runForward()['unavailable'] === 1 && $f->puts === 0);
    });
};
$tests['shared privacy hold and the canonical lock stop reverse reads and writes'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $lock = $f->store->lock();
        try {
            conversationReject($f->runReverse(...), Bridge\Exception\WorkerBusy::class);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $f->connection->pdo()->exec("INSERT INTO settings(key,value) VALUES('comments_privacy_hold','1')");
        $f->historyFailure = true;
        conversationCheck($f->runReverse()['held'] === true && $f->reverse->list()['stream_total'] === 0);
    });
};
$tests['stale GitHub preflight cannot become a blind repeated POST'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->staleIssue = true;
        $f->runReverse();
        conversationCheck($f->posts === 0 && $f->reverse->list()['items'][0]['status'] === 'uncertain');
        $f->staleIssue = false;
        $f->now += 61;
        $f->runReverse();
        conversationCheck($f->posts === 0);
    });
};
$tests['large legacy forward snapshots are suppressed before reverse formatting'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->question(str_repeat('x', 59_900));
        $f->runForward();
        $f->runReverse();
        conversationCheck($f->puts === 1 && $f->posts === 0);
    });
};
$tests['reverse creation budget and per-note fresh issue versions remain bounded'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        for ($i = 0; $i < 7; ++$i) {
            $f->reply((string) (1000 + $i), 'New reply ' . $i);
        }
        $f->runReverse();
        conversationCheck($f->posts === 5 && $f->reverse->list()['total'] === 7);
        $f->runReverse();
        conversationCheck($f->posts === 7);
    });
};
$tests['reverse API enforces strict payloads pagination separate authority and provider-free queries'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $calls = 0;
        $context = new Context(new Configuration('local', $f->home, 'local/demo', 'local'), null, null,
            static function () use ($f, &$calls): array {
                ++$calls;
                return [$f->source, $f->issues, $f->github, $f->zendesk];
            });
        $token = 'controlled-reverse-operator-token-0123456789abcdef';
        $metrics = 'controlled-reverse-metrics-token-0123456789abcdef';
        $api = new Api(static fn() => $context, $token, $metrics);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        conversationCheck($api->handle(new Request('POST', '/api/notes/sync', [], '{}'))->status === 401);
        conversationCheck($api->handle(new Request('POST', '/api/notes/sync',
            ['Authorization' => 'Bearer ' . $metrics], '{}'))->status === 401);
        conversationCheck($api->handle(new Request('GET', '/api/note-deliveries', $headers))->status === 200);
        conversationCheck($calls === 0);
        conversationCheck($api->handle(new Request('POST', '/api/notes/sync', $headers, '{"body":"injected"}'))->status === 422);
        conversationCheck($api->handle(new Request('GET', '/api/note-deliveries', $headers, '',
            ['limit' => '101']))->status === 422);
        $result = $api->handle(new Request('POST', '/api/notes/sync', $headers, '{}'));
        conversationCheck($result->status === 200 && $result->body['initialized'] === 1 && $calls === 1);
        $f->reply();
        $result = $api->handle(new Request('POST', '/api/notes/sync', $headers, '{}'));
        conversationCheck($result->body['delivered'] === 1 && $calls === 2);
        conversationReject(static fn() => new ListNoteDeliveries(101), Bridge\Exception\ValidationFailure::class);
    });
};
$tests['missing display names retain numeric attribution and rich text stays literal'] = static function (): void {
    $note = new ZendeskNote('1000', "A reply\n```\n@someone ![image](https://untrusted.invalid/)", '42', null,
        '2026-10-30T10:00:00Z', 'https://example.zendesk.com/agent/tickets/501');
    $body = $note->note('Support Bridge note: ' . str_repeat('e', 64));
    conversationCheck(str_contains($body, 'Zendesk user #42') && str_contains($body, "````text"));
    conversationReject(static fn() => (new ZendeskNote('1001', str_repeat('`', 30_000), '42', null,
        '2026-10-30T10:00:00Z', 'https://example.zendesk.com/agent/tickets/501'))->note(
            'Support Bridge note: ' . str_repeat('e', 64)), InvalidArgumentException::class);
};
$tests['complete source history rejects duplicate IDs incomplete pages and missing initial report'] = static function (): void {
    foreach (['duplicate', 'partial', 'empty'] as $mode) {
        $comment = ['id' => 800, 'body' => 'Initial', 'public' => true];
        $source = new ZendeskTicketNotes(new HttpClient(static fn() => new HttpResponse(200, [], [
            'comments' => $mode === 'duplicate' ? [$comment, $comment] : [],
            'next_page' => $mode === 'partial' ? 'https://untrusted.invalid/' : null,
        ])), 'example', new ZendeskAuthentication('', 'controlled-note-token', 'oauth'));
        conversationReject(static fn() => $source->baselineIds('501'), RemoteFailure::class);
    }
};
$tests['duplicate destination markers cannot acknowledge an uncertain reverse append'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->reply();
        $f->losePost = true;
        $f->runReverse();
        $copy = $f->githubComments[0];
        $copy['id'] = 701;
        $f->githubComments[] = $copy;
        $f->now += 61;
        $f->runReverse();
        conversationCheck($f->posts === 1 && $f->reverse->list()['items'][0]['status'] === 'uncertain');
    });
};

$tests['additive upgrade preserves legacy forward intent plans baselines and approval bytes'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->question();
        $f->losePut = true;
        $f->runForward();
        $f->store->update('case', ['status' => 'awaiting_approval',
            'plan' => ['opaque_saved_plan' => true], 'approval' => ['opaque_approval' => true]]);
        $db = $f->connection->pdo();
        $before = $db->query('SELECT * FROM comment_deliveries')->fetchAll(PDO::FETCH_ASSOC);
        $pair = $db->query('SELECT * FROM pairs')->fetchAll(PDO::FETCH_ASSOC);
        $db->exec('DROP TABLE note_deliveries; DROP TABLE note_streams;');
        new Bridge\Store($f->connection);
        conversationCheck($db->query('SELECT * FROM comment_deliveries')->fetchAll(PDO::FETCH_ASSOC) === $before);
        conversationCheck($db->query('SELECT * FROM pairs')->fetchAll(PDO::FETCH_ASSOC) === $pair);
        $f->now += 61;
        $f->runForward();
        conversationCheck($f->puts === 1 && $f->forward->list()['items'][0]['status'] === 'delivered');
        conversationCheck($db->query('SELECT * FROM pairs')->fetchAll(PDO::FETCH_ASSOC) === $pair);
    });
};
$tests['reverse metrics and linked-pair context retain separate real delivery and waiting counts'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $f->runReverse();
        $f->question();
        $f->runForward();
        $f->reply();
        $f->runReverse();
        $f->reply('1001', 'Another escalation reply');
        $f->store->update('case', ['status' => 'conflict']);
        $f->runReverse();
        $snapshot = $f->store->monitoringSnapshot();
        $pair = $snapshot['linked_pairs'][0];
        conversationCheck($pair['notes_delivered'] === 1 && $pair['notes_waiting'] === 0);
        conversationCheck($pair['notes_to_github_delivered'] === 1 && $pair['notes_to_github_waiting'] === 1);
        conversationCheck($snapshot['state']['notes']['delivered'] === 1
            && $snapshot['state']['notes']['pending'] === 1 && $snapshot['state']['note_streams_initialized'] === 1);
        $text = (new Bridge\Observability\Prometheus($f->home . '/metrics.sqlite'))->render(
            $snapshot['state'], $snapshot['linked_pairs']);
        conversationCheck(str_contains($text, 'support_bridge_note_deliveries{status="delivered"} 1'));
        conversationCheck(str_contains($text, 'support_bridge_linked_pair_notes_to_github_waiting{github_issue="101"} 1'));
        conversationCheck(str_contains($text, 'support_bridge_note_streams_initialized 1'));
        conversationCheck(!str_contains($text, 'Escalation Engineer') && !str_contains($text, 'Another escalation reply'));
    });
};
$tests['transport permits only the marked body-only issue-comment append alongside existing issue creation'] = static function (): void {
    $calls = 0;
    $http = new HttpClient(static function () use (&$calls): HttpResponse {
        ++$calls;
        return new HttpResponse(201, [], ['id' => 700]);
    });
    $url = 'https://api.github.com/repos/example/escalations/issues/101/comments';
    $body = 'Support Bridge note: ' . str_repeat('a', 64) . "\nAttributed message";
    $http->request('POST', $url, [], ['body' => $body]);
    foreach ([
        [$url, ['body' => 'Unmarked arbitrary comment']],
        [$url, ['body' => $body, 'author_id' => 99]],
        [$url . '?override=true', ['body' => $body]],
        ['https://example.zendesk.com/api/v2/tickets/501/comments.json', ['body' => $body]],
        ['https://api.github.com/repos/example/escalations/issues/101/comments/700', ['body' => $body]],
    ] as [$destination, $payload]) {
        conversationReject(static fn() => $http->request('POST', $destination, [], $payload),
            InvalidArgumentException::class);
    }
    conversationCheck($calls === 1);
};
$tests['disabled reverse execution cannot construct vendors while inspection remains available'] = static function (): void {
    conversationFixture(static function (ConversationFixture $f): void {
        $old = getenv('BRIDGE_ZENDESK_NOTES_ENABLED');
        putenv('BRIDGE_ZENDESK_NOTES_ENABLED=0');
        try {
            $context = new Context(new Configuration('live', $f->home, 'example/escalations', 'example'));
            conversationCheck($context->ask(new ListNoteDeliveries())['total'] === 0);
            conversationReject(static fn() => $context->handle(new Bridge\Application\Command\SyncZendeskNotes()),
                Bridge\Application\ConfigurationFailure::class);
        } finally {
            putenv($old === false ? 'BRIDGE_ZENDESK_NOTES_ENABLED' : 'BRIDGE_ZENDESK_NOTES_ENABLED=' . $old);
        }
    });
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
    }
}
echo count($tests) . ' conversation checks; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
