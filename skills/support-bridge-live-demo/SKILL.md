---
name: support-bridge-live-demo
description: Run an authorized Support Bridge demonstration against owned GitHub and Zendesk records and capture a private workflow replay. Use the traffic workflow for isolated synthetic load instead.
---

# Support Bridge live demonstration

Read [the live guide](../../docs/LIVE_DEMO.md), [customer walkthrough](../../docs/SCENARIO.md) and [governance](../../docs/GOVERNANCE.md). Inspect current deployment and mappings before acting. This skill supplies operating guidance, not permission to create credentials, vendor records or outbound effects.

## Deployment and records

Select `support-bridge-live-demo` with both Compose files explicitly. Verify actual host ports against the connection reference; the defaults are API8082, Prometheus9092 and Grafana3002. Keep its state and credentials separate from the default and traffic projects. Bootstrap creates schemas and local operator/monitoring credentials without provider requests, links or fixtures. The live worker is opt-in.

Use the documented private credential import. API, CLI and worker are the runtime credential readers; the isolated importer has the sole writable vendor-volume mount. Keep OAuth client secrets outside runtime containers and source. Reissue an expired access token manually with the narrow ticket scopes, import it, and recreate credential-reading containers. An existing Grafana bootstrap file does not prove the current login after account rotation.

Create only explicitly authorized fictional source records. Look up stable ownership markers before creating them; an uncertain creation outcome needs a read/search before another POST. Record the verified numeric mapping privately. Source setup actors do not receive the bridge operator token, supply approval, choose conflict winners or execute synchronization. Provider adapters reconcile existing records and never create them.

## Demonstration and authority

Inspect every linked pair before an explicit sweep: `sync` operates on all eligible pairs in this project. Capture the initial records, establish an equal baseline, edit one owned source title, then verify the actual bridge transfer by reading both vendors. Preserve existing durable plans, retry schedules, versions and audit evidence throughout.

A source edit already performed in a vendor is separate from a bridge outbound effect. Any outbound state transition holds the entire plan. Present the exact current target, preimages, versions, hash and policy for the operator's decision; general demonstration authorization is not an exact-plan approval. Never invent an attestation or approve from an AI recommendation. A conflict also requires an explicit fresh source decision. Retry and source resolution cannot approve a state change. Never rely on AI in compliance.

Use local reliability checks for injected failures; do not fabricate a live vendor outage. Keep the background worker disabled while presenting deliberate checkpoints unless its operation is authorized and understood.

## Evidence and presentation

Follow [capture and rendering](../../docs/LIVE_DEMO.md#capture-and-render-actual-observations) and the [viewer contract](../../tools/demo-viewer/README.md). Capture observes under the canonical lock without synchronization or approval. Vendor reads and aggregate telemetry remain sequential observations rather than a distributed snapshot. Missing monitoring data stays absent.

Keep the bounded archive bound to both provider identities. Labels and operation descriptions describe actions but do not prove them. Replay controls only navigate captured checkpoints; a video of playback is recorded evidence, not a live run. Review JSON, HTML, screenshots and video for private titles and vendor links before sharing. Store them outside source. Report local tests, authenticated vendor reads, actual outbound writes and customer validation separately.
