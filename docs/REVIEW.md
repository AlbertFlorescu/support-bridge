# Verification record

We verify the service with synthetic local data on Ubuntu/WSL and PHP 8.3. The results below were checked through 2026-10-25. We distinguish executable checks, actual local monitoring and provider-account validation.

## Automated checks

```bash
php bin/check.php
```

The gate passes **136 tests**, **nine demo assertions** and syntax checks for **43 PHP files**:

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
