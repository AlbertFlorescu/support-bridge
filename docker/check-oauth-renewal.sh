#!/bin/sh
# Disposable credential lifecycle: fake tokens and an internal-only network.
set -eu
cd "$(dirname "$0")/.."
docker=$(printenv DOCKER || printf docker)
project="support-bridge-oauth-check-$$"
export BRIDGE_GITHUB_REPOSITORY=example/renewal-check
export BRIDGE_ZENDESK_SUBDOMAIN=example
export BRIDGE_ZENDESK_AUTH=oauth
export BRIDGE_ZENDESK_EMAIL=
export BRIDGE_ZENDESK_TOKEN_FILE=
export BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE=/run/secrets/vendor/zendesk-oauth.json
export COMPOSE_PROFILES=oauth-renewal
forward=$(printenv WSLENV || true)
export WSLENV="$forward:BRIDGE_GITHUB_REPOSITORY:BRIDGE_ZENDESK_SUBDOMAIN:BRIDGE_ZENDESK_AUTH:BRIDGE_ZENDESK_EMAIL:BRIDGE_ZENDESK_TOKEN_FILE:BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE:COMPOSE_PROFILES"

compose() { "$docker" compose -p "$project" -f compose.yaml -f compose.live-demo.yaml -f docker/compose.live-check.yaml -f docker/compose.oauth-check.yaml "$@"; }
if [ -n "$(compose ps -a -q)" ] || [ -n "$("$docker" volume ls --filter "label=com.docker.compose.project=$project" --format '{{.Name}}')" ]; then
    echo 'Refusing to reuse an existing OAuth-check project.' >&2
    exit 1
fi
cleanup() { compose down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
trap 'exit 130' INT TERM

compose build oauth-credentials
config_probe=$(cat <<'PHP'
$config = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$services = $config['services'];
$importer = $services['oauth-credentials'];
$renewer = $services['zendesk-token-renewer'];
if ($importer['network_mode'] !== 'none' || $importer['user'] !== '10001:10001' || !$importer['read_only'] || count($importer['volumes']) !== 1 || $importer['volumes'][0]['source'] !== 'issuer-secret' || ($importer['volumes'][0]['read_only'] ?? false)) { throw new RuntimeException('OAuth importer isolation changed.'); }
if ($renewer['user'] !== '10001:10001' || !$renewer['read_only'] || !in_array('oauth-renewal', $renewer['profiles'], true)) { throw new RuntimeException('Renewer runtime isolation changed.'); }
$mounts = [];
foreach ($renewer['volumes'] as $mount) { $mounts[$mount['source']] = $mount; }
if (count($mounts) !== 2 || !isset($mounts['issuer-secret'], $mounts['vendor-secret']) || !$mounts['issuer-secret']['read_only'] || ($mounts['vendor-secret']['read_only'] ?? false)) { throw new RuntimeException('Renewer credential readers changed.'); }
foreach ($services as $name => $service) {
    foreach ($service['volumes'] ?? [] as $mount) {
        if ($mount['source'] === 'issuer-secret' && !in_array($name, ['oauth-credentials','zendesk-token-renewer'], true)) { throw new RuntimeException('Client credentials entered another service.'); }
    }
}
foreach (['api','cli','worker'] as $name) {
    if ($services[$name]['environment']['BRIDGE_ZENDESK_TOKEN_FILE'] !== '' || $services[$name]['environment']['BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE'] !== '/run/secrets/vendor/zendesk-oauth.json') { throw new RuntimeException('Managed credential selection changed.'); }
}
if (($config['networks']['stack']['internal'] ?? false) !== true) { throw new RuntimeException('OAuth check can reach external networks.'); }
echo "PASS isolated issuer importer, renewer mounts and managed credential configuration.\n";
PHP
)
compose --profile oauth-renewal --profile tools --profile live-worker --profile test config --format json |
    "$docker" run --rm -i --network none --read-only --user 10001:10001 --entrypoint php support-bridge:local -r "$config_probe"

payload='{"github-token":"synthetic-github-access-token","zendesk-token":"synthetic-legacy-zendesk-access-token"}'
printf '%s' "$payload" | compose run --rm --no-deps -T credentials
client='{"subdomain":"example","client_id":"synthetic-client","client_secret":"synthetic-client-secret"}'
printf '%s' "$client" | compose run --rm --no-deps -T oauth-credentials
compose run --rm --no-deps -T init
test "$(compose run --rm --no-deps -T cli status)" = '[]'

renewal_probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
use Bridge\Infrastructure\OAuth\ZendeskTokenIssuer;
use Bridge\Infrastructure\OAuth\ZendeskTokenRenewal;
use Bridge\Provider\ManagedZendeskAuthentication;
use Bridge\Provider\Zendesk;
use Bridge\HttpClient;
use Bridge\HttpResponse;

$directory = '/var/lib/support-bridge';
$original = [];
foreach (['github-token','zendesk-token'] as $name) { $original[$name] = hash_file('sha256', $directory . '/' . $name); }
$calls = 0;
$fail = false;
$issuer = new ZendeskTokenIssuer(static function (string $url, array $payload) use (&$calls, &$fail): array {
    ++$calls;
    if ($url !== 'https://example.zendesk.com/oauth/tokens' || $payload['scope'] !== 'tickets:read tickets:write' || $payload['expires_in'] !== 14400) { throw new RuntimeException('Unexpected OAuth request.'); }
    if ($fail) { throw new RuntimeException('synthetic-private-upstream-failure'); }
    return ['access_token'=>'synthetic-issued-token-' . $calls,'token_type'=>'bearer','scope'=>'tickets:read tickets:write','expires_in'=>14400];
});
$renewal = new ZendeskTokenRenewal('example', '/run/secrets/oauth/client.json', $directory, $issuer);
$result = $renewal->tick();
if ($result['status'] !== 'renewed' || $calls !== 1 || !$renewal->healthy()) { throw new RuntimeException('Initial credential renewal failed.'); }
$renewal->tick();
if ($calls !== 1) { throw new RuntimeException('A usable token was unnecessarily issued again.'); }
$headers = [];
$http = new HttpClient(static function ($method, $url, $requestHeaders, $json) use (&$headers): HttpResponse {
    $headers[] = $requestHeaders['Authorization'];
    return new HttpResponse(200, [], ['ticket'=>['subject'=>'Synthetic escalation','status'=>'open','updated_at'=>'2026-01-01T00:00:00Z']]);
});
$provider = new Zendesk($http, 'example', '', new ManagedZendeskAuthentication($directory . '/zendesk-oauth.json', 'example'), 'oauth');
$provider->fetch('42');
$makeDue = static function () use ($directory): void {
    $path = $directory . '/zendesk-oauth.json';
    $value = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    $value['issued_at'] = time() - 14100;
    $value['expires_at'] = $value['issued_at'] + 14400;
    $value['renew_at'] = $value['expires_at'] - 300;
    $temporary = $directory . '/.synthetic-due-bundle';
    file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR));
    chmod($temporary, 0400);
    rename($temporary, $path);
};
$makeDue();
$renewal->tick();
$provider->fetch('42');
if ($headers !== ['Bearer synthetic-issued-token-1','Bearer synthetic-issued-token-2']) { throw new RuntimeException('The same provider did not observe rotated authorization.'); }
$makeDue();
$before = hash_file('sha256', $directory . '/zendesk-oauth.json');
$fail = true;
try {
    $renewal->tick();
    throw new LogicException('Expected a token-issuance failure.');
} catch (RuntimeException $error) {
    if (str_contains($error->getMessage(), 'synthetic-private')) { throw new RuntimeException('OAuth failure details escaped.'); }
}
$renewal->recordFailure();
if ($before !== hash_file('sha256', $directory . '/zendesk-oauth.json') || !$renewal->healthy()) { throw new RuntimeException('Failure discarded the last usable token.'); }
$fail = false;
$renewal->tick();
foreach ($original as $name => $hash) {
    if (!hash_equals($hash, hash_file('sha256', $directory . '/' . $name))) { throw new RuntimeException('Renewal changed an unrelated access credential.'); }
}
echo "PASS scheduled renewal, per-request hot rotation and failure preservation with injected transport.\n";
PHP
)
printf '%s' "$renewal_probe" | compose run --rm --no-deps -T --entrypoint php zendesk-token-renewer
compose run --rm --no-deps -T --entrypoint php zendesk-token-renewer /app/docker/healthcheck-oauth.php

reader_probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
(new Bridge\Runtime\SecretLoader())->load();
if (getenv('BRIDGE_ZENDESK_TOKEN') !== false && getenv('BRIDGE_ZENDESK_TOKEN') !== '') { throw new RuntimeException('Managed token was cached in the environment.'); }
if (file_exists('/run/secrets/oauth/client.json')) { throw new RuntimeException('Client credentials entered an application reader.'); }
$authorization = new Bridge\Provider\ManagedZendeskAuthentication('/run/secrets/vendor/zendesk-oauth.json', 'example');
if (!str_starts_with($authorization->authorizationHeader(), 'Bearer synthetic-issued-token-')) { throw new RuntimeException('Managed credential reader failed.'); }
if (@file_put_contents('/run/secrets/vendor/.write-probe', 'must-not-write') !== false) { throw new RuntimeException('Managed credential reader is writable.'); }
$context = new Bridge\Application\Context(Bridge\Application\Configuration::fromEnvironment());
if ($context->store->all() !== []) { throw new RuntimeException('Credential renewal created business mappings.'); }
echo "PASS read-only hot credential reader without issuer secrets or business effects.\n";
PHP
)
printf '%s' "$reader_probe" | compose run --rm --no-deps -T --entrypoint php cli

compose up -d --no-deps --no-build --wait --wait-timeout 60 zendesk-token-renewer
id=$(compose ps -a -q zendesk-token-renewer)
before=$(compose exec -T zendesk-token-renewer php -r 'echo hash_file("sha256","/var/lib/support-bridge/zendesk-oauth.json");')
"$docker" restart "$id" >/dev/null
compose up -d --no-deps --no-build --wait --wait-timeout 60 zendesk-token-renewer
after=$(compose exec -T zendesk-token-renewer php -r 'echo hash_file("sha256","/var/lib/support-bridge/zendesk-oauth.json");')
test "$before" = "$after"
test "$(compose run --rm --no-deps -T cli status)" = '[]'
echo 'PASS running renewal process, health and bundle persistence across restart without vendor traffic'
