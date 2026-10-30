# syntax=docker/dockerfile:1
FROM php:8.5-cli-bookworm@sha256:104890ada56869090c9cd0ec709fe205834d3e383b46d9995dc16cd6388a9302 AS extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install -j2 curl pcntl

FROM php:8.5-cli-bookworm@sha256:104890ada56869090c9cd0ec709fe205834d3e383b46d9995dc16cd6388a9302 AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4 ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 bridge \
    && useradd --uid 10001 --gid bridge --home-dir /tmp --no-create-home --shell /usr/sbin/nologin bridge
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
RUN php -r 'foreach (["pdo_sqlite", "curl", "pcntl"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'
WORKDIR /app
COPY bootstrap.php ./
COPY src/ src/
COPY bin/ bin/
COPY public/ public/
COPY docs/api/ docs/api/
COPY docker/*.php docker/
RUN mkdir -p /app/var /var/lib/support-bridge /run/secrets/operator /run/secrets/metrics /run/secrets/grafana /run/secrets/oauth \
    && chown 10001:10001 /app/var /var/lib/support-bridge /run/secrets/operator /run/secrets/metrics /run/secrets/grafana /run/secrets/oauth
ENV BRIDGE_MODE=local BRIDGE_HOME=/var/lib/support-bridge
USER 10001:10001
EXPOSE 8080
ENTRYPOINT ["php", "/app/docker/entrypoint.php"]
CMD ["api"]

FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS node

FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer

FROM runtime AS verification
USER root
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 unzip && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm/ /opt/npm/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock phpcs.xml.dist ./
RUN composer install --no-interaction --no-progress --no-plugins --no-scripts --prefer-dist && composer autoload:check
COPY tools/api-docs/package*.json tools/api-docs/
RUN cd tools/api-docs && node /opt/npm/bin/npm-cli.js ci --ignore-scripts --no-audit --no-fund
COPY tools/api-docs/*.cjs tools/api-docs/
COPY tools/demo-viewer/ tools/demo-viewer/
COPY tools/oauth/ tools/oauth/
COPY tests/ tests/
USER 10001:10001
CMD ["verify"]

# A plain docker build produces the runtime, while verification remains an explicit target.
FROM runtime AS app
