# Dependency vulnerability management

We use Dependabot and GitHub Actions to detect dependency vulnerabilities, retain evidence and prepare reviewed fixes. This is repository maintenance. It does not authorize escalation writes or establish compliance. Never rely on AI in compliance.

## Architecture overview

| Component | Responsibility |
| --- | --- |
| Dependabot alerts | Match supported dependency manifests against GitHub-reviewed advisories. Dependency graph and automatic security updates are enabled for this private repository. |
| Dependabot security updates | Open npm fix PRs, including development dependencies. Swagger UI is development-classified but its browser files ship with the application. Security updates run in response to alerts rather than waiting for the weekly version-update schedule. |
| Vulnerability inventory workflow | Build and scan the application, verification, Prometheus and Grafana images, audit all locked npm dependencies, and read native Dependabot alerts. |
| CVE enrichment | Fetch exact detected CVE IDs from the official CVE List and NVD. Preserve source status, missing metadata and enrichment limits. |
| Vulnerability tracker | Update one managed GitHub issue from trusted default-branch runs. Retain raw JSON and a readable report as Actions artifacts for 14 days. |
| Dependency asset repair | Regenerate the allowlisted Swagger files on eligible dependency PRs, commit against the exact observed head and explicitly dispatch CI. No automatic approval or merge is performed. |

The workflows live in [`.github/workflows/vulnerabilities.yml`](.github/workflows/vulnerabilities.yml) and [`.github/workflows/dependency-assets.yml`](.github/workflows/dependency-assets.yml). [Our CI policy](docs/CI.md) defines the normal checks, dependency lines and current branch-rule limitation.

## Sources and matching

Dependabot uses the [GitHub Advisory Database](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/github-advisory-database). It cannot use an arbitrary NVD or CVE List URL as its advisory feed. Docker version PRs are separate from dependency-graph vulnerability detection. SHA-pinned Actions continue to receive reviewed version updates; native Actions vulnerability alerts do not cover those SHA references. [GitHub's ecosystem coverage](https://docs.github.com/en/code-security/reference/supply-chain-security/dependency-graph-supported-package-ecosystems) describes these boundaries.

We therefore also scan built image archives with a digest-pinned official Trivy image. The scanner receives no Docker socket, application credentials or running data volumes. It uses a newly downloaded vulnerability database, validates its timestamp, and retains findings with no available fix. npm auditing includes development dependencies and records the lockfile used for matching.

[CVEProject/cvelistV5](https://github.com/CVEProject/cvelistV5) supplies official CVE records. [NVD](https://nvd.nist.gov/developers/vulnerabilities) supplies additional analysis when available. Our integration enriches exact CVE identifiers already detected by the ecosystem-aware tools. It does not search product names and assume a CPE match, infer that a vendor backport is vulnerable from an upstream version string, or treat a CVE record as an executable fix instruction.

Requests are bounded, timed out and rate-limited. No NVD key is needed for the default workflow. Missing records, rejected identifiers, absent CVSS scores, rate limits and service failures remain explicit report states. A rejected record does not silently dismiss a scanner finding. GHSA-only findings remain visible even without a CVE alias. Enrichment can be truncated without truncating the underlying finding inventory.

This product uses data from the NVD API but is not endorsed or certified by the NVD.

## Tracking and operating policy

The inventory runs on pull requests, pushes to `main`, a daily 05:23 UTC schedule and manual dispatch. GitHub scheduling is best effort. The report records the revision, component reports, scanner/database information, findings and source coverage. A successful collection job means the inventory completed; it is not a declaration that no vulnerabilities exist or an acceptance of the remaining risk.

Findings retain component, package identity, observed version, advisory ID, severity and vendor-provided fixed-version information. Native Dependabot observations describe its default-branch graph, so their installed version remains unknown; we do not apply a PR's lockfile version to an older native alert. Unknown severity is not treated as zero. Fixed-version strings remain vendor data; we do not apply a generic version comparator to every package ecosystem.

Only trusted default-branch runs publish the tracker. A separate job has `issues: write`; scan jobs cannot edit issues. The publisher uses a managed marker, expected bot authorship and a stable finding fingerprint to avoid duplicate issues and repeated unchanged updates. Operator text outside the managed section is preserved. An incomplete inventory cannot clear prior findings. We do not automatically dismiss alerts, close a tracker as resolved, accept risk or claim a customer deployment has been repaired.

The issue links to the evidence that changed its findings; the workflow's run history contains subsequent inventories. Artifacts expire after 14 days, so they are an operational aid rather than a permanent audit archive. Missing inventory artifacts produce a fallback incomplete report that retains earlier findings. A failed inventory, missing database, malformed source report or unreadable native alert source is visible as incomplete coverage. Publisher/GitHub outages can still prevent an issue update; check failed workflow runs. Metadata service failures never turn findings into a clean result. If a maintainer closes the managed issue, later runs update its body without reopening it; reopen it manually to restore open-issue visibility.

## Fix workflow

For supported npm advisories, Dependabot proposes the package and lockfile changes. We group security fixes separately from routine version updates and keep development dependencies in scope. Every proposal still needs review and passing CI.

The application serves committed Swagger browser assets. A lockfile-only update can leave those assets stale. Native and container CI therefore compare the four served files with the installed `swagger-ui-dist` package. A stale asset fails verification with a regeneration instruction.

After a dependency PR's Verify run, the repair workflow uses trusted default-branch code to select an open, same-repository Dependabot PR targeting `main`. It rejects changes outside the two npm manifests and four Swagger asset paths. It reads exact-head manifests through GitHub's API, installs registry dependencies in isolated temporary storage with scripts disabled, and copies static bytes without executing PR scripts or package code. Integrity, path, symlink and size checks constrain the prepared artifact.

An isolated publisher rechecks the PR and its head, validates the artifact, and commits only the allowed static files with GitHub's atomic `createCommitOnBranch(expectedHeadOid)` mutation. A changed head rejects the commit, including a concurrent reset to an ancestor. GitHub suppresses workflows normally caused by its own workflow token, so the publisher explicitly dispatches Verify for the new revision. The workflow token can write contents and dispatch Actions in that job; it cannot grant business approval and the workflow never approves or merges a PR.

Maintainers can manually dispatch **Repair dependency assets** on `main` with a numeric `pr` input for an eligible same-repository dependency PR. Manual selection also checks the PR author's repository write permission. This is useful for a maintainer-authored fix or a repair retry. It does not permit arbitrary changed files or execution of the PR's code.

Container findings show upstream fixed versions where known. Dependabot continues to propose supported-line image updates, and the inventory scans candidate PR images. A required fix outside the selected line needs an explicit migration review; no available fix remains an open finding. We do not rewrite arbitrary Docker instructions, run blind package upgrades, suppress advisories or deploy containers automatically. Newly built images and a running deployment can differ; a successful rebuild is not deployment evidence.

## Local verification

Run the deterministic security-tool tests:

```bash
python3 -m unittest discover -s tools/security -p 'test_*.py' -v
```

With Docker, Node 24 and npm available, collect and enrich a local inventory:

```bash
python3 tools/security/scan.py --output /tmp/support-bridge-vulnerability-input
python3 tools/security/report.py build --input /tmp/support-bridge-vulnerability-input --output /tmp/support-bridge-vulnerability-report --repository AlbertFlorescu/support-bridge --sha REVISION --run-url RUN_URL
```

Use actual revision/run values. Reading private native alerts requires an appropriately scoped GitHub token in `GITHUB_TOKEN`; do not print it or add it to source. Local collection can report incomplete native coverage without that credential. The repository workflows provide their scoped token automatically.

## Trade-offs

- Native Dependabot handles ecosystem-aware fix proposals; the supplementary inventory covers image dependencies that native alerts omit. These sources can overlap or disagree.
- Fresh database downloads cost time and network bandwidth. A database or source outage reduces coverage visibly rather than reusing unknown-age data as a clean result.
- Exact-ID enrichment is bounded and traceable. It cannot discover an advisory the matching tools did not detect.
- One managed issue limits notification noise. JSON artifacts retain detail that a compact issue cannot display, with finite retention.
- Automatic static-asset repair completes dependency proposals while keeping execution and approval separate. It adds a narrowly scoped write-capable job and still requires maintainer review.

## Known limitations

Scanner coverage is not complete application coverage. Trivy's PHP package support concerns Composer manifests; this project has none. The source-built PHP runtime and copied Node executable are not ordinary distribution-managed packages. Go binary dependency detection depends on retained build metadata, and it does not prove complete identification of Prometheus or Grafana application vulnerabilities. We do not claim reachability analysis, exploitability assessment, complete license review, SAST, DAST or vulnerability-free images.

External databases can lag, disagree or omit analysis. NVD enrichment is not guaranteed for every CVE. Bounded requests can leave metadata unavailable. Scanner database age checks do not establish advisory completeness. A vendor fixed version does not prove that every supported image or distribution has published the fix.

The current private-repository plan does not enforce our prepared branch rule. CI and security reports remain maintainer controls, not a server-side barrier to direct pushes. Workflow token permissions and the repair allowlist do not establish separation of duties or verified human review. Never rely on AI output to accept risk, authorize a runtime write or determine policy satisfaction.

## Reporting a vulnerability

Report suspected vulnerabilities privately to the repository maintainers through an established private channel. Include the affected revision/component, advisory identifier, reproducible evidence and suggested mitigation. Keep credentials, customer records and exploit secrets out of public issues and logs. The generated dependency tracker is an inventory aid, not an incident-response or disclosure service.
