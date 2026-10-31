---
name: support-bridge-comments
description: Implement, inspect or operate bidirectional GitHub comments and new Zendesk internal-note delivery, preserving attribution, origin suppression and uncertain-outcome recovery.
---

# Conversation delivery

Read [comments](../../docs/COMMENTS.md), [governance](../../docs/GOVERNANCE.md) and [AGENTS.md](../../AGENTS.md). Work only inside Ubuntu WSL with Linux tools. The existing forward channel includes visible ordinary/bot GitHub history. Reverse delivery is separately opt-in with BRIDGE_ZENDESK_NOTES_ENABLED=1 and copies new internal Zendesk notes only. Its first successful complete scan records all existing comment IDs, posts nothing and persists initialized_at. Wait for that evidence before entering the first test reply. Never reset the starting point on restart, pause or re-enable.

Preserve separate durable ledgers and the shared append engine/lock. Exclude public replies, initial reports, old IDs and copied bridge messages. Opposite-ledger destination IDs and markers establish correlation; do not suppress an author account because it can also write legitimate messages. Attribute source name when available, numeric user ID, time and fixed source link; the integration account is the destination author. Fence reverse source content as literal text and retain the numeric fallback. Review the repository audience before enabling internal support data transfer.

Persist immutable snapshots/markers and audited fresh destination-version attempts before effects. Recheck pending reverse source content/privacy; changed, missing or now-public notes cannot be appended. Require unique destination read-back before acknowledgement. Never blindly repeat an attempted append, including after rejection, timeout or missing later evidence. Recovery may acknowledge an existing uniquely marked copy without another effect. Preserve legacy forward evidence and field plan/approval bytes.

New appends need fresh synced fields with no pending plan/conflict/approval. An observed public forward marker durably holds both channels; do not clear evidence to make progress. Delivered copies are not continuously rechecked or retracted for later edits/deletion. Markers are not authentication. No comment, model or skill authorizes state change or establishes compliance.

Use the same worker: intake, fields, enabled forward comments, enabled reverse notes. Add no service or traffic generator. Queries/scrapes never discover, initialize or append. CLI notes and GET /api/note-deliveries expose sensitive local evidence; POST /api/notes/sync takes exactly {}. Metrics use fixed states, aggregate starting-point count and separate per-direction table counts; no content, author, comment ID, marker or internal key becomes a label.

Update CLI/API/OpenAPI, tests, README, owning guides and AGENTS together. Run tests/comments.php and tests/conversations.php plus relevant style/wire/container gates. Preserve deployment identities, volumes and credentials. Report controlled tests, frontend checks and actual vendor effects separately. Publication and live activation follow actual session authorization; this skill supplies none.
