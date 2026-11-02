# C4 architecture and state

This optional pack shows the implemented single-host system. [Architecture](../ARCHITECTURE.md) owns design, [operations](../OPERATIONS.md) owns procedures and [decisions](../ASSUMPTIONS.md) owns authority/limits.

We use the [C4 model's four zoom levels](https://c4model.com/diagrams) with typed Mermaid elements; state machines are a behavioral supplement. A C4 container means an application/data store: SQLite files are not database servers, and worker components are not extra services. Functional roles do not establish distinct authenticated people.

## L1: system context

![Support, engineering and operator interactions](images/01-context.svg)

[Source](source/01-context.mmd). Support tags reports; engineering investigates; operators explicitly choose conflict sources and approve state plans.

## L2: workflow containers

![API, worker and CLI with their stores and vendors](images/02-containers-workflow.svg)

[Source](source/02-containers-workflow.mmd). API, worker and CLI share handlers/state/lock. Queries and browser GETs are provider-free; decision POSTs only read vendors and queue work.

## L2: monitoring and credentials

![Grafana, Prometheus and isolated credential renewal](images/03-containers-operations.svg)

[Source](source/03-containers-operations.mmd). Grafana's current table reads protected JSON; Prometheus supplies history. The fixed session verifier keeps operator authority server-side. Renewal has no business-state access.

Host bindings are loopback: API/review **1988 → 8080**, Prometheus **1989 → 9090**, Grafana **1990 → 3000**. Worker/renewer expose no host ports. Bootstrap/importers are one-off operations.

## L3: worker components

![Typed dispatch into intake, fields and two conversation directions](images/04-components-worker.svg)

[Source](source/04-components-worker.mmd). One PHP process orders intake, fields, forward comments and reverse notes. Their distinct recovery contracts share injected infrastructure.

## L4: reconciliation code

![Engine, pure policies and injected provider/repository contracts](images/05-code-reconciliation.svg)

[Source](source/05-code-reconciliation.mmd). This selected class view includes conflict-snapshot identity. No global connection Singleton or separate read database is introduced.

## Field states

![All six persisted field states and actual transitions](images/06-state-reconciliation.svg)

[Source](source/06-state-reconciliation.mmd). Persisted states: `new`, `synced`, `retry`, `conflict`, `dead`, `awaiting_approval`. Evaluation/skipped are transient outcomes. Source choice and approval remain separate.

## Intake states

![All six intake states with unknown-create recovery](images/07-state-intake.svg)

[Source](source/07-state-intake.mmd). States: `prepared`, `creating`, `uncertain`, `linked`, `blocked`, `withdrawn`. Attempted creation never blindly repeats.

## Conversation states

![Five delivery states in each independent direction](images/08-state-conversations.svg)

[Source](source/08-state-conversations.mmd). Each ledger has `pending`, `sending`, `uncertain`, `delivered`, `blocked`. Unknown appends recover through unique read-back.

## Storage and independent gates

| Retained volume | Owner/state |
| --- | --- |
| app-data | Shared live.sqlite/lock; separate metrics.sqlite and worker heartbeat |
| operator-secret | Broad operator bearer |
| metrics-secret | Distinct monitoring-read bearer |
| grafana-secret | Bootstrap password/signing secret |
| grafana-data | Accounts, sessions and settings |
| prometheus-data | Seven-day default numeric history |
| vendor-secret | Startup tokens; atomic managed Zendesk bundle/heartbeat |
| issuer-secret | Client configuration; importer writes, renewer alone reads |

The [schema](../../src/Infrastructure/Persistence/SchemaMigrator.php) has `pairs`, `events`, `settings`, `escalations`, `comment_deliveries`, `comment_streams`, `note_deliveries`, `note_streams`.

Independent gates are not extra row states: configuration opt-ins; reverse `initialized_at`; shared durable privacy hold; exact-plan approval; expiring browser proof; discovery cooldown; OAuth bundle/heartbeat health. Unknown/never-verified is absence, not success. Renewal heartbeat statuses are current/renewed/renewal_failed; readers require an unexpired tenant-bound bundle, and renewal also checks client binding.

Only the renewer reads issuer configuration, but its shared vendor volume also exposes the unused GitHub token. Events/archives are mutable. No diagram, dashboard, model or playback grants authority or proves compliance.

## Maintain

Edit corresponding `source/*.mmd`, regenerate SVGs and inspect them against code/schema/Compose. Mermaid CLI 11.12.0/Mermaid 11.17.2 with Linux Chromium was used outside the runtime image.

~~~bash
for diagram in docs/c4/source/*.mmd; do
  mmdc -i "$diagram" -o "docs/c4/images/$(basename "$diagram" .mmd).svg" -b white -w 1800
done
node tools/docs/check.cjs
~~~

Select a local Puppeteer/Chromium configuration if needed; keep machine paths private. Markdown checks validate links/fences, not rendering or image freshness. Never alter persisted/vendor evidence to match a diagram.
