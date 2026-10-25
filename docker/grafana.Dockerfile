FROM grafana/grafana:13.2.4@sha256:dbbc38ea21103cefb50396340cd88afec779c7e070b0a7bf5a75b4ce46651d3f

COPY --chown=472:472 docker/grafana.ini /etc/grafana/grafana.ini
COPY --chown=472:472 docker/grafana-datasource.yml /etc/grafana/provisioning/datasources/supportbridge.yml
COPY --chown=472:472 docker/grafana-dashboards.yml /etc/grafana/provisioning/dashboards/supportbridge.yml
COPY --chown=472:472 monitoring/grafana/dashboards/supportbridge.json /etc/grafana/dashboards/supportbridge.json

USER 472:472
