#!/bin/sh
set -eu
# Provision the read-only backend credential without embedding it in image/config/browser data.
BRIDGE_MONITORING_TOKEN=$(cat /run/secrets/metrics/token)
test -n "$BRIDGE_MONITORING_TOKEN"
export BRIDGE_MONITORING_TOKEN
exec /run.sh "$@"
