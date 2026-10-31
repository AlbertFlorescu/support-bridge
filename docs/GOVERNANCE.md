# Governance and operations

## Customer context and product boundary

Support Bridge reconciles summaries and resolution state for escalations tracked in both Zendesk and GitHub. We expect it to reduce manual copying and stale updates; a pilot still needs to measure that benefit.

Support starts with a Zendesk ticket. Adding the configured engineering tag explicitly opts a canonical-open ticket into creating an open GitHub issue and saving its mapping. Existing verified records may still be linked manually. This opt-in is not an attestation to compliance, a verified individual identity or approval to resolve the customer ticket. We reconcile title/subject and open/closed state. Separately enabled [conversation channels](COMMENTS.md) copy original GitHub comments into internal Zendesk notes and new internal Zendesk replies into GitHub comments, with source attribution and durable origin checks. Intake copies the initial Zendesk description once into the new issue body. Public Zendesk replies, later description edits, attachment bytes and requester details stay in their source system. The return channel needs an explicit data-sharing decision for GitHub's audience and copies no pre-cutover discussion. The first description may be internal; the explicit opt-in must approve that transfer. Comment text can contain sensitive data; internal visibility is not a compliance decision. Titles can contain confidential information, so teams must approve the records and destination before opting in.

Before live use, agree on field ownership, state semantics, permitted transfers, approvers and freshness targets. The [assumptions register](ASSUMPTIONS.md) records these open requirements.

The [primary customer scenario](SCENARIO.md) shows how these controls apply to an export escalation. The story's support, engineering and review roles describe the assumed business process; our shared credential does not enforce those individual identities.

## Workflow decisions

| Step | Rule | Evidence |
| --- | --- | --- |
| Create and link engineering issue | Require the configured tag on a fresh open ticket; preserve uncertain creation evidence. | Durable marker/attempt before POST, fixed repository, subject/initial-description/link creation and unique numeric mapping. No AI classification or approval. |
| Link legacy records | Require an explicit one-to-one mapping. | Operator-supplied IDs and database uniqueness. |
| Merge values | Compare both records with their common baseline; stop on conflicting edits. | Current fields and persisted baseline. |
| Resolve a conflict | Require an operator to choose a source from fresh observations. | Stored conflict fields and versions. |
| Check write eligibility | Allow only supported fields and writable destinations. | PHP policy and provider capabilities. |
| Deliver engineering comment | Append only internal Zendesk notes for explicitly linked records when enabled. | Durable first-observed snapshot/marker, audited attempt, safe update, unique internal read-back and no blind repeat. Pending field plans/conflicts/approval hold new notes; public evidence durably holds all note delivery. |
| Deliver internal support reply | Require reverse opt-in, initialized new-only cutover, original internal source and fresh canonical agreement with no pending review work. | Source user ID/available name, fixed link, immutable snapshot, origin-ID/marker checks, audited attempt and unique GitHub read-back; no blind repeat. |
| Close or reopen | Hold the whole plan for approval. | Exact plan hash, policy version and fresh observations. |
| Recover an uncertain write | Read before retrying; acknowledge an achieved target and stop on unrelated edits. | Durable target, preimages, versions and provider reads. |
| Explain pending work | Allow optional read-only assistance. | Inspection data, reviewed by the operator. |
| Determine compliance | Leave that decision with accountable policy owners. | Their approved obligations and controls. |

Merge, approval and recovery follow fixed rules. The application calls no model. Any future advisory integration needs a demonstrated use and credentials that cannot execute the workflow.

## Architecture overview

CLI and HTTP share typed application commands/queries and explicit constructor dependencies. Commands execute intake, field or comment workflows under the canonical lock. Queries inspect local evidence without providers. One scoped business connection supplies repositories; transaction/schema/lock infrastructure owns persistence. [Architecture](ARCHITECTURE.md) records these boundaries.

Monitoring has read authority only. The protected current table exposes linked baseline subjects and fixed source URLs to Grafana viewers; Prometheus retains numeric identifiers rather than content labels. Neither display proves fresh vendor state or policy satisfaction, and source navigation uses the viewer's own vendor login. [Observability](OBSERVABILITY.md#trade-offs-and-data-exposure) owns the access and retention boundary.

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

The [guided live demonstration](LIVE_DEMO.md) uses a separate, explicitly configured project. Its initializer performs no provider request or record linking, and effectful intake/reconciliation require explicit worker enablement. The [Zendesk-first path](INTAKE.md) uses that existing worker, with creation opt-in from the support tag and the unchanged state-review gate. An authorized source setup actor may create or edit owned fictional vendor records, but receives no bridge operator credential or approval capability. Vendor ticket scopes are not limited to the demonstrated pair IDs. A source-side resolution already performed in Zendesk is outside the bridge's outbound gate; every resulting bridge state-changing plan still requires its exact-plan approval.

Checkpoint capture reads provider records and local inspection under the canonical lock without reconciling or approving. Those provider reads are sequential, and optional monitoring samples are separate; the result is selected observation evidence, not an atomic transaction. The offline viewer's Next and Play controls only navigate embedded checkpoints. A record link, captured approval flag, screenshot or video cannot authorize a fresh effect or establish that a later plan still matches.

Keep captures in private artifact storage and review them before sharing. They omit free-form approval rationale and credentials, but can retain titles, versions, hashes and tenant-bearing record links. Their bounded snapshots supplement the current business state and audit; they are not a complete automatic archive, nonrepudiation control or compliance decision.

## Internal comment authority

Each append direction is explicitly enabled, separate from field-plan approval. Forward delivery includes eligible original GitHub histories. Reverse delivery records a durable per-pair starting point at its first complete scan and copies only subsequent internal notes; public replies, the initial report and copied bridge records are excluded. Pausing or restarting does not reset that starting point. The configured GitHub audience can be public, so reverse enablement must authorize that transfer.

No model filters authors, interprets a message as approval or rewrites it. Integration accounts perform destination writes; source names/logins/IDs in the body are attribution, not impersonation or verified reviewer identity. Opposite-ledger destination IDs and markers prevent ordinary reflection loops without suppressing human replies from the same account.

New messages in either direction wait during field review. A pending reverse note is re-read before its first append; changed, deleted or public source content cannot be silently replaced or published from a stale snapshot. Immutable snapshots and uncertain attempts remain evidence. After delivery, source edits/redaction/deletion do not retract copies. The shared privacy hold stops both channels after an observed forward internal-only violation. See [recovery and limits](COMMENTS.md).

## Audit and identity boundaries

Local events record plan hashes, approval rationale/policy, write attempts and changed field names. The pending plan is cleared after convergence, so full completed review snapshots are not automatically archived. Optional explicit checkpoint capture can retain selected observed stages outside the business store. Events and presentation artifacts are mutable; business audit retention remains unbounded.

Service credentials are bound to one GitHub repository and Zendesk tenant. Operator and read-only monitoring tokens have different capabilities; the latter permits all linked baseline subjects and source URLs as well as numeric scraping, but there are no individual roles or scoped agent credentials. Deployment owners must provide record access controls, secret rotation, storage encryption, retention and incident ownership. Audit failures must prevent subsequent effects; metrics are best effort and cannot decide business correctness.

OAuth bearer authentication does not change the operator principal or policy boundary. The confidential-client token acts through the client-associated Zendesk account, not a verified individual bridge approver. [Managed renewal](OAUTH.md) requests four-hour ticket read/write access through an isolated issuer service. A networkless importer writes its client configuration into an issuer-only protected volume; API, CLI, worker and monitoring never receive that client secret. The renewer publishes an atomic token/expiry bundle into the existing vendor volume, and workflow readers load the managed credential before each Zendesk request. Routine renewal needs no reader restart.

The renewer has no business database or operator credential and cannot synchronize, approve, select a conflict winner or reset retries. It uses the deployment credential lock, separate from the canonical business lock. Reusing the vendor volume gives it filesystem access to the GitHub token, although its implementation never uses or replaces that token. Files remain plaintext protected by mounts/ownership rather than an encrypted secret manager. Manual/static credentials remain a distinct compatibility mode and require reader recreation after rotation. Host downtime, a revoked client or a vendor outage can still interrupt access; renewal is not a compliance decision or a guarantee of business progress.

## Rollout and operating ownership

| Phase | Required evidence | Owners |
| --- | --- | --- |
| Local validation | Synthetic records, failure cases and documented assumptions. | Maintainers: implementation, tests and limitations. |
| Sandbox integration | Actual permissions, Zendesk rules/triggers, state mapping, rate limits and uncertain-write recovery. | Integration owners: provider behavior. Policy owners: data and write rules. |
| Bounded live pilot | Opt-in records, permitted destinations, baseline measurements, approval coverage and a recovery plan. | Support/engineering: links and decisions. Service owners: credentials, scheduling, monitoring and incidents. |
| Broader operation | Measured benefit and capacity, agreed acceptance criteria and runbooks. | Named owners for mappings, policies, code, data lifecycle and operational targets. |

Workflow and policy changes go through reviewed mainline changes. Deployment needs its own authorization. Customer-specific mappings and policies need named owners.

Measure copying effort, convergence lag, conflict/dead-work age, approval wait time, API calls per useful change and recovery success. Last-verified convergence age can be derived from stored timestamps, but the exporter does not measure customer benefit or every conflict/approval wait duration; agree on targets before a pilot.

## Trade-offs

- State review adds operator work and latency. Title-only transfers proceed through link opt-in, but some teams will need review for every write.
- Whole-plan approval keeps the decision coherent while delaying any accompanying title change.
- Strict version checks catch stale plans but can stop work after unrelated edits. Timestamp granularity and GitHub's read/PATCH race remain.
- A small code policy is testable. Expanding it requires reviewed code and a policy version change.
- A shared operator simplifies setup at the cost of individual attribution, independent approval and agent scopes.
- One explicitly selected live project and short access-token lifetime make account selection clear. Managed renewal adds an issuer service, private client volume and network dependency; manual credentials retain their separate maintenance. Recorded replay avoids repeating effects, but later decisions still need fresh evidence.

## Known limitations

Content/DLP policy, approval expiry/revocation, verified identity and tamper-evident audit are absent. We have not measured customer outcomes. Narrow additive schema/telemetry upgrades preserve legacy work; general migration, rollback and disaster recovery remain open work. [ARCHITECTURE.md](ARCHITECTURE.md) covers the concurrency, privacy, scale and provider limits.
