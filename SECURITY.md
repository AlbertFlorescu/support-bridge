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

## Selected dependencies

The deployment uses PHP 8.5 on Debian 13, Grafana 13.3 and Prometheus 3.15; verification uses Node 26 LTS and Composer 2.11, with npm 12.2.1 mounted only during its build. Swagger UI 5.34.1 ships as checked-in static assets. Image digests and lockfiles select exact inputs. Supported distro patches are applied during image builds, so APT/APK repository state also affects the result. PHP build tools are removed from the runtime.

The November 2026 refresh adopts the six outstanding dependency proposals together, with regenerated Swagger assets. Debian 13 supplies a newer SQLite than Debian 12; available Brotli patches still require rebuilding. Review [PHP migration notes](https://www.php.net/manual/en/migration85.php), [Node support](https://nodejs.org/en/about/previous-releases), [Grafana releases](https://github.com/grafana/grafana/releases/tag/v13.3.0) and [Prometheus changes](https://github.com/prometheus/prometheus/releases/tag/v3.15.1). Infinity 4.1.5, Trivy 0.76.1, PHP_CodeSniffer 4.0.4 and pinned Actions were already current when reviewed.

An upgrade is accepted only after the verification suite, disposable lifecycle/renewal checks and retained-volume monitoring checks. Scan the resulting images again; keep remaining upstream findings in the tracker rather than suppressing them.

## Swagger asset repair

The [repair workflow](.github/workflows/dependency-assets.yml) complements eligible dependency PRs:

1. Trusted main code reads an open same-repository PR targeting main. Only its two npm manifests and four Swagger paths may differ.
2. Isolated public-registry install disables scripts; only static bytes are copied. Integrity, path, symlink and size checks constrain the one-day artifact. Neither PR scripts nor package code execute.
3. A separate publisher rechecks identity/artifact/head and commits only allowlisted assets through atomic `createCommitOnBranch(expectedHeadOid)`.
4. Up to five read-only confirmation attempts observe an eligible old head; changed identity/third heads stop. The write is never repeated. Verify is explicitly dispatched because workflow-token commits suppress ordinary follow-up events.

Repair never approves, merges or deploys. If confirmation/dispatch fails, inspect the actual branch and run Verify. Manual dispatch on main takes numeric `pr` and requires an eligible maintainer-authored PR. The same restrictions apply.

## Runtime data boundaries

The npm CLI is build-only and absent from the resulting verification/runtime images. The pinned signed Infinity backend uses an API-only host allowlist and a monitoring-read token, without vendor/operator credentials. Their versions are explicit build/workflow arguments, so Dependabot does not discover them as package manifests. Review their releases/advisories separately. Rerun backend/render/container checks before replacement; image scanning is not complete plugin coverage.

Conversation channels transfer potentially confidential text to the configured repository audience. There is no visibility check, DLP/redaction, source-edit retraction or per-pair authorization. Internal-only payloads can still trigger tenant notifications. Pending reverse notes are checked again for privacy/content; public-copy evidence holds both directions.

Fixed provider paths, bounded literal-text formatting and opposite-ledger IDs/markers constrain appends. Markers correlate copies; they are not signatures. Integration authorship is not verified role or approval. [Assumptions and authority](docs/ASSUMPTIONS.md) owns the complete data/identity contract.

## Verify and report

Run `python3 -m unittest discover -s tools/security -p 'test_*.py'` and the [full verification procedure](docs/OPERATIONS.md#verification). Native alerts, current image inventory, metadata enrichment and deployment observations are different evidence.

Main protection is governed by the active server ruleset and [publication procedure](docs/OPERATIONS.md#publishing-and-protection). Scans and allowlists do not establish independent review, exploitability, SAST/DAST, full license coverage or compliance. Source-built PHP, copied Node binaries, missing Go build metadata and external database gaps can limit detection.

Report suspected vulnerabilities privately to maintainers through an established channel, including revision/component, advisory and reproduction. Keep credentials, customer records and exploit secrets out of public issues/logs. The tracker is not an incident-response channel.
