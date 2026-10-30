# Recorded workflow viewer

Render timestamped Support Bridge evidence as a self-contained, offline HTML replay:

```bash
node tools/demo-viewer/render.cjs --input var/guided-run/evidence.json --output var/guided-run/replay.html
node --test tools/demo-viewer/viewer.test.cjs
```

No installation or third-party package is needed. The private package manifest defines a CommonJS boundary and an optional `npm test` shorthand; it has no dependencies. On Windows UNC workspaces, run Node from a normal Windows directory and pass the renderer's absolute path. If Node's `--test` file discovery cannot handle the UNC share, invoking `viewer.test.cjs` directly runs the same built-in tests.

## Evidence interface

The input has `version: 1`, `run` metadata and between one and 250 chronological `checkpoints`. Its maximum size is 2 MiB. The renderer accepts at most 250 checkpoints; the producer is deliberately narrower.

Our capture command writes at most 100 checkpoints per archive. Labels have a 200 UTF-8-byte limit and operation descriptions a 500-byte limit. The archive binds the run ID, mode, repository and Zendesk subdomain; a different deployment needs a new output file. See [the capture procedure](../../docs/LIVE_DEMO.md#capture-and-render-actual-observations).

| Field | Captured content |
| --- | --- |
| `id`, `label`, `operation`, `observed_at` | Unique identifier, human-readable operation and ISO timestamp with timezone |
| `pair` | Pair/linked numeric IDs, status, failed attempts, retry due time, baseline, nullable plan/conflict/approval |
| `records.github`, `records.zendesk` | `kind` (`live` or `local`), matching record ID, title, canonical state, observed version, writable flag and optional provider URL |
| `governance` | Policy version, nullable exact-plan hash, requires-approval flag and approved flag |
| `events` | Up to 200 event type/timestamp entries with allowlisted operational details |
| `metrics` | Optional `up` (0 or 1), pair counts, pending-plan count and reconciliation counters |

`run` requires an identifier, `mode` (`local` or `live`) and `captured_at`. It may include a source commit digest, `owner/repository` name and `monitoring.grafana_url` / `monitoring.prometheus_url`.

The private producer also records `run.zendesk_subdomain` to prevent mixing tenants. The renderer excludes that metadata, but optional ticket URLs still expose their tenant and record ID. This is private presentation tooling, not a data-redaction guarantee.

Plans retain `target`, `before.github` / `before.zendesk` and both `versions`. Fields contain only `title` and `state` (`open` or `closed`). Conflicts retain a fixed reason code, field names, both `observed` field sets and their `versions`. Approvals retain only `plan_hash`, `policy_version`, `approved_at` and the fixed `shared-operator` principal when present.

`metrics.pairs` accepts a nonnegative numeric total or a map using the fixed workflow statuses. `metrics.reconciliations_total` accepts a numeric total or fixed outcome map, including `skipped`. Missing observations display “Not captured”; zero is retained as a real observation. Scraped aggregates can lag the inspected pair's state and include other pairs; they are not a simultaneous snapshot. No metric is inferred from the narrative.

## Read-only and data boundaries

Play, Next, Previous and checkpoint navigation only select embedded observations. The replay performs no API calls, source selection, retries or approvals. Captured provider records and the application run mode are labelled separately. A link to a live record is not proof that this run synchronized it. External monitoring links show current dashboards independently of the captured checkpoint.

The renderer rebuilds an allowlisted model rather than embedding the original input. Unknown fields, raw vendor responses, arbitrary approval rationale, unstructured messages and individual principal names are omitted. Event details retain only fixed side/source values, field names, plan hash, policy version, fixed authorization values, numeric retry metadata and known reason codes. Captured events preserve insertion order within the same timestamp second. A nonempty filtered failure displays “Present; details omitted” instead of claiming there was no error. Generic credential patterns and email addresses are rejected in retained text. These checks cannot classify every sensitive value: the producer must supply only owned synthetic or explicitly permitted evidence and keep credentials out of titles and labels.

Provider links must use HTTPS and match the captured issue/ticket ID on `github.com` or a normal `zendesk.com` tenant. User information, query strings, fragments and arbitrary schemes are rejected. Monitoring links must use loopback HTTP; Grafana accepts dashboard paths and a bounded set of time/refresh query fields. No credentials are accepted in links.

Embedded JSON escapes `<` and Unicode line separators. The browser uses DOM `textContent` and a hash-based Content Security Policy with network connections and form submission disabled. Generated files still contain captured titles and operational evidence. Store them in private runtime/artifact directories outside source, and review them before sharing.

The renderer writes output atomically and never overwrites the input file. Invalid JSON, invalid schema and oversized input fail without replacing an existing replay. Error messages omit input contents.

## Verification

The replay is separate from the live Grafana table. The table queries current stored context directly; this viewer renders only the selected historical checkpoints and never fills a gap from current data.

The built-in tests cover retained plan/conflict evidence, credential and identity exclusions, script-breaking values, URL restrictions, bounds/chronology, navigation without network effects and CLI success/failure behavior. A DOM test verifies playback using captured evidence; it does not establish live vendor compatibility or human authorization.

The viewer is presentation tooling. Business correctness, exact-plan governance and recovery remain in the application; the primary narrative and execution boundaries are documented in [SCENARIO.md](../../docs/SCENARIO.md).
