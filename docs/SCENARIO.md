# Customer scenario and demonstrations

We assume a B2B software provider: support works in Zendesk, engineering in GitHub, and an escalation owner decides when resolution is safe. Our primary case is a CSV export that fails above 100,000 rows. The expected value is less copying and fewer stale escalations; customer savings have not been measured.

The demonstrations use owned fictional records. Support, engineering and reviewer are roles played through integration accounts, not separately authenticated people. Never rely on AI in compliance or interpret fictional dialogue as approval.

## Demonstrate the complete flow

Before starting, check the selected [deployment, credentials and enabled channels](OPERATIONS.md). Keep the worker running; the default wait is 60 seconds after each pass. Grafana refresh alone does not contact either vendor.

1. **Start in Zendesk.** Create a fictional ticket, enter a subject and initial description, and submit as **Open**. Use an internal note and a controlled requester; tenant notification rules still apply.
2. **Opt in.** Add `support_bridge_escalate` to Tags and save. This authorizes transferring the subject and initial problem text to the configured repository. Do not include customer secrets.
3. **Find the automatic issue.** After intake, open GitHub Issues. The bridge creates the open issue, copies the initial description as literal text, adds the correct Zendesk link and saves the mapping. Do not create its partner manually or remove the recovery marker.
4. **Check Grafana.** Open [Linked escalations](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s). The current table automatically lists both clickable record numbers, the last verified subject, workflow state and separate message counts. A new row requires no dashboard edit.
5. **Refine the summary.** Change only the Zendesk subject to “CSV export fails above 100,000 rows”. Verify it reaches GitHub. One-sided GitHub title edits work in the other direction. Different simultaneous edits require a source decision.
6. **Ask an engineering question.** Add “Which report sizes pass and fail?” as a GitHub comment. Verify one attributed **internal** Zendesk note.
7. **Answer from support.** First confirm the reverse channel has initialized this pair. Add a new internal note: “80,000 rows passes; 120,000 rows fails.” Verify one attributed GitHub comment. Public replies and notes predating initialization are excluded. Repeat a question/answer and wait another pass: copies must not echo or duplicate.
8. **Demonstrate resolution.** Close the GitHub issue after the fictional fix is verified. Zendesk stays Open; Grafana shows **Awaiting approval**. Comments can wait behind the field plan.
9. **Review explicitly.** Use Grafana's **Review pending changes** link or the row's workflow link. Inspect the complete target and both observations, enter a reason, confirm and choose **Approve this exact change**. This records authority; it does not immediately patch Zendesk. A later fresh worker pass writes Solved and verifies convergence.
10. **Check the result.** Zendesk is Solved, GitHub Closed and Grafana Synced. Delivered counts can then advance as queued messages resume. Reopening later requires a separate exact-plan approval.

If both records already agree, there is no outbound state change to approve. GitHub Open and Zendesk Pending also agree in our canonical model; Pending is preserved. Approval is our business policy for a proposed cross-system state write, not an AI assessment of whether a defect is fixed. [Operations](OPERATIONS.md#inspect-and-review) covers stale pages and conflicts.

~~~mermaid
sequenceDiagram
    actor Operator
    participant ZD as Zendesk
    participant Bridge
    participant GH as GitHub
    Operator->>ZD: Save Open ticket and escalation tag
    Bridge->>ZD: Validate fresh initial report
    Bridge->>GH: Create marked issue and record ID/link
    Note over Bridge: Verify fields and initialize reverse starting point
    Operator->>GH: Ask engineering question
    Bridge->>ZD: Copy as attributed internal note
    Operator->>ZD: Add new internal answer
    Bridge->>GH: Copy as attributed comment
    Note over Bridge: Durable ledgers prevent echoing copies
    Operator->>GH: Close verified issue
    Note over Bridge: Save complete plan and await approval
    Operator->>Bridge: Inspect and approve exact plan
    Bridge->>ZD: Apply on later fresh worker pass
    Note over Bridge: Verify convergence before baseline advance
~~~

## Demonstration cases

The catalogue contains ten fictional cases. The following end states were observed on 2026-11-02; later source edits can change them. Actual Zendesk IDs, full dialogue, plan hashes and captured responses stay in the private run index. GitHub issue links identify the fictional engineering records.

| Case | Engineering issue | Verified situation |
| --- | --- | --- |
| CSV export | [#34](https://github.com/AlbertFlorescu/support-bridge/issues/34) | Solved/Closed, Synced after explicit resolution and reopening exercises |
| Duplicate webhooks | [#35](https://github.com/AlbertFlorescu/support-bridge/issues/35) | Pending/Open, Synced while support awaits another retry sample |
| SSO refresh | [#36](https://github.com/AlbertFlorescu/support-bridge/issues/36) | Open/Open, conflicting subject edits; later messages waiting |
| Invoice PDF | [#38](https://github.com/AlbertFlorescu/support-bridge/issues/38) | Pending/Open, Synced while waiting on an external font dependency |
| Notification preferences | [#37](https://github.com/AlbertFlorescu/support-bridge/issues/37) | Open/Open, Synced after separately approved resolution and regression reopening |
| UTF-8 import | [#40](https://github.com/AlbertFlorescu/support-bridge/issues/40) | Open/Open, Synced; support's narrower subject propagated |
| API throttling | [#41](https://github.com/AlbertFlorescu/support-bridge/issues/41) | Pending/Open, Synced; engineering's narrower title propagated |
| Mobile refresh token | [#42](https://github.com/AlbertFlorescu/support-bridge/issues/42) | Open/Closed, Awaiting approval to solve Zendesk |
| Stale seat count | [#43](https://github.com/AlbertFlorescu/support-bridge/issues/43) | Solved/Open, Awaiting approval to close GitHub |
| Search normalization | [#44](https://github.com/AlbertFlorescu/support-bridge/issues/44) | Open/Open, different support/engineering subjects retained as a conflict |

The five additional cases each completed at least two question/answer exchanges. UTF-8 import has three engineering deliveries and two support replies; throttling has three each. Mobile, seats and search each retain two delivered each way plus one later message waiting in each direction. They deliberately demonstrate a review/conflict hold without silently approving or choosing a source.

In the invoice case this tenant rejected Hold because its custom On-hold status was inactive. Pending was used instead; no tenant setting was changed. The SSO and search conflicts retain both source summaries, not a fabricated winner.

## Failure and evidence boundaries

Field recovery persists a target before writes. After a temporary error or lost response, a later pass reads both systems before deciding whether any patch remains necessary. A record already at target is not patched again. Unrelated changes stop recovery; verified convergence alone advances the baseline.

Creation and conversation appends have stricter recovery: an attempted unknown POST is searched by its exact marker and is never blindly repeated. Controlled fault tests cover these paths; the ten live cases do not constitute a vendor outage or lost-response experiment.

Private checkpoint captures and [offline replay](../tools/demo-viewer/README.md) present recorded observations. Capture, inspection and monitoring are sequential, not an atomic vendor snapshot. Playback performs no synchronization, source selection or approval. Message edits/deletions, attachments, production load and measured customer benefit are outside this demonstration.
