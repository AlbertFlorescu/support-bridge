<?php

declare(strict_types=1);

namespace Bridge\Provider;

use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Intake\EscalationTicket;
use Bridge\Intake\TicketSource;
use Bridge\Record;
use Bridge\RemoteFailure;
use InvalidArgumentException;

/** Ticket-scoped discovery filters listed tickets locally; it never edits Zendesk. */
final readonly class ZendeskEscalations implements TicketSource
{
    private string $subdomain;

    public function __construct(
        private HttpClient $http,
        string $subdomain,
        private ZendeskAuthorization $authorization
    ) {
        $this->subdomain = Identity::subdomain($subdomain);
    }

    public function discover(string $tag, int $page): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $tag) || $page < 1 || $page > 20) {
            throw new InvalidArgumentException('Invalid escalation discovery bounds.');
        }
        $query = http_build_query([
            'sort_by' => 'id', 'sort_order' => 'desc', 'per_page' => 50, 'page' => $page,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->request('GET', $this->origin() . '/api/v2/tickets.json?' . $query, $this->headers());
        $response->assertSuccess('Zendesk');
        $results = $response->body['tickets'] ?? null;
        if (
            !is_array($results) || !array_is_list($results) || count($results) > 50
            || !array_key_exists('next_page', $response->body)
            || (!is_string($response->body['next_page']) && $response->body['next_page'] !== null)
        ) {
            throw new RemoteFailure('Zendesk returned an invalid escalation listing.');
        }
        $tickets = [];
        foreach ($results as $result) {
            if (!is_array($result) || !is_array($result['tags'] ?? null) || !array_is_list($result['tags'])) {
                throw new RemoteFailure('Zendesk returned invalid ticket tags.');
            }
            if (
                !in_array($tag, $result['tags'], true)
                || in_array($result['status'] ?? null, ['solved', 'closed'], true)
            ) {
                continue;
            }
            $ticket = $this->ticket($result);
            if ($ticket->eligible($tag)) {
                $tickets[$ticket->id] = $ticket;
            }
        }
        return ['tickets' => array_values($tickets), 'has_more' => !empty($response->body['next_page'])];
    }

    public function fetch(string $id): EscalationTicket
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) {
            throw new InvalidArgumentException('Invalid escalation ticket ID.');
        }
        $response = $this->http->request(
            'GET',
            $this->origin() . '/api/v2/tickets/' . $id . '.json',
            $this->headers()
        );
        $response->assertSuccess('Zendesk');
        $ticket = $this->ticket($response->body['ticket'] ?? null, true);
        if ($ticket->id !== $id) {
            throw new RemoteFailure('Zendesk returned another escalation ticket.');
        }
        return $ticket;
    }

    private function origin(): string
    {
        return 'https://' . $this->subdomain . '.zendesk.com';
    }

    private function headers(): array
    {
        return ['Authorization' => $this->authorization->authorizationHeader(), 'Accept' => 'application/json'];
    }

    private function ticket(mixed $value, bool $includeDescription = false): EscalationTicket
    {
        if (
            !is_array($value) || (!is_int($value['id'] ?? null) && !is_string($value['id'] ?? null))
            || !is_string($value['subject'] ?? null) || !is_string($value['updated_at'] ?? null)
            || ($includeDescription && !is_string($value['description'] ?? null))
            || $value['updated_at'] === '' || !is_array($value['tags'] ?? null) || !array_is_list($value['tags'])
            || !in_array($value['status'] ?? null, ['new', 'open', 'pending', 'hold', 'solved', 'closed'], true)
        ) {
            throw new RemoteFailure('Zendesk returned an invalid escalation ticket.');
        }
        try {
            return new EscalationTicket(
                (string) $value['id'],
                new Record([
                    'title' => $value['subject'],
                    'state' => in_array($value['status'], ['solved', 'closed'], true) ? 'closed' : 'open',
                ], $value['updated_at']),
                $value['tags'],
                $includeDescription ? $value['description'] : null
            );
        } catch (InvalidArgumentException) {
            throw new RemoteFailure('Zendesk returned an unsupported escalation ticket.');
        }
    }
}
