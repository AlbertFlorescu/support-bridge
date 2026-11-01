# Operations

We operate one `support-bridge-live-demo` Compose project. Keep its existing state, accounts, credentials and eight volume identities. Local-provider tests are isolated fixtures, never another source of live state.

## Access and sign-in

| Surface | Address | Credential |
| --- | --- | --- |
| Grafana dashboard | [127.0.0.1:1990](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) | Persisted Grafana account |
| Browser review | [127.0.0.1:1988/review](http://127.0.0.1:1988/review) | Existing session of an explicitly allowed Grafana administrator |
| Swagger | [127.0.0.1:1988/docs](http://127.0.0.1:1988/docs) | Public reference; submission disabled |
| Prometheus | [127.0.0.1:1989](http://127.0.0.1:1989/) | No additional login on loopback |

Use `127.0.0.1` consistently. Cookies are shared across ports on one hostname; the Grafana cookie name is scoped by Compose project. Signing into another hostname creates a different browser session.

Grafana credential files bootstrap a new database. They do not prove a rotated account's current password; rebuilding preserves the account. Never reset an account or volume to satisfy the standard smoke check, which assumes bootstrap `admin` credentials.

## Run the existing deployment

Use Ubuntu WSL at `/home/aflorescu/adobe` and Linux executables. Select both Compose files and the same private deployment file for every command:

~~~bash
docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  up --build -d --wait
~~~

Use the same prefix with `ps`, `logs --tail=100 api worker` or `stop worker`. Stop/down normally retains data. Never remove live or historical volumes as cleanup. Confirm actual port bindings if shell overrides or old bookmarks differ.

The private env file contains settings and identities, not credential values. The live overlay sets the application mode. Configure the account, import credentials, then deliberately enable effects:

~~~dotenv
BRIDGE_GITHUB_REPOSITORY=owner/escalations
BRIDGE_ZENDESK_SUBDOMAIN=your-sandbox
BRIDGE_ZENDESK_AUTH=oauth
BRIDGE_LIVE_HTTP_PORT=1988
BRIDGE_LIVE_PROMETHEUS_PORT=1989
BRIDGE_LIVE_GRAFANA_PORT=1990
COMPOSE_PROFILES=oauth-renewal,live-worker
BRIDGE_LIVE_SWEEP_INTERVAL=60
BRIDGE_ZENDESK_INTAKE_ENABLED=1
BRIDGE_GITHUB_COMMENTS_ENABLED=1
BRIDGE_ZENDESK_NOTES_ENABLED=1
BRIDGE_REVIEW_ENABLED=1
BRIDGE_REVIEW_LOGINS=approved-admin-login
~~~

Profiles and effectful flags default off. Preserve already selected profiles when changing settings; starting the worker initiates immediate work. Recreate readers after configuration changes. Runtime services are non-root with read-only roots, restricted writable mounts and loopback host ports. PHP's development server remains a development server; production ingress/TLS is separate work.

## Live demonstrations

Use owned fictional reports and the agreed destination audience.

1. Create an Open Zendesk ticket with a subject and initial description.
2. Add `support_bridge_escalate`. The worker creates an open issue and mapping; create no matching issue manually.
3. Confirm the pair is Synced. Wait for its reverse stream's `initialized_at` before adding the first internal support reply: the first complete scan excludes existing history.
4. Add a GitHub engineering question, observe its internal Zendesk copy, then answer with a **new internal note** and observe one GitHub copy.
5. Refine a summary, demonstrate an explicit state review or deliberately create a conflict using the [case guide](SCENARIO.md).

Intake copies the initial description once. Public Zendesk replies, later description edits, attachment bytes, assignees and priority do not synchronize. Both conversation directions wait during field review; later source edits/deletions do not change delivered copies.

The worker runs intake, fields, GitHub comments and new Zendesk notes in order, then waits 60 seconds by default. Provider visibility, rotating discovery pages, processing and retry times add latency. There is no synthetic traffic generator or autonomous source actor.

## Inspect and review

In Grafana, open both linked source records using their numbers. The table contains every mapping automatically.

Choose **Review pending changes**, or a row's workflow-state link. For a pending plan:

1. Check both record IDs and every proposed title/state update.
2. Expand plan identity/versions if needed.
3. Enter your own reason, confirm the review and click **Approve this exact change**.
4. Refresh current evidence after another worker pass. Verified completion shows Synced and no pending plan.

The server carries the exact hash; no token or hash copying is needed. The enabled login must be both allowlisted and a Grafana server administrator. The datasource remains read-only and receives no operator credential.

A review form expires after ten minutes. Reload an old page after an update; native form responses must preserve their same-origin Origin header. Stale source evidence is rejected, not overwritten. If a client times out, inspect before resubmitting. Recorded business approvals themselves have no automatic expiry/revocation.

Approval is our chosen rule for outbound closing/reopening. Equal canonical states require no effect or approval. Engineering closure can precede support verification, so a source closure alone does not authorize the bridge's other-side update. [Governance](GOVERNANCE.md) owns that boundary.

## API and CLI

[Swagger](http://127.0.0.1:1988/docs) and [OpenAPI](api/openapi.json) define all endpoint fields, schemas and errors. JSON `/api` reads/writes require the separate broad operator bearer; Grafana cookies do not authenticate those endpoints. Browser `/review` routes have their own opt-in session boundary.

For administration, the CLI shares application logic, state and lock:

~~~bash
docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  run --rm --no-deps cli status
~~~

Use `inspect PAIR_ID` for baseline, plan, versions, governance and recent audit. `resolve PAIR_ID github|zendesk` rechecks a conflict and queues the whole chosen source; it never approves. `approve PAIR_ID PLAN_HASH REASON` remains an alternative to the browser. `retry PAIR_ID` resets failed work's budget after its cause is corrected.

Manual `sync` processes **all eligible pairs**. Inspect the full link set and authorization first. HTTP 200 from a sweep means it ran, not that every pair succeeded. CLI exit 2 denotes operator attention and exit 3 retry work. A 409 can indicate busy work, stale evidence or an invalid operation; inspect the error category and current state.

## Credentials and renewal

Use a repository-scoped GitHub token with Issues read/write and required Metadata read. Use Zendesk ticket read/write scopes. Keep private files outside source; never put values in images, command arguments, env metadata, logs or recordings.

The networkless `credentials` importer accepts bounded JSON stdin with exactly `github-token` and `zendesk-token`. It writes only the protected `vendor-secret` volume. For example, pipe file contents directly:

~~~bash
python3 - <<'PY' | docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  run --rm --no-deps -T credentials
import json
from pathlib import Path
print(json.dumps({
    "github-token": Path("/absolute/private/github-token").read_text().strip(),
    "zendesk-token": Path("/absolute/private/zendesk-token").read_text().strip()
}))
PY
~~~

Each replacement is atomic; importing two files is not one transaction. After successful manual/GitHub rotation, recreate API and enabled worker readers. New CLI containers load current files. Importing manual files does not replace a managed bundle.

Managed Zendesk renewal uses a confidential client and four-hour ticket-only access tokens. The private configuration selects one managed source:

~~~dotenv
BRIDGE_ZENDESK_TOKEN_FILE=
BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE=/run/secrets/vendor/zendesk-oauth.json
~~~

Also leave `BRIDGE_ZENDESK_TOKEN` unset. Do not configure both managed and manual Zendesk sources.

The separate `oauth-credentials` importer accepts exactly `subdomain`, `client_id` and `client_secret` through JSON stdin and writes only `issuer-secret`. Import an existing owner-authorized client, then enable `oauth-renewal` and recreate readers once. Only the renewer reads issuer configuration; it writes an atomic `0400` access-token/expiry bundle. Application readers load it per request, so routine renewal requires no restart.

~~~mermaid
flowchart LR
    Import["Networkless client importer"] --> Issuer[("issuer-secret")]
    Issuer -->|"Read only"| Renewer["Zendesk renewer"]
    Renewer <-->|"Ticket-only issuance"| OAuth["Zendesk OAuth"]
    Renewer -->|"Atomic bundle"| Vendor[("vendor-secret")]
    Vendor -->|"Per-request managed read"| App["API, worker and explicit CLI"]
~~~

Renewal uses a separate credential lock and has no business state, operator token or approval capability. The shared vendor volume also exposes the unused GitHub token to the renewer; files are protected plaintext, not encrypted secrets. Client revocation, expiry, host downtime or vendor failure can interrupt access. GitHub credentials do not auto-renew.

Changing the client identity is a deliberate migration: pause the renewer, import the authorized replacement, and under the credential lock retire only the old managed bundle and its matching heartbeat before restarting. Never delete business work, credentials wholesale or retry/approval evidence to repair access. The manual `tools/oauth/issue-zendesk-token.php` helper supports explicit compatibility mode; inspect its help for private file arguments.

## Monitoring

Grafana uses two paths: current table JSON from `/monitoring/pairs`, and Prometheus history from `/metrics`. Both require the separate monitoring-read token, which cannot approve or synchronize. The pinned Infinity backend has only the API host allowed.

The table shows last verified subjects/resolution, workflow, pending plan, attempts, each direction's delivered/waiting counts and last verified convergence. Source links require the viewer's vendor login. Subjects and URLs are not metric labels; validated numeric IDs produce nine series per mapping. Ten links therefore add 90 per-link series, in addition to aggregate metrics.

| Signal | Interpretation |
| --- | --- |
| Connected / up | Successful scrape, not healthy vendor credentials |
| Synced | Supported canonical fields were verified in agreement |
| Pending/Hold with GitHub Open | Healthy canonical open agreement |
| Awaiting approval | Whole field plan is held for an exact decision |
| Conflict | Divergent/stale evidence; no source silently wins |
| Delivered/waiting | Stored delivery evidence; waiting also includes uncertain/blocked work |
| Resolution / subject | Last verified baseline, not current pending values |
| Zero timestamp / unknown | Never verified or unavailable; never fabricated success |

Worker observation, five-second scraping and dashboard refresh have separate clocks. The current table ignores history range. Counters include no-op polls and are diagnostics, not customer value or audit. Prometheus retains seven days by default, without backfill. Three example rules have no configured Alertmanager or notification recipient.

## Recovery and evidence

Field recovery reads before retrying; already achieved targets need no resend. Creation and append recovery are stricter: an attempted unknown outcome never authorizes another POST. Inspect errors rather than creating duplicate tickets or resetting attempts.

Back up SQLite consistently with WAL files using SQLite tooling, or stop writers first. Retain all live/historical volumes during upgrades. Restoring mixed snapshots, horizontal workers and network filesystems are unsupported.

Capture under the canonical lock observes without reconciling or approving. Provider and monitoring reads are sequential, not atomic. Keep identity-bound archives and [replays](../tools/demo-viewer/README.md) private. Playback navigates recorded checkpoints only.

[CI](CI.md) lists isolated verification and selected live evidence. Never rely on AI in compliance; monitoring, replay and generated explanations provide no authority.
