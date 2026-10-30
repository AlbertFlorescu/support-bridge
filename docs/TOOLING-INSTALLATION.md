# Development and verification boundaries

PHP, PDO SQLite and cURL run the service. Composer style tooling, Node validators and optional development review aids stay outside the runtime. They supply evidence and suggestions, not authorization.

## Review approach

We review architecture, code quality, API behavior, security and the strength of our evidence. The questions are concrete: is the use case explicit, does authority stay at the intended boundary, are unknown outcomes preserved, do tests cover observable behavior, and does each claim match what actually ran?

Specialist review profiles are guidance, not independent human approval. Generic scale, SLA, scanner and coverage examples are not customer requirements or achieved results. [REVIEW.md](REVIEW.md) records executed checks separately from customer/vendor validation. The [README AI disclosure](../README.md#ai-use) describes assistance and the decisions we deliberately keep deterministic.

## Artifact inspection scope

Optional static analysis was applied to the shipped Swagger JavaScript, not used as a PHP architecture or compliance validator. Development setup records and raw analysis bundles stay outside source. Ordinary source inspection and behavior/style/contract tests own the PHP review.

The focused initializer pass parsed one 345-byte file, visited 45 AST nodes and reported no parse failures. Semantic graph coverage remained partial because global/dynamic behavior was unresolved. A combined pass exhausted an approximately 1 GiB analysis heap on the 1,684,244-byte vendor bundle; that bundle was not completely analyzed. Finding counts are not security certification.

| Artifact | SHA-256 |
| --- | --- |
| `initializer.js` | `4c548a49533e0f31939775c63bda768297bb2736302e4792663f342d6c4341c9` |
| `swagger-ui-bundle.js` | `3f6abf70480db7e07914df4c98a77385f22ea585eaf457a3e6415ba0eae2d167` |

Source and locally served bytes matched these hashes during the 2026-10-30 review. Source inspection confirms local `/openapi.json`, disabled submit methods, no external validator/query configuration and no persisted authorization. Artifact identity does not establish browser execution, PHP recovery, vendor compatibility or compliance.

## Earlier scenario artifact observation: 2026-10-26

The earlier selected-source check parsed the matching initializer and attempted a literal route trace within partial graph coverage. The trace found no graph match; that is not evidence that the route was absent. Private analysis identifiers and raw bundles are not a portable audit. We preserve those limits instead of relabelling earlier evidence as a new analysis run.

## Reusable project guidance

[AGENTS.md](../AGENTS.md) holds lasting engineering invariants and links to the owning guides. The [documentation index](README.md) identifies those owners. Repository skills preserve [tagged intake](../skills/support-bridge-intake/SKILL.md), [internal comments](../skills/support-bridge-comments/SKILL.md), [live demonstrations](../skills/support-bridge-live-demo/SKILL.md), [read-only escalation review](../skills/support-bridge-escalation-review/SKILL.md) and [documentation maintenance](../skills/support-bridge-documentation/SKILL.md).

Skills are versioned guidance, not runtime privilege enforcement or a model decision engine. The shared operator token still grants broad authority; a Markdown allowlist does not restrict it. Never rely on AI in compliance.
