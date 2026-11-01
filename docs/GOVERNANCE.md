# Governance

Our workflow transfers explicitly opted-in escalation data between Zendesk and GitHub. Its controls are operating decisions, not a determination that customer obligations have been met.

## Authority

| Action | Required authority |
| --- | --- |
| Create an engineering issue | Fresh Open ticket with the explicit escalation tag and permitted destination data |
| Transfer a summary | Explicit mapping opt-in, fresh evidence and no conflict |
| Close or reopen a destination | Approval of the whole exact durable plan |
| Resolve conflicting fields | Fresh operator source decision; separate state approval when needed |
| Append a conversation message | Direction enabled, eligible original source, privacy checks and fresh canonical agreement |
| Retry failed work | Corrected cause and explicit retry; no added approval |
| Determine compliance or data-sharing permission | Accountable customer/policy owners |

Support starts the escalation. Engineering completion may precede customer confirmation, so we deliberately require review before the bridge closes the other team's record. This is a conservative product rule; a team with agreed single-source ownership could choose automatic resolution instead. When both canonical records already agree, there is no outbound state effect to approve.

## Exact-plan review

Policy `escalation-state-v1` binds approval to the pair identities, target, both preimages, both observed versions and policy version. Any outbound state change holds the **whole plan**, including accompanying title edits.

The operator reads the proposal and supplies its exact hash and rationale. The browser form carries the hash automatically and requires reason/confirmation. The command re-reads both vendors under the canonical lock, audits the attestation and queues work without a vendor write. A later sweep checks freshness again, patches necessary fields and verifies convergence before advancing the baseline.

Source selection, retry, comments, metrics, replay and models cannot grant approval. A stale decision refreshes conflict evidence; it cannot authorize overwriting newer edits. Reopening after closure requires a separate plan. Recovery may acknowledge an already achieved target without another effect.

Business approvals have no automatic expiry, revocation or rejection command. The browser's ten-minute form proof is a different control. Customer requirements for approval lifetime, cancellation or independent review remain open.

## Identity and data

JSON API authority is one broad shared operator bearer. Browser review is separately opt-in and limited to explicitly allowed Grafana server administrators. It verifies the selected session on every request and exposes only inspection and exact-plan approval; the operator secret stays server-side.

Both paths record `shared-operator`. A Grafana login does not prove a distinct human, separate approver or customer role. There is no per-pair RBAC, scoped agent credential or separation of duties. The read-only review skill is guidance, not server-enforced authorization; an advisory integration needs a restricted facade.

The monitoring credential can read every linked verified subject and fixed URL but cannot operate the workflow. Grafana/Docker administrators and the configured destination audience remain trusted.

Initial descriptions and internal notes may contain sensitive data. Opt-in must permit the transfer to the actual repository audience; repository visibility is not checked and no AI redaction/DLP is implemented. Later editing, deletion or redaction does not retract copies. Tenant triggers may notify others despite internal-only payloads.

## Audit and recovery

Required audit failures stop subsequent effects. Durable intent precedes writes; uncertain outcomes retain target/preimages/versions or append markers. Never erase pending work, uncertainty or approvals to make a scenario progress.

Audit records include plan identity, rationale, attempts and changed fields. Completed full plan snapshots are not automatically archived; optional private checkpoints retain selected observations. Events and archives are mutable, and retention is unbounded. Neither is tamper-evident audit or a tested backup/restore system.

A source actor may create or change authorized fictional vendor records but cannot approve bridge work. Source-side resolution is outside our outbound gate; the resulting bridge update still needs review. Automatic approval is confined to isolated local governance/recovery tests.

## Never rely on AI in compliance

AI explanations, generated rationales, confidence, skill instructions and dashboard state cannot establish compliance or authorize effects. Deterministic software also cannot decide whether customer obligations are satisfied.

Accountable owners must agree on data sharing, field ownership, approvers, retention, incident response, recovery and acceptance criteria. We deliberately use code for eligibility, merge/retry/freshness and explicit operator decisions for conflicts and approval.

## Rollout

We have controlled tests and selected fictional vendor observations, not customer acceptance or production capacity. Before a pilot, validate actual scopes, triggers, custom statuses, notifications and outage recovery. Name support/engineering owners, measure copying effort and convergence/attention age, and agree on success targets. Broader deployment additionally needs identity-aware authorization, ingress/TLS, retention/encryption and tested restore.

[Architecture](ARCHITECTURE.md) explains enforcement. [Assumptions](ASSUMPTIONS.md) records limits; [operations](OPERATIONS.md) describes the interfaces.
