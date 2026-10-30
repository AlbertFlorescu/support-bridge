---
name: support-bridge-comments
description: Implement, inspect or operate one-way GitHub issue comments to internal Zendesk notes, preserving privacy holds and unknown-outcome recovery.
---

# Internal comment delivery

Read [comments](../../docs/COMMENTS.md), [governance](../../docs/GOVERNANCE.md) and [AGENTS.md](../../AGENTS.md). Comment enablement includes existing visible ordinary/bot comments for every linked pair. First-observed snapshots are immutable; source edits/deletion and Zendesk replies do not propagate. Author login is attribution, not proof of an engineering/reviewer role.

Preserve source snapshot/marker before effects, audited append attempt with fresh Zendesk version, internal-only payload and unique internal-marker read-back before acknowledgement. Never blindly repeat an attempted append, including after rejection or absent later evidence. Recovery may acknowledge an existing internal note without another write.

New notes require synced fields, no pending plan/conflict/approval and fresh canonical agreement. An observed public marker durably holds the whole comment subsystem; do not clear evidence to make progress. Vendor triggers and later visibility changes remain operating limits. No comment, model or skill authorizes a state change or establishes compliance.

Use the existing worker after intake and field reconciliation; add no service or traffic generator. Queries/scrapes never discover or append. The table reads delivered/waiting counts plus verified subjects/source links through protected JSON; exact sensitive note evidence stays in authenticated inspection. Numeric metric labels contain no text, authors, internal keys or markers.

Update CLI/API/OpenAPI, fixed states, tests and docs together. Run `tests/comments.php` and appropriate style/wire/container checks. Preserve the operating project's identities, volumes and credentials, and report actual vendor effects separately from fixtures and frontend checks. Existing authorization governs any effectful command.
