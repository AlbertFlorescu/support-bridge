# Verification record

We separate controlled checks, actual deployment evidence and customer validation. The current demo uses one live project with original scenarios and Zendesk-first intake; Grafana automatically lists every stored mapping. Earlier dated fixed-case verification below describes the layout exercised then. Retired traffic-generation and native-monitoring results are not current capabilities; their implementation and operating guides have been removed.

## Retired source publication evidence: 2026-10-30

An earlier general publication workflow preserved a maintainer-authored SHA, ran reusable verification and opened a bot PR. Its local evidence included 18 controlled publisher tests and the shared PHP/API/style/security/documentation/container gates. These are historical results; those publisher tests and the reusable entry point are removed from current CI.

Source publication now uses [normal maintainer branches and PRs](CI.md#maintainer-workflow). The separate staging pipeline duplicated verification and added write authority without a product need. The workflow is disabled, its helper/guide removed, and repository-level Actions PR creation/review approval disabled while default tokens stay read-only. Dependabot, the vulnerability tracker and bounded asset repair remain separate maintenance workflows. No runtime, provider or approval behavior changed.

The earlier local validator did not recognize the unchanged vulnerability-alert permission, although current GitHub documentation and successful hosted inventories supported it. That permission remains. Historical local checks, bot publication and hosted results retain their original scope; removing the publisher does not relabel them as current tests.


## Automated checks

The current local validation passed **281 PHP checks**, **nine recovery-demo assertions**, **13 replay checks**, **three documentation-check tests**, **70 security-tool tests** and **four Prometheus rule cases**. Syntax passed for **175 PHP files**. Configured PSR-12 and strict PSR-4 gates passed, with **139 classes** in the optimized mapping. The locked Composer advisory audit reported no advisories for its development dependencies. Runtime images contain no Composer or Node development dependency tree.

| Suite | Checks | Scope |
| --- | ---: | --- |
| Domain | 8 | Merge truth table and retry arithmetic |
| Reconciliation | 30 | Durable plans, concurrency guards, retries, conflicts and unknown outcomes |
| Governance | 17 | Whole-plan approval, freshness, policy binding, migration and audit failures |
| Persistence | 6 | Scoped connections, transactions, locks and legacy plans |
| Application | 8 | Typed dispatch, provider-free table queries and unchanged reads while the canonical command lock is held |
| Console/runtime | 9 | Parsing, file secrets, locking, heartbeats and retired-role rejection |
| OAuth | 9 | Narrow grants, bearer/legacy authentication, expiry and private output |
| OAuth renewal | 14 | Issuance, scope/identity/expiry validation, due checks, failures, private publication and tenant canonicalization |
| Managed credential rotation | 8 | Per-request hot reads, static compatibility, expiry rejection and no inline write replay |
| Engineering intake | 24 | Explicit-tag creation, unknown outcomes, link-audit recovery, cooldown, bounded adapters, provider-free queries and retained state gate |
| Internal comment delivery | 21 | Immutable snapshots, internal-only payload/read-back, unknown outcomes, audit failures, review holds, privacy holds, bounds and query separation |
| Live bootstrap | 8 | Explicit identity/opt-in without source access or records |
| Credential import | 10 | Isolated import, private files, rotation and sanitized failures |
| Recorded evidence | 13 | Read-only capture, identity, bounds, ordering and exclusions |
| Provider HTTP | 20 | Controlled transport contracts, updates, versions and rate guidance |
| Observability | 26 | Durable telemetry, complete linked-pair/subject projections, fixed source URLs, precision/privacy validation, note totals and 105-link coverage |
| Operator API | 31 | Authentication, read-only monitoring context, strict contracts, approval and error mapping |
| Wire HTTP | 19 | Loopback HTTP, Swagger, exporter, protected table context and exact-plan workflow |

`php bin/check.php` runs the PHP checks and recovery assertions. The Docker verification target also runs style, Swagger asset equality, **16 OpenAPI paths**, **35 actual loopback response fixtures** under JSON Schema 2020-12 and the dependency-free replay tests. Controlled fixtures stay outside the operating demo's data.

These current changes have been verified locally and have not been pushed or run in hosted CI. GitHub Actions runs the PHP and container jobs and the aggregate **Required checks** gate. [Actions](https://github.com/AlbertFlorescu/support-bridge/actions) records the result for each published revision. A green workflow does not establish server-side branch protection.

## Design, documentation and monitoring review: 2026-10-30

All 26 maintained Markdown files, including repository skills and the PR template, were reviewed and revised. A new guide index identifies the owner of each topic; the detailed CSV-export case remains the primary walkthrough. Ten Mermaid diagrams parsed and rendered with Mermaid 12.2.0 in an isolated headless browser. Wide layouts were revised and key overview/customer diagrams inspected. Generated previews remain outside source. The offline link/anchor/fence check passed and is included in the existing native Actions job; it does not validate prose, external URLs or Mermaid syntax.

The focused PHP refactor moves current-table assembly into `GetLinkedPairTable` and its handler, shared through the application query dispatcher. `MonitoringController` now names the actual HTTP responsibility and constructs no table presenter. Constructor wiring, provider-free reads and lock-held query behavior passed existing behavior checks. A corrupt timestamp that would overflow integer milliseconds now fails the table projection. Saved plan/approval JSON, merge/retry policies and vendor capabilities were not changed.

Rendered verification exposed a genuine PromQL label-matching defect: three unlabelled aggregate terms were combined with raw job/instance-labelled gauges. The result could be empty rather than numeric, including when concerns existed. Both the dashboard and rule now aggregate all terms consistently. Four networkless promtool cases cover a real zero, pending approval, privacy hold and missing inputs. A controlled copy of the old rule failed both positive-concern cases, while the corrected rules passed. Missing inputs remain unknown; no fallback vector invents a healthy zero. The stack smoke now compares the result with stored concern counts and checks dashboard/rule consistency; the disposable lifecycle passed with pending approval retained.

The complete verification image passed PHP/style/autoload, Swagger equality, 16 OpenAPI paths, 35 wire-response fixtures and 13 replay checks. Three documentation tests and all 70 security-tool tests passed; the latter used the locked verification image's Linux Node binary in an isolated development prefix so the Node-dependent case was exercised. Separate bootstrap/import and managed-renewal wiring checks passed with controlled credentials and no vendor requests. These results are local, not hosted CI or a new image-vulnerability scan.

The existing local API/worker and monitoring images were refreshed without resetting accounts or volumes. With the worker paused, full before/after pair inspection, intake and comment-ledger responses matched for all six mappings present at deployment; all eight volume identities were retained, and the worker resumed. The current table is dynamic rather than a fixed three/five-case list. Private browser verification checks exact subjects, both source links, note counts and recurring table refresh without attaching to the user's session or fetching vendor pages. Monitoring configuration changes do not approve or perform business writes.

Read-only repository metadata checks confirmed public visibility, an unprotected main branch and an empty ruleset list. The review made no repository-administration, ref or source-code changes remotely; the already authorized worker retained its configured workflow. No commit/push or hosted CI dispatch was made for these local changes. Customer policy acceptance, measured business benefit, general restore/rollback, broader vendor compatibility and production capacity remain unvalidated.

## Verified subjects and direct source links: 2026-10-30

The current local verification image passed the gate above. Added checks cover baseline-only subjects during pending work, literal text containing markup/quotes/Unicode, null subjects/local-fixture links, fixed configured HTTPS destinations, large string identifiers and unchanged metric-label privacy. Adapter and wire tests cover monitoring authentication before context creation, operator-token denial, write/query-override rejection and sanitized invalid-subject failures. The locked development-dependency audit reported no advisories. Hosted CI was not run and no code was published.

The disposable lifecycle smoke executed the actual Infinity backend query using secure provisioning and its fixed API host, then confirmed pending-plan/credential persistence across recreation. The no-vendor live-bootstrap/import check also passed. The final Grafana image registers the signed plugin at pinned version 4.1.5 in `/opt/grafana/plugins`, including the explicit runtime path setting needed by the upstream image.

The existing API and Grafana were recreated locally with the same eight volume identities and persisted account. Complete pair inspection, intake and comment-ledger snapshots matched before/after while the worker was paused; the existing worker was then recreated and resumed. A fresh isolated headless browser verified ten visible table columns, all five subjects against their verified baselines, all ten source-anchor destinations, hidden URL helpers and recurring refresh. Clicks on both source numbers for the later intake mapping opened separate tabs; navigation was intercepted before provider network access, so this validates the rendered click targets rather than vendor-page availability. Monitoring performed no source writes or approvals. Private observations and screenshots remain outside source.

Subjects and URLs are provided by the authenticated current-context endpoint, not Prometheus labels/history. The table's subject can lag pending edits and its current snapshot does not follow the history time range. The shared monitoring-read authority now includes every linked baseline subject; its access and plugin-maintenance limits are documented. These local checks do not establish production capacity or customer data-sharing approval.

## Complete linked-pair dashboard verification: 2026-10-30

The local verification image passed the current gate above, including 24 observability groups. Empty stores, new mappings, unknown states, note-ledger totals, corrupt/duplicate/unsafe evidence and 105 links beyond the API's first page were exercised with isolated fixtures. OpenAPI retained 15 paths and validated 31 actual loopback response fixtures; the locked Composer development-dependency audit reported no advisories. No hosted CI or code publication occurred.

Disposable lifecycle checks passed all nine integration groups and verified pending-plan/credential persistence after recreation. The separate live-overlay bootstrap/import check passed without vendor requests. The first lifecycle attempt encountered a test-port forwarding collision before verification; the temporary project was cleaned up and the rerun used isolated host ports. Neither run reset the operating deployment.

The existing live project's API and Grafana were recreated with the complete linked-pair projection, retaining all eight volume identities and the current account. With the worker paused, complete before/after API inspection, intake and comment-ledger responses matched for all five stored pairs. The worker was then recreated and resumed with its existing opt-ins. Monitoring reads initiated no source changes or approvals.

Prometheus returned five current mappings and five samples in each of the seven per-link families, including one delivered internal note on the later intake pair. A fresh isolated headless browser authenticated with the retained Grafana account and verified the actual frontend table: nine correctly joined columns, five matching ticket/issue rows, baseline-only resolutions, timestamps and delivered/waiting note counts. The later intake row showed one delivered and zero waiting notes. Recurring datasource queries confirmed automatic refresh; visual inspection confirmed five labeled history rows and mapped workflow states. This browser used a fresh profile and did not attach to the user's session. Raw observations and screenshots remain private.

The old fixed-case families are retired without rewriting retained Prometheus history. New numeric-identifier series begin at rollout. These checks demonstrate local projection/rendering and preserved workflow evidence, not production capacity, continuous vendor freshness or customer acceptance. Numeric-ID exposure and seven-series-per-link growth are recorded in the assumptions and monitoring guides.

## Initial description transfer verification: 2026-10-30

The local verification image passed 273 PHP checks, including 24 intake groups, syntax for 169 PHP files, configured PSR-12 and strict PSR-4 with 133 mapped classes. OpenAPI 3.1 validated the unchanged 15 paths and 31 real loopback response fixtures; all 13 JavaScript replay checks passed. The locked Composer development-dependency audit reported no advisories. Hosted CI was not run and no code was published.

New intake checks verified complete initial-description transfer, an explicit Zendesk link, literal handling of embedded fences/mentions, UTF-8/byte bounds, known-empty versus unknown text, fresh snapshot persistence before POST and legacy attempted-snapshot recovery without another creation request. Discovery remains metadata-only; unsupported descriptions on already linked tickets do not prevent scanning metadata. Existing state-review and unknown-outcome checks remained green.

Disposable lifecycle and live-overlay checks passed with isolated fake credentials. Unapproved plans, credentials and normal project volumes survived; cleanup targeted only the temporary check projects. These checks are separate from vendor-account behavior.

A separately authorized existing-issue repair used the shared body formatter and the canonical lock. It backed up the original body and intake evidence privately, required the expected legacy body and a synced pair with no pending plan/conflict/approval, recorded an audit event before a body-only PATCH, and read the result back. All 1,350 bytes of the selected initial description were copied into the literal-text section. The explicit Zendesk link and original recovery marker were verified; issue title/state, saved mapping, baseline, retry budget and original intake snapshot were unchanged. A subsequent GitHub connector read independently confirmed the result. Numeric mappings, source text and raw evidence remain private.

The worker was paused for the upgrade/repair. All five saved pair rows matched after recreation before it resumed, and the existing eight volumes were retained. API version 1.6.0 and the worker are healthy on the unchanged loopback ports. Subsequent ordinary observations may advance timestamps and append audit/telemetry; that is not a body backfill or a state approval.

The live repair verifies the new formatter and an existing-issue update. Creation with the new body is covered by controlled transport tests; no extra live issue was created solely for validation. Description transfer is creation-time only, with no automatic legacy backfill, later conversation mirroring, redaction propagation or source-content execution. GitHub's preflight/PATCH race and tenant-specific policies remain limits.

## Internal comment delivery verification: 2026-10-30

The local verification image passed 267 PHP checks, including 21 comment-delivery groups, syntax for 168 PHP files, configured PSR-12 and strict PSR-4 with 132 mapped classes. OpenAPI 3.1 validated 15 paths and 31 real loopback response fixtures; 13 JavaScript replay checks passed separately. The locked Composer development-dependency audit reported no advisories. These local results were not published or run in hosted CI.

Controlled comment checks exercised historical import, one-time delivery, immutable source snapshots, lost append responses, local acknowledgement/audit failures, absent/duplicate/public marker evidence, durable privacy holds, safe-update payloads, source/destination bounds, cooldown, query authority and unchanged field-plan review. Actual appends in these checks used controlled ports; they do not establish vendor trigger or notification behavior.

Disposable lifecycle and live-overlay checks passed with isolated state and fake credentials. The lifecycle smoke validated the seven-panel provisioning and accepted only fixed delivery metric labels. Existing unapproved field work and credentials survived container recreation. Cleanup removed only those checks' own temporary projects.

Read-only live preflight found five linked issues with no existing GitHub comments. The new source and destination adapters successfully read both vendors using the existing ticket-scoped/repository credentials, with no scope expansion. We paused the worker, captured all five pair inspections, and verified byte-for-byte equality after additive migration/recreation before restarting work. The existing eight named volumes and Grafana account were retained.

The enabled worker now runs intake, field reconciliation and comment discovery. Five live discovery streams have no errors; the delivery ledger is empty, all five delivery state counts are zero and privacy hold is false. No source comment or destination note was created just to populate monitoring. A complete live comment-to-note append awaits a real source comment; it is not claimed as executed evidence here.

All five operating services are healthy on the retained ports. Prometheus reports five fixed comment-state series, zero discovery failures, zero privacy hold and a healthy authenticated scrape. A fresh isolated headless browser verified actual Grafana sign-in, the original three-case table/history, intake panel and new five-state comment panel. The browser check did not attach to the user's session or reset accounts. Screenshots, identifiers and raw vendor observations remain private.

These observations verify deployment, authenticated reads and empty discovery, separately from controlled append/recovery tests. Customer acceptance, notification delivery, production capacity, source redaction propagation and a live note append remain distinct limitations. The first natural issue comment can exercise the enabled path without a manual link or synchronization command.

## Zendesk-first intake verification: 2026-10-30

The current implementation passed the local verification image: 245 PHP checks including 18 intake groups, syntax for 152 PHP files, configured PSR-12, strict PSR-4 with 117 mapped classes, 13 OpenAPI paths and 29 real loopback response fixtures. The 13 JavaScript replay checks passed separately. The locked Composer development-dependency audit reported no advisories; that is not a complete runtime vulnerability assessment or hosted CI result. No code was published for these changes.

Disposable lifecycle, live-overlay bootstrap/import and OAuth-renewal checks passed using isolated state and fake vendor credentials. The lifecycle smoke now checks the six fixed intake series and sixth dashboard panel as well as the original three-case contract. These checks do not create vendor records.

A live read-only preflight found that Zendesk search returned 403 with the selected ticket-scoped credential. Ticket listing succeeded with the same scopes. Discovery therefore uses the ticket-list endpoint and filters tags locally, without broadening access. Preflight found no eligible unlinked tickets and confirmed the original three pairs matched their saved baselines.

Recreation retained the existing eight volumes and all three complete inspection snapshots byte-for-byte before worker activation. We then explicitly enabled the existing worker and tagged intake. A source-only process, without business state or operator credentials, created one owned fictional open Zendesk ticket with an internal note and the engineering tag. The ordinary worker discovered it, created one open GitHub issue, persisted the mapping and established a converged open baseline. Subsequent scheduled passes discovered/created no additional issue. Fresh provider reads verified all four pairs, and exact-marker lookup found the one intended issue. Numeric mappings, tenant links, snapshots and rendered evidence are private.

All five operating services are healthy: API, worker, credential renewer, Prometheus and Grafana. Prometheus reports four actual linked pairs and one linked intake intent, with the other five intake states zero. An isolated headless browser authenticated with the retained Grafana account and verified the six-state intake panel, original three-case table and sampled history. Actual authenticated ledger and metrics responses also passed the current OpenAPI schemas. These rendering checks did not attach to the user's browser or reset accounts.

After worker activation, original mappings, baselines, pending plans, approvals, states and attempt budgets remained intact. Real no-op observations advance convergence timestamps and append audit/telemetry observations; their history is not frozen. The new case remained open and no state approval was supplied. Closing/reopening approval, uncertain POST recovery and audit failure were exercised with controlled fixtures, not injected faults or automatic approval in vendor accounts. This verifies a selected fictional intake, not customer acceptance, notification delivery, every tenant rule or production capacity.

## Managed Zendesk renewal verification: 2026-10-30

The offline gate above passed with the renewal and per-request credential suites included. The disposable OAuth wiring check passed four groups covering service/credential isolation, managed publication, hot rotation and actual daemon health/restart persistence. It used a controlled issuer, fake credentials and an internal network without vendor egress. The ordinary lifecycle check also passed nine service-network groups plus pending-plan/credential persistence, and the manual live-overlay check passed all four summaries. Each check removed only its own resources.

The existing live project enabled the isolated `oauth-renewal` profile and added `issuer-secret` while retaining all seven existing volume identities. Complete API inspection responses for all three cases matched before and after deployment. Four long-running services now provide API, Prometheus, Grafana and credential renewal; the reconciliation worker remains disabled.

The deployed renewer obtained a real token with exactly ticket read/write scope, became healthy and advanced its loop heartbeat. The API container's deployed Zendesk provider used the managed credential for an authenticated GET of an existing fictional demo ticket, observing its open state. The API had no client-credential mount or startup-cached Zendesk token value. The existing GitHub access credential and legacy manual Zendesk token file remained unchanged; managed mode does not use that manual Zendesk file or renew GitHub credentials.

API/Swagger responded on 1988, Prometheus on 1989 reported the exporter up with all three stored case projections, and the retained Grafana account on 1990 authenticated successfully with five panels and datasource health `OK`. These observations did not edit vendor records, run synchronization, grant approval, retry business work or alter saved evidence.

Due-time renewal, failed issuance and hot rotation were exercised under controlled clocks and transports. The live check verified one actual grant and authenticated read; it did not wait four hours for a live expiry cycle. These are local results, with no new published revision or hosted CI run claimed. They do not establish continuous vendor availability, production capacity or customer validation. See [renewal operation and limits](OAUTH.md).

## Host-port verification: 2026-10-30

The local Compose defaults and operating deployment use loopback host ports 1988 for API/Swagger, 1989 for Prometheus and 1990 for Grafana. Container ports remain 8080, 9090 and 3000. All three host endpoints responded successfully; the exporter target was up and Grafana's datasource health was `OK`.

A fresh isolated headless browser verified the persisted Grafana account, the three case rows and mapped history labels on the new origin. Complete before/after API inspections matched for all three cases, and all seven live volume identities were retained. The worker remained disabled; no vendor records were read or changed by these deployment checks.

The offline verification gate passed, including the PHP, style, OpenAPI/wire and replay checks described above. The disposable lifecycle check passed nine service-network groups and preserved its pending plan and credentials across recreation. The no-vendor live check passed importer isolation, bootstrap, read-only readers and manual rotation. Both temporary projects removed only their own resources. These are local results; they do not claim a new published CI run, vendor freshness or customer validation.

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

Metrics are best effort and independent of the business commit. Every stored mapping appears automatically with validated numeric vendor-ID labels, seven series per link and no content/review labels. Monitoring access, retention and capacity require broader review. Stored states are last observed, with no provider request during scraping. Quiet manual time is expected and no longer triggers a polling-age alert. Three local rules have no configured notification recipient.

Never rely on AI in compliance. AI assisted development, review and documentation as disclosed in the [README](../README.md#ai-use); it cannot determine policy satisfaction, choose conflicting sources or approve writes.
