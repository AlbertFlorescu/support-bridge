# Local walkthrough and maintainer guide

We use synthetic local records to explore reconciliation, approval, conflicts and recovery without provider accounts or network access. Start with PHP 8.2+, PDO SQLite and cURL. Run commands from the repository root.

## Run the verified demonstration

```bash
php bin/demo.php
php bin/check.php
```

The demonstration retains its state under a fresh `var/demo-*` directory and prints an inspection command. Its assertions cover equal baselines, title propagation, a mixed title/state plan held for approval, explicit synthetic approval, lost-response recovery, conflicts and no-op polling. The scripted approval demonstrates the code boundary; it is not a real human decision.

The full gate lints PHP and runs pure policies, durable reconciliation/governance, provider contracts, observability, API and real loopback HTTP checks. No vendor credentials are required. [Verification evidence](REVIEW.md) records actual counts and remaining validation.

## Explore a linked escalation

```bash
php bin/bridge.php init
php bin/bridge.php sync
php bin/bridge.php local-edit zendesk 501 title 'Export fails above 100k rows'
php bin/bridge.php sync
php bin/bridge.php inspect escalation-1
```

The initial sample links GitHub issue `101` and Zendesk ticket `501`. Title-only work uses that link's opt-in. Inspection shows baseline, pending plan, conflict, approval, governance and recent events. It intentionally reveals stored titles to the operator.

To isolate an experiment, add `--home=var/walkthrough` consistently to every command. Local mode remains the default. The state database is bound to the selected provider identities and mode.

## Review a state change

```bash
php bin/bridge.php local-edit zendesk 501 state closed
php bin/bridge.php sync
php bin/bridge.php inspect escalation-1
# Review the plan and copy governance.plan_hash.
php bin/bridge.php approve escalation-1 PLAN_HASH 'Reviewed the escalation and agreed this resolution'
php bin/bridge.php sync
```

Replace `PLAN_HASH` with the inspected 64-character hash. The first sweep exits with code 2 and retains `awaiting_approval`; it applies no effect from that plan. Approval re-reads both providers and records an attestation without writing remotely. The next sweep rechecks evidence before execution. Reopening uses the same gate. A stale approval request refreshes a conflict instead of reusing old evidence.

The shared credential does not establish human identity or independent review. [Governance](GOVERNANCE.md) explains the authorization, compliance and approval-lifetime boundaries.

## Resolve a conflict

```bash
php bin/bridge.php local-edit github 101 title 'Engineering summary'
php bin/bridge.php local-edit zendesk 501 title 'Support summary'
php bin/bridge.php sync
php bin/bridge.php inspect escalation-1
php bin/bridge.php resolve escalation-1 github
php bin/bridge.php sync
```

A source choice selects one whole observed record and checks both snapshots and versions. It does not grant state-change approval. If either record changes after conflict detection, inspection/source selection must be repeated over refreshed evidence. Retry cannot bypass this review.

## Inspect recovery behavior

We persist target, both preimages and both versions before an effect. A timeout can leave a committed remote write without an acknowledgement. On restart, the worker reads actual records: an achieved target needs no resend, while a destination still requiring a write must retain its original observed version. Only verified convergence advances the baseline.

Useful files and tests:

| Boundary | What to trace |
| --- | --- |
| `src/Domain/MergePolicy.php` | Per-field baseline decisions and whole-pair conflicts. |
| `src/Domain/GovernancePolicy.php` | Field capability validation, state classification and plan/policy hash binding. |
| `src/Engine.php` | Observe, persist intent, request/check approval, apply, verify and recover. |
| `src/Store.php` | Atomic updates, canonical worker lock and additive approval migration. |
| `tests/run.php`, `tests/governance.php` | Partial commits, lost responses, stale evidence, approval and audit-failure cases. |

The GitHub preflight/PATCH gap and finite provider timestamps remain limitations; local tests do not eliminate them.

## Use the API and monitoring

The API exposes the same application rules. Follow [API.md](API.md) to start the loopback server and open Swagger. The documentation UI is read-only; the authenticated API still permits real commands. Deployment mode, hosts, credentials and state paths cannot be selected in request JSON.

```bash
bash monitoring/install.sh
python3 monitoring/stack.py up --demo
python3 monitoring/stack.py verify
```

The optional monitoring stack uses isolated synthetic state, serves only loopback listeners, and provisions an eight-panel Grafana dashboard. Operator attention includes conflicts, dead work and approval waits. Metrics use aggregate bounded labels and are separate from the business audit. Example rules have no notification recipient; local health does not prove vendor health or an agreed service objective.

## Before live use

We still need sandbox verification of account permissions, required Zendesk fields/triggers, custom statuses, rate limits and terminal tickets. Establish data-sharing rules, operator ownership, approval requirements, credentials, retention, backups and incident handling before opting in real records. Use [ASSUMPTIONS.md](ASSUMPTIONS.md) and [GOVERNANCE.md](GOVERNANCE.md) to record decisions, then follow the README's sandbox setup with a dedicated state directory.
