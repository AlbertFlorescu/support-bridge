import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn, spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const home = fs.mkdtempSync(path.join(os.tmpdir(), 'support-bridge-native-browser-'));
const candidates = [process.env.BRIDGE_TEST_BROWSER, '/usr/bin/google-chrome',
  '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean);
const executable = candidates.find(file => fs.existsSync(file));
assert(executable, 'Install Chromium/Chrome or set BRIDGE_TEST_BROWSER to its Linux executable.');
const environment = {...process.env};
for (const key of Object.keys(environment)) if (key.startsWith('BRIDGE_')) delete environment[key];
delete environment.PHP_CLI_SERVER_WORKERS;
environment.BRIDGE_NATIVE_FORM_TEST = '1';
environment.BRIDGE_NATIVE_FORM_TEST_HOME = home;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

function cli(command, ...values) {
  const result = spawnSync('php', [path.join(root, 'bin/bridge.php'), command, ...values,
    '--mode=local', '--home=' + home], {cwd: root, env: environment, encoding: 'utf8'});
  assert([0, 2].includes(result.status), 'Isolated CLI failed: ' + command + '\n' + result.stderr);
  return JSON.parse(result.stdout);
}
async function until(read, message, timeout = 10000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    try { const value = await read(); if (value) return value; } catch {}
    await pause(100);
  }
  throw new Error(message);
}
class DevTools {
  constructor(socket) {
    this.socket = socket;
    this.next = 0;
    this.pending = new Map();
    this.events = [];
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        clearTimeout(pending.timer);
        if (message.error) pending.reject(new Error(message.error.message));
        else pending.resolve(message.result);
      } else for (const listener of this.events) listener(message);
    });
  }
  async send(method, params = {}, sessionId) {
    const id = ++this.next;
    const promise = new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error('Browser protocol timeout: ' + method));
      }, 5000);
      this.pending.set(id, {resolve, reject, timer});
    });
    this.socket.send(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})}));
    return promise;
  }
}
async function stop(child) {
  if (!child || child.exitCode !== null) return;
  child.kill('SIGTERM');
  await until(() => child.exitCode !== null, 'Child did not stop', 2000).catch(() => child.kill('SIGKILL'));
}

let server, browser, protocol;
try {
  cli('init'); cli('sync'); cli('local-edit', 'github', '101', 'state', 'closed'); cli('sync');
  assert.equal(cli('inspect', 'escalation-1').pair.status, 'awaiting_approval');
  const reservation = net.createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const origin = 'http://127.0.0.1:' + port;
  environment.BRIDGE_NATIVE_FORM_ORIGIN = origin;
  server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', path.join(root, 'public'),
    path.join(root, 'tools/browser/router.php')], {cwd: root, env: environment, stdio: ['ignore', 'ignore', 'pipe']});
  let serverErrors = '';
  server.stderr.on('data', chunk => { serverErrors = (serverErrors + chunk).slice(-4000); });
  await until(async () => (await fetch(origin + '/health')).ok, 'Local PHP fixture did not start');
  browser = spawn(executable, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--no-first-run', '--disable-background-networking', '--disable-extensions', '--remote-debugging-port=0',
    '--user-data-dir=' + path.join(home, 'browser'), 'about:blank'], {stdio: ['ignore', 'ignore', 'pipe']});
  let browserErrors = '';
  browser.stderr.on('data', chunk => { browserErrors = (browserErrors + chunk).slice(-12000); });
  const endpoint = await until(() => browserErrors.match(/DevTools listening on (ws:\/\/[^\s]+)/)?.[1],
    'Chromium did not expose its local test endpoint');
  const socket = new WebSocket(endpoint);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, {once: true});
    socket.addEventListener('error', reject, {once: true});
  });
  protocol = new DevTools(socket);
  const {targetId} = await protocol.send('Target.createTarget', {url: 'about:blank'});
  const {sessionId} = await protocol.send('Target.attachToTarget', {targetId, flatten: true});
  await protocol.send('Page.enable', {}, sessionId);
  await protocol.send('Network.enable', {}, sessionId);
  await protocol.send('Network.setCookie', {name: 'native_test_grafana_session',
    value: '0123456789abcdef0123456789abcdef01234567', url: origin, httpOnly: true, sameSite: 'Lax'}, sessionId);
  let postedOrigin, responsePolicy;
  protocol.events.push(message => {
    if (message.method === 'Network.requestWillBeSent' && message.params.request.method === 'POST') {
      postedOrigin = message.params.request.headers.Origin ?? message.params.request.headers.origin;
    }
    if (message.method === 'Network.responseReceived' &&
        message.params.response.url === origin + '/review/pairs/escalation-1') {
      const headers = message.params.response.headers;
      responsePolicy = Object.entries(headers).find(([key]) => key.toLowerCase() === 'referrer-policy')?.[1];
    }
  });
  async function evaluate(expression) {
    const result = await protocol.send('Runtime.evaluate', {expression, returnByValue: true}, sessionId);
    if (result.exceptionDetails) throw new Error('Browser evaluation failed.');
    return result.result.value;
  }
  await protocol.send('Page.navigate', {url: origin + '/review/pairs/escalation-1'}, sessionId);
  await until(() => evaluate('Boolean(document.querySelector("form [name=plan_hash]"))'),
    'Pending plan did not render a review form');
  await evaluate('document.getElementById("reason").value = "Isolated native form verification";' +
    'document.querySelector("input[name=confirm]").checked = true;' +
    'document.querySelector("button[type=submit]").click(); true');
  await until(() => evaluate('document.body.innerText.includes("Approval recorded")'),
    'Native form submission did not record approval. Check Origin/referrer policy and form validation.');
  const approved = cli('inspect', 'escalation-1');
  assert.equal(approved.governance.approved, true);
  assert.equal(approved.pair.status, 'new');
  assert.equal(JSON.parse(fs.readFileSync(path.join(home, 'zendesk.json'))).writes, 0);
  assert.equal(responsePolicy, 'same-origin');
  if (postedOrigin !== undefined) assert.equal(postedOrigin, origin);
  cli('sync');
  const completed = cli('inspect', 'escalation-1');
  assert.equal(completed.pair.status, 'synced');
  assert.equal(completed.pair.baseline.state, 'closed');
  assert.equal(completed.pair.plan, null);
  const report = {nativeFormSubmitted: true, sameOriginPolicy: true, approvalRecorded: true,
    noWriteBeforeSweep: true, convergedAfterSweep: true, providers: 'isolated local fixtures',
    grafanaVerifier: 'injected fixture; real Grafana session covered by container lifecycle'};
  fs.mkdirSync(path.join(root, 'var'), {recursive: true});
  fs.writeFileSync(path.join(root, 'var/native-browser-check.json'), JSON.stringify(report, null, 2) + '\n');
  console.log('PASS native Chromium form, exact approval without immediate effects, and later verified convergence.');
} finally {
  if (protocol) {
    await protocol.send('Browser.close').catch(() => {});
    protocol.socket.close();
  }
  await stop(browser);
  await stop(server);
  assert.equal(path.dirname(home), os.tmpdir());
  assert(path.basename(home).startsWith('support-bridge-native-browser-'));
  fs.rmSync(home, {recursive: true, force: true});
}
