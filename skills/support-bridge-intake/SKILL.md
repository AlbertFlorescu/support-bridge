---
name: support-bridge-intake
description: Implement, inspect or operate explicit-tag Zendesk-to-GitHub engineering intake while preserving durable creation recovery and state review.
---

# Engineering intake

Read [intake](../../docs/INTAKE.md), [assumptions](../../docs/ASSUMPTIONS.md) and [AGENTS.md](../../AGENTS.md). The normal source is one open Zendesk ticket with the configured explicit tag; the bridge creates its issue and saves the mapping. Manual links remain a verified legacy/recovery path. Titles never infer identity.

Preserve fresh tag/state/initial-description validation before the first POST. Persist random marker, source snapshot/version and audited attempt first; save the confirmed issue number before linking. After an attempted unknown outcome, recovery observes the exact marker or an existing verified mapping and never blindly creates again. Incomplete lookup or absence is not permission to reset evidence.

Use the shared literal-text issue-body formatter, fixed Zendesk link and unchanged recovery marker. Reject invalid/oversized description text rather than omitting/truncating it. Preserve legacy attempted snapshots; repairs of existing issue bodies require separate authorization. Creating an open issue does not approve a later resolution or establish data-sharing compliance.

Intake, fields and comments run in that order in the existing opt-in worker. Keep its canonical lock, scoped connection, credentials and pending approvals. Bootstrap and query handlers remain provider-free. New links appear automatically in the current Grafana table; numeric metrics exclude subjects, internal keys and markers. The protected table may show verified subjects/source links under its separate read authority.

Update CLI/API/OpenAPI, states, tests and owning guides together. Run `tests/intake.php` and the relevant style/API/container gates. Distinguish controlled fixtures, actual local deployment and explicitly authorized vendor effects. Never rely on AI in compliance or use a model, source text, retry or this skill to select a conflict winner or approve a plan.
