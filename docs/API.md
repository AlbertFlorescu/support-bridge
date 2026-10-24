# Operator API and Swagger

The API is an internal single-operator surface over the headless application used by the CLI. The same deterministic reconciliation and governance policies run behind both surfaces. See the [assumptions register](ASSUMPTIONS.md) and [OpenAPI 3.1 document](api/openapi.json).

## Start locally

From the repository root in bash/WSL:

```bash
php bin/bridge.php init
export BRIDGE_API_TOKEN="$(php -r 'echo bin2hex(random_bytes(32));')"
export BRIDGE_MODE=local
php -S 127.0.0.1:8080 -t public public/router.php
```

Keep that shell open. Visit [Swagger UI](http://127.0.0.1:8080/docs) or [the JSON specification](http://127.0.0.1:8080/openapi.json). The server is for local demonstration. It binds to loopback, serves only the public router/allowlisted assets, and must not be exposed as a production service.

The Swagger assets are vendored from `swagger-ui-dist` **5.34.0** with their license and a locked development dependency. The UI does not need Node/npm to run. Node is only used to refresh the assets and validate OpenAPI during development. It makes no CDN or remote-validator request. Try it out is disabled so visiting a reference page cannot initiate a synchronization command. The bearer token is never embedded in the HTML, specification, URL or initializer.

## Endpoints

| Method/path | Authorization | Meaning |
| --- | --- | --- |
| `GET /health` | Public | PHP liveness only; no provider or database readiness claim |
| `GET /docs`, `GET /openapi.json` | Public | Static API documentation; no record data |
| `GET /api/pairs?limit=50&offset=0` | Bearer | Bounded metadata page, without title snapshots |
| `GET /api/pairs/{id}` | Bearer | Baseline, pending intent, governance/approval evidence, conflict and recent audit; may contain sensitive titles |
| `GET /api/metrics` | Operator bearer | Local counts and Prometheus storage status |
| `GET /metrics` | Separate metrics bearer | Prometheus text exposition; cannot run operator commands |
| `POST /api/pairs` | Bearer | Link existing numeric issue/ticket IDs; creates no remote record |
| `POST /api/sync` | Bearer | Run one synchronous eligible sweep |
| `POST /api/pairs/{id}/resolve` | Bearer | Read both providers, validate conflict freshness and queue a selected source |
| `POST /api/pairs/{id}/approve` | Bearer | Attest to review of an exact pending state-change plan; re-read both records; no remote write |
| `POST /api/pairs/{id}/retry` | Bearer | Reset retry/dead budget while retaining any durable plan |

All `/api` reads and writes require a configured bearer token of at least 32 non-whitespace characters. Authentication runs before creating an application context or parsing operation bodies. Missing/weak deployment tokens fail closed with HTTP 503. Local/live mode, credentials, provider URLs and state path are process configuration only.

POST bodies must be JSON **objects**, with exactly the documented string fields and `Content-Type: application/json`. Empty-operation bodies are `{}`, not an empty body or array. The limit is 16 KiB. JSON IDs are strings to preserve numeric identifiers exactly. Pair names and IDs are validated separately; there is no mass assignment.

Examples, in another shell with the same `BRIDGE_API_TOKEN` securely available:

```bash
curl -sS http://127.0.0.1:8080/health
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  http://127.0.0.1:8080/api/pairs
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  http://127.0.0.1:8080/api/sync
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  http://127.0.0.1:8080/api/pairs/escalation-1
```

In a live configuration, `sync` can update both vendor accounts. `resolve` reads vendor records but does not write them; the next sweep evaluates its saved plan, including the approval gate. `approve` also reads both records but defers all effects to the next sweep. `link` and `retry` mutate local state only. Repeating link creation returns 409, and repeated source choices/approvals can be invalid after state changes. No client idempotency-key store is implemented.

## Deterministic governance and the operator gate

**Never rely on AI in compliance.** An AI recommendation, confidence score, generated rationale or prompt instruction cannot authorize an effect or override a fixed rule. This application calls no LLM. Its PHP policy `escalation-state-v1` allows title-only updates within an explicitly linked pair and requires an operator attestation for any planned outbound open/closed transition. A mixed title/state plan waits as a whole before its first effect. Both reopening and closing require approval. A plan already fully achieved can be acknowledged without another write.

These are operational controls, not a legal or regulatory compliance decision. The customer must determine its obligations, data-sharing permissions, reviewers and permitted actions. The shared bearer token represents one trusted operator identity; it cannot prove that the caller is human, establish a distinct approver or provide separation of duties. A future AI-facing surface must not receive the approval capability. Production use would require identity-aware authorization and an independently governed review path.

For a state change, first run a sweep and inspect the pair. `awaiting_approval` means the durable plan exists and no effects of that plan have been sent. Inspection always includes `governance` with `policy_version`, `plan_hash`, `requires_approval` and `approved`; `pair.plan` contains the target, both preimages and versions. `pair.approval` is null until attestation. Review that evidence and the intended business outcome before submitting a rationale.

```bash
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  http://127.0.0.1:8080/api/sync
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  http://127.0.0.1:8080/api/pairs/escalation-1

# After reviewing the displayed plan, paste its exact governance.plan_hash.
# The hash identifies evidence; it is not a credential.
REVIEWED_PLAN_HASH='paste-the-64-character-hash-you-reviewed'
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' \
  --data "{\"plan_hash\":\"$REVIEWED_PLAN_HASH\",\"reason\":\"Reviewed the linked escalation and confirmed this resolution should propagate.\"}" \
  http://127.0.0.1:8080/api/pairs/escalation-1/approve
curl -sS -H "Authorization: Bearer $BRIDGE_API_TOKEN" \
  -H 'Content-Type: application/json' -d '{}' \
  http://127.0.0.1:8080/api/sync
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
