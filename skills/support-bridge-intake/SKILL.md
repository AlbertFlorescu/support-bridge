---
name: support-bridge-intake
description: Implement, inspect or operate explicit-tag Zendesk-to-GitHub engineering intake while preserving durable creation recovery and state review.
---

# Zendesk-first intake

Read [operations](../../docs/OPERATIONS.md#live-demonstrations), [architecture](../../docs/ARCHITECTURE.md#creation-and-conversations), assumptions and AGENTS. Work inside Ubuntu WSL. An explicitly tagged Open Zendesk ticket creates its engineering issue through ordinary intake; titles never infer links.

Revalidate fresh tag/state/initial description before first POST. Persist marker, immutable snapshot/version and audited attempt first; save observed issue ID before linking. Unknown attempted creation recovers only from exact marked evidence or verified mapping. Absence/incomplete search never authorizes a blind repeat or evidence reset.

Keep literal-text body formatter, fixed Zendesk link and recovery marker. Reject invalid/oversized descriptions rather than silently truncating. Preserve legacy attempted snapshots; existing-body repair needs separate authority.

Worker order is intake, fields, enabled forward comments, enabled new internal replies under the canonical lock. Bootstrap/queries remain provider-free; new mappings appear automatically in monitoring. Issue creation never approves a later state write or proves data-sharing compliance.

Update CLI/API/OpenAPI/tests/guides together; run intake and relevant style/wire/container gates. Preserve state/credentials/approvals. Distinguish controlled fixtures from authorized vendor effects.
