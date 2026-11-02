# Security maintenance

Dependabot proposes updates; GitHub Actions inventories dependencies and maintains [the vulnerability tracker](https://github.com/AlbertFlorescu/support-bridge/issues/10). Maintainers review fixes before adoption. This process cannot approve escalation work or accept risk. **Never rely on AI in compliance.**

## Inventory and sources

| Source | Coverage and limits |
| --- | --- |
| Dependabot | Supported npm/Composer advisory matches and daily manifest/image/Actions update proposals, including newer runtime lines. It uses GitHub advisories, not a custom NVD feed; Docker update PRs do not scan contents. Native alerts describe the default-branch graph, not the scanned PR. |
| Verify | Locked Composer development audit, strict source checks and browser-asset freshness. There are no Composer runtime packages. Swagger browser assets ship; PHP_CodeSniffer does not. |
| [Inventory workflow](.github/workflows/vulnerabilities.yml) | Four built images—application, verification, Prometheus, Grafana—plus all locked npm dependencies and native alerts. Digest-pinned Trivy scans archives without a Docker socket, app credentials or running volumes. Each run checks a freshly downloaded database's timestamp. |
| CVE enrichment | Exact detected IDs from [CVE List](https://github.com/CVEProject/cvelistV5) and [NVD](https://nvd.nist.gov/developers/vulnerabilities). At most 20 severity-ordered CVEs, 180 seconds total and six seconds between NVD requests. Metadata limits never truncate findings. |

A successful collection can contain unresolved vulnerabilities. Missing databases, malformed inputs or unreadable alerts produce incomplete coverage and retain earlier findings. Rejected/missing CVEs, absent scores, GHSA-only findings and failed enrichment remain visible; failed NVD batches are not retried ID by ID. No product-name/CPE guessing or generic version comparison decides applicability.

This product uses NVD API data but is not endorsed or certified by NVD.

## Tracking and fixes

PR/main/daily/manual inventories retain reports for 14 days. Only a trusted default-branch publisher can update its bot-owned managed issue section; scan jobs cannot edit issues. Fingerprints avoid unchanged updates, human notes are preserved, and incomplete runs retain earlier findings. The workflow does not dismiss alerts or reopen a manually closed tracker. Check run failures too: publisher outages and expiring artifacts limit the record.

Dependabot security proposals and routine updates remain visible. Adoption needs current-revision CI and migration review; do not suppress a newer runtime line solely because the current deployment uses another. A vendor's fixed package version does **not** prove an available parent image contains the fix. Remediation needs a reviewed image/rebuild and another scan. Built inventory is not proof of the currently running image.

## Swagger asset repair

The [repair workflow](.github/workflows/dependency-assets.yml) complements eligible dependency PRs:

1. Trusted main code reads an open same-repository PR targeting main. Only its two npm manifests and four Swagger paths may differ.
2. Isolated public-registry install disables scripts; only static bytes are copied. Integrity, path, symlink and size checks constrain the one-day artifact. Neither PR scripts nor package code execute.
3. A separate publisher rechecks identity/artifact/head and commits only allowlisted assets through atomic `createCommitOnBranch(expectedHeadOid)`.
4. Up to five read-only confirmation attempts observe an eligible old head; changed identity/third heads stop. The write is never repeated. Verify is explicitly dispatched because workflow-token commits suppress ordinary follow-up events.

Repair never approves, merges or deploys. If confirmation/dispatch fails, inspect the actual branch and run Verify. Manual dispatch on main takes numeric `pr` and requires an eligible maintainer-authored PR. The same restrictions apply.

## Runtime data boundaries

The pinned signed Infinity backend has an API-only host allowlist and a monitoring-read token, without vendor/operator credentials. Its version is a Dockerfile CLI argument, so Dependabot does not discover it as a package manifest. Review releases/advisories and rerun backend/render/container checks before replacement; image scanning is not complete plugin coverage.

Conversation channels transfer potentially confidential text to the configured repository audience. There is no visibility check, DLP/redaction, source-edit retraction or per-pair authorization. Internal-only payloads can still trigger tenant notifications. Pending reverse notes are checked again for privacy/content; public-copy evidence holds both directions.

Fixed provider paths, bounded literal-text formatting and opposite-ledger IDs/markers constrain appends. Markers correlate copies; they are not signatures. Integration authorship is not verified role or approval. [Assumptions and authority](docs/ASSUMPTIONS.md) owns the complete data/identity contract.

## Verify and report

Run `python3 -m unittest discover -s tools/security -p 'test_*.py'` and the [full verification procedure](docs/OPERATIONS.md#verification). Native alerts, current image inventory, metadata enrichment and deployment observations are different evidence.

Main protection is governed by the active server ruleset and [publication procedure](docs/OPERATIONS.md#publishing-and-protection). Scans and allowlists do not establish independent review, exploitability, SAST/DAST, full license coverage or compliance. Source-built PHP, copied Node binaries, missing Go build metadata and external database gaps can limit detection.

Report suspected vulnerabilities privately to maintainers through an established channel, including revision/component, advisory and reproduction. Keep credentials, customer records and exploit secrets out of public issues/logs. The tracker is not an incident-response channel.
