FROM prom/prometheus:v3.13.5@sha256:aafa9d694e6a883c02db516d9f6e5b84e14195f49a2a6456a4cca815edda72b9

COPY --chown=65534:65534 docker/prometheus.yml /etc/prometheus/prometheus.yml
COPY --chown=65534:65534 monitoring/alerts.yml /etc/prometheus/alerts.yml

USER 65534:65534
