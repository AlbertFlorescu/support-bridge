# Development tools and review evidence

Our service runs on PHP with PDO SQLite and cURL. REA and Agency Agents are optional development aids; they are not service dependencies and are not needed to run the demo or tests.

## Pinned sources

| Tool | Pin | Purpose |
| --- | --- | --- |
| REA | `rea-agents@6.7.0` | Static inspection of the vendored Swagger JavaScript artifacts |
| REA release source | `27dc42d10249f0410904a75c0997842eb03b931c` | [Release provenance](https://github.com/morluto/rea/commit/27dc42d10249f0410904a75c0997842eb03b931c) |
| REA source inspected during setup | `6f28b3633ba2e9ae54e8a0de39b5921502dd8443` | Source inspection; distinct from the published runtime pin |
| Agency Agents | `4e3ac3ca6604c75113f52c695dbddcc7af928b46` | Architecture, implementation, API, security and verification review guidance |

We installed REA from its exact npm archive in an isolated prefix with `--ignore-scripts --omit=optional --no-audit --no-fund`. Optional native analysis engines were not installed. The matching bundled skill uses instruction version 42 and a 207-tool catalog.

The archive matched the registry integrity value:

```text
sha512-yhIx2ioJYYT1C/tONfYeitC6LhnKmCh+ZwRUOgYuhSc733lGhkkt73zhug9WmLgxoMdKuqIYGCoBfD4fXkOhFw==
```

We checked [npm provenance](https://registry.npmjs.org/-/npm/v1/attestations/rea-agents@6.7.0). Registry signature verification covered 146 dependencies and attestations for 33. These establish provenance; they do not establish dependency security.

## Review roles

We used seven selected [Agency Agents](https://github.com/msitarzewski/agency-agents) profiles: Backend Architect, Software Architect, Code Reviewer, API Tester, Reality Checker, Application Security Engineer and AI-Generated Code Security Auditor. We generated their native agent configuration format with the upstream conversion script and checked that the resulting TOML parsed.

We use these roles to challenge concrete decisions and failure cases. Their generic scale, coverage and service-level examples are not our requirements or achieved results. AI-assisted review is not independent human verification. Findings and executed checks belong in [REVIEW.md](REVIEW.md).

## REA access and scope

Our local MCP registration exposes only:

```text
binary_session
analyze_javascript_application
trace_application_feature
inspect_artifact
inspect_analysis_view
get_evidence_bundle
list_unknowns
```

Runtime, browser and device operations are outside this registration. We restrict REA here to supported shipped artifacts, rather than treating it as a PHP architecture checker. Setup follows the [upstream project](https://github.com/morluto/rea); machine-specific credentials, paths and configuration stay outside the repository.

The production MCP stdio server initialized successfully, matched all 207 tool definitions and six prompts, and accepted a target-free `binary_session` call. The observed catalog digest was:

```text
96d8f6e6ea1b1ba13557216624a3461c738729d53c79b4b0100d1f3a3a32441c
```

## Artifact analysis performed

We analyzed `public/assets/swagger-ui/initializer.js` and `swagger-ui-bundle.js` as inert JavaScript text with a 60-second per-source budget. The combined input was 1,684,589 bytes. The initializer parsed, but the 1,684,244-byte vendor bundle exhausted the approximately 1 GiB worker heap. That run provides partial evidence only.

A focused initializer pass read 345 bytes, parsed one file, visited 45 AST nodes and reported zero parse failures. Semantic coverage remained partial because global and dynamic behavior was unresolved and the vendor bundle was excluded. Zero reported findings is not a clean security assessment.

| Artifact | SHA-256 at analysis time |
| --- | --- |
| `initializer.js` | `4c548a49533e0f31939775c63bda768297bb2736302e4792663f342d6c4341c9` |
| `swagger-ui-bundle.js` | `3f6abf70480db7e07914df4c98a77385f22ea585eaf457a3e6415ba0eae2d167` |

Direct source inspection confirmed local `/openapi.json`, disabled external validation, disabled query configuration, disabled credential persistence and disabled submit methods. Those are source configuration facts. REA did not prove browser network behavior, run PHP, inspect customer records or call either provider. We reuse this evidence while the artifacts are unchanged; repeated analysis would not expand its coverage.

## Reusable project guidance

Our development, API and observability skills keep the recovery, transport and metric contracts together. The Git-versioned [escalation-review skill](../skills/support-bridge-escalation-review/SKILL.md) documents read-only business review. It cannot authorize execution or establish compliance, and its Markdown allowlist is not server-enforced access control. [AGENTS.md](../AGENTS.md) records our engineering and documentation conventions.
