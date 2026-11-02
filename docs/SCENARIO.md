# Scenario and case catalogue

We assume a B2B software team: support works in Zendesk, engineering in GitHub, and an escalation owner decides when a cross-system resolution is appropriate. The intended value is less copying and fewer stale handoffs; customer benefit remains unmeasured.

All catalogue records are fictional. Support/engineering/reviewer are demonstration roles using integration accounts, not independently authenticated people.

## Primary walkthrough

Use a CSV export that fails above 100,000 rows. Check [connections and enabled channels](OPERATIONS.md) first.

1. **Start in Zendesk:** create an Open ticket with subject and initial problem description. Use an internal report and controlled requester, then save `support_bridge_escalate`. Tenant notification rules still apply.
2. **Wait for intake:** the worker creates its open GitHub issue with the description and correct Zendesk link. Do not create the partner manually or remove its marker.
3. **Check Grafana:** the pair appears automatically with clickable source numbers and verified context. A refresh reads stored evidence; the worker normally waits 60 seconds after each pass.
4. **Refine the summary:** change the Zendesk subject to “CSV export fails above 100,000 rows”. Verify GitHub's title follows. One-sided GitHub edits also travel back.
5. **Exchange context:** engineering asks “Which sizes pass and fail?” in GitHub. Its copy is an internal Zendesk note. After reverse initialization, support answers in a **new internal note**: “80,000 rows passes; 120,000 fails.” Verify one attributed GitHub copy. Public replies/history are excluded.
6. **Complete engineering work:** close the fictional issue. Zendesk remains Open while the bridge prepares **Awaiting approval**. New messages can wait behind this field plan.
7. **Review:** use Grafana's workflow link, inspect the whole target, enter a reason, confirm and choose **Approve this exact change**.
8. **Verify:** a later fresh worker pass solves Zendesk and reads both sources back. Grafana becomes Synced and eligible messages resume.

For conflicting summaries, open a Conflict row, compare both observations, confirm and choose **Use Zendesk** or **Use GitHub**. This queues that side's title and canonical state; a resulting close/reopen still needs separate approval. [Operations](OPERATIONS.md#inspect-and-review) covers stale pages and recovery.

~~~mermaid
sequenceDiagram
    actor Support
    participant ZD as Zendesk
    participant Bridge
    participant GH as GitHub
    actor Engineer
    Support->>ZD: Save Open report and escalation tag
    Bridge->>GH: Create marked issue and retain mapping
    Note over Bridge: Verify agreement and initialize reverse cutover
    Engineer->>GH: Ask a question
    Bridge->>ZD: Copy as attributed internal note
    Support->>ZD: Add new internal answer
    Bridge->>GH: Copy attributed answer once
    Engineer->>GH: Close verified fictional issue
    Note over Bridge: Persist exact plan and await approval
    Support->>Bridge: Explicitly approve reviewed plan
    Bridge->>ZD: Solve on a later fresh worker pass
    Note over Bridge: Read back before advancing baseline
~~~

## Curated cases

Observed on 2026-11-03; later source edits can change these states. State pairs below mean **Zendesk / GitHub**. Grafana includes every mapping, including additional ad hoc tests outside this fictional catalogue.

| Case | Engineering record | Observed state / exercise |
| --- | --- | --- |
| CSV export | [#34](https://github.com/AlbertFlorescu/support-bridge/issues/34) | Solved/Closed, Synced after explicit state reviews |
| Duplicate webhooks | [#35](https://github.com/AlbertFlorescu/support-bridge/issues/35) | Pending/Open, Synced while waiting for a retry sample |
| SSO refresh | [#36](https://github.com/AlbertFlorescu/support-bridge/issues/36) | Open/Open, Conflict; messages held |
| Invoice PDF | [#38](https://github.com/AlbertFlorescu/support-bridge/issues/38) | Pending/Open, Synced; external dependency |
| Notification preferences | [#37](https://github.com/AlbertFlorescu/support-bridge/issues/37) | Open/Open, Synced after a separately reviewed reopening |
| UTF-8 import | [#40](https://github.com/AlbertFlorescu/support-bridge/issues/40) | Open/Open, Synced; support summary propagated |
| API throttling | [#41](https://github.com/AlbertFlorescu/support-bridge/issues/41) | Pending/Open, Synced; engineering summary propagated |
| Mobile refresh token | [#42](https://github.com/AlbertFlorescu/support-bridge/issues/42) | Open/Closed, Awaiting approval to solve Zendesk |
| Stale seat count | [#43](https://github.com/AlbertFlorescu/support-bridge/issues/43) | Solved/Open, Awaiting approval to close GitHub |
| Search normalization | [#44](https://github.com/AlbertFlorescu/support-bridge/issues/44) | Open/Open, Synced after an explicit Zendesk source choice |
| Daily usage date | [#52](https://github.com/AlbertFlorescu/support-bridge/issues/52) | Open/Open, Conflict; customer symptom vs aggregation diagnosis |
| Portrait previews | [#53](https://github.com/AlbertFlorescu/support-bridge/issues/53) | Open/Open, Conflict; visible rotation vs EXIF investigation |
| Concurrent board ordering | [#54](https://github.com/AlbertFlorescu/support-bridge/issues/54) | Open/Open, Conflict; workflow symptom vs rank-write investigation |
| Saved dashboard filter | [#55](https://github.com/AlbertFlorescu/support-bridge/issues/55) | Open/Open, Synced; three exchanges and support title update |
| Resumed upload | [#56](https://github.com/AlbertFlorescu/support-bridge/issues/56) | Pending/Open, Synced; two exchanges and engineering title update |
| Scheduled job progress | [#57](https://github.com/AlbertFlorescu/support-bridge/issues/57) | Solved/Closed, Synced; independently matching source closures |
| Mention notifications | [#58](https://github.com/AlbertFlorescu/support-bridge/issues/58) | Open/Closed, Awaiting approval; one later message held each way |
| Project template owner | [#59](https://github.com/AlbertFlorescu/support-bridge/issues/59) | Open/Open, Conflict; one later message held each way |

The five latest cases each completed two question/answer rounds; saved filters completed three. The job case demonstrates agreement after both fictional teams closed their own sources, with no outbound bridge state effect. The mention case retains a bridge closure plan for operator approval.

Pending/Open is healthy canonical-open agreement. This tenant rejected Hold because its custom On-hold status was inactive; Pending was used without changing tenant settings.

## Evidence boundaries

Live cases demonstrate selected vendor behavior. Temporary failures, lost responses and uncertain creation/appends are covered by controlled tests, not manufactured vendor outages. Delivered copies were read back for exact content, unique identity and internal visibility.

Private [checkpoint playback](../tools/demo-viewer/README.md) navigates sequential observations without executing decisions. Raw dialogues, source IDs and plan hashes stay in the private run index. No source text, model, dashboard or playback supplies approval or determines compliance.
