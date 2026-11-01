# Assumptions and operating limits

These are our explicit decisions and hypotheses. Controlled checks and fictional live observations do not establish customer acceptance, complete vendor compatibility or production readiness.

## Customer and data contract

| Decision or assumption | Consequence and validation |
| --- | --- |
| We target one B2B support team using Zendesk and GitHub. | Need and expected reduction in copying/stale handoffs remain hypotheses; measure them before a pilot. |
| Support explicitly tags a canonical-open ticket with `support_bridge_escalate`. | Untagged/resolved tickets create nothing. Similar titles never infer identity. Removing a tag after creation does not unlink records. |
| Mapping is one ticket to one issue in one repository/tenant. | Duplicate mappings are rejected; multiple tickets per issue and retargeting existing state are unsupported. Manual link is reserved for verified legacy/recovery records. |
| Both teams can edit summary and resolution; neither is globally authoritative. | One-sided fields propagate, different-field changes merge, same-field disagreement needs an operator source decision. Ownership must be agreed with the operating team. |
| Titles are nonempty UTF-8 and at most 500 bytes. | Unsupported values stop without truncation, rewriting or transliteration. |
| Zendesk New/Open/Pending/Hold mean canonical open; Solved/Closed mean closed. | The model is intentionally lossy. Title-only patches preserve native status; state writes use Open/Solved. Immutable Closed tickets cannot be edited or automatically replaced. |
| The initial description is transferred once, at issue creation, with a fixed link and marker. | The first comment may be internal. Missing/invalid/over-30,000-byte text stops creation. The issue body remains below 62,000 bytes; later description changes are outside scope. |
| Public replies, attachment bytes, assignee/priority, closure reasons and source edits/deletions do not synchronize. | A copied message is an immutable first-observed snapshot, not a continuously mirrored object. |
| Both destinations and enabled conversation directions are permitted for the data. | Internal notes can be confidential and the repository can be public. Visibility, notification triggers, DLP and retention need owner review; no automatic redaction or audience verification is claimed. |

## Merge, approval and failure

| Decision or assumption | Consequence and enforcement |
| --- | --- |
| A last verified common baseline distinguishes ordinary one-sided edits. | Edit-and-revert between observations can be invisible. Timestamps guard evidence, not latest-writer selection. |
| Different initial records and divergent same-field edits stop the whole pair. | Safe fields and messages can wait. Source selection rechecks observations and chooses the whole source record, never approval. |
| Persisted target, preimages and versions survive partial success and lost responses. | SQLite cannot transact across vendors. Read before recovery; a destination already at the complete target needs no resend. |
| A destination still needing a field write must retain its recorded version. | Even unrelated changes can require review. Timestamp granularity and GitHub's read/PATCH race prevent a complete lost-update guarantee. Zendesk safe-update collisions trigger re-observation. |
| Title-only transfer uses link opt-in; every outbound closing/reopening needs exact-plan approval. | The whole plan waits, including its title changes. This is our chosen policy, not a universal compliance requirement. |
| Approval binds identities, target, preimages, versions and policy. | Fresh reads are required at approval and execution. Previous approvals, retry and source choice cannot authorize a changed plan. No approval expiry/revocation/rejection workflow exists. |
| Five failed field attempts with exponential delay, bounded jitter and vendor guidance fit this slice. | Permanent or exhausted work becomes dead. This is not a customer-approved outage budget, staffed on-call process or automatic notification SLA. |
| Unknown creation and append outcomes must not be blindly repeated. | Persist marker/snapshot/audited attempt first. Recover only through unique read-back; absent evidence after an attempt can remain uncertain even after a known rejection. Marker/state loss or inconsistent restores break recovery assumptions. |
| Required audit is authoritative; telemetry is best effort. | Audit failure prevents subsequent effects. Metrics can omit crash-time observations and cannot decide business correctness. |

## Intake and conversations

| Decision or assumption | Consequence and enforcement |
| --- | --- |
| Low-volume discovery uses rotating 50-row pages, capped at 20 pages. | Offset movement/vendor visibility can delay candidates; more than 1,000 accessible tickets needs redesign. GitHub creation lookup must complete within five 100-entry pages. Five prepared and five recovery intents are processed per pass. |
| Creation performs fresh tag/state/description validation before an audited POST. | An observed issue ID survives a later link failure. Old attempted snapshots/markers are unchanged, and existing bodies are not automatically backfilled. |
| Forward enablement includes eligible existing original GitHub user/bot comments. | Source author is attribution, not verified engineering identity or approval. |
| Reverse enablement starts at the first complete per-pair Zendesk scan. | All existing IDs are excluded; only subsequent original internal notes travel. Public replies, initial reports and recognized copies are excluded. Pause/restart retains the starting point; no backfill/reset exists. |
| Opposite-ledger destination IDs and markers identify copies. | Markers are not signatures. Unknown reserved headers stop discovery; author filtering would suppress legitimate replies from the integration account. |
| New appends require fresh field agreement without review work. | Pending reverse source privacy/content is re-read; changed, deleted or public content cannot replace the saved snapshot. Five pending and five recovery deliveries per pass and five 100-entry history pages bound work; source text is at most 60,000 bytes. Larger histories fail visibly. |
| Observed public delivery holds both directions durably. | The hold cannot undo exposure or continuously detect later visibility changes. Delivered copies are not retracted; there is no automatic privacy-hold reset. |

## Deployment, credentials and browser authority

| Decision or assumption | Consequence and enforcement |
| --- | --- |
| One host and serial worker share a local SQLite business store and canonical lock. | No distributed lock, horizontal workers, network filesystem or measured capacity/availability target. WAL + synchronous FULL relies on host durability; corruption/disk failure and general restore/rollback remain untested. |
| One database is bound to local/live mode, repository and tenant. | Identity mismatch refuses reuse. Additive upgrades preserve old plans, approvals, ledgers and counters; snapshots must include WAL or stop writers. |
| The operating project is `support-bridge-live-demo`, with loopback API 1988, Prometheus 1989 and Grafana 1990. | Retain its eight volumes and historical state. Bootstrap is provider-free; worker/intake/conversation/review flags require deliberate enablement. Local fixtures never import live state. |
| The worker waits 60 seconds after each complete pass. | Vendor visibility, pagination, work and retry time add delay. No latency guarantee, global vendor-rate budget or traffic generator is provided. |
| Runtime uses PHP 8.2+ with PDO SQLite/cURL; deployment checks use PHP 8.3 and Linux Docker/WSL. | No framework or Composer runtime package. Other OSes, PHP-FPM, production ingress, TLS, rate limiting and proxies need validation. Base local mode is not an egress firewall. |
| Official GitHub.com and Zendesk tenant HTTPS hosts are sufficient. | Enterprise/custom API hosts are unsupported. Account scopes, required fields, custom statuses, triggers and notifications need account-specific verification. |
| Credentials are protected private files/volumes, never request input or source. | Configure one value/file source. Non-root/read-only services and separate reader mounts narrow access, but Docker administrators remain trusted and files are unencrypted. No complete secret-manager, rotation or backup policy is supplied. |
| Managed Zendesk renewal uses an isolated issuer and four-hour ticket-only grants. | Client-associated identity and ticket scopes are not per-pair authority. Only the importer writes client configuration and only the renewer reads it; the shared access-token volume also exposes the unused GitHub token to the renewer. |
| Managed bundles are atomic and read per request under a separate credential lock. | Routine renewal needs no reader restart. Manual/GitHub startup-token rotation requires recreation. Client revocation, identity migration, host downtime and vendor outages can interrupt access; renewal never approves or resets work. |
| JSON API authority is one broad shared bearer; monitoring uses a distinct read-only token. | No individual accountability, per-pair RBAC, scoped agent credential or separation of duties. Bodies are limited to 16 KiB; list pages to 100, while pair enumeration remains in memory. Health is liveness; synchronous HTTP 200 can contain business failures. |
| Browser review is opt-in for explicitly allowlisted Grafana server administrators on the same loopback hostname. | The project cookie is verified against fixed internal Grafana; no caller-selected upstream/redirect is accepted. An enabled account compromise can approve plans. Grafana unavailability blocks new browser review, not existing worker operation. |
| Native browser forms use exact Host/Origin, same-origin referrer policy and a ten-minute session/pair/hash proof. | Null/cross-site origins remain denied. Explicit reason/confirmation and the unchanged fresh engine gate are required. Form expiry does not expire an already recorded business approval. A Grafana session is not independent human identity. |
| Grafana bootstrap files initialize new databases only. | Rotated account passwords require actual login verification. Rebuilds preserve accounts. Project cookie names isolate local stacks; monitoring-token rotation recreates Grafana's startup reader. |
| Never rely on AI in compliance. | Models, source text, skills, dashboards and playback cannot decide obligations, choose a conflict winner or supply approval. Accountable owners must accept controls and data-sharing rules. |

## Monitoring, evidence and maintenance

| Decision or assumption | Consequence and validation |
| --- | --- |
| Every stored mapping appears through one consistent provider-free monitoring snapshot. | Grafana viewers can read all verified subjects and fixed links; token scope is not per-pair. Only validated numeric IDs label nine series per link plus a total. Read/query/render cost grows with mappings and historical churn. |
| Current table context and numeric history are separate. | Last baseline subjects/resolution can lag pending edits. Five-second scrape/refresh clocks follow worker observation. Unknown/never-verified data stays explicit; counters include no-op polls, and delivered counts do not continuously recheck privacy. History is not backfilled. |
| A pinned signed Infinity backend and fixed API allowed host support the current table. | Plugin maintenance and datasource authority need review; a rendered row/link is stronger evidence than a raw frame alone. Capacity and multi-tenant monitoring are unvalidated. |
| Captures/replays explain selected observations, not atomic transactions or approval. | Keep archives private and bound to both provider identities. Capture holds the canonical lock without effects; provider/monitoring reads are sequential. Archives are mutable, bounded to 100 checkpoints/2 MiB with 200-byte labels, and cannot replace full audit or backup. |
| Development tools and AI assistance need source/test/observation checks. | Optional artifact/profile analysis can be partial and is not independent validation. PSR/style and test success do not establish customer value, vendor completeness or production readiness. |
| GitHub Actions is CI, with reviewed adoption of visible dependency candidates. | Passing workflows do not enable server-side branch protection. Dependabot/image scans, CVE List/NVD enrichment and bounded Swagger repair have source gaps; a package fixed version is not a repaired parent image. Incomplete inventory retains prior findings; scheduled artifacts are not a permanent audit or response SLA. |

[Architecture](ARCHITECTURE.md), [governance](GOVERNANCE.md), [operations](OPERATIONS.md), [CI](CI.md) and [security maintenance](../SECURITY.md) own the implementation and validation procedures.
