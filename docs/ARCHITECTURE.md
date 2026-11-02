# Architecture

Support starts in Zendesk. An explicit escalation tag creates one GitHub issue and an exact one-to-one mapping. Shared fields and separately enabled conversations then reconcile that handoff.

## Components

~~~mermaid
flowchart TB
    Adapters["Worker, CLI, HTTP and browser review"] --> Commands["Typed commands"]
    Adapters --> Queries["Typed queries"]
    Commands --> Workflows["Intake, field engine and append workflow"]
    Workflows --> Policies["Pure merge, governance and retry policies"]
    Workflows --> Providers["Narrow provider capabilities"]
    Providers --> Vendors["GitHub and Zendesk"]
    Workflows --> Store[("Scoped SQLite connection, repositories and lock")]
    Queries --> Store
~~~

| Boundary | Responsibility |
| --- | --- |
| Application | Compose dependencies; dispatch synchronous commands/queries |
| Domain | Pure merge, retry, approval and conflict-evidence identity |
| Engine | Read, plan, authorize, patch and verify fields |
| Intake / Comments | Distinct durable creation and append contracts |
| Infrastructure | Scoped PDO connection, schema, transactions, locks, audit and OAuth |
| HTTP / Console / Runtime | Authentication, representation and process lifecycle |
| Observability | Provider-free current table and numeric history |

PHP follows PSR-4/PSR-12 with focused injected dependencies. Queries create no providers/workflows. Commands and queries share one business database: no queue, event sourcing, separate read database or process-global Singleton.

## Fields and reconciliation

| Canonical field | GitHub | Zendesk |
| --- | --- | --- |
| title | Title | Subject |
| open | Open | New, Open, Pending, Hold |
| closed | Closed | Solved, Closed |

A title-only patch preserves native status. State writes use Zendesk Open/Solved; irreversibly Closed tickets are immutable.

Compare each field against the last verified common baseline. Equal values require no write; one-sided edits propagate; independent fields combine. Initial disagreement or divergent same-field edits stop the whole pair. Timestamps guard freshness rather than choose winners.

~~~mermaid
flowchart TB
    Read["Read current sources"] --> Decide["Merge or validate durable target"]
    Decide -->|"Divergent / stale"| Conflict["Conflict: explicit source choice"]
    Decide -->|"Already agrees"| Verify["Verify and advance baseline"]
    Decide -->|"Write needed"| Persist["Persist target, preimages and versions"]
    Persist --> Gate["Writable and fresh authority?"]
    Gate -->|"State approval missing"| Review["Await exact-plan approval"]
    Gate -->|"Allowed"| Write["Audit, patch minimal fields and read back"]
    Write -->|"Agrees"| Verify
    Write -->|"Unknown / temporary"| Retry["Retain intent; read before retry"]
    Write -->|"Different"| Conflict
~~~

Recovery retains the target. A destination already at the complete target needs no resend; one needing a write must retain its recorded version/preimage. Only verified agreement advances the baseline. Conflict source selection chooses the whole supported record and queues work; any resulting close/reopen still needs separate approval.

## Creation and conversations

Intake freshly validates open/tag/description, then commits the immutable snapshot, marker and audited attempt before POST. It remembers the issue ID before linking. An attempted unknown create is searched by exact marker; absence never authorizes another POST.

Two opt-in channels share the append engine and lock but have independent ledgers:

- GitHub comments become internal Zendesk notes.
- Reverse initialization records existing Zendesk IDs without posting. Only subsequent original internal notes become GitHub comments.

Attribution and first-observed text are retained. Opposite-ledger IDs/markers suppress copies without excluding an integration account's genuine replies. Before a new reverse append, re-read source privacy/content. Changed/deleted/public notes cannot replace the snapshot.

New appends wait for fresh field agreement without review work. Unknown attempted appends recover only by unique marked read-back. Observed public-copy evidence durably holds both channels.

## Authentication and monitoring

The JSON API uses a broad operator bearer; monitoring has a separate read-only token. The browser adapter verifies only the selected project cookie against fixed internal Grafana and allows configured server administrators.

POST forms bind session, action, pair and reviewed evidence; source forms also bind the chosen side. The engine checks the conflict hash under its canonical lock and re-reads vendors. Approval separately requires an exact plan, reason and confirmation. Neither submission writes vendors. GET never selects, approves or reconciles. Exact Host/Origin, same-origin referrer policy and null-origin rejection protect native forms.

Grafana reads verified subjects, fixed links and direction counts from protected JSON. Prometheus supplies seven other panels and numeric history. Telemetry is best effort, separate from authoritative business audit.

The [C4 pack](c4/README.md) owns visual zoom levels/storage/state lifecycles. [Assumptions](ASSUMPTIONS.md) owns trade-offs, authority and known limits; [operations](OPERATIONS.md) owns procedures.
