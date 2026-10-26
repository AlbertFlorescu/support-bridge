# Verification record

We verify the service with synthetic local data on Ubuntu/WSL and PHP 8.3. The results below were checked through 2026-10-26. The opening summary describes the current gate; dated sections retain earlier evidence. We distinguish executable checks, actual local monitoring and provider-account validation.

## Automated checks

```bash
php bin/check.php
```

The current gate passes **170 PHP checks**, **nine demo assertions** and syntax checks for **112 PHP files**, including container helpers. The separate Composer gate checks **99 files** against PSR-12 and verifies optimized PSR-4 autoloading for **89 classes**:

| Suite | Checks | Coverage |
| --- | ---: | --- |
| Domain | 8 | Merge truth table and retry arithmetic |
| Reconciliation | 30 | Durable plans, versions, locks, retries, conflicts and uncertain outcomes |
| Governance | 17 | Whole-plan approval, stale evidence, policy binding, capabilities, migration and audit failures |
| Persistence | 6 | Scoped connections, atomic audit rollback, canonical locks and legacy-plan preservation |
| Application use cases | 8 | Typed command/query dispatch, local reads without provider access and shared workflow behavior |
| Console/runtime | 8 | CLI parsing/exit codes, secret loading, local-edit locking, heartbeats and termination |
| Synthetic traffic | 12 | Bounded owned records, normal HTTP workflow, delayed review, faults and uncertain outcomes |
| Provider HTTP | 20 | Payload/status mapping, safe updates, rate guidance and response validation |
| Observability | 16 | Persistence, concurrent increments, histogram consistency, upgrades and failed storage |
| Operator API | 29 | Authentication, strict contracts, approval, privacy and error mapping |
| Wire HTTP | 16 | Actual loopback server, Swagger assets, exporter and end-to-end approval |

The [post-refactor main Verify run](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40390702419) and [vulnerability inventory](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40390702405) passed. The [PHP application design section](#php-application-design-verification-2026-10-26) records the scope and local container evidence.

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

## Initial container verification: 2026-10-25

We built and started the complete Compose stack on Docker Desktop's Linux engine. The application image runs PHP 8.3.36 as UID10001 without Node, tests, credentials or host state embedded in its runtime layers. The separate verification target includes Node and the locked API tooling, and passed the then-current 136 tests, nine assertions, 49 lint checks and 27 response fixtures with runtime networking disabled.

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

These dated decisions retained the tested runtime images; they did not validate a customer deployment. On 2026-10-26 we removed the proposal suppressions so newer runtime lines can appear as Dependabot PRs for review. Our [dependency maintenance policy](CI.md#dependency-maintenance-policy) exposes available patch, minor and major proposals while reserving adoption for a deliberate, tested migration. The existing container and native monitoring evidence remains distinct from candidate-version evidence.

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

## Vulnerability automation verification: 2026-10-26

The native repository APIs confirmed Dependabot alerts enabled and automatic security updates enabled and unpaused. The native alert feed reported zero open alerts at setup; that is separate from image-scanner coverage.

Local verification passed 70 Python security-tool tests with one Node-dependent fixture skipped in WSL. The actual Node checker also passed against the committed Swagger assets, and the Docker verification image passed 136 PHP tests, nine demo assertions, 49 lint checks, Swagger byte equality, 11 OpenAPI paths and 27 actual response fixtures. Hosted Verify installs Node 24 before running the security-tool suite so that fixture is exercised there.

The tests cover incomplete scans, stale databases, metadata failures and rejected IDs, unsafe output, exact-head atomic publication, ancestor-reset races, dependency-source restrictions and retention of earlier issue evidence. We verified GitHub's live GraphQL input schema for atomic branch commits. Source metadata and scanner matching remain bounded by the coverage and retention limits in [SECURITY.md](../SECURITY.md).

The [initial main inventory](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40282645536) completed all four image scans, npm audit, native alert reads and managed issue publication. The [main Verify run](https://github.com/AlbertFlorescu/support-bridge/actions/runs/40282645619) also passed. The [tracker](https://github.com/AlbertFlorescu/support-bridge/issues/10) initially recorded 5,941 component/package/advisory observations; observations can repeat a CVE across packages or images. Unfixed findings remain visible. Source enrichment is a bounded subset and does not establish that every observed vulnerability has been analyzed or repaired.

## Synthetic traffic verification: 2026-10-26

The verification image passed 55 PHP syntax checks, 148 application checks (including 12 traffic checks), nine demo assertions, Swagger byte equality, 11 OpenAPI paths and 27 HTTP response fixtures.

The disposable traffic project ran 50 one-second ticks through the actual operator HTTP API. It produced support/engineering title edits, independent field merges, state changes, conflicts, scripted exact-plan approval/source selection, pre-write failures, lost responses and no-op polls. Audit events confirmed the ordinary workflow paths. The eight-pair pool remained bounded and the original sample was unchanged. Pair/provider/credential hashes matched across container recreation with writers stopped; the driver restarted and terminated cleanly.

The separate lifecycle check preserved an unapproved state-change plan and credentials through recreation. Both that project and the traffic project passed all nine API/exporter/Prometheus/Grafana smoke groups. A concurrent second traffic driver was rejected by its generator lock. A three-tick random-interval run also completed without errors.

The ongoing five-second traffic stack reported a healthy authenticated scrape, nine linked pairs, increasing reconciliation observations and nonzero retry and approval-wait counters. Its smoke checks verified the provisioned dashboard and datasource. These are synthetic workflow and local monitoring observations; they do not exercise vendor network behavior or measure production capacity. [Traffic operation](TRAFFIC.md) records the setup and limits.

## PHP application design verification: 2026-10-26

We separated application commands and queries into typed messages and focused handlers. HTTP and CLI map their inputs into those shared use cases; container entry scripts delegate to runtime objects. A scoped injected SQLite connection replaces implicit construction inside the repository, with separate schema, transaction and canonical-lock responsibilities. The existing plan JSON, approval rules and recovery ordering are unchanged.

The rebuilt verification image passed 170 PHP checks, nine demo assertions, syntax checks for 112 PHP files, the PSR-12 ruleset across 99 files, Swagger byte equality, 11 OpenAPI paths and 27 real HTTP response fixtures. The new checks cover persistence rollback and legacy-plan preservation, typed dispatch without provider access on queries, CLI exit behavior, local-edit lock contention, secret loading, heartbeat validation and graceful termination. Strict Composer autoload verification generated 89 classes without PSR-4 violations. Composer metadata validation and the locked development-package audit passed with no advisories at the time checked.

The style ruleset covers application classes and entrypoints. Tests and the standalone stack-verification harness are outside that ruleset; they still receive syntax and behavior checks. Composer and its development packages are present only in development/verification, while the runtime continues to use the existing lightweight PSR-4 loader.

A disposable lifecycle check preserved the exact unapproved plan and credentials through container recreation and confirmed graceful worker termination. Its nine real API/exporter/Prometheus/Grafana smoke groups passed. These checks use synthetic records and newly initialized monitoring credentials; they do not validate a rotated Grafana account or vendor services.

The rebuilt traffic stack completed 50 one-second ticks through the operator API, covering edits, independent merges, conflicts, exact-plan approvals, pre-write failures, lost responses and no-op sweeps. All nine service-network smoke groups passed. The eight-pair pool stayed bounded, the sample remained unchanged, durable state and credential hashes matched across recreation, the duplicate generator was rejected, and the restarted generator stopped with exit code zero.

A separate AI-assisted source review inspected command/query dispatch, authentication before application construction, CLI/runtime parity, lock ownership, transactions and additive migration. It found no blocking regression in that scope. This review did not establish live provider compatibility, customer value or compliance. We retain the limitations listed below and in the [architecture guide](ARCHITECTURE.md).

## Documentation consistency check: 2026-10-26

We checked the README and operating guides against both Compose definitions and the actual published bindings. The traffic project exposes API/Swagger 8081, Prometheus 9091 and Grafana 3001; the default project exposes 8080, 9090 and 3000. Both Swagger pages, Prometheus readiness endpoints and Grafana database-health endpoints responded successfully. These checks do not validate a Grafana user's login.

We validated 95 local documentation links/anchors, 52 shell blocks and two PowerShell examples. The guides distinguish project-specific accounts and volumes, native filesystem state, internal service addresses and host-port overrides. We also corrected the current check summary, persistence description and observed repository-control status. This was a documentation change; the checks did not approve plans, run business commands, reset credentials or change repository settings.

## Validation still required

We have not validated live GitHub/Zendesk sandbox accounts, customer-specific statuses/triggers, production load, restore procedures, customer outcomes or notification delivery. The shared operator token does not establish individual or human identity. Approval has no expiry/revocation workflow. GitHub's preflight/PATCH race and provider timestamp granularity remain material limitations. Our governance controls do not determine whether customer obligations have been fulfilled.
