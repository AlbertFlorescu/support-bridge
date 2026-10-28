# Governance and operations

## Customer context and product boundary

Support Bridge reconciles summaries and resolution state for escalations tracked in both Zendesk and GitHub. We expect it to reduce manual copying and stale updates; a pilot still needs to measure that benefit.

Each escalation must already have one ticket and one issue, linked by an operator. We copy only title/subject and open/closed state. Comments, descriptions, attachments and requester details stay in their source system. Titles can contain confidential information, so teams must approve the records and destination before opting in.

Before live use, agree on field ownership, state semantics, permitted transfers, approvers and freshness targets. The [assumptions register](ASSUMPTIONS.md) records these open requirements.

The [primary customer scenario](SCENARIO.md) shows how these controls apply to an export escalation. The story's support, engineering and review roles describe the assumed business process; our shared credential does not enforce those individual identities.

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

CLI and HTTP share typed application commands/queries, their handlers and `Engine`. `Application/Context` wires explicit dependencies, including a scoped SQLite connection; persistence helpers own schema upgrades, transactions and the canonical lock. Provider adapters fetch records and apply field patches. SQLite stores links, baselines, pending plans, conflicts, approvals and events. Prometheus and Grafana read operational metrics through a separate credential. Three fixed case aliases expose stored workflow observations without titles or vendor IDs; dashboard samples cannot establish fresh vendor state or authorize a decision.

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

### Live source setup and recorded replay

The [guided live demonstration](LIVE_DEMO.md) uses a separate, explicitly configured project. Its initializer performs no provider request or record linking, and scheduled reconciliation is disabled by default. An authorized source setup actor may create or edit owned fictional vendor records, but receives no bridge operator credential or approval capability. Vendor ticket scopes are not limited to the demonstrated pair IDs. A source-side resolution already performed in Zendesk is outside the bridge's outbound gate; every resulting bridge state-changing plan still requires its exact-plan approval.

Checkpoint capture reads provider records and local inspection under the canonical lock without reconciling or approving. Those provider reads are sequential, and optional monitoring samples are separate; the result is selected observation evidence, not an atomic transaction. The offline viewer's Next and Play controls only navigate embedded checkpoints. A record link, captured approval flag, screenshot or video cannot authorize a fresh effect or establish that a later plan still matches.

Keep captures in private artifact storage and review them before sharing. They omit free-form approval rationale and credentials, but can retain titles, versions, hashes and tenant-bearing record links. Their bounded snapshots supplement the current business state and audit; they are not a complete automatic archive, nonrepudiation control or compliance decision.

## Audit and identity boundaries

Local events record plan hashes, approval rationale/policy, write attempts and changed field names. The pending plan is cleared after convergence, so full completed review snapshots are not automatically archived. Optional explicit checkpoint capture can retain selected observed stages outside the business store. Events and presentation artifacts are mutable; business audit retention remains unbounded.

Service credentials are bound to one GitHub repository and Zendesk tenant. Operator and scrape tokens have different capabilities, but there are no individual roles or scoped agent credentials. Deployment owners must provide record access controls, secret rotation, storage encryption, retention and incident ownership. Audit failures must prevent subsequent effects; metrics are best effort and cannot decide business correctness.

OAuth bearer authentication does not change the operator principal or policy boundary. The confidential-client token acts through the client-associated Zendesk account, not a verified individual bridge approver. Our manual helper requests ticket read/write access for at most four hours and supplies no automatic renewal. Client identifier/secret remain on the issuance host. An explicit network-isolated UID10001 importer receives only access tokens through JSON stdin and writes a separate protected plaintext volume; API, CLI and the optional worker mount it read-only. This importer has no business state or operator capability and supplies no encrypted secret-manager guarantee. Reimport and recreate credential-reading containers after rotation, since startup processes retain their loaded values.

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
- One explicitly selected live project and short access-token lifetime make account selection clear, at the cost of manual credential maintenance. Recorded replay avoids repeating effects, but later decisions still need fresh evidence.

## Known limitations

Content/DLP policy, approval expiry/revocation, verified identity and tamper-evident audit are absent. We have not measured customer outcomes. Only the additive approval-column and telemetry-series upgrades are implemented; general migration, rollback and disaster recovery remain open work. [ARCHITECTURE.md](ARCHITECTURE.md) covers the concurrency, privacy, scale and provider limits.
