<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;

/** Wire-level checks run an isolated PHP server on loopback and never contact a provider. */
final class SmokeServer
{
    public readonly string $home;
    private readonly string $directory;
    private readonly string $token;
    private readonly string $metricsToken;
    private mixed $process = null;
    private string $baseUrl = '';
    private array $logs = [];

    public function __construct(private readonly string $root)
    {
        $this->directory = sys_get_temp_dir() . '/support-bridge-wire-' . bin2hex(random_bytes(8));
        $this->home = $this->directory . '/state';
        $this->token = bin2hex(random_bytes(24));
        $this->metricsToken = bin2hex(random_bytes(24));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create the isolated wire-test directory.');
        }
    }

    public function start(): void
    {
        $environment = getenv();
        $environment['BRIDGE_MODE'] = 'local';
        $environment['BRIDGE_HOME'] = $this->home;
        $environment['BRIDGE_API_TOKEN'] = $this->token;
        $environment['BRIDGE_METRICS_TOKEN'] = $this->metricsToken;
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $deadline = microtime(true) + 4.5;
        for ($attempt = 1; $attempt <= 3 && microtime(true) < $deadline; ++$attempt) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            if ($socket === false) {
                throw new RuntimeException('Cannot reserve a loopback test port.');
            }
            $address = stream_socket_get_name($socket, false);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            fclose($socket);
            $this->baseUrl = 'http://127.0.0.1:' . $port;
            $out = $this->directory . '/stdout-' . $attempt . '.log';
            $err = $this->directory . '/stderr-' . $attempt . '.log';
            $this->logs[] = $out;
            $this->logs[] = $err;
            $pipes = [];
            $this->process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port,
                '-t', $this->root . '/public', $this->root . '/public/router.php'],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'ab'], 2 => ['file', $err, 'ab']],
                $pipes, $this->root, $environment);
            if (!is_resource($this->process)) {
                throw new RuntimeException('Cannot start the PHP loopback server.');
            }
            fclose($pipes[0]);
            $attemptDeadline = min($deadline, microtime(true) + 1.2);
            while (microtime(true) < $attemptDeadline) {
                if (!proc_get_status($this->process)['running']) {
                    break;
                }
                try {
                    if ($this->request('GET', '/health', null, false, [], 0.2)['status'] === 200) {
                        return;
                    }
                } catch (RuntimeException) {
                    // The socket can become available shortly after proc_open returns.
                }
                usleep(20_000);
            }
            $this->stop();
        }
        throw new RuntimeException('PHP server did not become ready within the bounded startup window.');
    }

    /** @return array{status: int, headers: array<string, string>, body: string, json: mixed} */
    public function request(string $method, string $path, ?string $body = null, bool $authenticated = true,
        array $extraHeaders = [], float $timeout = 2.0): array
    {
        $headers = ['Connection' => 'close'];
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $headers['Content-Length'] = (string) strlen($body);
        }
        $lines = [];
        foreach (array_replace($headers, $extraHeaders) as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines),
            'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0,
            'max_redirects' => 0, 'protocol_version' => 1.1, 'timeout' => $timeout]]);
        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context, 0, 4_194_304);
        if ($responseBody === false || !isset($http_response_header[0])
            || preg_match('~^HTTP/\S+ ([0-9]{3})~', $http_response_header[0], $match) !== 1) {
            throw new RuntimeException('Loopback HTTP request failed: ' . $method . ' ' . $path);
        }
        $responseHeaders = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        $json = null;
        if (str_starts_with($responseHeaders['content-type'] ?? '', 'application/json')) {
            $json = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
        }
        return ['status' => (int) $match[1], 'headers' => $responseHeaders, 'body' => $responseBody, 'json' => $json];
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 0.5;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }

    public function metricsCredential(string $method = 'GET', string $path = '/metrics', ?string $body = null): array
    {
        return $this->request($method, $path, $body, false, ['Authorization' => 'Bearer ' . $this->metricsToken]);
    }

    public function cleanup(): void
    {
        // Only these exact files were created by this test; no recursive or user-path deletion.
        foreach (['local.sqlite', 'local.sqlite-wal', 'local.sqlite-shm', 'local.sqlite.lock',
            'metrics.sqlite', 'metrics.sqlite-wal', 'metrics.sqlite-shm', 'github.json', 'zendesk.json'] as $name) {
            if (is_file($this->home . '/' . $name)) {
                @unlink($this->home . '/' . $name);
            }
        }
        foreach ($this->logs as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->home)) {
            @rmdir($this->home);
        }
        @rmdir($this->directory);
    }
}

function wireSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function wireCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$server = new SmokeServer($root);
$passed = 0;
$failed = 0;
$fixtures = [];
$record = static function (string $method, string $path, array $response) use (&$fixtures): void {
    $mediaType = trim(explode(';', $response['headers']['content-type'])[0]);
    $fixtures[] = ['method' => $method, 'path' => $path, 'status' => $response['status'],
        'mediaType' => $mediaType, 'body' => $mediaType === 'application/json' ? $response['json'] : $response['body']];
};
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
};

try {
    $server->start();
    $run('Wire health returns JSON without opening the state database', static function () use ($server, $record): void {
        $response = $server->request('GET', '/health', null, false);
        wireSame(200, $response['status']);
        wireSame('ok', $response['json']['status']);
        wireCheck(!file_exists($server->home . '/local.sqlite'), 'Liveness must not initialize SQLite.');
        wireSame('no-store', $response['headers']['cache-control']);
        wireCheck(preg_match('/^[a-f0-9]{24}$/D', $response['headers']['x-request-id']) === 1, 'Liveness needs a request ID.');
        $record('GET', '/health', $response);
    });
    $run('Documentation serves local Swagger assets with a restrictive CSP', static function () use ($server): void {
        $response = $server->request('GET', '/docs', null, false);
        wireSame(200, $response['status']);
        wireCheck(str_starts_with($response['headers']['content-type'], 'text/html'), 'Documentation must have an HTML media type.');
        wireCheck(str_contains($response['body'], '/assets/swagger-ui/swagger-ui-bundle.js'), 'Load the vendored Swagger bundle.');
        wireCheck(preg_match('~(?:src|href)=["\'](?:https?:)?//~i', $response['body']) === 0, 'Documentation must not load assets from a CDN.');
        $csp = $response['headers']['content-security-policy'] ?? '';
        wireCheck(str_contains($csp, "script-src 'self'") && str_contains($csp, "connect-src 'self'")
            && str_contains($csp, "frame-ancestors 'none'"), 'Restrict scripts, connections, and framing.');
        foreach (['swagger-ui.css', 'swagger-ui-bundle.js', 'initializer.js'] as $asset) {
            $loaded = $server->request('GET', '/assets/swagger-ui/' . $asset, null, false);
            wireSame(200, $loaded['status']);
            wireCheck(strlen($loaded['body']) > 100, 'Vendored documentation asset must contain content.');
        }
        $initializer = $server->request('GET', '/assets/swagger-ui/initializer.js', null, false)['body'];
        wireCheck(str_contains($initializer, 'validatorUrl: null') && str_contains($initializer, 'persistAuthorization: false'),
            'Swagger must not contact an external validator or retain operator credentials.');
    });
    $run('Published OpenAPI is available as JSON over HTTP', static function () use ($server): void {
        $response = $server->request('GET', '/openapi.json', null, false);
        wireSame(200, $response['status']);
        wireSame('3.1.0', $response['json']['openapi']);
        wireCheck(isset($response['json']['paths']['/api/pairs/{id}/resolve']), 'Published specification must describe operator resolution.');
    });
    $run('Unauthenticated wire requests fail before database creation and cannot be cached', static function () use ($server, $record): void {
        foreach ([['GET', '/api/pairs', null], ['POST', '/api/sync', '{}'],
            ['POST', '/api/pairs/escalation-1/approve', '{}']] as [$method, $path, $body]) {
            $response = $server->request($method, $path, $body, false);
            wireSame(401, $response['status']);
            wireSame('unauthorized', $response['json']['error']['code']);
            wireSame('no-store', $response['headers']['cache-control']);
            wireSame('Bearer', $response['headers']['www-authenticate']);
            wireSame($response['headers']['x-request-id'], $response['json']['error']['requestId']);
            wireCheck(!file_exists($server->home . '/local.sqlite'), 'Unauthorized requests must not open SQLite.');
            $record($method, str_ends_with($path, '/approve') ? '/api/pairs/{id}/approve' : $path, $response);
        }
    });
    $run('Unsupported HTTP methods carry an Allow header', static function () use ($server): void {
        $response = $server->request('GET', '/api/sync');
        wireSame(405, $response['status']);
        wireSame('POST', $response['headers']['allow']);
        wireSame('method_not_allowed', $response['json']['error']['code']);
    });
    $run('Metrics authentication is separate and rejects unauthorized requests before state creation', static function () use ($server, $record): void {
        foreach ([$server->request('GET', '/metrics', null, false), $server->request('GET', '/metrics'),
            $server->metricsCredential('GET', '/api/pairs'), $server->metricsCredential('POST', '/api/sync', '{}')] as $response) {
            wireSame(401, $response['status']);
            wireSame('unauthorized', $response['json']['error']['code']);
        }
        wireCheck(!file_exists($server->home . '/local.sqlite') && !file_exists($server->home . '/metrics.sqlite'),
            'Unauthorized metrics requests must not create business or instrumentation storage.');
        $record('GET', '/metrics', $server->request('GET', '/metrics', null, false));
    });
    $run('An authenticated wire sweep reconciles the initialized local pair', static function () use ($server, $record): void {
        $context = new Context(new Configuration('local', $server->home, 'local/demo', 'local'));
        $context->initialize();
        unset($context);
        $response = $server->request('POST', '/api/sync', '{}');
        wireSame(200, $response['status']);
        wireSame(1, $response['json']['synced']);
        wireSame('nosniff', $response['headers']['x-content-type-options']);
        $record('POST', '/api/sync', $response);
    });
    $run('Inspection returns canonical state and audit evidence over HTTP', static function () use ($server, $record): void {
        $response = $server->request('GET', '/api/pairs/escalation-1');
        wireSame(200, $response['status']);
        wireSame('synced', $response['json']['pair']['status']);
        wireSame('CSV export fails for large reports', $response['json']['pair']['baseline']['title']);
        wireCheck($response['json']['recent_events'] !== [], 'Inspection should expose audit history.');
        $record('GET', '/api/pairs/{id}', $response);
    });
    $run('Operator JSON metrics expose Prometheus readiness and no pending plan', static function () use ($server, $record): void {
        $response = $server->request('GET', '/api/metrics');
        wireSame(200, $response['status']);
        wireSame(0, $response['json']['pending_plans']);
        wireSame(true, $response['json']['observability']['enabled']);
        wireSame('prometheus', $response['json']['observability']['vendor']);
        wireSame(true, $response['json']['observability']['storage_available']);
        $record('GET', '/api/metrics', $response);
    });
    $run('Intake queries are read-only and disabled creation fails closed over HTTP', static function () use ($server, $record): void {
        $listing = $server->request('GET', '/api/escalations');
        wireSame(200, $listing['status']);
        wireSame([], $listing['json']['items']);
        wireSame(0, $listing['json']['total']);
        $record('GET', '/api/escalations', $listing);
        $disabled = $server->request('POST', '/api/intake', '{}');
        wireSame(503, $disabled['status']);
        wireSame('service_not_configured', $disabled['json']['error']['code']);
        $record('POST', '/api/intake', $disabled);
    });
    $run('Comment ledger is read-only and disabled local delivery fails closed on the wire', static function () use ($server, $record): void {
        $listing = $server->request('GET', '/api/comment-deliveries');
        wireSame(200, $listing['status']);
        wireSame(0, $listing['json']['total']);
        wireSame(0, $listing['json']['stream_total']);
        $record('GET', '/api/comment-deliveries', $listing);
        $disabled = $server->request('POST', '/api/comments/sync', '{}');
        wireSame(503, $disabled['status']);
        $record('POST', '/api/comments/sync', $disabled);
    });
    $run('Reverse note queries expose no providers and local delivery stays disabled over HTTP', static function () use ($server, $record): void {
        $listing = $server->request('GET', '/api/note-deliveries');
        wireSame(200, $listing['status']);
        wireSame(0, $listing['json']['total']);
        wireSame(0, $listing['json']['stream_total']);
        $record('GET', '/api/note-deliveries', $listing);
        $repository = new Bridge\Infrastructure\Persistence\CommentRepository(
            new Bridge\Infrastructure\Persistence\SqliteConnection($server->home . '/local.sqlite'),
            Bridge\Comments\CommentDirection::ZendeskToGitHub
        );
        $repository->initialize('escalation-1', ['800'], time());
        $repository->prepare('escalation-1', new Bridge\Comments\ZendeskNote(
            '1000', 'Controlled new internal reply', '42', 'Escalation Engineer',
            '2026-10-30T10:00:00Z', 'https://example.zendesk.com/agent/tickets/501'
        ));
        $populated = $server->request('GET', '/api/note-deliveries');
        wireSame(200, $populated['status']);
        wireSame(1, $populated['json']['total']);
        wireSame(1, $populated['json']['streams'][0]['excluded_count']);
        wireSame('pending', $populated['json']['items'][0]['status']);
        $record('GET', '/api/note-deliveries', $populated);
        $disabled = $server->request('POST', '/api/notes/sync', '{}');
        wireSame(503, $disabled['status']);
        wireSame('service_not_configured', $disabled['json']['error']['code']);
        $record('POST', '/api/notes/sync', $disabled);
    });
    $run('The scrape endpoint emits Prometheus text with bounded labels and a final newline', static function () use ($server, $record): void {
        $response = $server->metricsCredential();
        wireSame(200, $response['status']);
        wireSame('text/plain; version=0.0.4; charset=utf-8', $response['headers']['content-type']);
        wireSame('no-store', $response['headers']['cache-control']);
        wireCheck(str_starts_with($response['body'], '# HELP ') && str_ends_with($response['body'], "\n"), 'Export raw newline-terminated text.');
        wireCheck(str_contains($response['body'], 'support_bridge_reconciliations_total{outcome="synced"} 1'), 'Persisted outcome counters must be visible to the scraper.');
        wireCheck(str_contains($response['body'], 'support_bridge_pairs{status="synced"} 1'), 'Current state gauges must be visible to the scraper.');
        foreach (['CSV export fails for large reports', 'escalation-1', 'github_id', 'zendesk_id', $server->home] as $private) {
            wireCheck(!str_contains($response['body'], $private), 'Metrics must not contain title snapshots, identifiers, or paths.');
        }
        wireCheck(str_contains($response['body'], 'support_bridge_linked_pair_status{github_issue="101",zendesk_ticket="501"} 2'), 'The scrape must include actual linked record IDs.');
        wireSame(null, $response['json']);
        $record('GET', '/metrics', $response);
    });
    $run('State-change review and explicit approval survive the real HTTP boundary', static function () use ($server, $record): void {
        $context = new Context(new Configuration('local', $server->home, 'local/demo', 'local'));
        [$github, $zendesk] = $context->providers();
        $github->edit('101', ['state' => 'closed']);
        $waiting = $server->request('POST', '/api/sync', '{}');
        wireSame(200, $waiting['status']);
        wireSame(1, $waiting['json']['awaiting_approval']);
        wireSame('open', $zendesk->fetch('501')->fields['state']);
        $record('POST', '/api/sync', $waiting);
        $inspection = $server->request('GET', '/api/pairs/escalation-1');
        wireSame('awaiting_approval', $inspection['json']['pair']['status']);
        wireSame(true, $inspection['json']['governance']['requires_approval']);
        wireSame(false, $inspection['json']['governance']['approved']);
        $record('GET', '/api/pairs/{id}', $inspection);
        $metrics = $server->request('GET', '/api/metrics');
        wireSame(1, $metrics['json']['pairs']['awaiting_approval']);
        $record('GET', '/api/metrics', $metrics);
        $scrape = $server->metricsCredential();
        wireSame(200, $scrape['status']);
        wireCheck(str_contains($scrape['body'], 'support_bridge_pairs{status="awaiting_approval"} 1'), 'Scrape must expose the persisted review queue.');
        $record('GET', '/metrics', $scrape);
        $hash = $inspection['json']['governance']['plan_hash'];
        $approvalBody = json_encode(['plan_hash' => $hash, 'reason' => 'Reviewed the synthetic state transition.'], JSON_THROW_ON_ERROR);
        wireSame(401, $server->metricsCredential('POST', '/api/pairs/escalation-1/approve', $approvalBody)['status']);
        $bypass = $server->request('POST', '/api/pairs/escalation-1/approve', json_encode([
            'plan_hash' => $hash, 'reason' => 'Reviewed', 'ai_approved' => 'true'], JSON_THROW_ON_ERROR));
        wireSame(422, $bypass['status']);
        $record('POST', '/api/pairs/{id}/approve', $bypass);
        $wrongHash = $server->request('POST', '/api/pairs/escalation-1/approve', json_encode([
            'plan_hash' => str_repeat('0', 64), 'reason' => 'Reviewed'], JSON_THROW_ON_ERROR));
        wireSame(409, $wrongHash['status']);
        $record('POST', '/api/pairs/{id}/approve', $wrongHash);
        $approved = $server->request('POST', '/api/pairs/escalation-1/approve', $approvalBody);
        wireSame(200, $approved['status']);
        wireSame('new', $approved['json']['status']);
        wireSame('open', $zendesk->fetch('501')->fields['state'], 'Approval must not execute a provider write.');
        $record('POST', '/api/pairs/{id}/approve', $approved);
        $attestation = $server->request('GET', '/api/pairs/escalation-1');
        wireSame(true, $attestation['json']['governance']['approved']);
        wireSame('shared-operator', $attestation['json']['pair']['approval']['principal']);
        $record('GET', '/api/pairs/{id}', $attestation);
        $completed = $server->request('POST', '/api/sync', '{}');
        wireSame(1, $completed['json']['synced']);
        wireSame('closed', $zendesk->fetch('501')->fields['state']);
        $record('POST', '/api/sync', $completed);
    });
    $run('Wire pair creation returns 201 and duplicate links return 409', static function () use ($server, $record): void {
        $body = json_encode(['id' => 'wire-second', 'github_id' => '102', 'zendesk_id' => '502'], JSON_THROW_ON_ERROR);
        $response = $server->request('POST', '/api/pairs', $body);
        wireSame(201, $response['status']);
        wireSame('/api/pairs/wire-second', $response['headers']['location']);
        $record('POST', '/api/pairs', $response);
        $duplicate = $server->request('POST', '/api/pairs', $body);
        wireSame(409, $duplicate['status']);
        wireSame('state_conflict', $duplicate['json']['error']['code']);
        wireCheck(!str_contains($duplicate['body'], 'SQLSTATE'), 'Duplicate responses must not expose SQL errors.');
        $record('POST', '/api/pairs', $duplicate);
    });
    $run('Query-string pagination and invalid query arrays are validated on the wire', static function () use ($server, $record): void {
        $response = $server->request('GET', '/api/pairs?limit=1&offset=1');
        wireSame(200, $response['status']);
        wireSame(2, $response['json']['total']);
        wireSame(['wire-second'], array_column($response['json']['items'], 'id'));
        wireCheck(!str_contains($response['body'], 'CSV export'), 'List summaries must omit private title snapshots.');
        $record('GET', '/api/pairs', $response);
        $invalid = $server->request('GET', '/api/pairs?limit%5B%5D=1');
        wireSame(422, $invalid['status']);
        $record('GET', '/api/pairs', $invalid);
    });
    $run('HTTP parsing enforces JSON syntax, media type, exact fields, and 16 KiB limit', static function () use ($server, $record): void {
        foreach ([['{', [], 400], ['{}', ['Content-Type' => 'text/plain'], 415],
            ['{"unexpected":"field"}', [], 422], ['{"large":"' . str_repeat('x', 16_384) . '"}', [], 413]] as [$body, $headers, $status]) {
            $response = $server->request('POST', '/api/sync', $body, true, $headers);
            wireSame($status, $response['status']);
            wireCheck(isset($response['json']['error']['requestId']), 'Wire validation errors must use the correlated JSON envelope.');
            $record('POST', '/api/sync', $response);
        }
    });
    $run('Repository source, environment files, and state are never served as static assets', static function () use ($server): void {
        foreach (['/.env', '/README.md', '/src/Engine.php', '/var/local/local.sqlite', '/../README.md', '/%2e%2e/README.md'] as $path) {
            $response = $server->request('GET', $path, null, false);
            wireCheck(in_array($response['status'], [400, 404], true), 'Private path was served: ' . $path);
            wireCheck(!str_contains($response['body'], '<?php') && !str_contains($response['body'], 'SQLite format'), 'Private source/state bytes must never reach HTTP responses.');
        }
    });

    $run('Monitoring context stays read-only behind its separate credential on the wire', static function () use ($server, $record): void {
        foreach ([$server->request('GET', '/monitoring/pairs', null, false),
            $server->request('GET', '/monitoring/pairs')] as $denied) {
            wireSame(401, $denied['status']); $record('GET', '/monitoring/pairs', $denied);
        }
        $response = $server->metricsCredential('GET', '/monitoring/pairs');
        wireSame(200, $response['status']);
        wireSame($server->request('GET', '/api/pairs')['json']['total'], count($response['json']['items']));
        $linked = array_values(array_filter($response['json']['items'], static fn(array $row): bool =>
            $row['github_issue'] === '101'));
        wireSame(1, count($linked)); wireSame(null, $linked[0]['zendesk_url']);
        wireSame('no-store', $response['headers']['cache-control'] ?? null);
        $record('GET', '/monitoring/pairs', $response);
        $invalid = $server->metricsCredential('GET', '/monitoring/pairs?subject=override');
        wireSame(422, $invalid['status']); $record('GET', '/monitoring/pairs', $invalid);
        $invalid = $server->metricsCredential('POST', '/monitoring/pairs', '{}');
        wireSame(405, $invalid['status']);
    });
    $run('Corrupt instrumentation storage returns a failed scrape without breaking inspection', static function () use ($server, $record): void {
        file_put_contents($server->home . '/metrics.sqlite', 'not a SQLite database');
        $response = $server->metricsCredential();
        wireSame(503, $response['status']);
        wireSame('metrics_unavailable', $response['json']['error']['code']);
        wireCheck(!str_contains($response['body'], 'SQLSTATE') && !str_contains($response['body'], $server->home), 'Scrape failures must not expose storage details.');
        $record('GET', '/metrics', $response);
        wireSame(200, $server->request('GET', '/api/pairs/escalation-1')['status']);
    });
    if ($failed === 0) {
        $export = $root . '/var/api-contract-fixtures.json';
        $json = json_encode(['generatedBy' => 'tests/api-server.php', 'fixtures' => $fixtures],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        wireCheck(file_put_contents($export, $json . PHP_EOL) === strlen($json) + 1, 'Cannot export synthetic API contract fixtures.');
    }
} catch (Throwable $error) {
    ++$failed;
    fwrite(STDERR, 'FAIL Wire server setup/export: ' . $error->getMessage() . PHP_EOL);
} finally {
    $server->stop();
    $server->cleanup();
}
fwrite(STDOUT, $passed . ' wire HTTP checks passed; ' . $failed . ' failed.' . PHP_EOL);
exit($failed === 0 ? 0 : 1);
