# Assumptions, authority and limits

These are explicit product decisions and hypotheses. Tests and fictional demonstrations do not establish customer acceptance, compliance or production readiness.

## Customer and data contract

- One B2B support team, one Zendesk tenant and one GitHub repository are our assumed customer context. Measure copying effort, stale handoffs and convergence/attention age before accepting the value hypothesis.
- A canonical-open ticket tagged `support_bridge_escalate` opts into creation. Untagged/resolved reports create nothing. Similar titles never infer identity; removing the tag later does not unlink.
- Mapping is one ticket to one issue. Duplicate mappings, retargeting and many-to-one escalation are unsupported. Manual linking is for verified legacy/recovery records.
- Both teams may edit title and resolution. Neither is globally authoritative. One-sided fields propagate, different fields combine and same-field divergence needs a whole-source operator choice.
- Titles must be nonempty UTF-8, at most 500 bytes. Unsupported values stop without truncation/transliteration. Zendesk New/Open/Pending/Hold map to open; Solved/Closed map to closed. State writes use Open/Solved; immutable Closed tickets cannot be replaced automatically.
- Initial description transfer occurs once at issue creation. Its first comment may be internal; missing/invalid/over-30,000-byte text stops creation. The formatted issue body stays below 62,000 bytes. Existing-body repair requires separate authorization.
- Public replies, attachment bytes, assignee/priority, closure reasons, later description changes and message edits/deletions are outside synchronization. Copies are immutable first-observed snapshots.
- Both destinations must be permitted audiences. Internal notes can be confidential while GitHub can be public. Repository visibility, DLP/redaction, retention and tenant notification rules are not enforced by the bridge; labels/markers are not permission or authentication.

## Authority

| Decision | Authority |
| --- | --- |
| Create issue / transfer title | Fresh explicit tag or mapping opt-in and permitted data |
| Resolve conflicting fields | Explicit fresh whole-source choice; no implied state approval |
| Close or reopen a destination | Approval of the complete exact durable plan |
| Append a message | Enabled direction, eligible original, privacy and fresh field agreement |
| Retry work | Corrected cause and explicit retry; no added approval |
| Accept obligations, data sharing or controls | Accountable customer/policy owners |

Policy `escalation-state-v1` binds approval to identities, target, both preimages/versions and policy. A state write holds the whole plan, including titles. Engineering completion can precede customer verification; conservative separate review is our chosen business rule. Already-matching canonical states need no outbound effect or approval.

Source selection and approval re-read both vendors under the canonical lock and queue work without vendor writes. The worker checks again before effects. Stale evidence cannot overwrite newer edits. Retry, comments, monitoring, playback, skills and models cannot grant approval. Reopening needs a separate plan. Business approvals have no automatic expiry, revocation or rejection command.

JSON API authority is one broad shared bearer. Browser review is opt-in for allowlisted Grafana server administrators; its proof expires after ten minutes and binds session/action/pair/evidence, plus the source side for conflicts. Approval also needs reason/confirmation. A Grafana login is not verified human identity, per-pair RBAC or separation of duties. The read-only review skill is guidance rather than a restricted server credential.

**Never rely on AI in compliance.** Deterministic code also cannot decide whether customer obligations are met. Owners must agree on field ownership, disclosure, approvers, retention, incidents, recovery and acceptance criteria.

## Recovery and operating bounds

| Assumption | Consequence |
| --- | --- |
| Last verified baseline identifies one-sided edits. | Edit-and-revert between observations can be invisible. Timestamps are freshness evidence. |
| Durable targets/preimages/versions survive partial writes. | SQLite cannot transact across vendors. Read before retry; acknowledge achieved targets without resending. GitHub's GET/PATCH race and finite timestamp precision prevent a complete lost-update guarantee. Zendesk safe-update collisions trigger observation again. |
| Required audit precedes effects; telemetry is best effort. | Audit failure stops later effects. Crash-time metrics can be missing and cannot decide correctness. Events/archives are mutable, not tamper-evident audit. Audit retention is unbounded; completed full plan snapshots are not automatically archived. |
| Field work has five failed attempts with exponential delay, bounded jitter and vendor guidance. | Permanent/exhausted work becomes dead; the budget is not a customer-approved outage/on-call SLA. Retry never authorizes a changed plan. |
| Attempted unknown creates/appends are never blindly repeated. | Unique marked read-back is required; absence can remain uncertain even after a known rejection. Lost markers/state or inconsistent restores break recovery assumptions. |
| Intake rotates 50-row pages, at most 20. | Offset movement can delay discovery; over 1,000 accessible tickets needs redesign. Creation lookup is capped at five 100-entry pages. Each pass handles five prepared and five recovery intents. |
| Conversation history is capped at five 100-entry pages; source text at 60,000 bytes. | Incomplete/oversized histories stop visibly. Each direction handles five pending and five recovery deliveries per pass. |
| Forward enablement can include existing original GitHub comments. Reverse starts at its first complete scan. | Existing Zendesk history and public replies are excluded; pause/restart retains the starting point. No reverse reset/backfill exists. Authorship is attribution, not verified role or approval. |
| Pending reverse notes are re-read; copies are recognized by IDs/markers. | Changed/deleted/public content cannot replace a snapshot. A public delivery holds both channels durably, with no automatic reset or retraction; later visibility changes are not continuously detected. |
| Worker waits 60 seconds after each complete pass. | Calls, discovery, work and retry add delay. No latency guarantee, global rate budget or traffic generator. |

## Deployment and evidence

- One host/serial worker shares local SQLite WAL, synchronous FULL and a canonical file lock. There is no distributed lock, horizontal scaling, network-filesystem support, measured capacity/availability target or tested general restore/rollback. Back up consistently with WAL or stop writers.
- State is bound to mode/repository/tenant; identity mismatch refuses reuse. Additive upgrades retain old plans, approvals, ledgers and counters. Mixed restores are unsupported.
- Retain the sole project's eight volumes and loopback API1988, Prometheus1989, Grafana1990. Bootstrap makes no source records; effects are opt-in. Non-root/read-only services narrow access, but host/Docker administrators remain trusted.
- PHP 8.2+ needs PDO SQLite/cURL; deployment uses PHP 8.5. Only official GitHub.com and fixed Zendesk HTTPS hosts are supported. Custom fields/statuses, triggers, scopes and notifications need account-specific verification; other platforms, production ingress/TLS/proxies and rate limiting need validation.
- Credentials are protected plaintext private files, not request input or source. Only the networkless importer writes OAuth issuer configuration; only the renewer reads it. The shared vendor-token volume also exposes the unused GitHub token to the renewer.
- Managed Zendesk grants last four hours. Atomic bundles are read per request under a separate credential lock, without inline write replay. Manual/GitHub startup-token rotation recreates readers. Revocation, host downtime and vendor outages can interrupt access; GitHub does not auto-renew. Full secret-manager/rotation/encryption policy is absent.
- API bodies are limited to 16 KiB; list pages to 100. Pair enumeration remains in memory. HTTP 200 can contain business failures; health means liveness, not valid vendor credentials.
- Monitoring exposes all linked verified subjects/fixed URLs to its audience, without per-pair scope. Nine numeric series per link and seven-day history grow with mappings/churn. Current table, worker and five-second scrape/refresh clocks differ; unknown/never-verified stays explicit. Counters include no-op polls; history is not backfilled.
- Captures are private, identity-bound and sequential, not atomic source snapshots. Capture holds the canonical lock without effects; replay never executes decisions. Archives are mutable, limited to 100 checkpoints/2 MiB and 200-byte labels, and replace neither audit nor backups.
- Grafana bootstrap does not prove current persisted credentials. Preserve accounts/cookie scope and verify actual login after changes. Its fixed API-only monitoring datasource has no vendor/operator authority. Three Prometheus rules have no Alertmanager or recipient configured.
- Dependency scans, CVE enrichment, green CI and reviewed pins have source/coverage limits. A package fixed version is not a repaired parent image. [Security maintenance](../SECURITY.md) owns those procedures; [operations](OPERATIONS.md) owns deployment and verification.
