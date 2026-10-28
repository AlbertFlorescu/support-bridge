#!/bin/sh
# Disposable fake credentials only: no vendor requests, linked records or reconciliation.
set -eu
cd "$(dirname "$0")/.."
docker=${DOCKER:-docker}
project="support-bridge-live-check-$$"
export BRIDGE_GITHUB_REPOSITORY=example/credential-check
export BRIDGE_ZENDESK_SUBDOMAIN=example
export BRIDGE_ZENDESK_AUTH=oauth
export BRIDGE_ZENDESK_EMAIL=
# Windows Docker invoked through WSL needs explicit forwarding of these fake metadata values.
export WSLENV="${WSLENV:+$WSLENV:}BRIDGE_GITHUB_REPOSITORY:BRIDGE_ZENDESK_SUBDOMAIN:BRIDGE_ZENDESK_AUTH:BRIDGE_ZENDESK_EMAIL"

compose() { "$docker" compose -p "$project" -f compose.yaml -f compose.live-demo.yaml -f docker/compose.live-check.yaml "$@"; }
if [ -n "$(compose ps -a -q)" ] || [ -n "$("$docker" volume ls --filter "label=com.docker.compose.project=$project" --format '{{.Name}}')" ]; then
    echo 'Refusing to reuse an existing live-check project.' >&2
    exit 1
fi
cleanup() { compose down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
trap 'exit 130' INT TERM

compose build credentials
services=$(compose config --services)
if printf '%s\n' "$services" | grep -Eq '^(worker|credentials|cli|verify|smoke)$'; then
    echo 'A live helper or worker is enabled by default.' >&2
    exit 1
fi
config_probe=$(cat <<'PHP'
$config = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$services = $config['services'];
$importer = $services['credentials'];
if ($importer['network_mode'] !== 'none' || $importer['user'] !== '10001:10001' || $importer['read_only'] !== true || count($importer['volumes']) !== 1) { throw new RuntimeException('Importer isolation changed.'); }
$volume = $importer['volumes'][0];
if ($volume['source'] !== 'vendor-secret' || $volume['target'] !== '/var/lib/support-bridge' || ($volume['read_only'] ?? false)) { throw new RuntimeException('Importer volume ownership changed.'); }
foreach (['api','cli','worker'] as $name) {
    $mounts = array_values(array_filter($services[$name]['volumes'], static fn(array $mount): bool => $mount['source'] === 'vendor-secret'));
    if (count($mounts) !== 1 || $mounts[0]['target'] !== '/run/secrets/vendor' || $mounts[0]['read_only'] !== true) { throw new RuntimeException('A vendor reader mount changed.'); }
    foreach (['github','zendesk'] as $provider) {
        $variable = 'BRIDGE_' . strtoupper($provider) . '_TOKEN_FILE';
        if ($services[$name]['environment'][$variable] !== '/run/secrets/vendor/' . $provider . '-token') { throw new RuntimeException('A token-file path changed.'); }
    }
}
foreach (['init','smoke','prometheus','grafana','verify'] as $name) {
    foreach ($services[$name]['volumes'] ?? [] as $mount) {
        if ($mount['source'] === 'vendor-secret') { throw new RuntimeException('An unrelated service received vendor credentials.'); }
    }
}
if (!in_array('live-worker', $services['worker']['profiles'], true)) { throw new RuntimeException('The worker opt-in profile changed.'); }
echo "PASS live service profiles and credential-volume readers.\n";
PHP
)
compose --profile tools --profile live-worker --profile test config --format json |
    "$docker" run --rm -i --network none --read-only --user 10001:10001 --entrypoint php support-bridge:local -r "$config_probe"

code=0
invalid=$(printf '%s' '{"github-token":"synthetic-private-marker","zendesk-token":123}' | compose run --rm --no-deps -T credentials 2>&1) || code=$?
if [ "$code" != 1 ] || printf '%s' "$invalid" | grep -q synthetic-private-marker; then
    echo 'Malformed credential input was accepted or disclosed.' >&2
    exit 1
fi
payload='{"github-token":"synthetic-github-access-token","zendesk-token":"synthetic-zendesk-access-token"}'
result=$(printf '%s' "$payload" | compose run --rm --no-deps -T credentials)
case "$result" in *'"changed":2'*) ;; *) echo 'Initial fake credential import failed.' >&2; exit 1 ;; esac

fingerprint_probe=$(cat <<'PHP'
<?php
$values = [];
foreach (['github-token','zendesk-token'] as $name) {
    $path = '/var/lib/support-bridge/' . $name;
    $values[$name] = [file_get_contents($path), fileinode($path), fileperms($path) & 0777];
}
echo hash('sha256', serialize($values));
PHP
)
before=$(printf '%s' "$fingerprint_probe" | compose run --rm --no-deps -T --entrypoint php credentials)
result=$(printf '%s' "$payload" | compose run --rm --no-deps -T credentials)
case "$result" in *'"changed":0'*) ;; *) echo 'Matching credentials were unnecessarily replaced.' >&2; exit 1 ;; esac
after=$(printf '%s' "$fingerprint_probe" | compose run --rm --no-deps -T --entrypoint php credentials)
test "$before" = "$after"

compose run --rm --no-deps -T init
test "$(compose run --rm --no-deps -T cli status)" = '[]'
probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
(new Bridge\Runtime\SecretLoader())->load();
$context = new Bridge\Application\Context(Bridge\Application\Configuration::fromEnvironment());
if ($context->store->all() !== [] || getenv('BRIDGE_MODE') !== 'live') { throw new RuntimeException('Live initialization created links.'); }
foreach (['github.json','zendesk.json','local.sqlite','github-token','zendesk-token'] as $name) {
    if (file_exists('/var/lib/support-bridge/' . $name)) { throw new RuntimeException('Vendor credentials or source fixtures entered business state.'); }
}
if ((fileperms('/run/secrets/vendor') & 0777) !== 0700) { throw new RuntimeException('Credential directory permissions changed.'); }
foreach (['github','zendesk'] as $provider) {
    $path = '/run/secrets/vendor/' . $provider . '-token';
    if (fileowner($path) !== 10001 || (fileperms($path) & 0777) !== 0400 || getenv('BRIDGE_' . strtoupper($provider) . '_TOKEN') !== 'synthetic-' . $provider . '-access-token') { throw new RuntimeException('Private credential loading failed.'); }
}
$probe = '/run/secrets/vendor/.reader-write-probe';
if (@file_put_contents($probe, 'must-not-write') !== false) { @unlink($probe); throw new RuntimeException('Vendor reader volume is writable.'); }
echo "PASS actual live bootstrap, empty CLI state and private read-only credential loading.\n";
PHP
)
printf '%s' "$probe" | compose run --rm --no-deps -T --entrypoint php cli

rotated='{"github-token":"synthetic-github-access-token","zendesk-token":"synthetic-rotated-zendesk-access-token"}'
result=$(printf '%s' "$rotated" | compose run --rm --no-deps -T credentials)
case "$result" in *'"changed":1'*) ;; *) echo 'Fake token rotation did not replace exactly one value.' >&2; exit 1 ;; esac
rotation_probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
(new Bridge\Runtime\SecretLoader())->load();
if (getenv('BRIDGE_GITHUB_TOKEN') !== 'synthetic-github-access-token' || getenv('BRIDGE_ZENDESK_TOKEN') !== 'synthetic-rotated-zendesk-access-token') { throw new RuntimeException('A fresh reader did not load the rotated fake token.'); }
echo "PASS manual credential rotation is observed by a fresh reader.\n";
PHP
)
printf '%s' "$rotation_probe" | compose run --rm --no-deps -T --entrypoint php cli
test "$(compose run --rm --no-deps -T cli status)" = '[]'
echo 'PASS isolated live credential import and bootstrap; no vendor requests or synchronization performed'
