# Verification record

We separate controlled checks, actual deployment evidence and customer validation. The current demo uses one live project and three linked cases. Retired traffic-generation and native-monitoring results are not current capabilities; their implementation and operating guides have been removed.

## Automated checks

The offline gate passed **204 PHP checks**, **nine recovery-demo assertions**, **13 replay checks** and syntax checks for **123 PHP files**. Configured PSR-12 and strict PSR-4 gates passed. Runtime images contain no Composer or Node dependency tree.

| Suite | Checks | Scope |
| --- | ---: | --- |
| Domain | 8 | Merge truth table and retry arithmetic |
| Reconciliation | 30 | Durable plans, concurrency guards, retries, conflicts and unknown outcomes |
| Governance | 17 | Whole-plan approval, freshness, policy binding, migration and audit failures |
| Persistence | 6 | Scoped connections, transactions, locks and legacy plans |
| Application | 8 | Typed command/query dispatch and local reads without providers |
| Console/runtime | 9 | Parsing, file secrets, locking, heartbeats and retired-role rejection |
| OAuth | 9 | Narrow grants, bearer/legacy authentication, expiry and private output |
| Live bootstrap | 8 | Explicit identity/opt-in without source access or records |
| Credential import | 10 | Isolated import, private files, rotation and sanitized failures |
| Recorded evidence | 13 | Read-only capture, identity, bounds, ordering and exclusions |
| Provider HTTP | 20 | Controlled transport contracts, updates, versions and rate guidance |
| Observability | 21 | Durable telemetry, failures and actual-store case projections |
| Operator API | 29 | Authentication, strict contracts, approval and error mapping |
| Wire HTTP | 16 | Loopback HTTP, Swagger, exporter and exact-plan workflow |

`php bin/check.php` runs the PHP checks and recovery assertions. The Docker verification target also runs style, Swagger asset equality, **11 OpenAPI paths**, **27 actual loopback response fixtures** under JSON Schema 2020-12 and the dependency-free replay tests. Controlled fixtures stay outside the operating demo's data.

GitHub Actions runs the PHP and container jobs and the aggregate **Required checks** gate. [Actions](https://github.com/AlbertFlorescu/support-bridge/actions) records the result for each published revision. A green workflow does not establish server-side branch protection.

## Three-case dashboard verification: 2026-10-28

The deployed exporter reported exactly eighteen case samples across CSV export, Webhook delivery and SSO sign-in. Each case was present and stored as synced, with zero pending plans and failed attempts. The last verified baseline was resolved for CSV and open for the other two. The aggregate linked count was three, read from actual state rather than a constant.

Authenticated Grafana reads confirmed the five-panel dashboard, datasource health `OK` and the three operating rules. Datasource queries returned all three cases for every table column. A fresh isolated headless Chrome process then verified the shipped Grafana table after its frontend transformations: six columns, three case rows, correct state/resolution mappings and correctly converted convergence dates. The history also displayed mapped workflow labels instead of default numeric threshold ranges; the smoke verifier now guards that configuration. The test did not attach to or control the user's browser. Private screenshots and query evidence remain outside source.

The unchanged live project retains its seven state/credential/monitoring volumes. Pre/post inspection snapshots verify that this cleanup did not rewrite the three business records, baselines, pending work or audit evidence. The worker remains disabled and no source edits, synchronization or extra ticket creation were part of this change. The OAuth credential expired between sessions; same-scope manual renewal and private import restored access without broadening scopes.

The disposable lifecycle check passed all nine service-network groups and preserved an unapproved plan and credentials through recreation. The live-overlay check passed fake-token import, guarded bootstrap, read-only mounts and fresh-reader rotation with vendor requests disabled. Cleanup addressed only the checks' own projects. Actual rotated Grafana credentials were verified separately; bootstrap smoke assumptions were not used to reset the operating account.

## Live demonstration verification: 2026-10-28

We created and read back three fictional GitHub issues and Zendesk tickets, recorded their mappings privately and established equal baselines. Fresh vendor reads again confirmed matching titles: CSV was closed/solved, while webhook and SSO remained open.

The CSV-export case has executed evidence of title propagation in both directions. A later engineering title/state change produced a durable mixed plan and withheld every destination effect. After the operator's explicit decision over its exact hash and policy, the normal approval command and freshness checks ran. The ticket received its subject and solved-state update, and both records converged. A repeated sweep left the audited write-attempt count unchanged at three.

Eleven private checkpoints preserve those observations. Replay only navigates evidence and cannot supply approval or execute the workflow. The archive is mutable selected evidence, not a complete backup or tamper-evident audit. Source mappings, tenant links, credentials and media remain outside the repository.

Webhook and SSO are linked cases prepared for their broader walkthroughs; their proposed conflict/recovery sequences are not claimed as executed live tests. The shared operator credential does not prove individual identity, independent review, customer confirmation or compliance. These results cover selected fictional records, not every vendor rule, notification path, production workload or measured customer benefit.

## Primary scenario evidence: 2026-10-26

The [primary scenario](SCENARIO.md) documents expected source transitions and operator checkpoints. Local recovery and governance checks exercised the engine's failure paths before the selected live CSV run. The [tooling record](TOOLING-INSTALLATION.md) separately describes bounded artifact analysis and its partial coverage. Narrative checkpoints, executable fixtures and live vendor effects remain distinct evidence categories.

## Dependency review: 2026-10-25

Dated candidate reviews retained PHP 8.3, Node 24 and the Prometheus 3.13 LTS line. Candidate CI was compatibility evidence, not automatic adoption or production validation. The remaining migration concerns were PHP 8.5 cURL deprecation cleanup and existing Prometheus TSDB upgrade testing. Current image pins and reviewed adoption policy live in [CI.md](CI.md#dependency-maintenance-policy).

Dependabot proposals remain visible across runtime lines. The vulnerability workflow scans pinned images and locked tooling, enriches bounded CVE evidence and maintains its tracker. A package's fixed version does not prove that a parent image is available or repaired. [SECURITY.md](../SECURITY.md) states scanner/source limits and the maintenance procedure.

## Operating limits

The PHP development server, shared operator token and SQLite lock support a single-host demonstration. General backup restoration, production ingress/identity, individual approval attribution, provider-wide rate budgets, customer policy validation and production capacity remain unfinished. Titles are stored without application encryption and are not automatically redacted.

Metrics are best effort and independent of the business commit. Case labels are a fixed demonstration allowlist; new cases need a reviewed mapping change. Stored states are last observed, with no provider request during scraping. Quiet manual time is expected and no longer triggers a polling-age alert. Three local rules have no configured notification recipient.

Never rely on AI in compliance. AI assisted development, review and documentation as disclosed in the [README](../README.md#ai-use); it cannot determine policy satisfaction, choose conflicting sources or approve writes.
