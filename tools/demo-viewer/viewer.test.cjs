'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const { sanitizeEvidence } = require('./model.cjs');
const { renderHtml } = require('./render.cjs');

const HASH = 'a'.repeat(64);
const TITLE = 'CSV export fails for large reports';
const fields = (title = TITLE, state = 'open') => ({ title, state });

function fixture() {
  const checkpoint = {
    id: 'baseline', label: 'Establish the common baseline', operation: 'Observed equal source records; no patches required.',
    observed_at: '2026-10-27T10:00:00Z',
    pair: { id: 'demo-export', github_id: '123', zendesk_id: '456', status: 'synced', attempts: 0, next_run: 0,
      error: null, baseline: fields(), plan: null, conflict: null, approval: null },
    records: { github: { kind: 'local', id: '123', ...fields(), version: '1', writable: true },
      zendesk: { kind: 'local', id: '456', ...fields(), version: '1', writable: true } },
    governance: { policy_version: 'escalation-state-v1', plan_hash: null, requires_approval: false, approved: false },
    events: [{ type: 'converged', created_at: '2026-10-27 10:00:00', details: '[]' }],
    metrics: { up: 1, pairs: { synced: 1, conflict: 0 }, pending_plans: 0, reconciliations_total: { synced: 1, retry: 0 } },
  };
  const pending = structuredClone(checkpoint);
  pending.id = 'approval'; pending.label = 'Hold the state-changing plan'; pending.operation = 'Captured an exact plan awaiting operator review.';
  pending.observed_at = '2026-10-27T10:01:00Z'; pending.pair.status = 'awaiting_approval';
  pending.pair.plan = { target: fields('CSV export fix verified', 'closed'),
    before: { github: fields('CSV export fix verified'), zendesk: fields(TITLE, 'closed') }, versions: { github: '2', zendesk: '2' } };
  pending.records.github = { kind: 'local', id: '123', ...fields('CSV export fix verified'), version: '2', writable: true };
  pending.records.zendesk = { kind: 'local', id: '456', ...fields(TITLE, 'closed'), version: '2', writable: true };
  pending.governance = { policy_version: 'escalation-state-v1', plan_hash: HASH, requires_approval: true, approved: false };
  pending.events = [{ type: 'approval_requested', created_at: '2026-10-27T10:01:00Z', details: { policy_version: 'escalation-state-v1', plan_hash: HASH } }];
  pending.metrics = { up: 1, pairs: { awaiting_approval: 1 }, pending_plans: 1, reconciliations_total: 2 };
  const conflict = structuredClone(checkpoint);
  conflict.id = 'conflict'; conflict.label = 'Inspect the conflicting summaries'; conflict.observed_at = '2026-10-27T10:02:00Z';
  conflict.pair.status = 'conflict'; conflict.pair.error = 'divergent_edits';
  conflict.pair.conflict = { reason: 'divergent_edits', fields: ['title'],
    observed: { github: fields('Engineering summary'), zendesk: fields('Support summary') }, versions: { github: '3', zendesk: '4' } };
  return { version: 1, run: { id: 'recorded-export-case', mode: 'local', captured_at: '2026-10-27T10:03:00Z',
    source_commit: 'f'.repeat(40), repository: 'example/demo', monitoring: {
      grafana_url: 'http://127.0.0.1:3001/d/supportbridge-overview?from=now-15m&to=now', prometheus_url: 'http://127.0.0.1:9091/' } },
  checkpoints: [checkpoint, pending, conflict] };
}

test('retains baseline, exact-plan and fresh conflict evidence without changing the input', () => {
  const evidence = fixture();
  const before = structuredClone(evidence);
  const result = sanitizeEvidence(evidence);
  assert.deepEqual(evidence, before);
  assert.equal(result.checkpoints[1].pair.plan.before.zendesk.state, 'closed');
  assert.equal(result.checkpoints[1].governance.plan_hash, HASH);
  assert.equal(result.checkpoints[2].pair.conflict.observed.zendesk.title, 'Support summary');
  assert.equal(result.checkpoints[0].events[0].created_at, '2026-10-27T10:00:00Z');
});

test('strips unknown credentials, user identities, raw responses and approval rationale recursively', () => {
  const input = fixture();
  input.api_token = 'DO-NOT-EMBED'; input.run.password = 'DO-NOT-EMBED';
  input.run.zendesk_subdomain = 'private-tenant-identity';
  input.checkpoints[0].records.github.raw_response = { token: 'DO-NOT-EMBED', requester: 'person@example.invalid' };
  input.checkpoints[0].pair.approval = { plan_hash: HASH, policy_version: 'escalation-state-v1', approved_at: 1793095200,
    principal: 'private-account', reason: 'person@example.invalid secret=DO-NOT-EMBED', token: 'DO-NOT-EMBED' };
  input.checkpoints[0].events[0].details = JSON.stringify({ reason: 'person@example.invalid secret=DO-NOT-EMBED',
    token: 'DO-NOT-EMBED', side: 'github', source: 'zendesk', fields: ['title'], plan_hash: HASH, attempts: 2 });
  input.checkpoints[0].metrics.token = 'DO-NOT-EMBED'; input.checkpoints[0].metrics.pairs.requester = 'DO-NOT-EMBED';
  const html = renderHtml(input);
  assert.ok(!html.includes('DO-NOT-EMBED'));
  assert.ok(!html.includes('private-account'));
  assert.ok(!html.includes('person@example.invalid'));
  assert.ok(!html.includes('private-tenant-identity'));
  const output = sanitizeEvidence(input);
  assert.deepEqual(output.checkpoints[0].events[0].details, { side: 'github', source: 'zendesk', fields: ['title'], plan_hash: HASH, attempts: 2 });
  assert.equal(output.checkpoints[0].pair.approval.reason, undefined);
});

test('retains fixed freshness reasons and excludes arbitrary conflict-source identities', () => {
  for (const reason of ['changed_since_conflict', 'changed_since_approval_request']) {
    const input = fixture();
    input.checkpoints[0].pair.error = reason;
    input.checkpoints[0].events[0].details = { source: 'private-person', reason };
    const result = sanitizeEvidence(input).checkpoints[0];
    assert.equal(result.pair.error, reason);
    assert.deepEqual(result.events[0].details, { reason });
  }
});

test('accepts matching UTF-8 label and operation byte limits', () => {
  const input = fixture();
  input.checkpoints[0].label = 'é'.repeat(100);
  input.checkpoints[0].operation = 'o'.repeat(500);
  assert.equal(Buffer.byteLength(sanitizeEvidence(input).checkpoints[0].label), 200);
  assert.equal(sanitizeEvidence(input).checkpoints[0].operation.length, 500);
  input.checkpoints[0].label += 'é';
  assert.throws(() => sanitizeEvidence(input), /at most 200 UTF-8 bytes/u);
});

test('escapes script-breaking input and preserves literal template-marker text', () => {
  const input = fixture();
  input.checkpoints[0].records.github.title = '</script><script>globalThis.pwned=true</script> __JS__';
  const html = renderHtml(input);
  const embedded = html.match(/<script id="evidence-data" type="application\/json">([\s\S]*?)<\/script>/u)[1];
  assert.ok(!embedded.includes('<'));
  assert.equal(JSON.parse(embedded).checkpoints[0].records.github.title, input.checkpoints[0].records.github.title);
  assert.ok(!html.includes('</script><script>globalThis.pwned=true'));
  assert.match(html, /connect-src 'none'/u);
  assert.match(html, /script-src 'sha256-[A-Za-z0-9+/=]+'/u);
});

test('rejects credential-like text in retained fields rather than publishing it', () => {
  for (const value of ['Bearer abcdefghijklmnopqrstuvwxyz', 'token=abcdefghi', 'person@example.invalid']) {
    const input = fixture(); input.checkpoints[0].records.github.title = value;
    assert.throws(() => renderHtml(input), /without credentials or email/u);
  }
});

test('allows only matching HTTPS provider links and loopback monitoring deep links', () => {
  const input = fixture();
  input.checkpoints[0].records.github.url = 'https://github.com/example/demo/issues/123';
  input.checkpoints[0].records.zendesk.url = 'https://sandbox-example.zendesk.com/agent/tickets/456';
  assert.equal(sanitizeEvidence(input).checkpoints[0].records.github.url, input.checkpoints[0].records.github.url);
  for (const bad of ['javascript:alert(1)', 'file:///private.txt', 'https://github.com.evil.invalid/example/demo/issues/123',
    'https://user:pass@github.com/example/demo/issues/123', 'https://github.com/example/demo/issues/999',
    'https://github.com/example/demo/issues/123?token=secret']) {
    const changed = fixture(); changed.checkpoints[0].records.github.url = bad;
    assert.throws(() => sanitizeEvidence(changed));
  }
  for (const bad of ['https://external.invalid/d/demo', 'http://127.0.0.1.evil.invalid:3001/d/demo',
    'http://127.0.0.1:3001/d/demo?token=secret', 'file:///dashboard', 'http://person:pass@localhost:3001/d/demo']) {
    const changed = fixture(); changed.run.monitoring.grafana_url = bad;
    assert.throws(() => sanitizeEvidence(changed));
  }
});

test('rejects invalid evidence identity, versions, bounds and chronology', () => {
  for (const mutate of [
    (input) => { input.version = 2; },
    (input) => { input.checkpoints = []; },
    (input) => { input.checkpoints[1].id = 'baseline'; },
    (input) => { input.checkpoints[1].observed_at = '2026-10-26T10:00:00Z'; },
    (input) => { input.checkpoints[0].observed_at = '2026-02-31T10:00:00Z'; },
    (input) => { input.checkpoints[0].records.github.id = '999'; },
    (input) => { input.checkpoints[0].records.github.title = 'x'.repeat(501); },
    (input) => { input.checkpoints[0].label = 'x'.repeat(201); },
    (input) => { input.checkpoints[0].operation = 'x'.repeat(501); },
    (input) => { input.checkpoints[0].records.github.version = 'value with whitespace'; },
    (input) => { input.checkpoints[0].metrics.pending_plans = -1; },
    (input) => { input.checkpoints[0].metrics.up = 2; },
    (input) => { input.checkpoints[0].pair.next_run = Number.MAX_SAFE_INTEGER; },
  ]) { const input = fixture(); mutate(input); assert.throws(() => sanitizeEvidence(input)); }
});

test('does not turn absent or unrecognized metric series into fabricated zero totals', () => {
  const input = fixture();
  input.checkpoints[0].metrics = { pairs: {}, reconciliations_total: { new: 99, token: 'hidden' } };
  const missing = sanitizeEvidence(input).checkpoints[0].metrics;
  assert.equal(missing.pairs, undefined);
  assert.equal(missing.reconciliations_total, undefined);
  input.checkpoints[0].metrics = { pairs: { synced: 0 }, reconciliations_total: 0, pending_plans: 0, up: 0 };
  assert.deepEqual(sanitizeEvidence(input).checkpoints[0].metrics,
    { up: 0, pairs: { synced: 0 }, reconciliations_total: 0, pending_plans: 0 });
});

class FakeElement {
  constructor(tagName = 'DIV') { this.tagName = tagName; this.children = []; this.handlers = {}; this.attributes = {}; this.textContent = ''; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children = [...children]; }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(type, handler) { this.handlers[type] = handler; }
  click() { this.handlers.click?.({}); }
}

test('browser next, previous, play and conflict rendering replay observations without side effects', () => {
  const input = fixture();
  input.checkpoints[0].records.github.title = '<img src=x onerror=alert(1)>';
  input.checkpoints[0].pair.error = 'details_omitted';
  input.checkpoints[0].events = ['write_started', 'write_returned', 'converged'].map((type) => ({
    type, created_at: '2026-10-27 10:00:00', details: {},
  }));
  const data = sanitizeEvidence(input);
  const nodes = new Map();
  const timers = new Map(); let nextTimer = 0; let networkCalls = 0;
  const document = { getElementById(id) { if (!nodes.has(id)) nodes.set(id, new FakeElement()); return nodes.get(id); },
    createElement(tag) { return new FakeElement(tag.toUpperCase()); }, addEventListener() {} };
  document.getElementById('evidence-data').textContent = JSON.stringify(data);
  const denied = () => { networkCalls++; throw new Error('Network must never be used by replay.'); };
  const context = { document, Intl, Date, JSON, console, fetch: denied, XMLHttpRequest: denied, WebSocket: denied,
    EventSource: denied, navigator: { sendBeacon: denied },
    setInterval(callback) { const id = ++nextTimer; timers.set(id, callback); return id; }, clearInterval(id) { timers.delete(id); } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, 'viewer.js'), 'utf8'), context);
  assert.equal(nodes.get('position').textContent, '1 / 3');
  assert.ok(nodes.get('github-record').children.some((node) => node.textContent === '<img src=x onerror=alert(1)>'));
  const recordedError = nodes.get('governance').children[0].children.find((node) => node.children[0]?.textContent === 'Recorded error');
  assert.equal(recordedError.children[1].textContent, 'Present; details omitted');
  assert.deepEqual(nodes.get('events').children.map((node) => node.children[1].children[0].textContent),
    ['write started', 'write returned', 'converged']);
  nodes.get('next').click();
  assert.equal(nodes.get('pair-status').textContent, 'Awaiting approval');
  assert.equal(nodes.get('position').textContent, '2 / 3');
  nodes.get('next').click();
  assert.equal(nodes.get('conflict-panel').hidden, false);
  nodes.get('previous').click();
  nodes.get('play').click();
  assert.equal(timers.size, 1);
  [...timers.values()][0]();
  assert.equal(nodes.get('position').textContent, '3 / 3');
  assert.equal(timers.size, 0);
  assert.equal(nodes.get('play').textContent, 'Play replay');
  nodes.get('checkpoints').children[0].click();
  assert.equal(nodes.get('position').textContent, '1 / 3');
  assert.equal(networkCalls, 0);
  assert.equal(JSON.stringify(data), JSON.stringify(sanitizeEvidence(input)));
});

function temporaryDirectory(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'support-bridge-viewer-test-'));
  t.after(() => {
    const resolved = fs.realpathSync(directory);
    const parent = fs.realpathSync(os.tmpdir());
    assert.equal(path.dirname(resolved).toLowerCase(), parent.toLowerCase());
    assert.ok(path.basename(resolved).startsWith('support-bridge-viewer-test-'));
    fs.rmSync(resolved, { recursive: true });
  });
  return directory;
}

test('CLI writes a self-contained replay and rejects malformed input without leaking its contents', (t) => {
  const directory = temporaryDirectory(t);
  const input = path.join(directory, 'evidence.json');
  const output = path.join(directory, 'replay.html');
  fs.writeFileSync(input, JSON.stringify(fixture()));
  const cli = path.join(__dirname, 'render.cjs');
  const success = spawnSync(process.execPath, [cli, '--input', input, '--output', output], { encoding: 'utf8' });
  assert.equal(success.status, 0, success.stderr);
  const html = fs.readFileSync(output, 'utf8');
  assert.ok(html.includes('Recorded observations'));
  assert.ok(!html.includes('<script src='));
  fs.writeFileSync(input, '{"token":"DO-NOT-PRINT",');
  const failure = spawnSync(process.execPath, [cli, '--input', input, '--output', output], { encoding: 'utf8' });
  assert.equal(failure.status, 1);
  assert.match(failure.stderr, /valid UTF-8 JSON/u);
  assert.ok(!failure.stderr.includes('DO-NOT-PRINT'));
  assert.equal(fs.readFileSync(output, 'utf8'), html);
  const same = spawnSync(process.execPath, [cli, '--input', input, '--output', input], { encoding: 'utf8' });
  assert.equal(same.status, 1);
});

test('CLI refuses oversized evidence before parsing or producing an output', (t) => {
  const directory = temporaryDirectory(t);
  const input = path.join(directory, 'large.json');
  const output = path.join(directory, 'not-created.html');
  fs.writeFileSync(input, Buffer.alloc(2 * 1024 * 1024 + 1, 32));
  const result = spawnSync(process.execPath, [path.join(__dirname, 'render.cjs'), '--input', input, '--output', output], { encoding: 'utf8' });
  assert.equal(result.status, 1);
  assert.ok(!fs.existsSync(output));
});

test('CLI preserves input when an output directory alias identifies the same file', (t) => {
  const directory = temporaryDirectory(t);
  const actual = path.join(directory, 'actual');
  const alias = path.join(directory, 'alias');
  fs.mkdirSync(actual);
  fs.symlinkSync(actual, alias, process.platform === 'win32' ? 'junction' : 'dir');
  const input = path.join(actual, 'evidence.json');
  const contents = JSON.stringify(fixture());
  fs.writeFileSync(input, contents);
  const result = spawnSync(process.execPath, [path.join(__dirname, 'render.cjs'), '--input', input,
    '--output', path.join(alias, 'evidence.json')], { encoding: 'utf8' });
  assert.equal(result.status, 1);
  assert.equal(fs.readFileSync(input, 'utf8'), contents);
});
