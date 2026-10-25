#!/bin/sh
# Disposable synthetic project: never operate on the normal application's volumes.
set -eu
cd "$(dirname "$0")/.."
docker=${DOCKER:-docker}
project="support-bridge-lifecycle-$$"
export BRIDGE_HTTP_PORT=${BRIDGE_LIFECYCLE_HTTP_PORT:-18080}
export BRIDGE_PROMETHEUS_PORT=${BRIDGE_LIFECYCLE_PROMETHEUS_PORT:-19090}
export BRIDGE_GRAFANA_PORT=${BRIDGE_LIFECYCLE_GRAFANA_PORT:-13000}

compose() { "$docker" compose -p "$project" "$@"; }
if [ -n "$(compose ps -a -q)" ]; then
    echo 'Refusing to reuse an existing lifecycle-check project.' >&2
    exit 1
fi
cleanup() { compose down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
trap 'exit 130' INT TERM

compose up --build -d --wait --wait-timeout 120
compose stop worker
worker_id=$(compose ps -a -q worker)
test "$("$docker" inspect -f '{{.State.ExitCode}}' "$worker_id")" = 0
compose run --rm cli local-edit zendesk 501 state closed
code=0
compose run --rm cli sync || code=$?
test "$code" = 2

probe=$(cat <<'PHP'
<?php
require '/app/bootstrap.php';
$context = new Bridge\Application\Context(Bridge\Application\Configuration::fromEnvironment());
$pair = $context->store->get('escalation-1');
if ($pair['status'] !== 'awaiting_approval' || $pair['plan'] === null || $pair['approval'] !== null) {
    fwrite(STDERR, "The synthetic state-change plan did not remain unapproved.\n");
    exit(1);
}
echo hash('sha256', serialize([$pair, file_get_contents('/run/secrets/operator/token'),
    file_get_contents('/run/secrets/metrics/token')]));
PHP
)
before=$(printf '%s' "$probe" | compose exec -T api php)
compose down
compose up -d --wait --wait-timeout 120
after=$(printf '%s' "$probe" | compose exec -T api php)
test "$before" = "$after"
compose --profile test run --rm smoke
echo 'PASS graceful worker termination, pending-plan persistence and unchanged credentials after recreation'
