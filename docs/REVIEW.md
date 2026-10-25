# Verification record

We verify the service with synthetic local data on Ubuntu/WSL and PHP 8.3. The results below were checked through 2026-10-25. We distinguish executable checks, actual local monitoring and provider-account validation.

## Automated checks

```bash
php bin/check.php
```

The gate passes **136 tests**, **nine demo assertions** and syntax checks for **49 PHP files**, including container helpers:

| Suite | Checks | Coverage |
| --- | ---: | --- |
| Domain | 8 | Merge truth table and retry arithmetic |
| Reconciliation | 30 | Durable plans, versions, locks, retries, conflicts and uncertain outcomes |
| Governance | 17 | Whole-plan approval, stale evidence, policy binding, capabilities, migration and audit failures |
| Provider HTTP | 20 | Payload/status mapping, safe updates, rate guidance and response validation |
| Observability | 16 | Persistence, concurrent increments, histogram consistency, upgrades and failed storage |
| Operator API | 29 | Authentication, strict contracts, approval, privacy and error mapping |
| Wire HTTP | 16 | Actual loopback server, Swagger assets, exporter and end-to-end approval |

Provider tests use controlled transports rather than vendor accounts. Wire tests create isolated state, start a loopback PHP server and stop their own process. Demo approvals use synthetic data; they are not human approval evidence.

## API schema validation

After the wire tests generate their synthetic response fixtures:

```bash
cd tools/api-docs
npm ci --ignore-scripts
node validate.cjs
```

We validated **11 OpenAPI paths** and **27 actual HTTP response fixtures** with JSON Schema 2020-12. Required approval fixtures cover success, authentication, stale evidence, invalid input and pending/approved inspection. We do not submit specs or fixtures to an online validator.

The repository's Verify workflow runs the PHP gate and schema validation on pushes and pull requests. [GitHub Actions](https://github.com/AlbertFlorescu/support-bridge/actions) records hosted results for the published revision.

## Container verification

We built and started the complete Compose stack on Docker Desktop's Linux engine. The application image runs PHP 8.3.36 as UID10001 without Node, tests, credentials or host state embedded in its runtime layers. The separate verification target includes Node and the locked API tooling, and passed the same 136 tests, nine assertions, 49 lint checks and 27 response fixtures with runtime networking disabled.

`docker compose --profile test run --rm smoke` passed nine read-only groups against the actual API, Prometheus and Grafana service network. The checks include separate credential authority, local Swagger, private-path denial, metric consistency, authenticated scrapes, query results, all four rules and the provisioned eight-panel dashboard. We also verified API liveness, Grafana health and Prometheus readiness through their published host-loopback ports.

The disposable lifecycle check stopped the worker with exit0, prepared a synthetic state-change plan without approving it, recreated every container while retaining named volumes, and confirmed the identical pending plan and operator/scrape credentials. Grafana authentication and monitoring still passed afterwards. The test removed only its uniquely named synthetic project's volumes; the normal local stack retained its state.

Image sources are pinned by verified manifest digests: official PHP8.3 and Node24 images, Prometheus3.13.5 and Grafana13.2.4. Builds still use package repositories for runtime libraries, so those pins do not imply a fully reproducible dependency build or vulnerability certification. Default Compose uses a standard bridge with outbound access and host ports on loopback. An internal-only network did not publish reachable host ports on the tested Docker Desktop engine; the bridge configuration was checked through real host requests.

A separate AI-assisted container review checked secret-volume readers, non-root ownership, read-only filesystems, startup, file-secret failure behavior and worker termination. Its two offline probes confirmed missing secret files and conflicting direct/file values fail with sanitized output. [Docker operation](DOCKER.md) records commands, persistence and deployment limits. CI additionally builds the images and runs the container suite, smoke and lifecycle checks.

## Dependency review: 2026-10-25

We reviewed three candidate image updates. Each candidate passed PHP, container and aggregate checks on GitHub Actions; passing those checks does not itself require adoption.

| Candidate | Evidence and decision |
| --- | --- |
| PHP 8.3 → 8.5 | [Candidate CI passed](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40176435376). We retain PHP 8.3.36; registry inspection confirmed our checked-in digest is current for the selected tag. PHP 8.3 remains security-supported through 2027-12-31. PHP 8.5 deprecates `curl_close()`, which we call in `src/HttpClient.php` and `docker/verify-stack.php`. A later migration needs that cleanup and a deprecation-free verification run; the passing candidate suite does not establish that property. [PHP support](https://www.php.net/supported-versions.php), [cURL change](https://www.php.net/manual/en/function.curl-close.php). |
| Node 24 → 26 | [Candidate CI passed](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40176440617). We retain Node 24 in both native CI and the verification image; registry inspection confirmed its digest is current. Node 26 is Current until its scheduled LTS transition on 2026-10-28. We have no demonstrated requirement to split our verification runtimes or adopt that line now. [Official schedule](https://github.com/nodejs/Release/blob/main/schedule.json). |
| Prometheus 3.13.5 → 3.15.1 | [Candidate CI passed](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40176444749), including authenticated scrapes/queries, four rules, Grafana provisioning and container recreation. We found no relevant configuration or API blocker, but retain the 3.13 LTS line without a demonstrated need for newer minor features. Both releases include the October 21 security fixes. The candidate used fresh monitoring storage; it does not verify an existing 3.13.5 TSDB upgrade. [3.13.5 release](https://github.com/prometheus/prometheus/releases/tag/v3.13.5), [3.15.1 release](https://github.com/prometheus/prometheus/releases/tag/v3.15.1). |

These are maintenance decisions, not runtime upgrades or vendor/production validation. The existing container and native monitoring evidence remains separate and unchanged. Our [dependency maintenance policy](CI.md#dependency-maintenance-policy) keeps patch/digest proposals available and reserves line changes for a deliberate migration.

## Local monitoring verification

```bash
bash monitoring/install.sh
python3 monitoring/stack.py up --demo
python3 monitoring/stack.py verify
```

We checked the real local Prometheus/Grafana path:

- Pinned Prometheus 3.13.5 and Grafana OSS 13.2.4 archives matched their checksums.
- Listeners were loopback-only; credentials and synthetic state stayed outside source control.
- An unauthenticated scrape returned 401; the separate scrape credential returned metrics.
- Prometheus reported `up=1`, and counters, histogram observations and state gauges were queryable.
- Grafana's provisioned datasource reported `OK`, and the eight-panel dashboard was loaded.
- `promtool` validated the scrape configuration and four rules; Prometheus loaded them.
- The pending-approval gauge was queryable, and the loaded dashboard/rule expressions included `awaiting_approval`. The monitoring fixture had zero pending approvals; application and wire tests exercise a nonzero approval wait.

The monitoring helper stores a secret-free `verification.json` in its private state directory. No notification recipient is configured. Loaded example rules do not prove notification delivery, production capacity or an incident-response process.

## Review findings addressed

We added tests and controls for these concrete failure cases:

1. Invalid provider configuration must fail before binding a state directory to the wrong identity.
2. Missing metric rows must fail scraping, rather than silently reset observations to zero.
3. Pending destinations and original reviewed sources must retain the required version evidence before another effect.
4. Metrics credentials must not authorize operator commands, and equal configured tokens must fail closed.
5. State-changing plans must withhold every effect until exact-plan approval; stale fields, versions or hashes cannot reuse it.
6. Approval audit failures must prevent authorization/effects, while best-effort metric failures must not change business results.
7. Existing write intents must survive the approval-column migration, and telemetry upgrades must preserve historical counters.

A separate AI-assisted Code Reviewer pass inspected approval, recovery, API and migration paths and independently ran 17 governance, 29 API and 16 observability checks. It found no actionable blocker within that scope. This is bounded review evidence, not a security or compliance certification. [Development tooling](TOOLING-INSTALLATION.md) records the separate, partial REA artifact analysis and its limits.

## Validation still required

We have not validated live GitHub/Zendesk sandbox accounts, customer-specific statuses/triggers, production load, restore procedures, customer outcomes or notification delivery. The shared operator token does not establish individual or human identity. Approval has no expiry/revocation workflow. GitHub's preflight/PATCH race and provider timestamp granularity remain material limitations. Our governance controls do not determine whether customer obligations have been fulfilled.
