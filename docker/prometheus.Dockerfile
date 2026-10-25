FROM prom/prometheus:v3.15.1@sha256:fa3302279710be2f21a8301ec17a8b4f2301fd829bd113839b3b6b02b6f191a2

COPY --chown=65534:65534 docker/prometheus.yml /etc/prometheus/prometheus.yml
COPY --chown=65534:65534 monitoring/alerts.yml /etc/prometheus/alerts.yml

USER 65534:65534
