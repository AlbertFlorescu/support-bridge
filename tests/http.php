<?php
declare(strict_types=1);

namespace Bridge\Tests\Http;

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\HttpClient;
use Bridge\HttpResponse;
use Bridge\Provider\GitHub;
use Bridge\Provider\Zendesk;
use Bridge\Record;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function raises(string $class, callable $operation): \Throwable
{
    try {
        $operation();
    } catch (\Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }
        throw new \RuntimeException('Expected ' . $class . '; got ' . $error::class . ': ' . $error->getMessage());
    }
    throw new \RuntimeException('Expected ' . $class . '; no exception was raised.');
}

/** No real requests: assert the HTTP boundary using a queue of decoded responses. */
function scripted(array $responses, ?array &$requests): HttpClient
{
    $requests = [];
    return new HttpClient(static function (string $method, string $url, array $headers, ?array $json) use (&$requests, &$responses): HttpResponse {
        $requests[] = compact('method', 'url', 'headers', 'json');
        if ($responses === []) {
            throw new \LogicException('Unexpected extra request.');
        }
        $next = array_shift($responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    });
}

function issue(string $title = 'Checkout fails', string $state = 'open', array $extra = []): HttpResponse
{
    return new HttpResponse(200, [], array_replace([
        'number' => 7, 'title' => $title, 'state' => $state, 'updated_at' => '2026-10-20T12:00:00Z',
    ], $extra));
}

function ticket(string $status = 'pending', string $subject = 'Checkout fails'): HttpResponse
{
    return new HttpResponse(200, [], ['ticket' => [
        'id' => 42, 'subject' => $subject, 'status' => $status, 'updated_at' => '2026-10-20T12:00:00Z',
    ]]);
}

$tests = [];
$tests['GitHub fetch uses official endpoint, bearer auth and pinned API version'] = static function (): void {
    $http = scripted([issue()], $requests);
    $record = (new GitHub($http, 'acme/store', 'fake-github-token'))->fetch('7');
    same(['title' => 'Checkout fails', 'state' => 'open'], $record->fields);
    same('2026-10-20T12:00:00Z', $record->version);
    same('GET', $requests[0]['method']);
    same('https://api.github.com/repos/acme/store/issues/7', $requests[0]['url']);
    same('Bearer fake-github-token', $requests[0]['headers']['Authorization']);
    same('2026-03-10', $requests[0]['headers']['X-GitHub-Api-Version']);
};

$tests['GitHub preflight checks observed version and PATCHes only changed fields'] = static function (): void {
    $http = scripted([issue(), issue('Checkout fixed')], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], '2026-10-20T12:00:00Z');
    (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['title' => 'Checkout fixed'], $expected);
    same(['GET', 'PATCH'], array_column($requests, 'method'));
    same(['title' => 'Checkout fixed'], $requests[1]['json']);
    same(false, isset($requests[1]['headers']['If-Match']));
};

$tests['GitHub observed concurrent changes prevent PATCH'] = static function (): void {
    $http = scripted([issue('Human edit')], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], 'v1');
    raises(RemoteConflict::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['title' => 'Bot edit'], $expected));
    same(1, count($requests));
};

$tests['GitHub changed version prevents an observable ABA write even when fields match'] = static function (): void {
    $http = scripted([issue(extra: ['updated_at' => '2026-10-20T12:01:00Z'])], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], '2026-10-20T12:00:00Z');
    raises(RemoteConflict::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['title' => 'Bot edit'], $expected));
    same(1, count($requests));
};

$tests['GitHub no-op patch does not write'] = static function (): void {
    $http = scripted([issue()], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], '2026-10-20T12:00:00Z');
    (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['title' => 'Checkout fails'], $expected);
    same(['GET'], array_column($requests, 'method'));
};

$tests['GitHub pull requests are rejected on GET and on PATCH response'] = static function (): void {
    $pr = issue(extra: ['pull_request' => ['url' => 'https://api.github.com/repos/acme/store/pulls/7']]);
    $http = scripted([$pr], $requests);
    raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('7'));
    $http = scripted([issue(), $pr], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], '2026-10-20T12:00:00Z');
    raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['state' => 'closed'], $expected));
};

$tests['Zendesk maps every supported status and marks closed tickets immutable'] = static function (): void {
    foreach (['new', 'open', 'pending', 'hold', 'solved', 'closed'] as $status) {
        $http = scripted([ticket($status)], $requests);
        $record = (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->fetch('42');
        same(in_array($status, ['solved', 'closed'], true) ? 'closed' : 'open', $record->fields['state']);
        same($status !== 'closed', $record->writable);
    }
};

$tests['Zendesk title-only updates preserve pending status and send optimistic lock'] = static function (): void {
    $http = scripted([ticket('pending', 'A new subject')], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], '2026-10-20T12:00:00Z');
    (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->patch('42', ['title' => 'A new subject'], $expected);
    same('PUT', $requests[0]['method']);
    same('https://acme.zendesk.com/api/v2/tickets/42.json', $requests[0]['url']);
    same('Basic ' . base64_encode('agent@example.com/token:fake'), $requests[0]['headers']['Authorization']);
    same(['ticket' => ['safe_update' => true, 'updated_stamp' => '2026-10-20T12:00:00Z', 'subject' => 'A new subject']], $requests[0]['json']);
};

$tests['Zendesk closing uses solved and reopening uses open'] = static function (): void {
    foreach (['closed' => 'solved', 'open' => 'open'] as $canonical => $native) {
        $http = scripted([ticket($native)], $requests);
        $expected = new Record(['title' => 'Checkout fails', 'state' => $canonical === 'closed' ? 'open' : 'closed'], '2026-10-20T12:00:00Z');
        (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->patch('42', ['state' => $canonical], $expected);
        same($native, $requests[0]['json']['ticket']['status']);
    }
};

$tests['Zendesk rejects writes to immutable closed tickets before network access'] = static function (): void {
    $http = scripted([], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'closed'], 'v1', false);
    raises(RemoteFailure::class, static fn () => (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->patch('42', ['state' => 'open'], $expected));
    same([], $requests);
};

$tests['Zendesk safe-update collision becomes a reconciliation conflict'] = static function (): void {
    $http = scripted([new HttpResponse(409, [], ['error' => 'UpdateConflict'])], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], 'v1');
    raises(RemoteConflict::class, static fn () => (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->patch('42', ['state' => 'closed'], $expected));
};

$tests['HTTP auth, missing resources and validation failures are permanent and sanitized'] = static function (): void {
    foreach ([401, 403, 404, 422] as $status) {
        $http = scripted([new HttpResponse($status, [], ['error' => 'SECRET customer ticket text'])], $requests);
        $failure = raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('7'));
        same(false, $failure->retryable);
        same(null, $failure->retryAfter);
        same(false, str_contains($failure->getMessage(), 'SECRET'));
    }
};

$tests['HTTP transport timeouts, throttling and server failures are retryable'] = static function (): void {
    foreach ([408, 429, 500, 502, 503, 504] as $status) {
        $response = new HttpResponse($status, ['Retry-After' => '12']);
        $failure = raises(RemoteFailure::class, static fn () => $response->assertSuccess('Zendesk'));
        same(true, $failure->retryable);
        same(12, $failure->retryAfter);
    }
    $http = scripted([new \RuntimeException('SECRET transport detail')], $requests);
    $failure = raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('7'));
    same(true, $failure->retryable);
    same(false, str_contains($failure->getMessage(), 'SECRET'));
};

$tests['GitHub rate-limited 403 needs response headers, with documented fallback'] = static function (): void {
    $response = new HttpResponse(403, ['X-RateLimit-Remaining' => '0']);
    $failure = raises(RemoteFailure::class, static fn () => $response->assertSuccess('GitHub'));
    same(true, $failure->retryable);
    same(60, $failure->retryAfter);
    $response = new HttpResponse(403, ['Retry-After' => '18']);
    $failure = raises(RemoteFailure::class, static fn () => $response->assertSuccess('GitHub'));
    same(true, $failure->retryable);
    same(18, $failure->retryAfter);
    $response = new HttpResponse(403, ['Retry-After' => 'invalid'], ['message' => 'rate limit']);
    $failure = raises(RemoteFailure::class, static fn () => $response->assertSuccess('GitHub'));
    same(false, $failure->retryable);
    $response = new HttpResponse(403, [], ['message' => 'You have exceeded a secondary rate limit.']);
    $failure = raises(RemoteFailure::class, static fn () => $response->assertSuccess('GitHub'));
    same(true, $failure->retryable);
    same(60, $failure->retryAfter);
};

$tests['Retry-After supports HTTP dates and waits for the later primary reset'] = static function (): void {
    $now = 1_700_000_000;
    $response = new HttpResponse(429, [
        'Retry-After' => gmdate(DATE_RFC7231, $now + 30),
        'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($now + 90),
    ]);
    same(90, $response->retryDelay($now));
    same(0, (new HttpResponse(429, ['Retry-After' => gmdate(DATE_RFC7231, $now - 30)]))->retryDelay($now));
    same(null, (new HttpResponse(429, ['Retry-After' => 'tomorrow']))->retryDelay($now));
    same(10, (new HttpResponse(429, ['Retry-After' => '10', 'X-RateLimit-Reset' => (string) ($now + 90)]))->retryDelay($now));
};

$tests['Host restrictions run before any transport and prevent credential forwarding'] = static function (): void {
    $http = scripted([], $requests);
    foreach ([
        'http://api.github.com/repos/acme/store/issues/7',
        'https://api.github.com.evil.test/repos/acme/store/issues/7',
        'https://evil.test/',
        'https://acme.zendesk.com.evil.test/api/v2/tickets/42.json',
        'https://user:password@api.github.com/issues/7',
        'https://api.github.com:8443/issues/7',
        'https://api.github.com/issues/7#fragment',
        'https://nested.acme.zendesk.com/',
    ] as $url) {
        raises(\InvalidArgumentException::class, static fn () => $http->request('GET', $url, ['Authorization' => 'Bearer fake']));
    }
    same([], $requests);
};

$tests['Redirects are permanent and never followed by adapters'] = static function (): void {
    $http = scripted([new HttpResponse(302, ['Location' => 'https://evil.test/'])], $requests);
    $failure = raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('7'));
    same(false, $failure->retryable);
    same(1, count($requests));
};

$tests['Provider configuration and IDs cannot introduce paths or header injection'] = static function (): void {
    $http = scripted([], $requests);
    raises(\InvalidArgumentException::class, static fn () => new GitHub($http, 'acme/../other', 'fake'));
    raises(\InvalidArgumentException::class, static fn () => new GitHub($http, 'acme/..', 'fake'));
    raises(\InvalidArgumentException::class, static fn () => new GitHub($http, 'acme/store', "fake\r\nX-Evil: true"));
    raises(\InvalidArgumentException::class, static fn () => new Zendesk($http, 'acme.zendesk.com', 'agent@example.com', 'fake'));
    raises(\InvalidArgumentException::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('../7'));
    raises(\InvalidArgumentException::class, static fn () => (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->fetch('0'));
    same([], $requests);
};

$tests['Malformed provider representations fail without leaking payloads'] = static function (): void {
    $http = scripted([new HttpResponse(200, [], ['title' => 'SECRET'])], $requests);
    $failure = raises(RemoteFailure::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->fetch('7'));
    same(false, str_contains($failure->getMessage(), 'SECRET'));
    $http = scripted([new HttpResponse(200, [], ['ticket' => ['subject' => 'SECRET', 'status' => 'unknown']])], $requests);
    $failure = raises(RemoteFailure::class, static fn () => (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->fetch('42'));
    same(false, str_contains($failure->getMessage(), 'SECRET'));
};

$tests['Only canonical fields may be written'] = static function (): void {
    $http = scripted([], $requests);
    $expected = new Record(['title' => 'Checkout fails', 'state' => 'open'], 'v1');
    raises(\InvalidArgumentException::class, static fn () => (new GitHub($http, 'acme/store', 'fake'))->patch('7', ['body' => 'no'], $expected));
    raises(\InvalidArgumentException::class, static fn () => (new Zendesk($http, 'acme', 'agent@example.com', 'fake'))->patch('42', ['tags' => []], $expected));
    same([], $requests);
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (\Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
    }
}
echo count($tests) . ' HTTP contract tests; ' . $failed . " failed.\n";
exit($failed === 0 ? 0 : 1);
