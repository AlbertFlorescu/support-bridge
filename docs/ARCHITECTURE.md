# Architecture

Support starts in Zendesk. The explicit escalation tag authorizes issue creation and an exact one-to-one mapping. The bridge then reconciles summary/state and separately delivers enabled conversations.

For the four C4 zoom levels, persistent state ownership and complete workflow lifecycles, see the separate [C4 architecture pack](c4/README.md).

## Components

~~~mermaid
flowchart TB
    Adapters["CLI, HTTP, browser review and worker"] --> Commands["Typed commands and handlers"]
    Adapters --> Queries["Typed queries and handlers"]
    Commands --> Intake["Tagged intake"]
    Commands --> Engine["Reconciliation engine"]
    Commands --> Conversation["Shared append workflow"]
    Intake --> Ports["Narrow provider capabilities"]
    Engine --> Ports
    Conversation --> Ports
    Ports --> Vendors["GitHub and Zendesk"]
    Intake --> Store[("Scoped business SQLite connection")]
    Engine --> Store
    Conversation --> Store
    Queries --> Store
    Engine --> Policies["Pure merge, retry and governance policies"]
~~~

| Boundary | Ownership |
| --- | --- |
| `Application/Context` | Composes constructor dependencies and dispatches shared use cases |
| `Application/Command` and `Application/Query` | Synchronous CQRS; queries construct no vendor providers and run no workflows |
| `Domain` | Pure merge, retry and approval decisions |
| `Engine` | Read, decide, persist intent, authorize, patch and verify |
| `Intake` and `Comments` | Separate creation/append recovery contracts |
| `Infrastructure/Persistence` | Connection lifetime, additive schema upgrades, transactions and canonical locking |
| `Http`, `Console` and `Runtime` | Transport authentication, representation and process lifecycle |
| `Observability` | Provider-free metrics and current-table projection |

PSR-4 maps `Bridge\` to `src/`; the checked-in PSR-12 gate enforces source style. A scoped injected business connection avoids hidden global state. Commands and queries share one database, without a queue, event sourcing or separate read store. Composer and browser tooling are development-only.

## Field contract and merge

| Canonical field | GitHub | Zendesk |
| --- | --- | --- |
| `title` | Issue title | Subject |
| `open` | Open | New, Open, Pending, Hold |
| `closed` | Closed | Solved, Closed |

State writes use Zendesk Open or Solved. A title-only patch omits status, preserving Pending/Hold. An irreversibly Closed Zendesk ticket is immutable.

Each field is compared with the last verified common baseline. Agreement needs no write. A one-sided change propagates; independent changes to different fields combine. First-seen disagreement or divergent edits to one field stop the whole pair. Timestamps guard evidence, not winner selection. Choosing a conflict source rechecks both records and selects the whole observed source record; it does not grant state approval.

~~~mermaid
flowchart TD
    Read["Lock and read current records"] --> Target["Merge fields or validate saved intent"]
    Target -->|"Divergent or stale"| Stop["Retain conflict for source decision"]
    Target -->|"Already at target"| Complete["Verify agreement and advance baseline"]
    Target -->|"Writes remain"| Intent["Persist or retain exact plan"]
    Intent --> Gate["Check writable and fresh authority"]
    Gate -->|"Approval missing"| Hold["Await exact-plan approval"]
    Gate -->|"Allowed"| Write["Audit, patch needed fields and read back"]
    Write -->|"Target verified"| Complete
    Write -->|"Mismatch"| Stop
    Write -->|"Temporary or unknown outcome"| Retry["Keep intent and retry when due"]
~~~

Later passes restart from current reads. A saved plan retains its exact target during recovery. A destination already at the complete target needs no resend. One still requiring a write must retain its recorded version and permitted preimage. Only verified convergence advances the baseline and clears the plan/approval. Five failed field attempts or permanent failures become dead work; retry resets a budget, never authority.

## Creation and conversations

Intake checks a fresh open tagged ticket and initial description, then persists a random marker, immutable creation snapshot and audited attempt before POST. It remembers the observed issue ID before linking. After an unknown outcome it searches for that exact marker; absence does not authorize another POST. Removing a tag later does not unlink records.

Two separately enabled append channels share `CommentWorkflow` but use independent durable ledgers:

- GitHub comments become internal Zendesk notes, including eligible existing original comments.
- The first complete reverse scan records existing Zendesk IDs without posting history. Only subsequent original internal notes become GitHub comments.

Both retain source attribution and immutable text. Opposite-ledger destination IDs and reserved markers suppress copied messages without filtering the integration account's genuine replies. A pending reverse note is re-read before a new append; changed/deleted/public content cannot replace its saved snapshot.

An attempted append is never blindly repeated. Recovery searches for a unique marked destination. New appends wait for fresh canonical field agreement without pending review. Observed public delivery sets a durable hold for both channels. Markers are correlation evidence, not signatures; delivered edits/redactions are not propagated.

## Authentication and monitoring

JSON `/api` requests authenticate with the broad operator bearer before constructing business context. `/metrics` and `/monitoring/pairs` use a distinct monitoring credential. Health and Swagger contain no record data. No arbitrary source URLs or credentials are accepted in requests.

The optional browser adapter verifies the selected project cookie against the fixed internal Grafana `/api/user`. Only explicitly allowed Grafana server administrators may inspect and submit exact-plan forms. The operator secret stays server-side. A POST requires the configured Host/Origin, explicit reason/confirmation and an expiring HMAC bound to session, pair and plan hash. The same `ApprovePlan` command re-reads vendors under the canonical lock; no browser GET reconciles or approves.

Review pages use `Referrer-Policy: same-origin` so native forms preserve Origin. Opaque/null origins remain denied. Session rotation is returned to the browser. This is a local same-host adapter, not general SSO or independent reviewer identity.

Grafana's current table reads protected JSON with last verified subjects, fixed links and each direction's counts. Prometheus scrapes numeric observations and supplies history and rules. Both read stored evidence without vendors or writes. Telemetry uses a separate SQLite registry; best-effort recording cannot decide business correctness.

## Trade-offs and known limitations

A canonical single-host lock coordinates commands but cannot lock vendor users or multiple hosts. Zendesk safe-update stamps help detect races; GitHub retains a GET/PATCH race and finite timestamp granularity. SQLite cannot transact across both services, so partial success and uncertain outcomes remain explicit.

A small binary state model loses native workflow detail. Whole-plan approval and strict version checks can delay unrelated titles or messages. Complete history scans and nine metric series per mapping grow with volume; throughput, rate budgets and production availability are unmeasured.

Account triggers, custom statuses, confidential-content policy, per-user roles, expiring/revocable approvals, encrypted storage and tested restore/rollback need further deployment work. [Assumptions](ASSUMPTIONS.md), [governance](GOVERNANCE.md) and [operations](OPERATIONS.md) define those limits.
