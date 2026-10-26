# Dependency vulnerability management

Dependabot proposes dependency updates; GitHub Actions scans our images and publishes a vulnerability tracker. Maintainers review fixes before merging or deploying them. This workflow has no authority over escalation writes. **Never rely on AI in compliance.**

## Architecture overview

| Component | Responsibility |
| --- | --- |
| Dependabot alerts | Match supported npm and Composer manifests against GitHub-reviewed advisories. Dependency graph and automatic security updates are enabled for this private repository. |
| Dependabot security updates | Propose supported npm/Composer fixes, including development dependencies. Swagger UI is development-classified, but its browser files ship with the application. PHP_CodeSniffer is development-only and absent from runtime images. Security updates respond to alerts independently of the version-update schedule. |
| Locked Composer audit | Verify checks the root development lockfile, including PHP_CodeSniffer. This is a CI package audit, not a separate custom tracker source. |
| Vulnerability inventory | Build and scan the application, verification, Prometheus and Grafana images; audit all locked npm dependencies; read native Dependabot alerts. |
| CVE enrichment | Retrieve exact detected CVE IDs from the official CVE List and NVD, recording missing data and source failures. |
| Vulnerability tracker | Update one managed issue from trusted default-branch runs. Keep JSON reports and a readable summary as Actions artifacts for 14 days. |
| Dependency asset repair | Regenerate allowlisted Swagger files, commit against the observed PR head and dispatch CI. Repairs do not approve or merge PRs. |

See the [inventory workflow](.github/workflows/vulnerabilities.yml), [repair workflow](.github/workflows/dependency-assets.yml) and [CI policy](docs/CI.md).

## Sources and matching

Dependabot uses the [GitHub Advisory Database](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/github-advisory-database), not a custom NVD or CVE List feed. Docker version PRs do not scan image contents. SHA-pinned Actions receive version updates but fall outside native Actions vulnerability alerts. See [GitHub's ecosystem coverage](https://docs.github.com/en/code-security/reference/supply-chain-security/dependency-graph-supported-package-ecosystems).

Our supplementary scanner uses a digest-pinned official Trivy image and scans built image archives. It receives no Docker socket, application credentials or running data volumes. Each run downloads a fresh vulnerability database, checks its timestamp and retains findings without fixes. npm auditing includes development dependencies and saves the lockfile used for matching.

The application has no Composer runtime packages. Composer installs locked development tooling with plugins and scripts disabled; native Dependabot and the Verify job's locked audit cover that package graph. The custom inventory still collects four built-image scans, npm audit and native alert observations. It does not import Composer audit JSON as another tracker feed. Native alerts can report supported Composer advisories through their existing source.

[CVEProject/cvelistV5](https://github.com/CVEProject/cvelistV5) provides official CVE records; [NVD](https://nvd.nist.gov/developers/vulnerabilities) adds analysis where available. We enrich IDs detected by the matching tools. We do not infer CPE matches from product names, judge vendor backports by upstream version strings or execute instructions from CVE records.

Enrichment is limited to 20 severity-ordered CVE IDs and a 180-second request budget, with six seconds between NVD requests. The default workflow needs no NVD key. Missing records, rejected IDs, absent CVSS scores, rate limits and service failures remain visible. A failed NVD batch stays partial; we do not retry each ID separately. Rejection does not dismiss the original scanner finding. GHSA-only findings are retained, and the metadata limit does not truncate the finding inventory.

This product uses data from the NVD API but is not endorsed or certified by the NVD.

## Tracking and operating policy

The inventory runs on pull requests, pushes to `main`, daily at 05:23 UTC and by manual dispatch. Scheduled runs are best effort. Reports include the revision, component results, scanner/database details, findings and source coverage. A successful job means collection completed; unresolved findings still require review.

Findings record component, package, observed version, advisory, severity and vendor fixed versions. Native Dependabot alerts describe its default-branch graph, so their installed versions remain unknown. We never substitute a PR lockfile version for an older native alert. Unknown severity stays unknown; vendor version strings are not compared using a generic version rule.

Only trusted default-branch runs publish the tracker. A separate job has `issues: write`; scan jobs cannot edit issues. The publisher finds its bot-owned issue by a managed marker and updates it when the finding fingerprint changes. It preserves operator notes outside the managed section and earlier findings when collection is incomplete. It does not dismiss alerts or change issue state. A manually closed tracker still receives body updates; a maintainer must reopen it to restore open-issue visibility.

The issue links to the run that last changed its findings; run history contains later inventories. Artifacts expire after 14 days. Missing artifacts produce a fallback incomplete report. Failed scans, missing databases, malformed source reports and unreadable native alerts also mark coverage incomplete. Metadata failures retain the findings. Publisher or GitHub outages can prevent an issue update, so check failed runs as well as the issue.

## Fix workflow

Dependabot includes development dependencies in supported npm and Composer fixes. npm security fixes are grouped separately from routine npm updates. Each proposal needs review and passing CI. Composer changes must preserve the development-only package boundary and the lockfile; the Swagger asset repair workflow remains restricted to its npm manifests and static assets.

A Swagger lockfile update can leave the committed browser assets stale. Native and container CI compare all four served files with the installed `swagger-ui-dist` package and fail with a regeneration instruction when they differ.

The repair workflow runs after a dependency PR's Verify run:

1. Trusted default-branch code selects an open, same-repository Dependabot PR targeting `main`. Only the two npm manifests and four Swagger asset paths may differ.
2. It reads manifests from the exact head through GitHub's API, installs public registry dependencies in isolated temporary storage with scripts disabled, and copies static bytes. It executes neither PR scripts nor package code. Integrity, path, symlink and size checks constrain the artifact.
3. An isolated publisher rechecks the PR, head and artifact, then commits only the static files through `createCommitOnBranch(expectedHeadOid)`. A changed head, including an ancestor reset, rejects the atomic commit.
4. Confirmation makes at most five read-only attempts. Only an otherwise eligible observation of the old head is retried; a third head or changed identity stops confirmation. The write is never repeated. Zero-diff PRs are accepted only during post-commit confirmation.
5. The publisher explicitly dispatches Verify because GitHub suppresses normal follow-up workflows from its own token. That job has content-write and Actions-dispatch permissions, and never approves or merges a PR.

If confirmation or dispatch fails after a commit, inspect the branch and run Verify manually. Maintainers can also dispatch **Repair dependency assets** on `main` with a numeric `pr` input. Manual selection requires an eligible same-repository PR whose author has repository write permission; the same file and execution restrictions apply.

The tracker links to open Dependabot PRs and image update files. Dependabot checks declared dependencies daily and can propose new image version lines; adoption still requires migration review and tests. A fixed OS or bundled Go package does not prove a newer parent image includes the fix. Image remediation needs an upstream release or reviewed rebuild and a new scan. We do not run blind package upgrades, rewrite arbitrary Docker instructions, suppress advisories or deploy containers automatically. The newly built inventory can differ from a running deployment.

## Local verification

Run the security-tool tests:

```bash
python3 -m unittest discover -s tools/security -p 'test_*.py' -v
composer install --no-interaction --no-plugins --no-scripts
composer audit --locked --no-plugins
```

With Docker, Node 24 and npm, collect and enrich a local inventory:

```bash
python3 tools/security/scan.py --output /tmp/support-bridge-vulnerability-input
python3 tools/security/report.py build --input /tmp/support-bridge-vulnerability-input --output /tmp/support-bridge-vulnerability-report --repository AlbertFlorescu/support-bridge --sha REVISION --run-url RUN_URL
```

Replace `REVISION` and `RUN_URL` with actual values. Private native alerts require a scoped token in `GITHUB_TOKEN`. Keep it out of source and logs. Without it, local collection reports incomplete native coverage. Repository workflows supply their scoped token automatically.

## Trade-offs

- Dependabot handles supported manifests; supplementary image scans cover dependencies it omits. The sources can overlap or disagree.
- Fresh databases cost CI time and bandwidth. Download failures produce incomplete coverage instead of a result based on unknown-age data.
- Exact-ID enrichment is traceable and bounded, but cannot discover advisories missed by the matching tools.
- One issue reduces notification noise. Artifacts hold the detailed inventory, with finite retention.
- Asset repair completes dependency proposals but adds a write-capable job. Maintainers still review the resulting PR.

## Known limitations

Trivy's PHP package scanning covers Composer manifests, but the runtime has no Composer packages. The root lockfile contains development tooling, audited separately and covered by supported native alerts. The source-built PHP runtime and copied Node executable are not ordinary distribution packages. Go dependency detection requires retained build metadata and may miss application-level Prometheus or Grafana vulnerabilities. The workflow does not perform reachability or exploitability analysis, full license review, SAST or DAST.

External databases can lag, disagree or omit analysis. Database freshness does not prove completeness, and a vendor fixed version may not yet exist in a supported distribution or image.

Our current private-repository plan does not enforce the prepared branch rule. CI and reports do not block direct pushes. Workflow permissions and the file allowlist do not establish separation of duties or human review. Maintainers remain responsible for risk acceptance and deployment; AI output cannot make those decisions.

## Reporting a vulnerability

Report suspected vulnerabilities privately to repository maintainers through an established channel. Include the affected revision/component, advisory ID, reproduction and suggested mitigation. Keep credentials, customer records and exploit secrets out of public issues and logs. The dependency tracker is not an incident-response or disclosure channel.
