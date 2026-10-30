# Monitoring quick guide

The existing `support-bridge-live-demo` deployment has one Grafana and one Prometheus. [Docker operation](../docs/DOCKER.md#choose-a-stack-and-its-ports) owns startup, credentials, retained volumes and connection troubleshooting.

| Surface | Address | Access |
| --- | --- | --- |
| Grafana dashboard | [127.0.0.1:1990](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) | Persisted Grafana account; bootstrap files may be stale after rotation |
| Prometheus | [127.0.0.1:1989](http://127.0.0.1:1989/) | No additional login on loopback |
| API reference | [127.0.0.1:1988/docs](http://127.0.0.1:1988/docs) | Documentation is public; record/command endpoints require their token |

## Read the dashboard

**All linked ticket and issue pairs** lists every stored mapping. Its subject and resolution come from the last verified common baseline. Click the Zendesk or GitHub number to open its source page in a new tab using your own vendor session. A newer edit can be pending worker observation or review; no baseline displays **No verified subject** / **No verified baseline**. A zero convergence timestamp means **Never verified**.

Delivered internal notes were observed in Zendesk. Waiting combines pending, sending, uncertain and blocked work; authenticated inspection gives the exact reason. A delivered count does not continuously recheck visibility or establish customer confirmation.

The other six panels show metric connection, total links, operator attention, intake totals, comment totals and workflow history. **Synced** means last stored convergence. Operator attention sums concerns and can count one escalation more than once. A connected exporter does not prove vendor credentials or current source state.

## Why two data sources?

| Data | Source used by Grafana | Time meaning |
| --- | --- | --- |
| Current table: subjects, clickable numbers, state and note counts | Protected Bridge API through the Infinity backend | Current stored snapshot, regardless of the history time range |
| Other panels and workflow timeline | Prometheus | Numeric samples; the timeline follows the selected range |

The table does not use Prometheus. Grafana can run with API data alone, but our selected stack retains Prometheus for metric history, queries and rules. It collects numeric `/metrics` samples every five seconds. Grafana refreshes both data sources independently every five seconds. The worker supplies new vendor observations, with a default 60-second delay after each pass; neither monitoring path contacts vendors.

A new mapping appears in the table after the link is saved and the next table refresh. The Prometheus panels may lag until another scrape. An untagged ticket or still-unlinked intake has no pair row. Reload once after a layout upgrade; new mappings thereafter require no dashboard edit.

## Access and operating limits

The shared monitoring token reads numeric metrics and every linked baseline subject/source URL. It cannot run operator commands. Grafana holds it server-side for the API-only allowed host; browser viewers have no per-pair authorization. Monitoring receives no vendor or operator credential. Source text, URLs, internal keys, errors and approval evidence never enter Prometheus labels.

Seven gauge series per mapping, complete table reads and retained numeric history grow with usage. Capacity is unmeasured. Three example Prometheus rules have no configured recipient or Alertmanager. No panel, model or playback supplies approval or proves compliance.

[OBSERVABILITY.md](../docs/OBSERVABILITY.md) owns metric contracts, data exposure, retention and verification. [REVIEW.md](../docs/REVIEW.md) separates controlled tests, actual local rendering and selected vendor observations. Never reset accounts or volumes to satisfy a smoke test.
