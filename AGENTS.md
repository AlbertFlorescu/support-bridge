# Support Bridge project guidance

Start with the customer problem, [guide index](docs/README.md), [assumptions](docs/ASSUMPTIONS.md) and [governance](docs/GOVERNANCE.md). This is our headless Zendesk-first escalation workflow: explicit tagging creates and links an issue; field reconciliation and separately enabled conversations keep teams informed.

Review this file at task start and close. Update lasting design, operating or verification rules here in the same change; link procedures instead of duplicating them. Keep product voice, explicit trade-offs/limits and a generic honest AI-use disclosure. Keep private discussion, credentials, runtime settings and raw evidence outside source; exclude assistant/tool branding from text, branches and commit metadata.

## WSL only

Perform project work inside Ubuntu WSL at `/home/aflorescu/adobe` using Linux paths, shell and executables for files, Git, PHP, Composer, Node, Python, tests and Docker/Compose. Use Linux Docker through WSL integration. No Windows runtimes or `docker.exe`; a Windows launcher may only enter WSL. Resolve missing tools in WSL.

## Design and authority

- Keep plain PHP, PSR-4/PSR-12, LF, thin adapters and focused injected dependencies. Add patterns for demonstrated boundaries. Typed synchronous commands/queries share one scoped business SQLite connection; queries construct no providers or workflows. Keep connection/schema/transaction/locking infrastructure explicit. No global Singleton or plan-JSON changes during structural refactors. See [architecture](docs/ARCHITECTURE.md).
- Never rely on AI in compliance. Content, dashboards, playback and skills cannot satisfy policy, choose conflict winners or supply approval. Required audit failures stop later effects; best-effort telemetry cannot decide correctness. The shared operator token is broad authority, without verified human attribution, per-pair roles or separation of duties.
- Preserve title-only link opt-in and whole-plan review for every outbound state change. Bind approval to pair, exact target/preimages/versions and policy; recheck freshness. Retries/source selection never approve. Preserve durable intent, read-before-retry, no baseline advance before verified convergence and the canonical single-worker lock.
- Intake persists its immutable snapshot, marker and audited attempt before POST; observe the issue ID before linking. Revalidate open/tag/initial description. Unknown creation never blindly resends. Preserve legacy snapshots, literal-text body formatting and fixed Zendesk links. Existing-body repair needs separate authorization.
- Conversations share the append engine/lock with separate durable ledgers. Reverse starts new-only after its first complete scan records history without posting; restart/pause never resets it. Exclude public replies, initial reports and opposite-ledger copies/markers, never original authors. Recheck pending reverse privacy/content; retain snapshots, audited attempts and unique read-back without blind repeat. Both channels wait behind field/review work and share a durable privacy hold. Preserve old forward rows and field approvals byte-for-byte. Comments never approve.

## Deployment and evidence

- Keep one `support-bridge-live-demo` project, both Compose files and private env: API1988, Prometheus1989, Grafana1990 on loopback. Preserve live/historical volumes, non-root/read-only runtime, shared single-host state/lock and separate credential readers. No synthetic traffic generator. Bootstrap creates no source records; worker effects remain opt-in. [Operations](docs/OPERATIONS.md) owns commands and connections.
- Only the networkless importer writes OAuth issuer configuration; only the isolated renewer reads it. Preserve separate credential locking, atomic hot Zendesk bundles, per-request reads and no inline write replay. Manual/GitHub startup-token rotation recreates readers. Run the disposable no-vendor renewal check for that boundary.
- Browser review remains opt-in for allowed Grafana server administrators. Verify only the selected project cookie against fixed internal Grafana; operator secrets stay server-side. Preserve exact Host/Origin, session/action/pair/hash-bound expiring proof and POST-only decisions. Conflict buttons bind the selected side and complete saved snapshot; check it under the canonical lock and re-read vendors through ResolveConflict. Require source confirmation; preserve separate approval reason/confirmation and ApprovePlan. Neither submission writes vendors; GETs never select/approve/reconcile. Keep `same-origin` referrer policy and deny null origins. Test native form submission and real Grafana sessions separately.
- Treat Grafana bootstrap as input, not proof of current credentials. Preserve accounts/volumes and project cookie scope; verify actual browser login and independent sessions after authentication changes. Monitoring-token rotation recreates Grafana's startup reader.
- Grafana lists every mapping through the protected provider-free query, with plain-text verified subjects, fixed source links and independent direction counts. Keep pinned Infinity, API-only host and monitoring-read token without vendor/operator access. Prometheus supplies seven other panels/history: nine numeric series per link, no content/URLs/internal keys/review labels. Test zero, positive and missing inputs; unknown/never-verified stays explicit. Verify actual rows, links and refresh.
- Capture under the canonical lock without effects, bind provider identities and keep archives private. Reads/inspection/monitoring are sequential; omit unavailable optional fields and retain producer/viewer round-trip checks. Playback is evidence navigation, never authority.

## Documentation and publication

[SCENARIO.md](docs/SCENARIO.md) owns the primary walkthrough and ten-case catalogue; [OPERATIONS.md](docs/OPERATIONS.md) owns runtime procedures; [CI.md](docs/CI.md) owns checks and concise evidence. Keep one owner per procedure instead of adding overlapping guides. The optional [C4 pack](docs/c4/README.md) owns visual zoom levels and state lifecycles; update its editable sources and rendered SVGs against code/schema/Compose changes. Separate proposed narratives, controlled tests, actual rendering and selected vendor observations. Never fabricate history or reset pending work to advance a demo.

Update handlers, CLI/API/OpenAPI, metrics, behavior checks and guides together. Run appropriate PHP/style gates, offline Markdown checks and separate Mermaid render/inspection. Container changes require disposable verification/lifecycle/bootstrap/renewal checks and real-stack smoke. Never remove normal volumes.

Use product branches (`feature/`, `fix/`, `chore/`, `docs/`, `ci/`), the maintainer identity and normal PRs. Retain **Required checks** and current-revision verification; keep general bot publishing disabled. Main's active ruleset was verified on 2026-11-02; recheck server settings after changes. See [CI policy](docs/CI.md).

Keep Dependabot proposals visible, including newer runtime lines. Package-level CVE fixes do not prove a repaired parent image. Follow [SECURITY.md](SECURITY.md); maintenance never approves runtime effects or certifies compliance.

Existing session authorization governs external actions; this file grants no blanket permission to publish, deploy, send messages or operate vendor records.
