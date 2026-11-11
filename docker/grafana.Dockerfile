FROM grafana/grafana:13.3.1@sha256:0615ec4fbd4072004b7d86b46ded63013a74b7d6eb1119273dd89e8fa25c13cf

USER root
RUN apk upgrade --no-cache \
    && for plugin in cloudwatch elasticsearch grafana-postgresql-datasource \
        grafana-pyroscope-datasource influxdb jaeger loki mssql mysql opentsdb stackdriver tempo zipkin; do \
        rm -rf -- "/usr/share/grafana/data/plugins-bundled/$plugin"; \
    done \
    && grafana cli --pluginsDir /opt/grafana/plugins plugins install yesoreyeram-infinity-datasource 4.1.5 \
    && chown -R 472:472 /opt/grafana/plugins
ENV GF_PATHS_PLUGINS=/opt/grafana/plugins
COPY --chown=472:472 --chmod=0555 docker/grafana-entrypoint.sh /usr/local/bin/support-bridge-grafana
COPY --chown=472:472 docker/grafana.ini /etc/grafana/grafana.ini
COPY --chown=472:472 docker/grafana-datasource.yml /etc/grafana/provisioning/datasources/supportbridge.yml
COPY --chown=472:472 docker/grafana-dashboards.yml /etc/grafana/provisioning/dashboards/supportbridge.yml
COPY --chown=472:472 monitoring/grafana/dashboards/supportbridge.json /etc/grafana/dashboards/supportbridge.json

USER 472:472
ENTRYPOINT ["/usr/local/bin/support-bridge-grafana"]
