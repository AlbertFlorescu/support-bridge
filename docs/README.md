# Documentation guide

Read [the project overview](../README.md) for the product and [SCENARIO.md](SCENARIO.md) for the primary customer case. The guides below own different questions; the OpenAPI document and running code define the implemented interface, while the verification record states what was actually exercised.

## Choose a guide

| Question | Maintained guide |
| --- | --- |
| What happens after support creates a ticket? | [Customer walkthrough](SCENARIO.md) |
| Which five customer cases can I demonstrate? | [Demonstration catalogue](DEMO_CASES.md) |
| Which assumptions did we make? | [Assumptions register](ASSUMPTIONS.md) |
| What can authorize a write or resolve a conflict? | [Governance](GOVERNANCE.md) |
| How do the code and failure-recovery boundaries fit together? | [Architecture](ARCHITECTURE.md) |
| How do I run the existing stack, find ports or sign in? | [Docker operation](DOCKER.md) |
| How are live identities, credentials and checkpoints selected? | [Live setup and evidence](LIVE_DEMO.md) |
| How does an explicit Zendesk tag create an issue safely? | [Engineering intake](INTAKE.md) |
| How do engineering questions and internal support replies travel both ways? | [Conversation delivery](COMMENTS.md) |
| How are Zendesk credentials renewed? | [OAuth operation](OAUTH.md) |
| What can the CLI/HTTP interface do? | [API guide](API.md), [OpenAPI](api/openapi.json) and [Swagger](http://127.0.0.1:1988/docs) |
| What is current state, history or a monitoring signal? | [Observability contracts](OBSERVABILITY.md), [monitoring quick guide](../monitoring/README.md) |
| Which checks and vendor observations actually passed? | [Verification record](REVIEW.md) |
| What do Actions, Dependabot and the CVE tracker cover? | [CI](CI.md) and [security maintenance](../SECURITY.md) |
| Where were development tools and AI used? | [AI disclosure](../README.md#ai-use) and [tooling evidence](TOOLING-INSTALLATION.md) |
| How do source changes reach main? | [Maintainer workflow](CI.md#maintainer-workflow) |
| How do I replay private captured observations? | [Viewer interface](../tools/demo-viewer/README.md) |

## Reading the design

There are three effectful workflow types: tagged issue creation, field reconciliation and conversation delivery. The opt-in worker runs intake, fields, forward GitHub comments and new reverse Zendesk notes in that order. Each preserves its own durable recovery evidence under the canonical business lock. State-change approval belongs to field reconciliation; an issue comment, retry, dashboard or source actor cannot provide it.

There are also two monitoring paths. Grafana's current table reads `/monitoring/pairs` with the read-only monitoring credential. It shows verified baseline subjects and source links. Prometheus scrapes numeric `/metrics` samples and supplies history, counts and rules. Neither monitoring path contacts vendors or executes a workflow.

## Documentation conventions

- The assumptions register records decisions and consequences without claiming customer acceptance.
- Current guides describe current behavior; dated verification sections retain the behavior exercised at that time.
- Commands select a deployment explicitly. Native local fixtures and disposable checks never share the operating demo's business volume.
- Source contains no credentials, private discussion, provider-response dump or runtime evidence archive.
- Diagrams explain control and data flow; only recorded executed checks substantiate behavior. No diagram, skill or generated explanation supplies approval or compliance evidence.

The [documentation-maintenance skill](../skills/support-bridge-documentation/SKILL.md) supports this review workflow. [AGENTS.md](../AGENTS.md) holds engineering invariants and links to the owning guides. [Local documentation checks](../tools/docs/check.cjs) validate links/anchors/fences without network access; they do not certify prose, external references or diagram semantics.
