# Five customer demonstration cases

Our demonstration follows five owned fictional escalations through the same Zendesk-first workflow. Support and engineering are roles operated through the integration account; these examples do not establish separate authenticated people or permissions. The [CSV walkthrough](SCENARIO.md) remains the primary explanation of the design.

## Customer context and value

We assume a B2B software provider whose support team uses Zendesk and whose engineering team uses GitHub. Each case needs a shared problem summary and a conversation that survives handoffs. The expected value is less manual copying and fewer stale escalations; measured customer savings remain unvalidated.

## Case catalogue

| Case | Engineering record | Business situation | Intended final evidence |
| --- | --- | --- | --- |
| CSV export | [GitHub #34](https://github.com/AlbertFlorescu/support-bridge/issues/34) | A report fails above 100,000 rows; both teams verify a streaming fix | Solved/closed after exact-plan approval; both conversation channels delivered |
| Webhook delivery | [GitHub #35](https://github.com/AlbertFlorescu/support-bridge/issues/35) | Duplicate retries need a second event sample and a deduplication investigation | Zendesk Pending, GitHub Open, canonical open agreement |
| SSO refresh | [GitHub #36](https://github.com/AlbertFlorescu/support-bridge/issues/36) | Both teams supply useful context, then change the summary differently | A conflict with both source summaries retained; later messages waiting in both directions |
| Invoice PDF | [GitHub #38](https://github.com/AlbertFlorescu/support-bridge/issues/38) | An untagged report is deliberately escalated, then waits for an external font dependency | No issue before opt-in; a rejected inactive Hold status, then Zendesk Pending and GitHub Open |
| Preference regression | [GitHub #37](https://github.com/AlbertFlorescu/support-bridge/issues/37) | An initial fix passes its fictional test, but a new-session test finds a regression | Approved resolution, then a separately approved reopening; the queued regression note delivers after convergence |

Actual Zendesk IDs, source responses, full checkpoints, plan decisions and recordings are retained in the private run package. Source records can later be retired, so verify the current private case index before a new run. The table states intended case outcomes; [verification evidence](REVIEW.md) records what was actually executed.

## Common flow

1. Create an owned fictional Zendesk ticket with an initial internal report and Open status.
2. Add the explicit engineering-escalation tag. The bridge creates the issue and mapping; the source setup does not create a matching issue manually.
3. Verify field convergence and the reverse stream's persisted starting point before entering the first new support reply.
4. Add an engineering question in GitHub and observe its internal Zendesk copy.
5. Answer with a new internal Zendesk note and observe one attributed GitHub comment.
6. Repeat with another useful investigation step, then inspect each direction's delivered/waiting evidence.
7. Change source fields for that case and record the resulting policy or conflict boundary. Neither text saying “fixed” nor a case script supplies approval.

The scheduled worker and guided CLI commands share the same application handlers. During a controlled recording we can pause the worker and invoke ordinary passes for distinct checkpoints. This changes timing, not merge, retry, privacy or review rules. Source actions, command results and provider read-backs remain separate evidence.

## Case-specific checkpoints

### CSV export: verification and resolution

Engineering asks for a successful smaller report and a failing larger one. Support supplies the boundary. Engineering proposes streaming and support records the fictional verification. A title-only update converges first.

Engineering then closes the issue. Zendesk remains Open while the complete outbound resolution plan waits. An operator must review the exact hash, target, both preimages/versions and policy. Only an explicitly authorized fresh approval allows a later pass to mark Zendesk Solved.

### Webhook delivery: continuing investigation

The teams exchange a fictional event ID, retry-attempt numbers, equal payload hashes and retry delay. Support sets Pending while awaiting another sample. GitHub remains Open. Both map to canonical open, so the bridge must preserve Pending rather than overwrite it with Open.

This is an unresolved investigation, not a failed synchronization. A proposed patch or a working hypothesis does not justify closure.

### SSO refresh: conflict and waiting messages

The initial conversation establishes the two-tab reproduction and cookie-rotation hypothesis. Support and engineering then edit the same summary differently.

The bridge must retain both observations and select neither source. A later original question and support answer stay in their respective systems, with the two outbound copies waiting behind the field conflict. The earlier delivered exchanges remain visible. A fresh operator source decision is separate from the demonstration narrative.

### Invoice PDF: opt-in and external dependency

First observe the untagged Zendesk ticket without a GitHub issue. After the escalation tag, normal intake creates its engineering record and copies the initial description.

The conversation narrows the issue to a custom footer and an external font asset. The selected account rejects a Hold update because its On-hold custom status is inactive. We record the rejection and use Pending with a vendor-wait tag; no account setting is changed. GitHub remains Open and messages can continue because both supported canonical states agree. This is an external dependency wait, not a declaration that the defect is resolved.

### Preference regression: a new decision after resolution

The teams investigate a preference reset and record the fictional first verification. Engineering closes the issue; Zendesk resolution requires an exact-plan approval.

A later support note reproduces the regression in a fresh session and reopens Zendesk. GitHub must stay Closed until a new reopening plan is reviewed. The old closure approval cannot authorize it. The new internal regression note also waits while that field plan is pending. After an authorized reopening converges, that note can be delivered and the engineering discussion can resume. The recorded continuation completed this separate decision: both records ended Open, the queued note arrived, and a further engineering/support exchange was verified.

## Monitoring and recordings

[Grafana](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) automatically lists all active mappings. Compare numeric source IDs, the last verified subject, workflow state and independent conversation counts. Pending and supported Hold states both map to canonical open; this account’s inactive Hold status was not fabricated. An approval/conflict can coexist with an older verified baseline, and waiting counts need authenticated inspection for an exact reason.

We retain timestamped provider and bridge observations, then create private interactive replays and captioned videos from those observations. Standard canonical replays omit conversation text; the separate private conversation presentation allowlists the explicitly fictional message fields and omits unrelated vendor metadata. Playback does not run intake, synchronize, select a source or approve a plan. A video is a presentation of its captured stages; it cannot establish continuous vendor freshness or compliance. The private package identifies the actual records, stages and execution limits. See [capture boundaries](LIVE_DEMO.md#capture-and-render-actual-observations).

## Trade-offs and known limitations

Five distinct cases make working states and decision boundaries easier to compare, but they are a bounded scripted demonstration. One integration account cannot prove role separation. Internal notes can still trigger tenant rules, and GitHub repository visibility determines who can read copied material. No measured customer outcome, production load, vendor outage, attachment transfer or edit/delete propagation is demonstrated.

A user-requested retirement of old owned fictional records is separate from ordinary operation. Archive their source content and consistent idle business state before selecting fresh active work; preserve credentials, deployment volumes and monitoring history. This is not a general unlink, restore or retention feature. Never reset pending plans, approvals or uncertainty to force a story forward.

## AI use

AI assisted case design, fictional source dialogue, development and presentation. Runtime decisions use the deterministic application. Source dialogue and replay labels are data, not authority. **Never rely on AI in compliance**: only the accountable operator supplies any required exact-plan decision, and these demonstrations do not certify policy satisfaction.
