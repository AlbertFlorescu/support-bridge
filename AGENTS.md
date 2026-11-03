# Support Bridge project guidance

Start with the customer problem, [guide index](docs/README.md) and [assumptions/authority](docs/ASSUMPTIONS.md). This is our headless Zendesk-first escalation workflow. Review this file at task start and close; update lasting rules with the change, not a task diary. Keep product voice, explicit limits and honest generic AI disclosure. Private discussion, credentials, settings and raw evidence stay outside source; exclude assistant/tool branding from text, branches and commit metadata.

## WSL only

Work inside Ubuntu WSL at `/home/aflorescu/adobe` with Linux paths, shell, Git, PHP, Composer, Node, Python and Docker/Compose through WSL integration. No Windows runtimes or `docker.exe`; a Windows launcher may only enter WSL. Resolve missing tools there.

## Design and authority

- Keep plain PHP, PSR-4/PSR-12, LF, thin adapters and focused injection. Patterns must serve demonstrated boundaries. Typed synchronous commands/queries share one scoped business SQLite connection; queries construct no providers/workflows. Keep schema, transactions and canonical locking explicit. No global Singleton or persisted plan-JSON changes during structural refactors.
- Never rely on AI in compliance. Models, content, skills, dashboards and playback cannot choose conflict winners, supply approval or satisfy policy. Shared operator credentials do not identify verified humans, per-pair roles or separation of duties. Required audit failures stop effects; telemetry cannot decide correctness.
- Preserve title-only link opt-in and whole-plan review for every outbound state change. Bind approval to pair, exact target/preimages/versions/policy and recheck freshness. Source choice/retry never approve. Persist intent before effects, read before recovery and advance the baseline only after verified convergence.
- Intake keeps immutable snapshots/markers/audited attempts, observes issue ID before linking and freshly revalidates open/tag/description. Unknown attempted creation never blindly resends. Preserve legacy evidence, literal descriptions and fixed Zendesk links; existing-body repair needs separate authorization.
- Conversations share the engine/lock but keep separate durable ledgers. Reverse starts new-only after a complete history scan; pause/restart never resets it. Exclude public replies, initial reports and recognized copies, never original authors. Recheck pending reverse privacy/content, retain snapshots/audited attempts and recover by unique read-back without blind repeat. Both channels wait behind fields/review and share the durable privacy hold. Preserve old forward rows and approvals byte-for-byte.

## Deployment and evidence

- Retain one `support-bridge-live-demo` project, both Compose files/private env and all live/historical volumes. Loopback API1988, Prometheus1989, Grafana1990. Preserve non-root/read-only runtime, separate readers and single-host state/lock. No traffic generator; bootstrap creates no source records and effects stay opt-in.
- Only the networkless importer writes issuer configuration; only the isolated renewer reads it. Preserve separate credential lock, atomic hot Zendesk bundles/per-request reads and no inline write replay. Manual/GitHub startup-token rotation recreates readers. Run disposable no-vendor renewal checks.
- Browser review is opt-in for allowed Grafana server administrators verified against fixed internal Grafana/project cookie. Keep operator secrets server-side, exact Host/Origin, same-origin referrer policy and null-origin rejection. POST proofs bind session/action/pair/evidence; conflict buttons also bind side/complete snapshot checked under the canonical lock before fresh ResolveConflict reads. Preserve separate reason/confirmation and ApprovePlan. Neither submission writes vendors; GETs never select/approve/reconcile. Test native forms and real Grafana sessions separately.
- Grafana bootstrap is not proof of current credentials. Preserve accounts/cookie scope; verify actual and independent sessions after auth changes. Monitoring-token rotation recreates its startup reader.
- Enumerate every mapping through protected provider-free monitoring queries: verified plain-text subjects, fixed links and independent counts. Preserve pinned API-only Infinity/read-only credential and the Prometheus/Infinity data-source allowlist; unused Grafana backends stay removed/disabled. Prometheus supplies seven other panels/history and nine numeric series/link without content/URLs/internal keys/review labels. Test zero/positive/missing data; verify actual rows, links and refresh.
- Capture privately under the canonical lock without effects; bind provider identities, read sources/monitoring sequentially, omit unavailable optional fields and retain producer/viewer round trips. Playback supplies no authority.

## Documentation and publication

[Architecture](docs/ARCHITECTURE.md) owns design; [assumptions](docs/ASSUMPTIONS.md) owns authority/limits; [operations](docs/OPERATIONS.md) owns runtime/checks/publication; [scenario](docs/SCENARIO.md) owns the walkthrough/catalogue. Keep one owner per procedure. The optional [C4 pack](docs/c4/README.md) owns editable diagrams/rendered SVGs and state lifecycles; update against code/schema/Compose. Separate narratives, controlled tests, rendering and vendor observations. Never fabricate history or erase pending work to advance a demo.

Update affected handlers, CLI/API/OpenAPI, metrics, behavior checks and guides together. Run appropriate PHP/style, offline Markdown and separate Mermaid render/inspection. Container changes require disposable verification/lifecycle/bootstrap/renewal plus retained-stack smoke. A controlled worker pause must exit gracefully and restore its prior running state.

Review the staged diff before committing and preserve unrelated local changes. Use product branches (`feature/`, `fix/`, `chore/`, `docs/`, `ci/`), maintainer identity and normal PRs. Keep Required checks/current-revision verification and general bot publishing disabled. Recheck server protection after administrative changes. Dependabot proposals include newer runtime lines; package fixes do not prove repaired parent images. Dependency adoption must verify rebuilt inventories, distro patches, Swagger assets and retained-volume upgrades; keep unresolved upstream findings visible. Follow [SECURITY.md](SECURITY.md).

Existing session authorization governs external actions. This file grants no blanket permission to publish, deploy, send messages or operate vendor records.
