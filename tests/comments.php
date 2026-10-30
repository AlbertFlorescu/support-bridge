<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeProvider.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Comments\CommentSource;
use Bridge\Comments\CommentWorkflow;
use Bridge\Comments\InternalNotes;
use Bridge\Comments\IssueComment;
use Bridge\Http\Api;
use Bridge\Http\Request;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Observability\CommentMetrics;
use Bridge\Provider\GitHubIssueComments;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Provider\ZendeskInternalNotes;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;
use Bridge\Store;
use Bridge\Tests\FakeProvider;

function commentAssert(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Comment delivery expectation failed.');
    }
}

function commentReject(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        commentAssert($error instanceof $type);
        return;
    }
    throw new RuntimeException('Expected comment delivery rejection.');
}

function sourceComment(string $id = '700', string $body = 'The fix is ready for verification.'): IssueComment
{
    return new IssueComment($id, $body, 'engineer', '2026-10-30T10:00:00Z', '2026-10-30T10:00:00Z',
        'https://github.com/example/escalations/issues/101#issuecomment-' . $id);
}

final class CommentSourceFixture implements CommentSource
{
    public array $comments = [];
    public int $reads = 0;
    public ?RemoteFailure $failure = null;

    public function list(string $issueId): array
    {
        ++$this->reads;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->comments;
    }
}

final class InternalNotesFixture implements InternalNotes
{
    public array $notes = [];
    public int $appends = 0;
    public bool $loseResponse = false;
    public bool $hide = false;
    public bool $unsafe = false;
    public ?Closure $beforeAppend = null;
    public ?Closure $afterAppend = null;
    public ?RemoteConflict $rejection = null;

    public function find(string $ticketId, string $marker): ?string
    {
        if ($this->unsafe) { throw new Bridge\Comments\UnsafeNoteEvidence('Synthetic privacy violation.'); }
        return $this->hide ? null : ($this->notes[$marker]['id'] ?? null);
    }

    public function append(string $ticketId, string $body, string $expectedVersion): void
    {
        if ($this->beforeAppend !== null) {
            ($this->beforeAppend)($body, $expectedVersion);
        }
        ++$this->appends;
        if ($this->rejection !== null) {
            throw $this->rejection;
        }
        $this->notes[strtok($body, "\n")] = ['id' => (string) (900 + $this->appends), 'body' => $body];
        if ($this->afterAppend !== null) {
            ($this->afterAppend)();
        }
        if ($this->loseResponse) {
            throw new RemoteFailure('Synthetic lost append response.', true);
        }
    }
}

function commentFixture(callable $operation): void
{
    $home = sys_get_temp_dir() . '/bridge-comments-' . bin2hex(random_bytes(8));
    mkdir($home, 0700);
    $connection = new SqliteConnection($home . '/local.sqlite');
    $store = new Store($connection);
    $repository = new CommentRepository($connection);
    $store->addPair('case', '101', '501');
    $fields = ['title' => 'CSV export fails', 'state' => 'open'];
    $store->update('case', ['status' => 'synced', 'baseline' => $fields]);
    $github = new FakeProvider();
    $zendesk = new FakeProvider();
    $github->seed('101', $fields);
    $zendesk->seed('501', $fields);
    $source = new CommentSourceFixture();
    $source->comments = [sourceComment()];
    $notes = new InternalNotesFixture();
    $now = time();
    $clock = static function () use (&$now): int { return $now; };
    $workflow = static fn(): CommentWorkflow => new CommentWorkflow(
        $store, $repository, $source, $notes, $github, $zendesk, $clock
    );
    try {
        $operation($store, $repository, $source, $notes, $workflow, $connection, $github, $zendesk, $now, $home);
    } finally {
        foreach (glob($home . '/*') as $file) {
            if (is_file($file)) { unlink($file); }
        }
    }
}

$tests = [];
$tests['copies historical comments once with source attribution and durable pre-write evidence'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow): void {
        $notes->beforeAppend = static function ($body, $version) use ($repository): void {
            $delivery = $repository->list()['items'][0];
            commentAssert($delivery['status'] === 'sending' && $delivery['attempted_at'] !== null);
            commentAssert($version === '1' && $delivery['destination_version'] === '1');
            commentAssert(str_contains($body, '@engineer') && str_contains($body, '#issuecomment-700'));
        };
        commentAssert($workflow()->run()['delivered'] === 1);
        $workflow()->run();
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'delivered');
        commentAssert($store->get('case')['baseline']['state'] === 'open');
    });
};
$tests['unknown committed append is recovered after restart without a second append'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now): void {
        $notes->loseResponse = true;
        $workflow()->run();
        commentAssert($repository->list()['items'][0]['status'] === 'uncertain');
        $now += 61;
        $workflow()->run();
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'delivered');
    });
};
$tests['unconfirmed absent note never authorizes another append'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now): void {
        $notes->loseResponse = true;
        $notes->hide = true;
        $workflow()->run();
        for ($i = 0; $i < 3; ++$i) { $now += 61; $workflow()->run(); }
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'uncertain');
    });
};
$tests['audit failure before append prevents all outbound effects'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection): void {
        $connection->pdo()->exec("CREATE TRIGGER reject_note BEFORE INSERT ON events "
            . "WHEN NEW.type='comment_send_started' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        commentReject(static fn() => $workflow()->run(), PDOException::class);
        commentAssert($notes->appends === 0 && $repository->list()['items'][0]['attempted_at'] === null);
    });
};
$tests['lost local delivery acknowledgement recovers from sending without resubmission'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection): void {
        $connection->pdo()->exec("CREATE TRIGGER reject_ack BEFORE INSERT ON events "
            . "WHEN NEW.type='comment_delivered' BEGIN SELECT RAISE(ABORT,'unavailable'); END");
        commentReject(static fn() => $workflow()->run(), PDOException::class);
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'sending');
        $connection->pdo()->exec('DROP TRIGGER reject_ack');
        $workflow()->run();
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'delivered');
    });
};
$tests['pending state review and conflicts hold notes without mutating plan or approval'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now): void {
        foreach (['awaiting_approval', 'conflict', 'retry', 'dead', 'new'] as $status) {
            $plan = ['target' => ['title' => 'Held', 'state' => 'closed']];
            $approval = ['opaque_existing_evidence' => true];
            $store->update('case', ['status' => $status, 'plan' => $plan, 'approval' => $approval]);
            $workflow()->run();
            commentAssert($notes->appends === 0 && $store->get('case')['plan'] === $plan);
            commentAssert($store->get('case')['approval'] === $approval);
            $now += 61;
        }
        $store->update('case', ['status' => 'synced', 'plan' => null, 'approval' => null]);
        $workflow()->run();
        commentAssert($notes->appends === 1);
    });
};
$tests['source fields changed after reconciliation prevent a note write'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $github): void {
        $github->change('101', ['state' => 'closed']);
        $workflow()->run();
        commentAssert($notes->appends === 0 && $repository->list()['items'][0]['status'] === 'pending');
    });
};
$tests['immutable Zendesk tickets stop visibly without follow-up creation'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd): void {
        $zd->seed('501', $store->get('case')['baseline'], false);
        $workflow()->run();
        commentAssert($notes->appends === 0 && $repository->list()['items'][0]['status'] === 'blocked');
    });
};
$tests['a changed Zendesk subject defers notes until ordinary reconciliation converges'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd): void {
        $zd->change('501', ['title' => 'A customer clarification']);
        $workflow()->run();
        commentAssert($notes->appends === 0 && $repository->list()['items'][0]['status'] === 'pending');
    });
};
$tests['a public-note observation durably stops every further comment append'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow): void {
        $source->comments = [sourceComment('700'), sourceComment('701')];
        $notes->afterAppend = static function () use ($notes): void { $notes->unsafe = true; };
        $outcome = $workflow()->run();
        commentAssert($outcome['held'] && $notes->appends === 1 && $repository->held());
        $notes->unsafe = false;
        $reads = $source->reads;
        $outcome = $workflow()->run();
        commentAssert($outcome['held'] && $notes->appends === 1 && $source->reads === $reads);
        commentAssert($store->metrics()['comment_privacy_hold']);
    });
};
$tests['unlinked issues are never read or mirrored'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection): void {
        $connection->pdo()->exec('DELETE FROM pairs');
        $workflow()->run();
        commentAssert($source->reads === 0 && $notes->appends === 0 && $repository->list()['total'] === 0);
    });
};
$tests['edits and deletion do not rewrite or remove a delivered internal note'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow): void {
        $workflow()->run();
        $original = $repository->list()['items'][0]['note'];
        $source->comments = [sourceComment('700', 'An edited comment')];
        $workflow()->run();
        $source->comments = [];
        $workflow()->run();
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['note'] === $original);
    });
};
$tests['source outage honors cooldown and exposes a recoverable stream error'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now): void {
        $source->failure = new RemoteFailure('Synthetic rate limit.', true, 3600);
        $workflow()->run();
        commentAssert($store->metrics()['comment_stream_errors'] === 1 && $notes->appends === 0);
        $source->failure = null;
        $now += 3599;
        $workflow()->run();
        commentAssert($source->reads === 1);
        ++$now;
        $workflow()->run();
        commentAssert($notes->appends === 1 && $store->metrics()['comment_stream_errors'] === 0);
    });
};
$tests['a known rejected safe update remains conservative and does not blindly repeat'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now): void {
        $notes->rejection = new RemoteConflict('Synthetic safe update rejection.');
        $workflow()->run();
        $notes->rejection = null;
        $now += 61;
        $workflow()->run();
        commentAssert($notes->appends === 1 && $repository->list()['items'][0]['status'] === 'uncertain');
    });
};
$tests['new notes use fresh ticket versions and a bounded creation budget'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd): void {
        $source->comments = array_map(static fn(int $id): IssueComment => sourceComment((string) $id), range(700, 706));
        $notes->afterAppend = static fn() => $zd->change('501', []);
        $notes->beforeAppend = static function ($body, $version) use ($notes): void {
            commentAssert($version === (string) ($notes->appends + 1));
        };
        $workflow()->run();
        commentAssert($notes->appends === 5 && $repository->list()['total'] === 7);
        $workflow()->run();
        commentAssert($notes->appends === 7);
    });
};
$tests['canonical lock excludes concurrent comment workers'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow): void {
        $lock = $store->lock();
        try { commentReject(static fn() => $workflow()->run(), Bridge\Exception\WorkerBusy::class); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        commentAssert($notes->appends === 0 && $source->reads === 0);
    });
};
$tests['GitHub adapter constructs trusted links and rejects partial or malformed history'] = static function (): void {
    $value = ['id' => 700, 'body' => 'Ready', 'user' => ['login' => 'engineer'],
        'created_at' => '2026-10-30T10:00:00Z', 'updated_at' => '2026-10-30T10:00:00Z',
        'html_url' => 'https://untrusted.invalid/'];
    $adapter = new GitHubIssueComments(new HttpClient(static fn() => new HttpResponse(200, [], [$value])),
        'example/escalations', 'synthetic-token');
    commentAssert($adapter->list('101')[0]->url === sourceComment()->url);
    $partial = new GitHubIssueComments(new HttpClient(static fn() => new HttpResponse(200,
        ['Link' => '<https://untrusted.invalid/>; rel="next"'], [])), 'example/escalations', 'synthetic-token');
    commentReject(static fn() => $partial->list('101'), RemoteFailure::class);
    $value['body'] = str_repeat('x', 60_001);
    $large = new GitHubIssueComments(new HttpClient(static fn() => new HttpResponse(200, [], [$value])),
        'example/escalations', 'synthetic-token');
    commentReject(static fn() => $large->list('101'), RemoteFailure::class);
};
$tests['Zendesk adapter sends only an internal note and the observed safe-update guard'] = static function (): void {
    $marker = 'Support Bridge comment: ' . str_repeat('a', 64);
    $body = sourceComment()->note($marker);
    $posted = false;
    $adapter = new ZendeskInternalNotes(new HttpClient(
        static function ($method, $url, $headers, $json) use (&$posted, $body): HttpResponse {
            if ($method === 'PUT') {
                commentAssert($json === ['ticket' => ['comment' => ['body' => $body, 'public' => false],
                    'safe_update' => true, 'updated_stamp' => 'observed-version']]);
                $posted = true;
                return new HttpResponse(200, [], ['ticket' => ['id' => 501]]);
            }
            commentAssert(str_contains($url, '/tickets/501/comments.json?per_page=100&page=1'));
            return new HttpResponse(200, [], ['comments' => $posted ? [
                ['id' => 901, 'body' => $body, 'plain_body' => $body, 'public' => false],
            ] : [], 'next_page' => null]);
        }
    ), 'example', new ZendeskAuthentication('', 'synthetic-token', 'oauth'));
    commentAssert($adapter->find('501', $marker) === null);
    $adapter->append('501', $body, 'observed-version');
    commentAssert($adapter->find('501', $marker) === '901');
};
$tests['public, duplicate and incomplete Zendesk marker evidence cannot confirm delivery'] = static function (): void {
    $marker = 'Support Bridge comment: ' . str_repeat('b', 64);
    $value = ['id' => 901, 'body' => $marker . "\nA note", 'public' => true];
    foreach (['public', 'duplicate', 'incomplete'] as $mode) {
        $records = $mode === 'duplicate' ? [array_replace($value, ['public' => false]),
            array_replace($value, ['id' => 902, 'public' => false])] : [$value];
        $adapter = new ZendeskInternalNotes(new HttpClient(static fn() => new HttpResponse(200, [], [
            'comments' => $mode === 'incomplete' ? [] : $records,
            'next_page' => $mode === 'incomplete' ? 'https://untrusted.invalid/' : null,
        ])), 'example', new ZendeskAuthentication('', 'synthetic-token', 'oauth'));
        commentReject(static fn() => $adapter->find('501', $marker),
            $mode === 'public' ? Bridge\Comments\UnsafeNoteEvidence::class : RemoteFailure::class);
    }
};
$tests['operator API enforces authority, strict bodies and provider-free ledger queries'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow, $connection, $gh, $zd, &$now, $home): void {
        $calls = 0;
        $context = new Context(new Configuration('local', $home, 'local/demo', 'local'), null,
            static function () use ($source, $notes, $gh, $zd, &$calls): array {
                ++$calls; return [$source, $notes, $gh, $zd];
            });
        $token = 'synthetic-comment-operator-token-0123456789abcdef';
        $metrics = 'synthetic-comment-metrics-token-0123456789abcdef';
        $api = new Api(static fn() => $context, $token, $metrics);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        commentAssert($api->handle(new Request('POST', '/api/comments/sync', [], '{}'))->status === 401);
        commentAssert($api->handle(new Request('POST', '/api/comments/sync',
            ['Authorization' => 'Bearer ' . $metrics], '{}'))->status === 401);
        $listing = $api->handle(new Request('GET', '/api/comment-deliveries', $headers));
        commentAssert($listing->status === 200 && $calls === 0);
        commentAssert($api->handle(new Request('POST', '/api/comments/sync', $headers, '{"public":true}'))->status === 422);
        $result = $api->handle(new Request('POST', '/api/comments/sync', $headers, '{}'));
        commentAssert($result->status === 200 && $result->body['delivered'] === 1 && $calls === 1);
        $listing = $api->handle(new Request('GET', '/api/comment-deliveries', $headers));
        commentAssert($listing->body['total'] === 1 && $listing->body['stream_total'] === 1);
        commentAssert($api->handle(new Request('GET', '/api/comment-deliveries', $headers, '', ['limit' => '101']))->status === 422);
    });
};
$tests['comment metrics contain only complete fixed states and a numeric stream-error count'] = static function (): void {
    commentFixture(static function ($store, $repository, $source, $notes, $workflow): void {
        $workflow()->run();
        $metrics = $store->metrics();
        $renderer = new CommentMetrics();
        $text = implode("\n", $renderer->render($metrics['comments'], $metrics['comment_stream_errors']));
        commentAssert(str_contains($text, 'support_bridge_comment_deliveries{status="delivered"} 1'));
        commentAssert(!str_contains($text, 'engineer') && !str_contains($text, 'case') && !str_contains($text, '700'));
        unset($metrics['comments']['pending']);
        commentReject(static fn() => $renderer->render($metrics['comments'], 0), RuntimeException::class);
    });
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$failed; echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL; }
}
echo count($tests) . ' comment delivery checks; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
