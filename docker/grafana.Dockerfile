FROM grafana/grafana:13.2.4@sha256:dbbc38ea21103cefb50396340cd88afec779c7e070b0a7bf5a75b4ce46651d3f

USER root
RUN grafana cli --pluginsDir /opt/grafana/plugins plugins install yesoreyeram-infinity-datasource 4.1.5 \
    && chown -R 472:472 /opt/grafana/plugins
ENV GF_PATHS_PLUGINS=/opt/grafana/plugins
COPY --chown=472:472 --chmod=0555 docker/grafana-entrypoint.sh /usr/local/bin/support-bridge-grafana
COPY --chown=472:472 docker/grafana.ini /etc/grafana/grafana.ini
COPY --chown=472:472 docker/grafana-datasource.yml /etc/grafana/provisioning/datasources/supportbridge.yml
COPY --chown=472:472 docker/grafana-dashboards.yml /etc/grafana/provisioning/dashboards/supportbridge.yml
COPY --chown=472:472 monitoring/grafana/dashboards/supportbridge.json /etc/grafana/dashboards/supportbridge.json

USER 472:472
ENTRYPOINT ["/usr/local/bin/support-bridge-grafana"]
