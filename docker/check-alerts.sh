#!/bin/sh
# Offline rule tests against the image's actual rules, with no application credentials/state.
set -eu
cd "$(dirname "$0")/.."
docker=${DOCKER:-docker}
"$docker" build -f docker/prometheus.Dockerfile -t support-bridge-prometheus:local .
cat monitoring/alerts.test.yml | "$docker" run --rm -i --network none --read-only \
    --tmpfs /tmp:rw,nosuid,nodev,size=64m --entrypoint /bin/sh support-bridge-prometheus:local \
    -c 'cat > /tmp/alerts.test.yml && /bin/promtool test rules /tmp/alerts.test.yml'
