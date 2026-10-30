<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\HttpClient;
use Bridge\Intake\EngineeringIssues;
use Bridge\Intake\EscalationTicket;
use Bridge\Intake\EngineeringIssueBody;
use Bridge\RemoteFailure;
use InvalidArgumentException;

/** Creation is a separate capability from the ordinary fetch/patch provider port. */
final class GitHubEscalations implements EngineeringIssues
{
    private readonly string $repository;
    private readonly EngineeringIssueBody $body;
    private readonly array $headers;
    private ?array $issues = null;

    public function __construct(
        private readonly HttpClient $http,
        string $repository,
        string $subdomain,
        #[\SensitiveParameter] string $token
    ) {
        $this->repository = Identity::repository($repository);
        $this->body = new EngineeringIssueBody($subdomain);
        if ($token === '' || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new InvalidArgumentException('A GitHub access credential is required.');
        }
        $this->headers = [
            'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2026-03-10',
        ];
    }

    public function find(string $marker): ?string
    {
        $this->validateMarker($marker);
        $matches = [];
        foreach ($this->listing() as $issue) {
            if (str_contains($issue['body'], $marker)) {
                $matches[] = $issue['number'];
            }
        }
        if (count($matches) > 1) {
            throw new RemoteFailure('Several GitHub issues carry this escalation marker.');
        }
        return $matches[0] ?? null;
    }

    public function create(EscalationTicket $ticket, string $marker): string
    {
        $this->validateMarker($marker);
        $response = $this->http->request('POST', $this->origin(), $this->headers, [
            'title' => $ticket->record->fields['title'],
            'body' => $this->body->render($ticket, $marker),
        ]);
        $response->assertSuccess('GitHub');
        if ($response->status !== 201) {
            throw new RemoteFailure('GitHub did not confirm issue creation.');
        }
        $issue = $this->issue($response->body);
        if (!str_contains($issue['body'], $marker)) {
            throw new RemoteFailure('GitHub returned an unrecognized created issue.');
        }
        if ($this->issues !== null) {
            $this->issues[] = $issue;
        }
        return $issue['number'];
    }

    private function listing(): array
    {
        if ($this->issues !== null) {
            return $this->issues;
        }
        $issues = [];
        for ($page = 1; $page <= 5; ++$page) {
            $response = $this->http->request(
                'GET',
                $this->origin() . '?state=all&sort=created&direction=desc&per_page=100&page=' . $page,
                $this->headers
            );
            $response->assertSuccess('GitHub');
            if (!array_is_list($response->body) || count($response->body) > 100) {
                throw new RemoteFailure('GitHub returned an invalid issue listing.');
            }
            foreach ($response->body as $value) {
                if (!is_array($value)) {
                    throw new RemoteFailure('GitHub returned an invalid issue listing.');
                }
                if (!array_key_exists('pull_request', $value)) {
                    $issues[] = $this->issue($value);
                }
            }
            if (!str_contains($response->headers['link'] ?? '', 'rel="next"')) {
                return $this->issues = $issues;
            }
        }
        throw new RemoteFailure('GitHub issue lookup exceeded its five-page bound.', true, 60);
    }

    private function origin(): string
    {
        return 'https://api.github.com/repos/' . $this->repository . '/issues';
    }

    private function issue(array $value): array
    {
        if (
            array_key_exists('pull_request', $value) || !array_key_exists('body', $value)
            || (!is_int($value['number'] ?? null) && !is_string($value['number'] ?? null))
            || !preg_match('/^[1-9][0-9]{0,15}$/D', (string) $value['number'])
            || (!is_string($value['body'] ?? null) && ($value['body'] ?? null) !== null)
        ) {
            throw new RemoteFailure('GitHub returned an invalid engineering issue.');
        }
        return ['number' => (string) $value['number'], 'body' => $value['body'] ?? ''];
    }

    private function validateMarker(string $marker): void
    {
        if (!preg_match('/^<!-- support-bridge-escalation:[a-f0-9]{64} -->$/D', $marker)) {
            throw new InvalidArgumentException('Invalid escalation marker.');
        }
    }
}
