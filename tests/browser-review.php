<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bridge\Application\Configuration;
use Bridge\Application\Context;
use Bridge\Http\Request;
use Bridge\Http\Response;
use Bridge\Http\Review\Configuration as ReviewConfiguration;
use Bridge\Http\Review\Controller;
use Bridge\Http\Review\FormProtection;
use Bridge\Http\Review\GrafanaSession;
use Bridge\Provider\Local;

final class BrowserReviewFixture
{
    public const TOKEN = 'browser-test-operator-0123456789abcdef';
    public const COOKIE = 'abcdef0123456789abcdef0123456789abcdef0123';
    public readonly string $home;
    public readonly Context $context;
    public readonly FormProtection $protection;
    public int $contexts = 0;
    public int $now = 1_800_000_000;
    public int $authStatus = 200;
    public array $user = ['login' => 'reviewer', 'isGrafanaAdmin' => true];
    public array $cookies = [];
    public ?string $authBody = null;

    public function __construct(bool $pendingPlan = true)
    {
        $this->home = sys_get_temp_dir() . '/bridge-browser-' . bin2hex(random_bytes(8));
        $this->context = new Context(new Configuration('local', $this->home, 'local/demo', 'local'));
        $this->context->initialize();
        $this->context->engine()->run();
        if ($pendingPlan) {
            $this->github()->edit('101', ['state' => 'closed']);
            $this->context->engine()->run();
        }
        $this->protection = new FormProtection(self::TOKEN, fn(): int => $this->now);
    }

    public function github(): Local { return new Local($this->home . '/github.json'); }
    public function zendesk(): Local { return new Local($this->home . '/zendesk.json'); }

    public function controller(?ReviewConfiguration $configuration = null): Controller
    {
        return new Controller(function (): Context {
            ++$this->contexts;
            return $this->context;
        }, self::TOKEN, 'different-metrics-token-0123456789abcdef',
            $configuration ?? new ReviewConfiguration(true, ['reviewer'], 'demo_grafana_session'),
            new GrafanaSession(fn(): array => [
                'status' => $this->authStatus,
                'body' => $this->authBody ?? json_encode($this->user, JSON_THROW_ON_ERROR),
                'cookies' => $this->cookies,
            ]), $this->protection);
    }

    public function request(string $method = 'GET', string $path = '/review/pairs/escalation-1',
        ?array $form = null, array $headers = [], ?Controller $controller = null): Response
    {
        return ($controller ?? $this->controller())->handle(new Request($method, $path, array_replace([
            'Host' => '127.0.0.1:1988',
            'Cookie' => 'demo_grafana_session=' . self::COOKIE,
            'Origin' => 'http://127.0.0.1:1988',
            'Sec-Fetch-Site' => 'same-origin',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $headers), $form === null ? '' : http_build_query($form)));
    }

    public function form(): array
    {
        $response = $this->request();
        browserSame(200, $response->status);
        preg_match('/name="plan_hash" value="([^"]+)"/', $response->body, $hash);
        preg_match('/name="csrf" value="([^"]+)"/', $response->body, $csrf);
        return ['plan_hash' => $hash[1], 'csrf' => $csrf[1], 'reason' => 'Reviewed this synthetic resolution.', 'confirm' => 'yes'];
    }

    public function cleanup(): void
    {
        foreach (['local.sqlite', 'local.sqlite-wal', 'local.sqlite-shm', 'local.sqlite.lock',
            'metrics.sqlite', 'metrics.sqlite-wal', 'metrics.sqlite-shm', 'github.json', 'zendesk.json'] as $name) {
            if (is_file($this->home . '/' . $name)) { unlink($this->home . '/' . $name); }
        }
        @rmdir($this->home);
    }
}

function browserSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
$fixtures = [];
function browserFixture(bool $pendingPlan = true): BrowserReviewFixture
{
    global $fixtures;
    return $fixtures[] = new BrowserReviewFixture($pendingPlan);
}
register_shutdown_function(static function () use (&$fixtures): void {
    foreach ($fixtures as $fixture) { $fixture->cleanup(); }
});

function browserConflict(bool $stateChange = false): BrowserReviewFixture
{
    $f = browserFixture(false);
    $f->github()->edit('101', ['title' => 'Engineering diagnosis', 'state' => $stateChange ? 'closed' : 'open']);
    $f->zendesk()->edit('501', ['title' => 'Support diagnosis']);
    $f->context->engine()->run();
    browserSame('conflict', $f->context->store->get('escalation-1')['status']);
    return $f;
}
function browserConflictForm(BrowserReviewFixture $f, string $source): array
{
    $response = $f->request();
    browserSame(200, $response->status);
    preg_match_all('~<form.*?</form>~s', $response->body, $forms);
    foreach ($forms[0] as $form) {
        if (!str_contains($form, 'name="source" value="' . $source . '"')) { continue; }
        preg_match('/name="conflict_hash" value="([^"]+)"/', $form, $hash);
        preg_match('/name="csrf" value="([^"]+)"/', $form, $csrf);
        return ['conflict_hash' => $hash[1], 'source' => $source, 'csrf' => $csrf[1], 'confirm' => 'yes'];
    }
    throw new RuntimeException('Conflict source form missing.');
}

$tests = [];
$tests['Browser review defaults off and missing authentication never constructs business context'] = static function (): void {
    $f = browserFixture();
    browserSame(503, $f->request(controller: $f->controller(new ReviewConfiguration()))->status);
    browserSame(401, $f->request(headers: ['Cookie' => '', 'Authorization' => 'Bearer ' . BrowserReviewFixture::TOKEN])->status);
    browserSame(401, $f->request(headers: ['Cookie' => 'demo_grafana_session=malformed'])->status);
    browserSame(401, $f->request(headers: ['Cookie' => 'demo_grafana_session=' . BrowserReviewFixture::COOKIE . '; demo_grafana_session=' . BrowserReviewFixture::COOKIE])->status);
    browserSame(0, $f->contexts);
};
$tests['Only explicitly enabled Grafana administrators can review'] = static function (): void {
    $f = browserFixture();
    $f->user['isGrafanaAdmin'] = false;
    browserSame(403, $f->request()->status);
    $f->user = ['login' => 'other-admin', 'isGrafanaAdmin' => true];
    browserSame(403, $f->request()->status);
    $f->user = ['login' => 'reviewer', 'isGrafanaAdmin' => true, 'isDisabled' => true];
    browserSame(403, $f->request()->status);
    browserSame(0, $f->contexts);
};
$tests['Expired sessions and invalid upstream evidence fail closed'] = static function (): void {
    $f = browserFixture();
    $f->authStatus = 401;
    browserSame(401, $f->request()->status);
    $f->authStatus = 302;
    browserSame(503, $f->request()->status);
    $f->authStatus = 200; $f->authBody = '<html>login</html>';
    browserSame(503, $f->request()->status);
    $f->authBody = 'null';
    browserSame(403, $f->request()->status);
    browserSame(0, $f->contexts);
};
$tests['Rendered review reads do not approve or write providers and escape source markup'] = static function (): void {
    $f = browserFixture();
    $pair = $f->context->store->get('escalation-1');
    $pair['plan']['target']['title'] = '<script>alert("source")</script>';
    $f->context->store->update('escalation-1', ['plan' => $pair['plan']]);
    $before = $f->context->store->get('escalation-1');
    $response = $f->request();
    browserSame(200, $response->status);
    browserSame(true, str_contains($response->body, '&lt;script&gt;'));
    browserSame(false, str_contains($response->body, '<script>'));
    browserSame(false, str_contains($response->body, BrowserReviewFixture::TOKEN));
    browserSame(true, str_contains($response->headers['Content-Security-Policy'], "form-action 'self'"));
    browserSame($before, $f->context->store->get('escalation-1'));
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
    browserSame(200, $f->request(path: '/review')->status);
};
$tests['Numeric dashboard links resolve only exact stored mappings'] = static function (): void {
    $f = browserFixture();
    $r = $f->request(path: '/review/links/101/501');
    browserSame(303, $r->status);
    browserSame('/review/pairs/escalation-1', $r->headers['Location']);
    browserSame(404, $f->request(path: '/review/links/101/502')->status);
    browserSame(405, $f->request(path: '/review/pairs/escalation-1/approve')->status);
};
$tests['Cross-origin submissions and host rebinding cannot authorize a plan'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    foreach (['Origin' => 'http://evil.invalid', 'Sec-Fetch-Site' => 'cross-site', 'Host' => 'evil.invalid:1988'] as $header => $value) {
        browserSame(403, $f->request('POST', '/review/pairs/escalation-1/approve', $form, [$header => $value])->status);
    }
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
};
$tests['Form proof binds pair, session, exact hash and expiry'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    $changed = $form; $changed['plan_hash'] = str_repeat('0', 64);
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/approve', $changed)->status);
    browserSame(403, $f->request('POST', '/review/pairs/other/approve', $form)->status);
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/approve', $form,
        ['Cookie' => 'demo_grafana_session=' . str_repeat('a', 40)])->status);
    $f->now += 601;
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/approve', $form)->status);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
};
$tests['Confirmation, exact form fields and rationale remain mandatory'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    foreach ([array_replace($form, ['confirm' => 'no']), array_replace($form, ['reason' => '']),
        array_replace($form, ['reason' => str_repeat('x', 501)]), $form + ['override' => 'true']] as $bad) {
        browserSame(422, $f->request('POST', '/review/pairs/escalation-1/approve', $bad)->status);
    }
    browserSame(415, $f->request('POST', '/review/pairs/escalation-1/approve', $form,
        ['Content-Type' => 'application/json'])->status);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
};
$tests['Explicit browser approval delegates to the existing command and a later sweep applies it'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    $r = $f->request('POST', '/review/pairs/escalation-1/approve', $form);
    browserSame(303, $r->status);
    browserSame('/review/pairs/escalation-1', $r->headers['Location']);
    browserSame('new', $f->context->store->get('escalation-1')['status']);
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
    browserSame(true, str_contains($f->request()->body, 'Approval recorded'));
    browserSame(409, $f->request('POST', '/review/pairs/escalation-1/approve', $form)->status);
    $f->context->engine()->run();
    browserSame('synced', $f->context->store->get('escalation-1')['status']);
    browserSame('closed', $f->zendesk()->fetch('501')->fields['state']);
    browserSame(false, str_contains($f->request()->body, '<form'));
};
$tests['A source edit after the page was rendered invalidates browser approval'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    $f->zendesk()->edit('501', ['title' => 'New support evidence']);
    browserSame(409, $f->request('POST', '/review/pairs/escalation-1/approve', $form)->status);
    browserSame('conflict', $f->context->store->get('escalation-1')['status']);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
};
$tests['Grafana session rotation reaches the browser and the next submitted form remains valid'] = static function (): void {
    $f = browserFixture(); $next = str_repeat('b', 40);
    $f->cookies = ['demo_grafana_session=' . $next . '; Path=/; HttpOnly', 'unrelated=ignored'];
    $r = $f->request();
    browserSame(true, str_contains($r->headers['Set-Cookie'], $next . '; Path=/; HttpOnly; SameSite=Lax'));
    $form = $f->form(); $f->cookies = [];
    browserSame(303, $f->request('POST', '/review/pairs/escalation-1/approve', $form,
        ['Cookie' => 'demo_grafana_session=' . $next])->status);
};
$tests['Malformed or duplicate form fields cannot silently replace reviewed input'] = static function (): void {
    $f = browserFixture(); $form = $f->form();
    $r = $f->controller()->handle(new Request('POST', '/review/pairs/escalation-1/approve', [
        'Host' => '127.0.0.1:1988', 'Cookie' => 'demo_grafana_session=' . BrowserReviewFixture::COOKIE,
        'Origin' => 'http://127.0.0.1:1988', 'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query($form) . '&confirm=yes'));
    browserSame(422, $r->status);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
};

$tests['Review source links use the configured provider identities rather than source text'] = static function (): void {
    $f = browserFixture();
    $view = new \Bridge\Http\Review\Page(
        new ReviewConfiguration(true, ['reviewer'], 'demo_grafana_session'),
        new Configuration('live', $f->home, 'example/escalations', 'example-support')
    );
    $inspection = $f->context->inspect('escalation-1');
    $html = $view->inspection($inspection,
        new \Bridge\Http\Review\Session('reviewer', BrowserReviewFixture::COOKIE, BrowserReviewFixture::COOKIE),
        $f->protection);
    browserSame(true, str_contains($html, 'https://github.com/example/escalations/issues/101'));
    browserSame(true, str_contains($html, 'https://example-support.zendesk.com/agent/tickets/501'));
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
};

$tests['Native form policy preserves same-origin POST identity while opaque origins remain denied'] = static function (): void {
    $f = browserFixture();
    browserSame('same-origin', $f->request()->headers['Referrer-Policy']);
    $form = $f->form();
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/approve', $form,
        ['Origin' => 'null'])->status);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
    browserSame(303, $f->request('POST', '/review/pairs/escalation-1/approve', $form)->status);
};

$tests['Conflict page compares saved evidence, escapes it and makes no decisions on GET'] = static function (): void {
    $f = browserConflict();
    $f->github()->edit('101', ['title' => '<script>unsafe</script>']);
    try { $f->context->engine()->resolve('escalation-1', 'github'); } catch (\Bridge\Exception\StateConflict) {}
    $before = $f->context->store->get('escalation-1');
    $r = $f->request();
    browserSame(200, $r->status);
    browserSame(true, str_contains($r->body, 'Use Zendesk'));
    browserSame(true, str_contains($r->body, 'Use GitHub'));
    browserSame(true, str_contains($r->body, 'Support diagnosis'));
    browserSame(true, str_contains($r->body, '&lt;script&gt;unsafe&lt;/script&gt;'));
    browserSame(false, str_contains($r->body, '<script>'));
    browserSame(false, str_contains($r->body, '/approve'));
    browserSame(false, str_contains($r->body, BrowserReviewFixture::TOKEN));
    browserSame($before, $f->context->store->get('escalation-1'));
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
    browserSame(405, $f->request(path: '/review/pairs/escalation-1/resolve')->status);
};
$tests['Either source button queues its whole record without approval or immediate vendor writes'] = static function (): void {
    foreach (['github', 'zendesk'] as $source) {
        $f = browserConflict();
        $before = $f->context->store->get('escalation-1');
        $form = browserConflictForm($f, $source);
        $r = $f->request('POST', '/review/pairs/escalation-1/resolve', $form);
        browserSame(303, $r->status);
        browserSame('/review/pairs/escalation-1', $r->headers['Location']);
        $after = $f->context->store->get('escalation-1');
        browserSame('new', $after['status']);
        browserSame($before['conflict']['observed'][$source], $after['plan']['target']);
        browserSame($before['baseline'], $after['baseline']);
        browserSame(null, $after['approval']);
        browserSame(null, $after['conflict']);
        browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
        browserSame(409, $f->request('POST', '/review/pairs/escalation-1/resolve', $form)->status);
        $f->context->engine()->run();
        browserSame('synced', $f->context->store->get('escalation-1')['status']);
        browserSame($after['plan']['target'], $f->github()->fetch('101')->fields);
        browserSame($after['plan']['target'], $f->zendesk()->fetch('501')->fields);
        browserSame(false, str_contains($f->request()->body, '/resolve'));
    }
};
$tests['A complete-source state change still needs a separate exact-plan browser approval'] = static function (): void {
    foreach (['github', 'zendesk'] as $source) {
        $f = browserConflict(true);
        $form = browserConflictForm($f, $source);
        browserSame(303, $f->request('POST', '/review/pairs/escalation-1/resolve', $form)->status);
        $f->context->engine()->run();
        browserSame('awaiting_approval', $f->context->store->get('escalation-1')['status']);
        browserSame(null, $f->context->store->get('escalation-1')['approval']);
        browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
        $approval = $f->form();
        browserSame(303, $f->request('POST', '/review/pairs/escalation-1/approve', $approval)->status);
        browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
        $f->context->engine()->run();
        browserSame('synced', $f->context->store->get('escalation-1')['status']);
    }
};
$tests['Conflict forms bind source, action, session, pair, snapshot and expiry'] = static function (): void {
    $f = browserConflict(); $form = browserConflictForm($f, 'github');
    foreach ([
        array_replace($form, ['source' => 'zendesk']),
        array_replace($form, ['conflict_hash' => str_repeat('0', 64)]),
        array_replace($form, ['csrf' => $f->protection->issue(BrowserReviewFixture::COOKIE, 'escalation-1', $form['conflict_hash'])]),
    ] as $changed) {
        browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $changed)->status);
    }
    browserSame(403, $f->request('POST', '/review/pairs/other/resolve', $form)->status);
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $form,
        ['Cookie' => 'demo_grafana_session=' . str_repeat('a', 40)])->status);
    $f->now += 601;
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $form)->status);
    browserSame('conflict', $f->context->store->get('escalation-1')['status']);
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
};
$tests['Conflict choices retain exact origin, authentication, confirmation and form boundaries'] = static function (): void {
    $f = browserConflict(); $form = browserConflictForm($f, 'zendesk');
    foreach (['null', '', 'http://evil.invalid'] as $origin) {
        browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $form, ['Origin' => $origin])->status);
    }
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $form, ['Sec-Fetch-Site' => 'cross-site'])->status);
    browserSame(403, $f->request('POST', '/review/pairs/escalation-1/resolve', $form, ['Host' => 'evil.invalid:1988'])->status);
    browserSame(401, $f->request('POST', '/review/pairs/escalation-1/resolve', $form, ['Cookie' => ''])->status);
    foreach ([array_replace($form, ['confirm' => 'no']), array_replace($form, ['source' => 'other']),
        $form + ['approve' => 'yes'], array_diff_key($form, ['confirm' => true])] as $bad) {
        browserSame(422, $f->request('POST', '/review/pairs/escalation-1/resolve', $bad)->status);
    }
    browserSame(415, $f->request('POST', '/review/pairs/escalation-1/resolve', $form, ['Content-Type' => 'application/json'])->status);
    browserSame('conflict', $f->context->store->get('escalation-1')['status']);
};
$tests['Vendor edits refresh conflicts and stale open tabs cannot choose refreshed evidence'] = static function (): void {
    $f = browserConflict(); $form = browserConflictForm($f, 'github');
    $f->zendesk()->edit('501', ['title' => 'Newer support diagnosis']);
    browserSame(409, $f->request('POST', '/review/pairs/escalation-1/resolve', $form)->status);
    browserSame('changed_since_conflict', $f->context->store->get('escalation-1')['conflict']['reason']);
    browserSame(409, $f->request('POST', '/review/pairs/escalation-1/resolve', $form)->status);
    browserSame(null, $f->context->store->get('escalation-1')['approval']);
    browserSame(0, $f->github()->writes() + $f->zendesk()->writes());
    $fresh = browserConflictForm($f, 'zendesk');
    browserSame(303, $f->request('POST', '/review/pairs/escalation-1/resolve', $fresh)->status);
    browserSame('Newer support diagnosis', $f->context->store->get('escalation-1')['plan']['target']['title']);
};
$tests['A rotated Grafana session carries conflict proof into the subsequent POST'] = static function (): void {
    $f = browserConflict(); $next = str_repeat('c', 40);
    $f->cookies = ['demo_grafana_session=' . $next . '; Path=/; HttpOnly'];
    $form = browserConflictForm($f, 'zendesk');
    $f->cookies = [];
    browserSame(303, $f->request('POST', '/review/pairs/escalation-1/resolve', $form,
        ['Cookie' => 'demo_grafana_session=' . $next])->status);
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $e) { ++$failed; fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL); }
}
echo count($tests) . ' browser review checks; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
