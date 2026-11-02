<?php

/** Effectful browser-contract check only for the disposable local-provider lifecycle project. */

declare(strict_types=1);

if (getenv('BRIDGE_MODE') !== 'local') {
    throw new RuntimeException('Browser contract effects require isolated local providers.');
}
$origin = getenv('BRIDGE_TEST_REVIEW_ORIGIN') ?: '';
if (preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $origin) !== 1) {
    throw new RuntimeException('Select the disposable review origin.');
}
$host = substr($origin, strlen('http://'));
$operator = trim(file_get_contents('/run/secrets/operator/token'));
$password = trim(file_get_contents('/run/secrets/grafana/password'));

$request = static function (
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null
): array {
    $responseHeaders = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException('Disposable browser HTTP request failed.');
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$api = static function (string $id = 'escalation-1') use ($request, $operator): array {
    $result = $request('http://api:8080/api/pairs/' . rawurlencode($id), headers: [
        'Authorization: Bearer ' . $operator,
    ]);
    if ($result['status'] !== 200) {
        throw new RuntimeException('Disposable inspection failed.');
    }
    return json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
};
$before = $api();
$assert(
    $before['pair']['status'] === 'awaiting_approval'
        && $before['pair']['github_id'] === '101' && $before['pair']['zendesk_id'] === '501'
        && $before['pair']['approval'] === null,
    'Expected exactly the retained unapproved synthetic lifecycle plan.'
);
$login = $request('http://grafana:3000/login', 'POST', ['Content-Type: application/json'], json_encode([
    'user' => 'admin', 'password' => $password,
], JSON_THROW_ON_ERROR));
$assert($login['status'] === 200, 'Disposable Grafana login failed.');
$cookie = '';
foreach ($login['headers']['set-cookie'] ?? [] as $line) {
    if (preg_match('/^([A-Za-z0-9_-]+_grafana_session)=([A-Za-z0-9_-]{16,256});/', $line, $match)) {
        $cookie = $match[1] . '=' . $match[2];
    }
}
$assert($cookie !== '', 'Disposable project session cookie was not returned.');
$headers = ['Host: ' . $host, 'Cookie: ' . $cookie];
$unauthenticated = $request('http://api:8080/review', headers: ['Host: ' . $host]);
$assert($unauthenticated['status'] === 401, 'Anonymous browser inspection must be denied.');
$review = $request('http://api:8080/review/pairs/escalation-1', headers: $headers);
$assert($review['status'] === 200, 'Real Grafana session did not authorize the selected local reviewer.');
$assert(!str_contains($review['body'], $operator), 'Operator secret reached HTML.');
$assert(
    ($review['headers']['referrer-policy'][0] ?? '') === 'same-origin',
    'Native browser forms must retain their same-origin Origin header.'
);

$assert(
    str_contains($review['headers']['content-security-policy'][0] ?? '', "form-action 'self'"),
    'Rendered review form lost its same-origin policy.'
);
preg_match('/name="plan_hash" value="([^"]+)"/', $review['body'], $hash);
preg_match('/name="csrf" value="([^"]+)"/', $review['body'], $proof);
$assert(isset($hash[1], $proof[1]), 'Exact plan form is missing.');
foreach ($review['headers']['set-cookie'] ?? [] as $line) {
    if (str_starts_with($line, explode('=', $cookie, 2)[0] . '=')) {
        $cookie = explode(';', $line, 2)[0];
    }
}
$headers = ['Host: ' . $host, 'Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'];
$form = ['plan_hash' => $hash[1], 'csrf' => $proof[1],
    'reason' => 'Isolated local-provider browser contract decision.', 'confirm' => 'yes'];
$path = 'http://api:8080/review/pairs/escalation-1/approve';
$denied = $request($path, 'POST', [...$headers, 'Origin: http://evil.invalid'], http_build_query($form));
$assert($denied['status'] === 403 && $api()['pair']['approval'] === null, 'Cross-origin form authorized work.');
$approved = $request($path, 'POST', [...$headers, 'Origin: ' . $origin], http_build_query($form));
$assert($approved['status'] === 303, 'Explicit disposable browser decision was not accepted.');
$after = $api();
$assert(
    $after['pair']['status'] === 'new' && $after['governance']['approved'] === true
        && $after['pair']['plan'] === $before['pair']['plan'],
    'Browser decision changed intent or failed to preserve the existing exact-plan gate.'
);
$assert(
    $after['pair']['plan']['before']['github']['state'] === 'open',
    'The browser test must not reconcile or change the destination.'
);
$duplicate = $request($path, 'POST', [...$headers, 'Origin: ' . $origin], http_build_query($form));
$assert($duplicate['status'] === 409, 'Repeated decision did not stop at the existing engine gate.');
$conflictBefore = $api('browser-conflict');
$assert($conflictBefore['pair']['status'] === 'conflict', 'Disposable conflict was not prepared.');
$conflictPage = $request('http://api:8080/review/pairs/browser-conflict', headers: $headers);
$assert($conflictPage['status'] === 200, 'Real Grafana session could not inspect the conflict.');
$assert(
    str_contains($conflictPage['body'], 'Use Zendesk') && str_contains($conflictPage['body'], 'Use GitHub'),
    'Both source buttons must be rendered for the real session.'
);
foreach ($conflictPage['headers']['set-cookie'] ?? [] as $line) {
    if (str_starts_with($line, explode('=', $cookie, 2)[0] . '=')) {
        $cookie = explode(';', $line, 2)[0];
    }
}
$headers = ['Host: ' . $host, 'Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'];
preg_match_all('~<form.*?</form>~s', $conflictPage['body'], $forms);
foreach ($forms[0] as $formHtml) {
    if (!str_contains($formHtml, 'name="source" value="zendesk"')) {
        continue;
    }
    preg_match('/name="conflict_hash" value="([^"]+)"/', $formHtml, $hash);
    preg_match('/name="csrf" value="([^"]+)"/', $formHtml, $proof);
}
$assert(isset($hash[1], $proof[1]), 'Conflict source form is missing.');
$choice = ['conflict_hash' => $hash[1], 'csrf' => $proof[1], 'source' => 'zendesk', 'confirm' => 'yes'];
$path = 'http://api:8080/review/pairs/browser-conflict/resolve';
$denied = $request($path, 'POST', [...$headers, 'Origin: null'], http_build_query($choice));
$assert($denied['status'] === 403, 'Opaque origin selected a source.');
$selected = $request($path, 'POST', [...$headers, 'Origin: ' . $origin], http_build_query($choice));
$assert($selected['status'] === 303, 'Real-session conflict source choice failed.');
$conflictAfter = $api('browser-conflict');
$assert(
    $conflictAfter['pair']['status'] === 'new' && $conflictAfter['pair']['approval'] === null
        && $conflictAfter['pair']['plan']['target'] === $conflictBefore['pair']['conflict']['observed']['zendesk'],
    'Source choice failed to queue the complete source independently of state approval.'
);
$assert(
    $conflictAfter['pair']['baseline'] === $conflictBefore['pair']['baseline'],
    'Source selection must not advance the baseline.'
);
$duplicate = $request($path, 'POST', [...$headers, 'Origin: ' . $origin], http_build_query($choice));
$assert($duplicate['status'] === 409, 'Duplicate source choice was not rejected.');
$stillSignedIn = $request('http://grafana:3000/api/user', headers: ['Cookie: ' . $cookie]);
$assert($stillSignedIn['status'] === 200, 'Review invalidated the Grafana session.');
echo "PASS real Grafana session, source buttons, separate approval, origin denial and duplicate rejection.\n";
