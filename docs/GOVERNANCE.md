# Governance and operations

## Customer context and product boundary

Support Bridge reconciles summaries and resolution state for escalations tracked in both Zendesk and GitHub. We expect it to reduce manual copying and stale updates; a pilot still needs to measure that benefit.

Each escalation must already have one ticket and one issue, linked by an operator. We copy only title/subject and open/closed state. Comments, descriptions, attachments and requester details stay in their source system. Titles can contain confidential information, so teams must approve the records and destination before opting in.

Before live use, agree on field ownership, state semantics, permitted transfers, approvers and freshness targets. The [assumptions register](ASSUMPTIONS.md) records these open requirements.

## Workflow decisions

| Step | Rule | Evidence |
| --- | --- | --- |
| Link records | Require an explicit one-to-one mapping. | Operator-supplied IDs and database uniqueness. |
| Merge values | Compare both records with their common baseline; stop on conflicting edits. | Current fields and persisted baseline. |
| Resolve a conflict | Require an operator to choose a source from fresh observations. | Stored conflict fields and versions. |
| Check write eligibility | Allow only supported fields and writable destinations. | PHP policy and provider capabilities. |
| Close or reopen | Hold the whole plan for approval. | Exact plan hash, policy version and fresh observations. |
| Recover an uncertain write | Read before retrying; acknowledge an achieved target and stop on unrelated edits. | Durable target, preimages, versions and provider reads. |
| Explain pending work | Allow optional read-only assistance. | Inspection data, reviewed by the operator. |
| Determine compliance | Leave that decision with accountable policy owners. | Their approved obligations and controls. |

Merge, approval and recovery follow fixed rules. The application calls no model. Any future advisory integration needs a demonstrated use and credentials that cannot execute the workflow.

## Architecture overview

CLI and HTTP share typed application commands/queries, their handlers and `Engine`. `Application/Context` wires explicit dependencies, including a scoped SQLite connection; persistence helpers own schema upgrades, transactions and the canonical lock. Provider adapters fetch records and apply field patches. SQLite stores links, baselines, pending plans, conflicts, approvals and events. Prometheus and Grafana read aggregate metrics through a separate credential.

Command/query separation organizes use cases without changing authority. Queries read local state without provider access; the approval command still invokes the normal engine gate and fresh provider checks. There is one business database, with the same persisted plan shape and audit ordering. Class boundaries, PSR formatting and a command type cannot themselves authorize an effect.

The write sequence is:

1. Read both records and calculate the merged target.
2. Persist the target, both preimages and both observed versions.
3. Check supported fields and destination writability.
4. Hold any plan containing an outbound state change for approval.
5. Recheck approval and freshness, patch changed fields, then verify both records.
6. Advance the common baseline only after observed convergence.

A state-changing plan also holds its title changes. A title-only plan uses the explicit link's opt-in. Equal records need no write or approval.

## Never rely on AI in compliance

AI explanations, confidence scores, skill instructions and generated rationales cannot establish compliance or authorize writes. PHP enforces the field contract, freshness checks, approval gate and retry limits. Policy owners must decide whether those controls meet their obligations, including whether title-only transfer is permitted.

Under `escalation-state-v1`, approval binds the pair identities, target, both preimages, observed versions and policy version. The operator reviews the whole plan, supplies its hash and records a rationale. Approval reads both providers again; execution checks freshness once more. Retry and source selection never grant approval, and another plan or policy cannot inherit it. Recovery may acknowledge an already-achieved target without writing; any remaining write still needs authorization.

The shared operator credential can approve a plan but cannot identify a person or enforce independent review. Treat the record as an operator attestation. Do not give this credential or the approval capability to an agent. The [review skill](../skills/support-bridge-escalation-review/SKILL.md) lists read-only tools; a future integration must enforce those scopes through an authenticated facade and per-user authorization.

Approvals have no expiry, revocation or rejection command. `approved_at` records when the attestation occurred; unchanged evidence and policy can remain authorized. Pending reviews wait for an operator, without an automatic approver or reminder delivery. Teams requiring expiring or revocable approval need that workflow before rollout.

The gate does not classify confidential titles, decide data-sharing permission or check every prerequisite for closure. Our [AI-use disclosure](../README.md#ai-use) describes development assistance separately from runtime authority.

### Scripted synthetic review

The opt-in [traffic simulation](TRAFFIC.md) uses a separate local stack and a fixed pool of owned synthetic pairs. In `scripted` mode, a deterministic driver waits at least ten seconds before choosing a conflict source or submitting the current exact-plan hash through the normal API. It receives that project's broad operator credential. The approval gate and freshness checks still run; the resulting attestation is scripted, not human review or compliance evidence. `manual` mode leaves conflicts and approval waits for the operator. The simulator refuses live mode and does not call a model.

## Audit and identity boundaries

Local events record plan hashes, approval rationale/policy, write attempts and changed field names. The pending plan is cleared after convergence, so full completed review snapshots are not archived. Events are mutable and retention is unbounded.

Service credentials are bound to one GitHub repository and Zendesk tenant. Operator and scrape tokens have different capabilities, but there are no individual roles or scoped agent credentials. Deployment owners must provide record access controls, secret rotation, storage encryption, retention and incident ownership. Audit failures must prevent subsequent effects; metrics are best effort and cannot decide business correctness.

## Rollout and operating ownership

| Phase | Required evidence | Owners |
| --- | --- | --- |
| Local validation | Synthetic records, failure cases and documented assumptions. | Maintainers: implementation, tests and limitations. |
| Sandbox integration | Actual permissions, Zendesk rules/triggers, state mapping, rate limits and uncertain-write recovery. | Integration owners: provider behavior. Policy owners: data and write rules. |
| Bounded live pilot | Opt-in records, permitted destinations, baseline measurements, approval coverage and a recovery plan. | Support/engineering: links and decisions. Service owners: credentials, scheduling, monitoring and incidents. |
| Broader operation | Measured benefit and capacity, agreed acceptance criteria and runbooks. | Named owners for mappings, policies, code, data lifecycle and operational targets. |

Workflow and policy changes go through reviewed mainline changes. Deployment needs its own authorization. Customer-specific mappings and policies need named owners.

Measure copying effort, convergence lag, conflict/dead-work age, approval wait time, API calls per useful change and recovery success. The exporter does not yet cover every business measure or per-pair age; agree on targets before a pilot.

## Trade-offs

- State review adds operator work and latency. Title-only transfers proceed through link opt-in, but some teams will need review for every write.
- Whole-plan approval keeps the decision coherent while delaying any accompanying title change.
- Strict version checks catch stale plans but can stop work after unrelated edits. Timestamp granularity and GitHub's read/PATCH race remain.
- A small code policy is testable. Expanding it requires reviewed code and a policy version change.
- A shared operator simplifies setup at the cost of individual attribution, independent approval and agent scopes.

## Known limitations

Content/DLP policy, approval expiry/revocation, verified identity and tamper-evident audit are absent. We have not measured customer outcomes. Only the additive approval-column and telemetry-series upgrades are implemented; general migration, rollback and disaster recovery remain open work. [ARCHITECTURE.md](ARCHITECTURE.md) covers the concurrency, privacy, scale and provider limits.
