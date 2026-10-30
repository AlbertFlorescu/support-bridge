<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\Comments\CommentSource;
use Bridge\Comments\IssueComment;
use Bridge\HttpClient;
use Bridge\RemoteFailure;
use InvalidArgumentException;

final readonly class GitHubIssueComments implements CommentSource
{
    private string $repository;
    private array $headers;

    public function __construct(private HttpClient $http, string $repository, #[\SensitiveParameter] string $token)
    {
        $this->repository = Identity::repository($repository);
        if ($token === '' || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new InvalidArgumentException('A GitHub access credential is required.');
        }
        $this->headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2026-03-10',
        ];
    }

    public function list(string $issueId): array
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $issueId)) {
            throw new InvalidArgumentException('Invalid GitHub issue ID.');
        }
        $comments = [];
        for ($page = 1; $page <= 5; ++$page) {
            $response = $this->http->request(
                'GET',
                'https://api.github.com/repos/' . $this->repository . '/issues/' . $issueId
                    . '/comments?per_page=100&page=' . $page,
                $this->headers
            );
            $response->assertSuccess('GitHub');
            if (!array_is_list($response->body) || count($response->body) > 100) {
                throw new RemoteFailure('GitHub returned an invalid comment listing.');
            }
            foreach ($response->body as $value) {
                $comment = $this->comment($value, $issueId);
                if (isset($comments[$comment->id])) {
                    throw new RemoteFailure('GitHub returned duplicate comment IDs.');
                }
                $comments[$comment->id] = $comment;
            }
            if (!str_contains($response->headers['link'] ?? '', 'rel="next"')) {
                return array_values($comments);
            }
        }
        throw new RemoteFailure('GitHub comment listing exceeded its five-page bound.', true, 60);
    }

    private function comment(mixed $value, string $issueId): IssueComment
    {
        if (
            !is_array($value) || (!is_int($value['id'] ?? null) && !is_string($value['id'] ?? null))
            || !is_string($value['body'] ?? null) || !is_string($value['created_at'] ?? null)
            || !is_string($value['updated_at'] ?? null)
            || !is_string($value['user']['login'] ?? null)
        ) {
            throw new RemoteFailure('GitHub returned an unsupported comment.');
        }
        try {
            $id = (string) $value['id'];
            return new IssueComment(
                $id,
                $value['body'],
                $value['user']['login'],
                $value['created_at'],
                $value['updated_at'],
                'https://github.com/' . $this->repository . '/issues/' . $issueId . '#issuecomment-' . $id
            );
        } catch (InvalidArgumentException) {
            throw new RemoteFailure('GitHub returned an unsupported comment.');
        }
    }
}
