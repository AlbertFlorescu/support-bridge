# syntax=docker/dockerfile:1
FROM php:8.5-cli-trixie@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554 AS extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install -j2 curl pcntl

FROM php:8.5-cli-trixie@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554 AS runtime
# Apply the selected distribution's available patches; runtime needs no compiler.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends libcurl4t64 ca-certificates \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
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

FROM node:26-trixie-slim@sha256:e4db4c914bf8ad241bd24804fc25d272d89bad97e68cdc8a1414862790a02c39 AS node

FROM composer:2.11.1@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer

FROM runtime AS verification
USER root
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 libatomic1 unzip && rm -rf /var/lib/apt/lists/*
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
COPY tools/browser/ tools/browser/
COPY tests/ tests/
USER 10001:10001
CMD ["verify"]

# A plain docker build produces the runtime, while verification remains an explicit target.
FROM runtime AS app
