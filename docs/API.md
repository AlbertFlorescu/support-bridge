# Operator API and Swagger

The API is an internal single-operator surface over the headless application used by the CLI. The same deterministic reconciliation and governance policies run behind both surfaces. See the [assumptions register](ASSUMPTIONS.md) and [OpenAPI 3.1 document](api/openapi.json).

## Choose the API instance

The operating API belongs to `support-bridge-live-demo` at `http://127.0.0.1:1988`; [Swagger](http://127.0.0.1:1988/docs) and the [JSON specification](http://127.0.0.1:1988/openapi.json) use that same origin. Use the [Docker connection guide](DOCKER.md#choose-a-stack-and-its-ports) and retain the private env file, project name and both Compose files. Container-to-container access uses `http://api:8080`.

Load that running project's existing operator token privately in bash/WSL:

```bash
BRIDGE_API_TOKEN="$(docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml exec -T api cat /run/secrets/operator/token)"
BASE_URL=http://127.0.0.1:1988
```

Initialization must already have completed. [Managed Zendesk renewal](OAUTH.md) is separate from this operator bearer credential: it publishes a private token bundle consumed per vendor request, without a new API endpoint or approval capability. API queries and scrapes do not trigger renewal or provider reads. Keep the operator token out of shell tracing, logs and source. It grants broad operator authority and is separate from the scrape token and Grafana account. Generating a new host token does not change the running API. Local test fixtures use separate state and credentials; their IDs and tokens must not be substituted for live mappings.

Fresh deployments leave the worker disabled until explicitly configured. The Zendesk-first flow enables the existing worker and intake setting; it runs intake before reconciliation. Reading the API, Swagger or Grafana does not authorize synchronization or approval. `POST /api/sync` can update every eligible linked pair; review the whole link set and intended effects before an authorized sweep. The original demonstration pair IDs are `csv-export`, `webhook-retry` and `sso-conflict`; additional links are equally valid. Grafana automatically lists the entire stored link set using numeric ticket/issue IDs. Its status shows stored evidence, not a guarantee of current provider state. The protected text scrape exports seven per-link gauges; the operator JSON metrics response remains aggregate-only. See [metric contracts and identifier exposure](OBSERVABILITY.md).

The Swagger assets are vendored from `swagger-ui-dist` **5.34.0** with their license and a locked development dependency. The UI does not need Node/npm to run. Node is only used to refresh the assets and validate OpenAPI during development. It makes no CDN or remote-validator request. Try it out is disabled so visiting a reference page cannot initiate a synchronization command. The bearer token is never embedded in the HTML, specification, URL or initializer.

## Endpoints

| Method/path | Authorization | Meaning |
| --- | --- | --- |
| `GET /health` | Public | PHP liveness only; no provider or database readiness claim |
| `GET /docs`, `GET /openapi.json` | Public | Static API documentation; no record data |
| `GET /api/pairs?limit=50&offset=0` | Bearer | Bounded metadata page, without title snapshots |
| `GET /api/pairs/{id}` | Bearer | Baseline, pending intent, governance/approval evidence, conflict and recent audit; may contain sensitive titles |
| `GET /api/metrics` | Operator bearer | Local business counts and diagnostic metric-registry availability |
| `GET /metrics` | Separate monitoring bearer | Numeric Prometheus text exposition; cannot run operator commands |
| `GET /monitoring/pairs` | Same monitoring bearer | Complete read-only table context, including baseline subjects and fixed source URLs |
| `GET /api/comment-deliveries?limit=50&offset=0` | Bearer | Provider-free sensitive note ledger and discovery streams, independently paged; includes privacy_hold |
| `POST /api/comments/sync` | Bearer | Run one explicitly enabled live internal-note delivery pass with exactly `{}` |
| `GET /api/escalations?limit=50&offset=0` | Bearer | Read-only intake ledger page, including sensitive snapshots and recovery markers; no provider request |
| `POST /api/intake` | Bearer | Run one enabled live intake pass with body `{}`; may create open GitHub issues and link tickets |
| `POST /api/pairs` | Bearer | Link existing numeric issue/ticket IDs; creates no remote record |
| `POST /api/sync` | Bearer | Run one synchronous eligible sweep |
| `POST /api/pairs/{id}/resolve` | Bearer | Read both providers, validate conflict freshness and queue a selected source |
| `POST /api/pairs/{id}/approve` | Bearer | Attest to review of an exact pending state-change plan; re-read both records; no remote write |
| `POST /api/pairs/{id}/retry` | Bearer | Reset retry/dead budget while retaining any durable plan |

All `/api` reads and writes require a configured bearer token of at least 32 non-whitespace characters. Authentication runs before creating an application context or parsing operation bodies. Missing/weak deployment tokens fail closed with HTTP 503. Local/live mode, credentials, provider URLs and state path are process configuration only.

POST bodies must be JSON **objects**, with exactly the documented string fields and `Content-Type: application/json`. Empty-operation bodies are `{}`, not an empty body or array. The limit is 16 KiB. JSON IDs are strings to preserve numeric identifiers exactly. Pair names and IDs are validated separately; there is no mass assignment.

Examples in bash/WSL, using the `BASE_URL` and `BRIDGE_API_TOKEN` selected above. The guards stop if either value is missing:

```bash
: "${BASE_URL:?Select a deployment first}"
: "${BRIDGE_API_TOKEN:?Load the matching operator token first}"

curl -sS "$BASE_URL/health"
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  "$BASE_URL/api/pairs"
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  "$BASE_URL/api/pairs/csv-export"
```

The preceding commands read existing evidence. The following command is effectful and can process every eligible linked pair; run it only for an explicitly authorized sweep:

```bash
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  "$BASE_URL/api/sync"
```

In a live configuration, `sync` can update both vendor accounts. `resolve` reads vendor records but does not write them; the next sweep evaluates its saved plan, including the approval gate. `approve` also reads both records but defers all effects to the next sweep. `link` and `retry` mutate local state only. Repeating link creation returns 409, and repeated source choices/approvals can be invalid after state changes. No client idempotency-key store is implemented.

## Internal comment delivery

The [comment command](COMMENTS.md) copies linked GitHub issue comments as internal Zendesk notes. Live mode and `BRIDGE_GITHUB_COMMENTS_ENABLED=1` are required; disabled/local mode returns 503. No text, public flag, identity, source URL or approval can be supplied in the body. HTTP 200 reports per-pass `discovered`, `delivered`, `waiting`, `unavailable` and boolean `held`; it does not imply every note was delivered. A lost append response is uncertain, not a reason to issue another append. Busy canonical work returns 409.

`GET /api/comment-deliveries` constructs no provider. It exposes immutable sensitive note snapshots and delivery evidence in `items`, plus discovery errors/schedules in `streams`. Both collections use the same bounded limit/offset independently; `total` and `stream_total` are separate counts. `privacy_hold` records the subsystem-wide internal-only evidence hold. The metrics response adds five fixed `comments` counts, numeric `comment_stream_errors` and boolean `comment_privacy_hold`. New appends wait for field convergence with no pending plan/conflict/approval; a comment never supplies state approval. Swagger submission remains disabled.

## Initial description in intake evidence

New issue creation includes a freshly read initial Zendesk description as a one-time literal-text body section, plus an explicit Zendesk ticket link and the existing recovery marker. Intake inspection may expose `snapshot.initial_description` (up to 30,000 UTF-8 bytes); this sensitive field is optional for legacy and discovery-only snapshots. Existing attempted evidence is not rewritten. The operator cannot submit description text, URLs or an arbitrary body through the intake endpoint; source reads supply it. Description updates and existing-issue backfill are not new API capabilities. See [intake data contract](INTAKE.md#initial-description-and-issue-body).

## Engineering intake

`POST /api/intake` dispatches the same typed command as `php bin/bridge.php intake` and the worker. It accepts exactly `{}`; clients cannot supply a provider URL, ticket ID, credentials, tag or approval. Live mode and `BRIDGE_ZENDESK_INTAKE_ENABLED=1` are required, otherwise it returns 503. It uses the canonical worker lock and may return 409 when busy.

HTTP 200 returns `discovered`, `linked`, `waiting`, `skipped`, `truncated` and `deferred`. These describe this pass, not the full ledger. A rate-limit/cooldown or held uncertain creation can return 200 without creating an issue. An unavailable discovery request returns 502 and persists a cooldown. Inspect `/api/escalations` for durable prepared/creating/uncertain/linked/blocked/withdrawn records and `/api/pairs/{id}` for reconciliation evidence. The listing query never constructs providers. Pagination is the same bounded limit/offset contract as pair listing. Its title snapshots, vendor IDs and marker are sensitive operator data.

The operator metrics response now includes `intake`, with all six fixed state counts. The separate text exporter publishes the same aggregate states without per-ticket labels. [Intake operation](INTAKE.md) covers tag opt-in, recovery and limits. A timeout after POST is an unknown outcome; never clear its durable attempt and resend to make progress.

## Deterministic governance and the operator gate

**Never rely on AI in compliance.** An AI recommendation, confidence score, generated rationale or prompt instruction cannot authorize an effect or override a fixed rule. This application calls no LLM. Its PHP policy `escalation-state-v1` allows title-only updates within an explicitly linked pair and requires an operator attestation for any planned outbound open/closed transition. A mixed title/state plan waits as a whole before its first effect. Both reopening and closing require approval. A plan already fully achieved can be acknowledged without another write.

These are operational controls, not a legal or regulatory compliance decision. The customer must determine its obligations, data-sharing permissions, reviewers and permitted actions. The shared bearer token represents one trusted operator identity; it cannot prove that the caller is human, establish a distinct approver or provide separation of duties. A future AI-facing surface must not receive the approval capability. Production use would require identity-aware authorization and an independently governed review path.

For a state change, first run a sweep and inspect the pair. `awaiting_approval` means the durable plan exists and no effects of that plan have been sent. Inspection always includes `governance` with `policy_version`, `plan_hash`, `requires_approval` and `approved`; `pair.plan` contains the target, both preimages and versions. `pair.approval` is null until attestation. Review that evidence and the intended business outcome before submitting a rationale.

```bash
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  "$BASE_URL/api/sync"
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  "$BASE_URL/api/pairs/csv-export"

# After reviewing the displayed plan, paste its exact governance.plan_hash.
# The hash identifies evidence; it is not a credential.
REVIEWED_PLAN_HASH='paste-the-64-character-hash-you-reviewed'
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' \
  --data "{\"plan_hash\":\"$REVIEWED_PLAN_HASH\",\"reason\":\"Reviewed the linked escalation and confirmed this resolution should propagate.\"}" \
  "$BASE_URL/api/pairs/csv-export/approve"
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  "$BASE_URL/api/sync"
```

`approve` accepts exactly `plan_hash` and `reason`, both strings. The hash must be 64 lowercase hexadecimal characters. The rationale must be valid UTF-8, nonempty after trimming and at most 500 bytes; do not put secrets or unnecessary customer data in it. The application records the policy version, hash, rationale, `shared-operator` principal and approval time locally before effects. Supplied `actor`, `ai_approved`, role or override fields are rejected. Approvals bind the pair identity, target, both preimages/versions and policy version. They do not authorize future plans.

Approval acquires the canonical worker lock and re-reads both records. A mismatched hash or stale snapshot returns 409; inspect again and resolve refreshed conflicts before another decision. Execution rechecks evidence too: a later remote edit invalidates authorization rather than being overwritten using an old approval. Recovery still acknowledges completed effects without blindly replaying them. `retry` and `resolve` never grant approval. A client timeout is uncertain: inspect the persisted attestation/status before deciding whether another command is needed.

## Outcomes, errors and concurrency

HTTP 200 from a sweep means processing completed. Inspect the returned `synced`, `retry`, `conflict`, `dead`, `awaiting_approval` and `skipped` counts; a successful HTTP request can include business failures or a review gate requiring attention. The sweep's `awaiting_approval` count records newly gated plans; plans already waiting are skipped. Use pair listing or `/api/metrics` for the full persisted queue. An HTTP/client timeout does not prove the process stopped or remote writes failed. Inspect persisted state before retrying. There is no asynchronous queue, cancellation protocol or bounded per-sweep link limit.

All error responses use `error.code`, `error.message` and server-generated `error.requestId`, also returned in `X-Request-Id`. Responses do not expose exception messages, tokens, SQL statements or raw provider bodies. Structured error logs correlate the stable category with that identifier. Metrics deliberately exclude request IDs and record identifiers. A separate strong `BRIDGE_METRICS_TOKEN`, different from the operator token, authorizes only the text exporter; it cannot read snapshots or issue operator commands.

| Status | Meaning |
| --- | --- |
| 400 | Malformed JSON/non-object body |
| 401 | Invalid/missing bearer credentials |
| 404 | Endpoint or linked pair missing |
| 405 | Unsupported method; `Allow` names supported methods |
| 409 | Duplicate link, stale source choice/approval, invalid operator state, concurrent remote change or busy worker |
| 413 / 415 / 422 | Body size / media type / field or query contract violation |
| 500 | Sanitized storage/internal failure |
| 502 / 503 | Permanent/transient provider failure during an operator command, or incomplete deployment configuration |

Worker-busy 409 responses include `Retry-After: 1`; a retry still has to acquire the lock. This is distinct from vendor rate limits, whose delays are persisted by the engine. API ingress has no separate rate limiter. `Cache-Control: no-store`, `nosniff`, no-referrer and a restrictive documentation CSP reduce accidental snapshot caching and external requests. There are no cookie sessions, CORS grants, roles or tenant boundaries.

## Contract checks

```bash
php tests/api.php
php tests/api-server.php
```

The integration suite exercises real SQLite/local provider state, including plan review, exact-field validation, approval freshness at decision and execution, worker exclusion and approval surviving lost-response recovery. The server suite verifies real loopback HTTP, routing, headers, assets and synthetic response fixtures, including pending and approved plans. Neither contacts vendor accounts. The PHP contract assertions intentionally implement only the schema subset used by those tests.

For standards-based OpenAPI validation with the locked development dependencies:

```bash
cd tools/api-docs
npm ci --ignore-scripts
node validate.cjs
```

The development validator resolves only the local document's internal references, then checks the actual wire-test response fixtures using JSON Schema 2020-12, including the Prometheus text response. Run the wire suite first to generate those synthetic fixtures. It does not send the specification or fixtures to an online service. The Node toolchain is separate from the PHP runtime.

## Grafana table context

`GET /monitoring/pairs` requires the strong read-only monitoring bearer, rejects query parameters and accepts no writes. It returns `{ "items": [...] }` with the complete link set and no pagination: string record numbers, nullable last verified subject, numeric workflow/resolution/plan/attempt/note fields, convergence in milliseconds and nullable fixed HTTPS source URLs. Unknown subjects and local-fixture URLs remain null. `GetLinkedPairTable` and its handler build each response from one consistent business snapshot without provider construction/reads, synchronization or approval. `MonitoringController` performs HTTP authentication and dispatch; it does not construct the view. No internal pair key, note text, plan, marker, rationale or credential is returned. The protected response uses no-store; errors are sanitized.

This extends monitoring-read authority to all linked baseline subjects and configured provider links. Grafana's pinned Infinity backend uses the server-held token and API-only allowed host. Grafana viewers have no per-pair authorization, and administrators can configure datasource queries. Keep the operator token separate. Prometheus `/metrics` continues to exclude subjects and URLs; table context is not a historical subject query and cannot establish current vendor state. Click-through uses the viewer's own provider login.
