# Governance and operations

## Customer context and product boundary

We target support and engineering teams that maintain the same escalation in Zendesk and GitHub. Manual copying can leave summaries and resolution updates stale. Our working hypothesis is that explicit reconciliation reduces duplicate work while preserving an accountable review boundary for consequential changes.

We currently support two existing records and two canonical fields: title and open/closed state. We do not infer identity, create remote records or copy comments, attachments or requester details. Titles can still contain confidential information. Teams must decide which records may cross the boundary before opting them in.

Our customer model and expected value remain assumptions. Before a pilot, we need to validate actual copying effort, state semantics, field ownership, permitted destinations, approvers and freshness expectations. We do not claim measured time savings or a completed rollout.

## Workflow decisions

We choose a fixed execution path for each step that authorizes or applies a change.

| Step | Our decision | Authority and evidence |
| --- | --- | --- |
| Establish record identity | Require an explicit one-to-one link. Similar titles do not establish identity. | Operator-provided numeric identifiers and database uniqueness. |
| Decide canonical values | Compare both records with their common baseline. Preserve independent edits; quarantine disagreements. | Pure merge policy and current provider observations. |
| Select a conflicting source | Require an explicit choice over fresh evidence. | Operator command checked against stored fields and versions. |
| Decide write eligibility | Check supported fields, immutable destinations and approval requirements before effects. | Fixed PHP policy and provider capabilities. |
| Close or reopen | Hold the complete plan until exact-plan approval. | Operator attestation, bound plan hash and fresh observations. |
| Recover an uncertain outcome | Read before retry; acknowledge achieved targets and quarantine unrelated changes. | Durable target, preimages, versions and actual provider observations. |
| Explain a pending escalation | Permit optional read-only assistance, with no execution authority. | Structured evidence inspected independently by the operator. No runtime model is implemented. |
| Determine whether obligations are satisfied | Keep this outside the service's authority. | Accountable policy owners and their approved controls. |

A model loop would add unpredictable choices, latency, cost and failure modes to a process with a known path. We therefore keep merge, approval and recovery deterministic. A future advisory integration must remain separate from execution authority and have a demonstrated operational need.

## Architecture overview

CLI and HTTP share `Application/Context` and `Engine`. Provider adapters expose a narrow fetch/patch contract. SQLite retains links, baselines, pending intent, conflicts, approvals and local events. Prometheus and Grafana observe aggregates through a separate read-only credential.

The write sequence is:

1. Observe both records and decide the merged target.
2. Persist target, both preimages and both observed versions.
3. Check supported capabilities and every destination's writability.
4. Require exact-plan approval if any outbound open/closed transition is planned.
5. Recheck authorization and freshness, patch only changed fields, then verify both records.
6. Advance the common baseline only after observed convergence.

A state-changing plan also holds its title updates, so no part of that decision is applied before review. A title-only plan uses the explicit link's opt-in. Equal records need no effect or approval.

## Never rely on AI in compliance

AI-generated explanations, confidence scores, skill instructions and approval rationales cannot establish compliance or authorize a write. The application calls no LLM. PHP enforces the supported field contract, freshness guards, state-change gate and retry limits; model output cannot override those rules.

Our policy `escalation-state-v1` requires approval for any planned outbound open/closed transition. Approval binds the pair identities, target, both preimages, observed versions and policy version. The operator inspects the whole plan, supplies its hash and records a rationale. Approval re-reads both providers. Execution checks freshness again, and neither retry nor source selection grants approval. A different plan or policy cannot inherit authorization. Already-achieved effects may be acknowledged without writing; that does not authorize a remaining effect.

The shared operator credential can issue approval commands. It cannot prove human origin, identify a distinct policy owner or enforce independent review. This is an explicit operator attestation, not verified human approval or compliance certification. An agent must not receive that broad credential or the approval capability. The [review skill](../skills/support-bridge-escalation-review/SKILL.md) documents a read-only allowlist, but Markdown does not enforce scopes. Any future agent integration needs a restricted authenticated facade and per-user authorization first.

Approval has no expiry, revocation or rejection command. `approved_at` records the attestation time without defining a validity period. Unchanged evidence and policy can remain authorized. Pending reviews remain paused until an operator acts; we have no automatic approver or reminder delivery. Teams needing expiring or revocable authorization need an additional policy and workflow before rollout.

The gate does not classify confidential titles, decide whether a transfer is permitted, or verify all business prerequisites for closure. Policy owners must approve those boundaries, including the title-only opt-in. We use AI during development, as disclosed in the README, but software checks and generated explanations do not replace accountable control ownership.

## Audit and identity boundaries

Local events retain plan hashes, approval rationale/policy, write attempts and changed field names separately from best-effort metrics. Full completed review snapshots are not archived: the pending plan is cleared after convergence. Events are mutable local records with unbounded retention; they are not a tamper-evident evidence service.

Service credentials are bound to one GitHub repository and Zendesk tenant. The operator and scrape tokens have separate capabilities, but individual operator roles and scoped agent credentials are absent. Record access, secret rotation, storage encryption, retention and incident ownership need deployment controls beyond this service.

## Rollout and operating ownership

We use phased validation so each expansion has evidence and an owner.

| Phase | Evidence needed before expanding | Responsibilities to establish |
| --- | --- | --- |
| Local validation | Synthetic records, deterministic failure cases and documented assumptions. | Maintainers own implementation, test coverage and known limitations. |
| Sandbox integration | Actual permissions, Zendesk rules/triggers, state mapping, rate limits and uncertain-outcome recovery. | Integration owners validate provider behavior; policy owners approve data and write boundaries. |
| Bounded live pilot | An opt-in record set, acceptable destinations, baseline measurements, approval coverage and recovery plan. | Support/engineering own links and decisions; service owners own credentials, scheduling, monitoring and incidents. |
| Broader operation | Measured benefit, capacity and failure behavior; agreed acceptance criteria and runbooks. | Named owners maintain mappings, policy versions, code, data lifecycle and operational objectives. |

Changes to reusable workflow code and policy go through reviewed mainline changes. Deployment remains a separate operational action. Customer-specific mappings and policies need explicit owners; a shared repository does not itself supply authorization or release approval.

We need to measure copying actions and effort, convergence lag, conflict/dead-work age, approval wait time, API calls per useful change and recovery success. The exporter does not yet measure every business indicator or per-pair age. Set targets with the operating team before using these measurements as acceptance criteria.

## Trade-offs

- State review adds operator work and latency. Title-only propagation keeps routine summaries moving, but some teams will need review for every transfer.
- Whole-plan approval is easier to inspect and recover than per-field approvals, at the cost of holding an accompanying title change.
- Strict version guards can require review after unrelated edits. They cannot remove timestamp granularity or GitHub's read/PATCH race.
- A fixed small policy is testable and explainable. Policy expansion needs reviewed code and version changes rather than a prompt edit.
- A shared operator keeps local setup simple. It does not supply individual accountability, independent approval or agent scopes.

## Known limitations

We have no runtime model, verified human identity, scoped agent token, complete content/DLP policy, approval expiry/revocation, tamper-evident audit or measured customer outcome. Only narrow additive approval-column and telemetry-series migrations are implemented; general rollback and disaster recovery remain unfinished. The existing concurrency, privacy, scale and live-vendor limitations in [ARCHITECTURE.md](ARCHITECTURE.md) remain after adding approvals.
