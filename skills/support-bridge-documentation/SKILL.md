---
name: support-bridge-documentation
description: Review and maintain Support Bridge guides, diagrams, assumptions and verification evidence against the implemented workflow and selected deployment.
---

# Documentation maintenance

Start with [the guide index](../../docs/README.md), [AGENTS.md](../../AGENTS.md), current Git state and the relevant source/OpenAPI contract. Preserve uncommitted work. Keep product voice, explicit assumptions/trade-offs/limits and an honest AI-use disclosure. Credentials, private discussion and raw runtime evidence stay outside source.

Use one owning guide per question: SCENARIO for the customer story, DOCKER for connections/lifecycle, INTAKE/COMMENTS/OAUTH for their operating boundaries, ARCHITECTURE for implementation, OBSERVABILITY for metric/context semantics, CI/SECURITY for maintenance and REVIEW for actual evidence. Link those guides rather than copying increasingly different procedures.

Keep the Zendesk-first flow and the distinct recovery contracts: fields read before retry; attempted creation/appends never blindly repeat. Reverse internal replies use a persisted new-only starting point, exclude public/history/bridge copies, and retain separate direction counts. Any outbound state change holds its complete field plan for exact fresh approval. Never rely on AI in compliance or turn an explanation/skill/dashboard into approval.

Draw both monitoring paths accurately. The current twelve-column table queries protected `/monitoring/pairs` with last verified subjects and fixed links; Prometheus supplies the other seven panels/history from numeric `/metrics`. Subjects/URLs are not metric labels. Worker observation, table refresh and scrape/query clocks are separate. Missing/unknown evidence stays explicit.

Preserve dated verification sections as historical observations. Report local tests separately from hosted CI, vendor compatibility, customer acceptance and production readiness. Check current repository metadata read-only before making a fresh branch-protection claim; local rules/workflow files do not establish enforcement. Follow session authorization for publication; this skill grants none.

Run `node --test tools/docs/check.test.cjs` and `node tools/docs/check.cjs`. These verify local links/headings/fences only; parse/render changed Mermaid separately and inspect layout. Run PHP/style/API gates for runtime refactors and required disposable/actual-stack checks for container changes. Documentation-only edits do not justify mutating live state. Review AGENTS.md at close and update lasting decisions there with links, not a task diary.
