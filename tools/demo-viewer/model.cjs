'use strict';

const MAX_INPUT_BYTES = 2 * 1024 * 1024;
const STATUSES = ['new', 'synced', 'retry', 'conflict', 'dead', 'awaiting_approval'];
const OUTCOMES = ['synced', 'retry', 'conflict', 'dead', 'awaiting_approval', 'skipped'];
const REASONS = new Set([
  'initial_mismatch', 'divergent_edits', 'changed_since_conflict',
  'changed_since_approval_request', 'changed_during_recovery',
  'changed_since_approval', 'verification_mismatch', 'state_change_requires_approval',
]);

function invalid(path, expected) {
  throw new Error(`${path} must be ${expected}.`);
}

function object(value, path) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) invalid(path, 'an object');
  return value;
}

function text(value, path, maximum = 160) {
  if (typeof value !== 'string' || value.length === 0 || Buffer.byteLength(value, 'utf8') > maximum
    || /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/u.test(value)) {
    invalid(path, `nonempty text of at most ${maximum} UTF-8 bytes`);
  }
  if (/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u.test(value)
    || /\b(?:Bearer\s+\S+|(?:password|token|secret|api[_-]?key)\s*[:=]\s*\S+)/iu.test(value)
    || /(?:github_pat_|gh[pousr]_)[A-Za-z0-9_]{15,}/u.test(value)
    || /\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/u.test(value)) {
    invalid(path, 'text without credentials or email addresses');
  }
  return value;
}

function choice(value, values, path) {
  if (!values.includes(value)) invalid(path, `one of ${values.join(', ')}`);
  return value;
}

function identifier(value, path) {
  text(value, path, 128);
  if (!/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/u.test(value)) invalid(path, 'a simple identifier');
  return value;
}

function numericId(value, path) {
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,15}$/u.test(value)) invalid(path, 'a numeric string ID');
  return value;
}

function number(value, path, integer = false) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > Number.MAX_SAFE_INTEGER
    || (integer && !Number.isSafeInteger(value))) invalid(path, 'a nonnegative finite number');
  return value;
}

function boolean(value, path) {
  if (typeof value !== 'boolean') invalid(path, 'a boolean');
  return value;
}

function unixSeconds(value, path) {
  number(value, path, true);
  if (value > 253402300799) invalid(path, 'a Unix timestamp within the supported calendar');
  return value;
}

function timestamp(value, path, sqlite = false) {
  text(value, path, 40);
  if (sqlite && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/u.test(value)) value = value.replace(' ', 'T') + 'Z';
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/u.test(value)
    || !Number.isFinite(Date.parse(value))) invalid(path, 'an ISO timestamp with timezone');
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);
  const calendar = new Date(Date.UTC(year, month - 1, day));
  if (calendar.getUTCFullYear() !== year || calendar.getUTCMonth() !== month - 1 || calendar.getUTCDate() !== day) {
    invalid(path, 'a valid calendar date');
  }
  return value;
}

function hash(value, path, nullable = false) {
  if (nullable && value === null) return null;
  if (typeof value !== 'string' || !/^[a-f0-9]{64}$/u.test(value)) invalid(path, 'a SHA-256 hexadecimal digest');
  return value;
}

function version(value, path) {
  text(value, path, 128);
  if (!/^[A-Za-z0-9_.:+-]+$/u.test(value)) invalid(path, 'a bounded record version');
  return value;
}

function fields(value, path) {
  object(value, path);
  return { title: text(value.title, `${path}.title`, 500), state: choice(value.state, ['open', 'closed'], `${path}.state`) };
}

function sides(value, path, parser) {
  object(value, path);
  return { github: parser(value.github, `${path}.github`), zendesk: parser(value.zendesk, `${path}.zendesk`) };
}

function fieldNames(value, path) {
  if (!Array.isArray(value) || value.length > 2) invalid(path, 'a bounded field list');
  return [...new Set(value.map((name) => choice(name, ['title', 'state'], path)))];
}

function policy(value, path) {
  text(value, path, 64);
  if (!/^[a-z][a-z0-9-]*$/u.test(value)) invalid(path, 'a policy version identifier');
  return value;
}

function reason(value) {
  return typeof value === 'string' && REASONS.has(value) ? value : 'details_omitted';
}

function plan(value, path) {
  if (value === null) return null;
  object(value, path);
  return { target: fields(value.target, `${path}.target`), before: sides(value.before, `${path}.before`, fields),
    versions: sides(value.versions, `${path}.versions`, version) };
}

function conflict(value, path) {
  if (value === null || value === undefined) return null;
  object(value, path);
  return { reason: reason(value.reason), fields: fieldNames(value.fields, `${path}.fields`),
    observed: sides(value.observed, `${path}.observed`, fields), versions: sides(value.versions, `${path}.versions`, version) };
}

function approval(value, path) {
  if (value === null) return null;
  object(value, path);
  const result = { plan_hash: hash(value.plan_hash, `${path}.plan_hash`),
    policy_version: policy(value.policy_version, `${path}.policy_version`),
    approved_at: unixSeconds(value.approved_at, `${path}.approved_at`) };
  if (value.principal === 'shared-operator') result.principal = 'shared-operator';
  return result;
}

function pair(value, path) {
  object(value, path);
  return { id: identifier(value.id, `${path}.id`), github_id: numericId(value.github_id, `${path}.github_id`),
    zendesk_id: numericId(value.zendesk_id, `${path}.zendesk_id`), status: choice(value.status, STATUSES, `${path}.status`),
    attempts: number(value.attempts, `${path}.attempts`, true), next_run: unixSeconds(value.next_run, `${path}.next_run`),
    error: value.error == null ? null : reason(value.error), baseline: value.baseline === null ? null : fields(value.baseline, `${path}.baseline`),
    plan: plan(value.plan, `${path}.plan`), conflict: conflict(value.conflict, `${path}.conflict`),
    approval: approval(value.approval, `${path}.approval`) };
}

function providerUrl(value, side, id, path) {
  text(value, path, 500);
  let url;
  try { url = new URL(value); } catch { invalid(path, 'an HTTPS provider record URL'); }
  if (url.protocol !== 'https:' || url.username || url.password || url.port || url.search || url.hash) invalid(path, 'an HTTPS provider record URL');
  if (side === 'github') {
    if (url.hostname !== 'github.com' || !new RegExp(`^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/${id}/?$`, 'u').test(url.pathname)) {
      invalid(path, 'a matching github.com issue URL');
    }
  } else if (!/^[a-z0-9][a-z0-9-]*\.zendesk\.com$/u.test(url.hostname)
    || !new RegExp(`^/agent/tickets/${id}/?$`, 'u').test(url.pathname)) invalid(path, 'a matching zendesk.com ticket URL');
  return url.href;
}

function record(value, path, side, expectedId) {
  object(value, path);
  const id = numericId(value.id, `${path}.id`);
  if (id !== expectedId) invalid(`${path}.id`, 'the linked provider ID');
  const result = { kind: choice(value.kind, ['live', 'local'], `${path}.kind`), id,
    title: text(value.title, `${path}.title`, 500), state: choice(value.state, ['open', 'closed'], `${path}.state`),
    version: version(value.version, `${path}.version`), writable: boolean(value.writable, `${path}.writable`) };
  if (value.url !== undefined) result.url = providerUrl(value.url, side, id, `${path}.url`);
  return result;
}

function monitoringUrl(value, path, grafana) {
  text(value, path, 500);
  let url;
  try { url = new URL(value); } catch { invalid(path, 'a loopback HTTP monitoring URL'); }
  if (url.protocol !== 'http:' || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
    || url.username || url.password || url.hash) invalid(path, 'a loopback HTTP monitoring URL');
  if (grafana) {
    if (url.pathname !== '/' && !/^\/d\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)?\/?$/u.test(url.pathname)) invalid(path, 'a Grafana dashboard URL');
    for (const [key, item] of url.searchParams) {
      const valid = ((key === 'from' || key === 'to') && /^(?:[0-9]+|now(?:-[0-9]+[smhdw])?)$/u.test(item))
        || (key === 'orgId' && /^[0-9]{1,8}$/u.test(item)) || (key === 'refresh' && /^[0-9]{1,4}[smh]$/u.test(item));
      if (!valid) invalid(path, 'a dashboard URL without credentials or arbitrary query fields');
    }
  } else if (!['/', '/graph', '/query'].includes(url.pathname) || url.search) invalid(path, 'a Prometheus root or query-page URL');
  return url.href;
}

function eventDetails(value) {
  if (typeof value === 'string') {
    if (Buffer.byteLength(value, 'utf8') > 8192) return {};
    try { value = JSON.parse(value); } catch { return {}; }
  }
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return {};
  const result = {};
  if (['github', 'zendesk'].includes(value.side)) result.side = value.side;
  if (['github', 'zendesk'].includes(value.source)) result.source = value.source;
  if (Array.isArray(value.fields) && value.fields.length <= 2 && value.fields.every((name) => ['title', 'state'].includes(name))) result.fields = [...new Set(value.fields)];
  if (typeof value.plan_hash === 'string' && /^[a-f0-9]{64}$/u.test(value.plan_hash)) result.plan_hash = value.plan_hash;
  if (typeof value.policy_version === 'string' && /^[a-z][a-z0-9-]{0,63}$/u.test(value.policy_version)) result.policy_version = value.policy_version;
  if (['operator_approval', 'linked_title_workflow'].includes(value.authorization)) result.authorization = value.authorization;
  for (const key of ['attempts', 'next_run', 'delay_seconds']) {
    if (Number.isSafeInteger(value[key]) && value[key] >= 0) result[key] = value[key];
  }
  if (REASONS.has(value.reason)) result.reason = value.reason;
  return result;
}

function metrics(value, path) {
  object(value, path);
  const result = {};
  if (value.up !== undefined) result.up = choice(value.up, [0, 1], `${path}.up`);
  for (const key of ['pairs', 'reconciliations_total']) {
    if (value[key] === undefined) continue;
    if (typeof value[key] === 'number') result[key] = number(value[key], `${path}.${key}`);
    else {
      object(value[key], `${path}.${key}`);
      const counts = {};
      for (const status of key === 'pairs' ? STATUSES : OUTCOMES) {
        if (value[key][status] !== undefined) counts[status] = number(value[key][status], `${path}.${key}.${status}`);
      }
      if (Object.keys(counts).length > 0) result[key] = counts;
    }
  }
  if (value.pending_plans !== undefined) result.pending_plans = number(value.pending_plans, `${path}.pending_plans`);
  return result;
}

function sanitizeEvidence(input) {
  object(input, 'evidence');
  if (input.version !== 1) invalid('evidence.version', '1');
  object(input.run, 'run');
  const run = { id: identifier(input.run.id, 'run.id'), mode: choice(input.run.mode, ['local', 'live'], 'run.mode'),
    captured_at: timestamp(input.run.captured_at, 'run.captured_at'), monitoring: {} };
  if (input.run.source_commit !== undefined) {
    if (typeof input.run.source_commit !== 'string' || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/u.test(input.run.source_commit)) invalid('run.source_commit', 'a Git commit digest');
    run.source_commit = input.run.source_commit;
  }
  if (input.run.repository !== undefined) {
    text(input.run.repository, 'run.repository', 201);
    if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/u.test(input.run.repository)) invalid('run.repository', 'an owner/repository name');
    run.repository = input.run.repository;
  }
  if (input.run.monitoring !== undefined) {
    object(input.run.monitoring, 'run.monitoring');
    for (const [key, grafana] of [['grafana_url', true], ['prometheus_url', false]]) {
      if (input.run.monitoring[key] !== undefined) run.monitoring[key] = monitoringUrl(input.run.monitoring[key], `run.monitoring.${key}`, grafana);
    }
  }
  if (!Array.isArray(input.checkpoints) || input.checkpoints.length < 1 || input.checkpoints.length > 250) invalid('checkpoints', 'an array of 1–250 checkpoints');
  const ids = new Set();
  let previousTime = -Infinity;
  const checkpoints = input.checkpoints.map((value, index) => {
    const path = `checkpoints[${index}]`;
    object(value, path);
    const id = identifier(value.id, `${path}.id`);
    if (ids.has(id)) invalid(`${path}.id`, 'unique within the run');
    ids.add(id);
    const observed_at = timestamp(value.observed_at, `${path}.observed_at`);
    if (Date.parse(observed_at) < previousTime) invalid(`${path}.observed_at`, 'in recorded chronological order');
    previousTime = Date.parse(observed_at);
    const linked = pair(value.pair, `${path}.pair`);
    object(value.records, `${path}.records`);
    object(value.governance, `${path}.governance`);
    if (!Array.isArray(value.events) || value.events.length > 200) invalid(`${path}.events`, 'an array of at most 200 events');
    const events = value.events.map((item, eventIndex) => {
      const eventPath = `${path}.events[${eventIndex}]`;
      object(item, eventPath);
      const type = text(item.type, `${eventPath}.type`, 64);
      if (!/^[a-z][a-z0-9_]*$/u.test(type)) invalid(`${eventPath}.type`, 'an event type code');
      return { type, created_at: timestamp(item.created_at, `${eventPath}.created_at`, true), details: eventDetails(item.details) };
    });
    return { id, label: text(value.label, `${path}.label`, 200), operation: text(value.operation, `${path}.operation`, 500), observed_at,
      pair: linked, records: { github: record(value.records.github, `${path}.records.github`, 'github', linked.github_id),
        zendesk: record(value.records.zendesk, `${path}.records.zendesk`, 'zendesk', linked.zendesk_id) },
      governance: { policy_version: policy(value.governance.policy_version, `${path}.governance.policy_version`),
        plan_hash: hash(value.governance.plan_hash, `${path}.governance.plan_hash`, true),
        requires_approval: boolean(value.governance.requires_approval, `${path}.governance.requires_approval`),
        approved: boolean(value.governance.approved, `${path}.governance.approved`) },
      events, metrics: metrics(value.metrics ?? {}, `${path}.metrics`) };
  });
  return { version: 1, run, checkpoints };
}

module.exports = { MAX_INPUT_BYTES, sanitizeEvidence };
