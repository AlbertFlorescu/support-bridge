# Monitoring

Open [Grafana](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) with the persisted project account, or [Prometheus](http://127.0.0.1:1989/) without a separate login on loopback.

The twelve-column **All linked ticket and issue pairs** table reads protected API JSON. It lists every mapping, last verified subject, clickable source IDs, workflow and independent message counts. The other seven panels use Prometheus numeric samples/history. Subjects and source URLs never become metric labels.

**Synced** is stored convergence, not a live vendor query. Worker observation, API refresh and Prometheus scrape are separate clocks. Pending/Open can be Synced; approval/conflict can retain an older verified subject. Unknown and Never verified are explicit.

**Review pending changes** opens the separate opt-in approval page; monitoring reads never approve or write.

[Operations](../docs/OPERATIONS.md#monitoring) owns interpretation, access, credentials, retention and troubleshooting. [Scenarios](../docs/SCENARIO.md) explains the ten fictional cases. Preserve volumes and current Grafana accounts.
