<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\HttpClient;
use Bridge\RemoteFailure;
use InvalidArgumentException;

/** One fixed-host, complete bounded history reader shared by both comment channels. */
final readonly class ZendeskCommentHistory
{
    private string $subdomain;

    public function __construct(
        private HttpClient $http,
        string $subdomain,
        private ZendeskAuthorization $authorization
    ) {
        $this->subdomain = Identity::subdomain($subdomain);
    }

    public function read(string $ticketId): array
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $ticketId)) {
            throw new InvalidArgumentException('Invalid Zendesk ticket ID.');
        }
        $comments = $users = [];
        for ($page = 1; $page <= 5; ++$page) {
            $url = 'https://' . $this->subdomain . '.zendesk.com/api/v2/tickets/' . $ticketId
                . '/comments.json?per_page=100&page=' . $page . '&sort_order=asc&include=users';
            $response = $this->http->request('GET', $url, [
                'Authorization' => $this->authorization->authorizationHeader(), 'Accept' => 'application/json',
            ]);
            $response->assertSuccess('Zendesk');
            if (
                !is_array($response->body['comments'] ?? null) || !array_is_list($response->body['comments'])
                || count($response->body['comments']) > 100 || !array_key_exists('next_page', $response->body)
                || ($response->body['next_page'] !== null && !is_string($response->body['next_page']))
            ) {
                throw new RemoteFailure('Zendesk returned an invalid comment history.');
            }
            foreach ($response->body['comments'] as $comment) {
                $id = is_array($comment) ? ($comment['id'] ?? null) : null;
                if (
                    (!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)
                    || isset($comments[(string) $id]) || !is_bool($comment['public'] ?? null)
                    || !is_string($comment['body'] ?? null)
                    || (isset($comment['plain_body']) && !is_string($comment['plain_body']))
                ) {
                    throw new RemoteFailure('Zendesk returned an invalid or duplicate comment.');
                }
                $comments[(string) $id] = $comment;
            }
            $included = $response->body['users'] ?? [];
            if (!is_array($included) || !array_is_list($included)) {
                throw new RemoteFailure('Zendesk returned invalid source attribution.');
            }
            foreach ($included as $user) {
                $id = is_array($user) ? ($user['id'] ?? null) : null;
                $name = is_array($user) ? ($user['name'] ?? null) : null;
                if (
                    (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)
                    && is_string($name) && trim($name) !== '' && strlen($name) <= 500
                    && preg_match('//u', $name) && !preg_match('/[\x00-\x1f\x7f]/', $name)
                ) {
                    $users[(string) $id] = $name;
                }
            }
            if ($response->body['next_page'] === null) {
                return ['comments' => array_values($comments), 'users' => $users];
            }
        }
        throw new RemoteFailure('Zendesk comment history exceeded its five-page bound.', true, 60);
    }
}
