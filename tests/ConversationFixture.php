<?php

declare(strict_types=1);

use Bridge\Comments\CommentDirection;
use Bridge\Comments\CommentWorkflow;
use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Infrastructure\Persistence\CommentRepository;
use Bridge\Infrastructure\Persistence\SqliteConnection;
use Bridge\Provider\GitHubIssueComments;
use Bridge\Provider\ZendeskAuthentication;
use Bridge\Provider\ZendeskInternalNotes;
use Bridge\Provider\ZendeskTicketNotes;
use Bridge\RemoteFailure;
use Bridge\Store;
use Bridge\Tests\FakeProvider;

/** Real adapters and ledgers, with a controlled transport and no external network. */
final class ConversationFixture
{
    public readonly string $home;
    public readonly SqliteConnection $connection;
    public readonly Store $store;
    public readonly CommentRepository $forward;
    public readonly CommentRepository $reverse;
    public readonly FakeProvider $github;
    public readonly FakeProvider $zendesk;
    public readonly GitHubIssueComments $issues;
    public readonly ZendeskInternalNotes $internal;
    public readonly ZendeskTicketNotes $source;
    public array $githubComments = [];
    public array $zendeskComments;
    public int $posts = 0;
    public int $puts = 0;
    public int $now;
    public bool $losePost = false;
    public bool $losePut = false;
    public bool $hidePosts = false;
    public bool $historyFailure = false;
    public bool $staleIssue = false;

    public function __construct()
    {
        $this->home = sys_get_temp_dir() . '/bridge-conversation-' . bin2hex(random_bytes(8));
        mkdir($this->home, 0700);
        $this->connection = new SqliteConnection($this->home . '/local.sqlite');
        $this->store = new Store($this->connection);
        $this->forward = new CommentRepository($this->connection);
        $this->reverse = new CommentRepository($this->connection, CommentDirection::ZendeskToGitHub);
        $this->store->addPair('case', '101', '501');
        $fields = ['title' => 'CSV export fails', 'state' => 'open'];
        $this->store->update('case', ['status' => 'synced', 'baseline' => $fields]);
        $this->github = new FakeProvider();
        $this->zendesk = new FakeProvider();
        $this->github->seed('101', $fields);
        $this->zendesk->seed('501', $fields);
        $this->zendeskComments = [$this->rawNote('800', 'Original customer report', true)];
        $http = new HttpClient($this->transport(...));
        $auth = new ZendeskAuthentication('', 'controlled-note-token', 'oauth');
        $this->issues = new GitHubIssueComments($http, 'example/escalations', 'controlled-issue-token');
        $this->internal = new ZendeskInternalNotes($http, 'example', $auth);
        $this->source = new ZendeskTicketNotes($http, 'example', $auth);
        $this->now = time();
    }

    public function runForward(): array
    {
        return (new CommentWorkflow($this->store, $this->forward, $this->issues, $this->internal,
            $this->github, $this->zendesk, fn(): int => $this->now))->run();
    }

    public function runReverse(): array
    {
        return (new CommentWorkflow($this->store, $this->reverse, $this->source, $this->issues,
            $this->github, $this->zendesk, fn(): int => $this->now))->run();
    }

    public function question(string $body = 'Can support provide an example export?'): void
    {
        $this->githubComments[] = $this->rawIssueComment((string) (700 + count($this->githubComments)), $body);
    }

    public function reply(string $id = '1000', string $body = 'The customer reproduced it with 120,000 rows.', bool $public = false): void
    {
        $this->zendeskComments[] = $this->rawNote($id, $body, $public);
    }

    public function rawNote(string $id, string $body, bool $public): array
    {
        return ['id' => $id, 'body' => $body, 'plain_body' => $body, 'public' => $public,
            'author_id' => 42, 'created_at' => '2026-10-30T10:00:00Z'];
    }

    public function rawIssueComment(string $id, string $body): array
    {
        // One account writes both human and integration messages: author filtering would lose replies.
        return ['id' => $id, 'body' => $body, 'user' => ['login' => 'engineer'],
            'created_at' => '2026-10-30T10:00:00Z', 'updated_at' => '2026-10-30T10:00:00Z'];
    }

    public function transport(string $method, string $url, array $headers, ?array $json): HttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);
        conversationCheck(in_array($host, ['api.github.com', 'example.zendesk.com'], true));
        if ($host === 'api.github.com' && $path === '/repos/example/escalations/issues/101') {
            conversationCheck($method === 'GET');
            if ($this->staleIssue) {
                $this->github->change('101', []);
            }
            return new HttpResponse(200, [], ['id' => 101, 'updated_at' => $this->github->fetch('101')->version]);
        }
        if ($host === 'api.github.com' && $path === '/repos/example/escalations/issues/101/comments') {
            if ($method === 'GET') {
                return new HttpResponse(200, [], array_values(array_filter($this->githubComments,
                    fn(array $comment): bool => !$this->hidePosts
                        || !str_starts_with($comment['body'], 'Support Bridge note: '))));
            }
            conversationCheck($method === 'POST' && array_keys($json) === ['body']);
            ++$this->posts;
            $id = (string) (700 + count($this->githubComments));
            $this->githubComments[] = $this->rawIssueComment($id, $json['body']);
            $this->github->change('101', []);
            if ($this->losePost) {
                throw new RemoteFailure('Controlled lost GitHub response.', true);
            }
            return new HttpResponse(201, [], ['id' => $id]);
        }
        if ($host === 'example.zendesk.com' && $path === '/api/v2/tickets/501/comments.json') {
            conversationCheck($method === 'GET');
            if ($this->historyFailure) {
                throw new RemoteFailure('Controlled Zendesk discovery outage.', true, 3600);
            }
            return new HttpResponse(200, [], ['comments' => $this->zendeskComments, 'next_page' => null,
                'users' => [['id' => 42, 'name' => 'Escalation Engineer']]]);
        }
        conversationCheck($host === 'example.zendesk.com' && $path === '/api/v2/tickets/501.json'
            && $method === 'PUT' && array_keys($json['ticket']) === ['comment', 'safe_update', 'updated_stamp']);
        conversationCheck($json['ticket']['comment']['public'] === false
            && $json['ticket']['updated_stamp'] === $this->zendesk->fetch('501')->version);
        ++$this->puts;
        $this->reply((string) (900 + $this->puts), $json['ticket']['comment']['body']);
        $this->zendesk->change('501', []);
        if ($this->losePut) {
            throw new RemoteFailure('Controlled lost Zendesk response.', true);
        }
        return new HttpResponse(200, [], ['ticket' => ['id' => 501]]);
    }

    public function close(): void
    {
        foreach (glob($this->home . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->home);
    }
}
