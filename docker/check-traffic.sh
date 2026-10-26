#!/bin/sh
# Exercise the simulator only in a uniquely named disposable project.
set -eu
cd "$(dirname "$0")/.."
docker=${DOCKER:-docker}
project="support-bridge-traffic-check-$$"
export BRIDGE_TRAFFIC_HTTP_PORT=${BRIDGE_TRAFFIC_CHECK_HTTP_PORT:-18081}
export BRIDGE_TRAFFIC_PROMETHEUS_PORT=${BRIDGE_TRAFFIC_CHECK_PROMETHEUS_PORT:-19091}
export BRIDGE_TRAFFIC_GRAFANA_PORT=${BRIDGE_TRAFFIC_CHECK_GRAFANA_PORT:-13001}

compose() { "$docker" compose -p "$project" -f compose.yaml -f compose.traffic.yaml "$@"; }
if [ -n "$(compose ps -a -q)" ]; then
    echo 'Refusing to reuse an existing traffic-check project.' >&2
    exit 1
fi
log=$(mktemp)
cleanup() {
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -f "$log"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

compose up --build -d --wait --wait-timeout 120 api worker prometheus grafana
compose run --rm --no-deps -e BRIDGE_TRAFFIC_INTERVAL=1 -e BRIDGE_TRAFFIC_REVIEW=scripted -e BRIDGE_TRAFFIC_MAX_TICKS=50 traffic >"$log"
for action in support_title engineering_title independent_merge state_change conflict_created transient_failure lost_response no_op scripted_approval scripted_resolution; do
    if ! grep -q "\"action\":\"$action\"" "$log"; then
        echo "Missing synthetic traffic action: $action" >&2
        cat "$log"
        exit 1
    fi
done

probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
$context = new Bridge\Application\Context(Bridge\Application\Configuration::fromEnvironment());
$db = new PDO('sqlite:' . $context->configuration->home . '/local.sqlite');
if (count($context->store->all()) !== 9) {
    throw new RuntimeException('Traffic must use a bounded pool of eight synthetic pairs.');
}
foreach (['approval_requested', 'approval_granted', 'conflict', 'resolution_selected', 'retry_scheduled', 'converged'] as $type) {
    $query = $db->prepare('SELECT COUNT(*) FROM events WHERE pair_id LIKE ? AND type = ?');
    $query->execute(['traffic-%', $type]);
    if ((int) $query->fetchColumn() < 1) {
        throw new RuntimeException('Missing workflow evidence: ' . $type);
    }
}
if ($context->store->get('escalation-1')['baseline'] !== ['title' => 'CSV export fails for large reports', 'state' => 'open']) {
    throw new RuntimeException('The initial sample was changed by the traffic workload.');
}
echo "PASS bounded synthetic pool, normal approval/conflict/retry events and untouched sample\n";
PHP
)
printf '%s' "$probe" | compose exec -T api php
compose --profile test run --rm smoke

# Compare durable records and pending work with all writers stopped.
compose stop worker
snapshot=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
$context = new Bridge\Application\Context(Bridge\Application\Configuration::fromEnvironment());
echo hash('sha256', serialize([$context->store->all(),
    file_get_contents($context->configuration->home . '/github.json'),
    file_get_contents($context->configuration->home . '/zendesk.json'),
    file_get_contents('/run/secrets/operator/token'), file_get_contents('/run/secrets/metrics/token')]));
PHP
)
before=$(printf '%s' "$snapshot" | compose exec -T api php)
compose down
compose up -d --wait --wait-timeout 120 api prometheus grafana
after=$(printf '%s' "$snapshot" | compose exec -T api php)
test "$before" = "$after"
compose up -d --wait --wait-timeout 60 worker

# A generator restart reuses its owned records and exits cleanly on SIGTERM.
compose up -d --wait --wait-timeout 60 traffic
duplicate_code=0
compose run --rm --no-deps -e BRIDGE_TRAFFIC_MAX_TICKS=1 traffic >/dev/null 2>&1 || duplicate_code=$?
test "$duplicate_code" = 1
compose stop traffic
traffic_id=$(compose ps -a -q traffic)
test "$("$docker" inspect -f '{{.State.ExitCode}}' "$traffic_id")" = 0
printf '%s' "$probe" | compose exec -T api php
echo 'PASS real HTTP traffic, durable state through recreation and graceful simulator termination'
