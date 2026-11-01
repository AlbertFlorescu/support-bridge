# Recorded workflow viewer

Render private timestamped evidence as self-contained offline HTML using Linux Node in WSL:

~~~bash
node tools/demo-viewer/render.cjs --input /private/run/evidence.json --output /private/run/replay.html
node --test tools/demo-viewer/viewer.test.cjs
~~~

No third-party package is required. [Operations](../../docs/OPERATIONS.md#recovery-and-evidence) owns capture; [the scenario](../../docs/SCENARIO.md) distinguishes narrative and execution.

## Evidence contract

Version 1 input contains run identity/mode and chronological checkpoints: inspected pair, canonical provider observations, exact plan/conflict/approval evidence, allowlisted events and optional numeric monitoring. Capture binds repository/tenant and permits at most 100 checkpoints, 200-byte labels and 500-byte operation descriptions. Renderer bounds are 250 checkpoints and 2 MiB. Missing monitoring stays **Not captured**; zero remains zero.

The allowlisted projection omits raw responses, arbitrary rationale/messages, individual principals and unknown fields. Credential/email patterns are rejected, but this is not complete redaction. Use only owned fictional or explicitly permitted evidence; inspect it before sharing. Generated HTML still contains subjects and operational evidence.

Source links must be HTTPS GitHub/Zendesk links matching recorded IDs; monitoring links are bounded loopback URLs. Arbitrary schemes, userinfo and query-bearing source links are rejected. Text uses escaped JSON and DOM `textContent`; a hash-based CSP disables network and forms. Output is atomic and never replaces its input.

## Playback limits

Play/Next/Previous only select captured checkpoints. No API requests, reconciliation, source selection or approval occur. Provider reads, local inspection and monitoring were sequential, not an atomic snapshot. Live links and labels do not prove live effects, human authority, continuous freshness or compliance.

Tests cover evidence bounds, exclusions, script/URL handling, chronology, read-only DOM playback and CLI failure preserving output. The producer-to-viewer round trip is also checked by the PHP suite. Keep recordings outside source.
