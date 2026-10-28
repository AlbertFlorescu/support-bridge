(function () {
  'use strict';

  const evidence = JSON.parse(document.getElementById('evidence-data').textContent);
  const checkpoints = evidence.checkpoints;
  const names = { new: 'Ready', synced: 'Synced', retry: 'Retry scheduled', conflict: 'Conflict', dead: 'Stopped', awaiting_approval: 'Awaiting approval' };
  const explanations = {
    new: 'Eligible work will be evaluated by the next sweep.',
    synced: 'The last reconciliation observed convergence for this pair.',
    retry: 'Durable work remains. Recovery must read current records before another effect.',
    conflict: 'Competing evidence requires a fresh, explicit source decision.',
    dead: 'Automatic retries have stopped. Inspect the cause before an explicit retry.',
    awaiting_approval: 'Every outbound effect in the state-changing plan is held for exact-plan review.',
  };
  let position = 0;
  let timer = null;

  function element(tag, className, value) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = String(value);
    return node;
  }

  function set(id, value) {
    document.getElementById(id).textContent = value;
  }

  function time(value) {
    return new Date(value).toISOString().replace('T', ' ').replace(/\.\d{3}Z$/u, ' UTC');
  }

  function title(value) {
    return value.replaceAll('_', ' ');
  }

  function badge(value, className = 'neutral-badge') {
    return element('span', className, value);
  }

  function link(label, url, className) {
    const node = element('a', className, label);
    node.href = url;
    node.target = '_blank';
    node.rel = 'noopener noreferrer';
    return node;
  }

  function definition(list, label, value, className) {
    const group = element('div', className);
    group.append(element('dt', null, label), element('dd', null, value));
    list.append(group);
  }

  function provider(side, record) {
    const node = document.getElementById(`${side}-record`);
    const heading = element('div', 'provider-top');
    heading.append(element('h3', null, side === 'github' ? 'GitHub issue' : 'Zendesk ticket'));
    heading.append(badge(record.kind === 'live' ? 'Live provider capture' : 'Local simulator', `source-badge ${record.kind === 'live' ? 'source-live' : ''}`));
    const metadata = element('dl', 'provider-meta');
    definition(metadata, 'Record ID', `#${record.id}`);
    definition(metadata, 'Canonical state', record.state);
    definition(metadata, 'Observed version', record.version);
    definition(metadata, 'Writable', record.writable ? 'Yes' : 'No');
    node.replaceChildren(heading, element('p', 'provider-title', record.title), metadata);
    if (record.url) node.append(link(record.kind === 'live' ? 'Open provider record ↗' : 'Open external reference ↗', record.url, 'provider-link'));
  }

  function table(headers, rows) {
    const node = element('table', 'comparison-table');
    const head = element('thead');
    const headRow = element('tr');
    for (const heading of headers) headRow.append(element('th', null, heading));
    head.append(headRow);
    const body = element('tbody');
    for (const row of rows) {
      const tr = element('tr');
      row.forEach((value, index) => {
        const cell = element(index === 0 ? 'th' : 'td', value === null ? 'empty' : null, value === null ? '—' : value);
        if (index === 0) cell.scope = 'row';
        tr.append(cell);
      });
      body.append(tr);
    }
    node.append(head, body);
    return node;
  }

  function comparison(checkpoint) {
    const pair = checkpoint.pair;
    const rows = ['title', 'state'].map((field) => [field === 'title' ? 'Summary' : 'State', pair.baseline?.[field] ?? null,
      checkpoint.records.github[field], checkpoint.records.zendesk[field], pair.plan?.target[field] ?? null]);
    document.getElementById('comparison').replaceChildren(table(['Field', 'Common baseline', 'GitHub captured', 'Zendesk captured', 'Pending target'], rows));
    set('plan-indicator', pair.plan ? 'Durable plan retained' : 'No pending write plan');
    const container = document.getElementById('plan-evidence');
    container.replaceChildren();
    if (pair.plan) {
      const details = element('details');
      details.append(element('summary', null, 'Inspect persisted preimages and versions'));
      details.append(table(['Evidence', 'GitHub preimage', 'Zendesk preimage'], [
        ['Summary', pair.plan.before.github.title, pair.plan.before.zendesk.title],
        ['State', pair.plan.before.github.state, pair.plan.before.zendesk.state],
        ['Version', pair.plan.versions.github, pair.plan.versions.zendesk],
      ]));
      container.append(details);
    }
    const conflict = document.getElementById('conflict-panel');
    conflict.hidden = !pair.conflict;
    conflict.replaceChildren();
    if (pair.conflict) {
      const heading = element('div', 'section-heading');
      heading.append(element('h3', null, 'Competing source evidence'), badge('Explicit source decision required', 'status-badge status-conflict'));
      conflict.append(heading, element('p', null, `Reason: ${title(pair.conflict.reason)}. Conflicting fields: ${pair.conflict.fields.join(', ')}. This replay cannot choose a source.`));
      conflict.append(table(['Evidence', 'GitHub observed', 'Zendesk observed'], [
        ['Summary', pair.conflict.observed.github.title, pair.conflict.observed.zendesk.title],
        ['State', pair.conflict.observed.github.state, pair.conflict.observed.zendesk.state],
        ['Version', pair.conflict.versions.github, pair.conflict.versions.zendesk],
      ]));
    }
  }

  function governance(checkpoint) {
    const { governance: review, pair } = checkpoint;
    const node = document.getElementById('governance');
    const metadata = element('dl', 'meta-grid');
    definition(metadata, 'Policy', review.policy_version);
    definition(metadata, 'Failed attempts', pair.attempts);
    definition(metadata, 'Retry due', pair.next_run === 0 ? 'Not scheduled' : time(pair.next_run * 1000));
    definition(metadata, 'Recorded error', pair.error === 'details_omitted' ? 'Present; details omitted' : pair.error ? title(pair.error) : 'None');
    const hashGroup = element('div', 'wide');
    hashGroup.append(element('dt', null, 'Exact plan hash'), element('dd', 'hash', review.plan_hash ?? 'No pending plan hash'));
    metadata.append(hashGroup);
    let message = 'No pending state-change review at this captured checkpoint.';
    if (review.requires_approval && !review.approved) message = 'Human review required: the whole plan is held. No replay control can provide approval.';
    if (review.approved) message = 'An attestation for this exact plan was recorded. A later sweep must recheck the evidence before effects.';
    node.replaceChildren(metadata, element('p', `governance-callout ${review.requires_approval && !review.approved ? 'waiting' : ''}`, message));
    if (pair.approval) node.append(element('p', 'small muted', `Attestation recorded ${time(pair.approval.approved_at * 1000)}. Shared authority does not verify a human identity.`));
  }

  function total(value) {
    return typeof value === 'number' ? value : Object.values(value).reduce((sum, count) => sum + count, 0);
  }

  function metric(label, value, subtitle) {
    const node = element('article', 'metric');
    node.append(element('p', 'metric-label', label), element('p', 'metric-value', value), element('p', 'metric-subtitle', subtitle));
    return node;
  }

  function metrics(checkpoint) {
    const values = checkpoint.metrics;
    const format = (value) => new Intl.NumberFormat('en', { maximumFractionDigits: 2 }).format(value);
    const count = (value) => value === undefined ? '—' : format(total(value));
    const statuses = typeof values.pairs === 'object' ? Object.entries(values.pairs).filter((entry) => entry[1] > 0).map(([status, count]) => `${title(status)} ${format(count)}`).join(' · ') : 'All configured pairs';
    document.getElementById('metrics').replaceChildren(
      metric('Metrics scrape', values.up === undefined ? '—' : `up=${values.up}`, values.up === undefined ? 'Not captured' : 'Exporter reachability at observation'),
      metric('Linked pairs', count(values.pairs), values.pairs === undefined ? 'Not captured' : statuses),
      metric('Pending plans', count(values.pending_plans), values.pending_plans === undefined ? 'Not captured' : 'Aggregate unfinished write plans'),
      metric('Reconciliations', count(values.reconciliations_total), values.reconciliations_total === undefined ? 'Not captured' : 'Counter observations, including no-ops'),
    );
  }

  function events(checkpoint) {
    const list = document.getElementById('events');
    list.replaceChildren();
    const ordered = checkpoint.events.map((event, index) => ({ ...event, ordinal: index }))
      // Captures retain insertion order, including events that share one timestamp.
      .sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at) || a.ordinal - b.ordinal);
    set('event-count', `${ordered.length} captured ${ordered.length === 1 ? 'event' : 'events'}`);
    if (ordered.length === 0) list.append(element('li', 'muted', 'No events were included in this checkpoint.'));
    for (const event of ordered) {
      const row = element('li');
      const description = element('div');
      description.append(element('p', 'event-type', title(event.type)));
      if (Object.keys(event.details).length > 0) description.append(element('pre', 'event-details', JSON.stringify(event.details, null, 2)));
      row.append(element('span', 'audit-time', time(event.created_at)), description);
      list.append(row);
    }
  }

  function stop() {
    if (timer !== null) clearInterval(timer);
    timer = null;
    set('play', 'Play replay');
    document.getElementById('play').setAttribute('aria-pressed', 'false');
  }

  function render() {
    const checkpoint = checkpoints[position];
    set('position', `${position + 1} / ${checkpoints.length}`);
    set('checkpoint-number', `Checkpoint ${position + 1} of ${checkpoints.length}`);
    set('checkpoint-label', checkpoint.label);
    set('operation', checkpoint.operation);
    set('observed-at', `Recorded observation: ${time(checkpoint.observed_at)}`);
    set('pair-id', checkpoint.pair.id);
    const status = document.getElementById('pair-status');
    status.textContent = names[checkpoint.pair.status];
    status.className = `status-badge status-${checkpoint.pair.status}`;
    set('status-explanation', explanations[checkpoint.pair.status]);
    const progress = document.getElementById('progress');
    progress.max = checkpoints.length;
    progress.value = position + 1;
    document.getElementById('previous').disabled = position === 0;
    document.getElementById('next').disabled = position === checkpoints.length - 1;
    for (let index = 0; index < checkpointButtons.length; index++) {
      checkpointButtons[index].className = `checkpoint-button ${index === position ? 'is-current' : ''}`;
      checkpointButtons[index].setAttribute('aria-current', index === position ? 'step' : 'false');
    }
    provider('github', checkpoint.records.github);
    provider('zendesk', checkpoint.records.zendesk);
    comparison(checkpoint);
    governance(checkpoint);
    metrics(checkpoint);
    events(checkpoint);
  }

  function move(index) {
    stop();
    position = Math.max(0, Math.min(checkpoints.length - 1, index));
    render();
  }

  const checkpointButtons = checkpoints.map((checkpoint, index) => {
    const button = element('button', 'checkpoint-button');
    button.type = 'button';
    button.append(element('span', 'step-number', index + 1), element('span', null, checkpoint.label));
    button.addEventListener('click', () => move(index));
    document.getElementById('checkpoints').append(button);
    return button;
  });
  set('run-id', evidence.run.id);
  set('run-meta', `${evidence.run.mode === 'local' ? 'Local provider run' : 'Live provider run'}${evidence.run.repository ? ` · ${evidence.run.repository}` : ''}`);
  set('run-captured', `Run captured ${time(evidence.run.captured_at)}`);
  set('revision', evidence.run.source_commit ? `Source revision ${evidence.run.source_commit}` : 'Source revision was not captured.');
  for (const [key, label] of [['grafana_url', 'Open Grafana ↗'], ['prometheus_url', 'Open Prometheus ↗']]) {
    if (evidence.run.monitoring[key]) document.getElementById('monitoring-links').append(link(label, evidence.run.monitoring[key]));
  }
  document.getElementById('previous').addEventListener('click', () => move(position - 1));
  document.getElementById('next').addEventListener('click', () => move(position + 1));
  document.getElementById('play').addEventListener('click', () => {
    if (timer !== null) { stop(); return; }
    if (position === checkpoints.length - 1) { position = 0; render(); }
    set('play', 'Pause replay');
    document.getElementById('play').setAttribute('aria-pressed', 'true');
    timer = setInterval(() => {
      if (position < checkpoints.length - 1) { position++; render(); }
      if (position === checkpoints.length - 1) stop();
    }, 4000);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      move(position + (event.key === 'ArrowRight' ? 1 : -1));
    }
  });
  render();
}());
