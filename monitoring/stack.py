#!/usr/bin/env python3
"""Accountless, loopback-only local stack. Credentials and data stay outside the repo."""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SOURCE = Path(__file__).resolve().parent
PROJECT = SOURCE.parent
STATE = Path(os.environ.get('BRIDGE_MONITORING_HOME', str(Path(os.environ.get('XDG_DATA_HOME', str(Path.home() / '.local/share'))) / 'supportbridge/monitoring'))).resolve()
PROMETHEUS = STATE / 'bin/prometheus-3.13.5'
GRAFANA = STATE / 'bin/grafana-13.2.4'


def private_file(path, value=None):
    if not path.exists():
        path.write_text((value or secrets.token_hex(32)) + '\n')
    path.chmod(0o600)
    return path.read_text().strip()


def pid_start(pid):
    try:
        return Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[19]
    except (OSError, IndexError):
        return None


def read_processes():
    path = STATE / 'processes.json'
    return json.loads(path.read_text()) if path.exists() else {}


def alive(item):
    return item.get('start') is not None and pid_start(item['pid']) == item['start']


def free_port(port):
    with socket.socket() as test:
        try:
            test.bind(('127.0.0.1', port))
        except OSError:
            raise RuntimeError(f'Port {port} is already in use; no existing service will be replaced.')


def start(name, command, env, port, processes):
    previous = processes.get(name)
    if previous and alive(previous):
        return
    free_port(port)
    with (STATE / f'{name}.log').open('ab') as log:
        process = subprocess.Popen(command, env=env, cwd=PROJECT, stdout=log,
                                   stderr=subprocess.STDOUT, start_new_session=True)
    processes[name] = {'pid': process.pid, 'start': pid_start(process.pid)}
    (STATE / 'processes.json').write_text(json.dumps(processes, indent=2) + '\n')


def request(url, *, auth=None):
    headers = {'Authorization': auth} if auth else {}
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=5) as response:
        data = response.read()
        return json.loads(data) if response.headers.get('Content-Type', '').startswith('application/json') else data.decode()


def wait(url, seconds=45):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        try:
            return request(url)
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            time.sleep(0.5)
    raise RuntimeError(f'Service did not become ready: {url}; inspect private log files in {STATE}.')


def admin_auth():
    password = (STATE / 'grafana-admin-password').read_text().strip()
    return 'Basic ' + base64.b64encode(('admin:' + password).encode()).decode()


def prom_value(expression):
    query = urllib.parse.urlencode({'query': expression})
    result = request('http://127.0.0.1:9090/api/v1/query?' + query)['data']['result']
    if not result:
        raise RuntimeError('Expected metric is absent from the real Prometheus query result: ' + expression)
    return float(result[0]['value'][1])


def configure():
    STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
    STATE.chmod(0o700)
    token = private_file(STATE / 'metrics-token')
    private_file(STATE / 'operator-token')
    private_file(STATE / 'grafana-admin-password')
    if len(token) < 32 or any(character.isspace() for character in token):
        raise RuntimeError('The private metrics-token file must contain at least 32 non-whitespace characters.')
    template = (SOURCE / 'prometheus.yml.in').read_text()
    for name, value in {'RULES_FILE': str(SOURCE / 'alerts.yml'),
                        'METRICS_TOKEN_FILE': str(STATE / 'metrics-token'),
                        'APP_TARGET': '127.0.0.1:8080'}.items():
        template = template.replace('@' + name + '@', json.dumps(value))
    (STATE / 'prometheus.yml').write_text(template)
    (STATE / 'grafana.ini').write_text(f'''[paths]
data = {STATE / 'grafana-data'}
logs = {STATE / 'grafana-logs'}
plugins = {STATE / 'grafana-plugins'}
provisioning = {SOURCE / 'grafana/provisioning'}
[server]
http_addr = 127.0.0.1
http_port = 3000
domain = localhost
[security]
admin_user = admin
admin_password = $__file{{{STATE / 'grafana-admin-password'}}}
secret_key = $__file{{{STATE / 'grafana-secret-key'}}}
disable_gravatar = true
[users]
allow_sign_up = false
[auth.anonymous]
enabled = false
[analytics]
reporting_enabled = false
check_for_updates = false
check_for_plugin_updates = false
[plugins]
preinstall_disabled = true
[unified_alerting]
enabled = false
''')
    private_file(STATE / 'grafana-secret-key')
    return token


def up(demo):
    token = configure()
    if not (PROMETHEUS / 'prometheus').is_file() or not (GRAFANA / 'bin/grafana').is_file():
        raise RuntimeError('Run bash monitoring/install.sh first.')
    env = os.environ.copy()
    env['BRIDGE_MONITORING_DASHBOARDS'] = str(SOURCE / 'grafana/dashboards')
    processes = read_processes()
    if demo:
        app_env = env.copy()
        app_env.update({'BRIDGE_MODE': 'local', 'BRIDGE_HOME': str(STATE / 'demo-app'),
                    'BRIDGE_API_TOKEN': (STATE / 'operator-token').read_text().strip(),
                    'BRIDGE_METRICS_TOKEN': token})
        for command in ['init', 'sync', 'sync']:
            subprocess.run(['php', str(PROJECT / 'bin/bridge.php'), command], cwd=PROJECT,
                           env=app_env, check=True, stdout=subprocess.DEVNULL)
        start('app', ['php', '-S', '127.0.0.1:8080', '-t', str(PROJECT / 'public'),
                      str(PROJECT / 'public/router.php')], app_env, 8080, processes)
        wait('http://127.0.0.1:8080/health')
    subprocess.run([str(PROMETHEUS / 'promtool'), 'check', 'config', str(STATE / 'prometheus.yml')], check=True)
    start('prometheus', [str(PROMETHEUS / 'prometheus'), '--config.file=' + str(STATE / 'prometheus.yml'),
                        '--storage.tsdb.path=' + str(STATE / 'prometheus-data'), '--storage.tsdb.retention.time=7d',
                        '--web.listen-address=127.0.0.1:9090'], env, 9090, processes)
    start('grafana', [str(GRAFANA / 'bin/grafana'), 'server', '--homepath=' + str(GRAFANA),
                     '--config=' + str(STATE / 'grafana.ini')], env, 3000, processes)
    wait('http://127.0.0.1:9090/-/ready')
    wait('http://127.0.0.1:3000/api/health')
    status()


def verify():
    token = (STATE / 'metrics-token').read_text().strip()
    rejected = False
    try:
        request('http://127.0.0.1:8080/metrics')
    except urllib.error.HTTPError as error:
        rejected = error.code == 401
    if not rejected:
        raise RuntimeError('Unauthenticated metrics request was not rejected with 401.')
    metrics = request('http://127.0.0.1:8080/metrics', auth='Bearer ' + token)
    if 'support_bridge_reconciliations_total' not in metrics:
        raise RuntimeError('Authenticated application metrics missing.')
    deadline = time.monotonic() + 20
    targets = None
    while time.monotonic() < deadline:
        targets = request('http://127.0.0.1:9090/api/v1/targets')['data']['activeTargets']
        if any(item['labels'].get('job') == 'support_bridge' and item['health'] == 'up' for item in targets):
            break
        time.sleep(1)
    else:
        raise RuntimeError('Prometheus target did not become healthy; check token and application logs.')
    if prom_value('up{job="support_bridge"}') != 1:
        raise RuntimeError('Real Prometheus scrape query did not return up=1.')
    reconciliations = prom_value('sum(support_bridge_reconciliations_total)')
    duration_samples = prom_value('sum(support_bridge_reconciliation_duration_seconds_count)')
    pairs = prom_value('sum(support_bridge_pairs)')
    awaiting_approval = prom_value('sum(support_bridge_pairs{status="awaiting_approval"})')
    dashboard = request('http://127.0.0.1:3000/api/dashboards/uid/supportbridge-overview', auth=admin_auth())
    health = request('http://127.0.0.1:3000/api/datasources/uid/supportbridge-prometheus/health', auth=admin_auth())
    if not dashboard.get('meta', {}).get('provisioned') or health.get('status') != 'OK':
        raise RuntimeError('Grafana provisioning or datasource query health failed.')
    attention_panels = [panel for panel in dashboard['dashboard']['panels'] if panel.get('id') == 3]
    if not attention_panels or not any('awaiting_approval' in target.get('expr', '')
                                       for target in attention_panels[0].get('targets', [])):
        raise RuntimeError('Grafana operator-attention panel does not include pending approvals.')
    rules = request('http://127.0.0.1:9090/api/v1/rules')['data']['groups']
    attention_rules = [rule for group in rules for rule in group['rules']
                       if rule.get('name') == 'SupportBridgeOperatorAttention']
    if not attention_rules or 'awaiting_approval' not in attention_rules[0].get('query', ''):
        raise RuntimeError('Loaded operator-attention rule does not include pending approvals.')
    report = {'metrics_unauthenticated_status': 401, 'metrics_authenticated': True,
              'prometheus_target': 'up', 'promql_up': 1, 'grafana_datasource': health['status'],
              'grafana_dashboard_uid': dashboard['dashboard']['uid'],
              'grafana_dashboard_panels': len(dashboard['dashboard']['panels']),
              'promql_reconciliations': reconciliations, 'promql_duration_samples': duration_samples,
              'promql_linked_pairs': pairs,
              'promql_awaiting_approval': awaiting_approval,
              'approval_attention_dashboard_and_rule': True,
              'prometheus_rule_count': sum(len(group['rules']) for group in rules),
              'verified_at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
              'scope': 'loopback synthetic local data; no vendor or cloud accounts'}
    (STATE / 'verification.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


def status():
    print(json.dumps({'state_directory': str(STATE), 'processes': {
        name: {'pid': item['pid'], 'running': alive(item)} for name, item in read_processes().items()},
        'prometheus': 'http://127.0.0.1:9090', 'grafana': 'http://127.0.0.1:3000/d/supportbridge-overview',
        'grafana_user': 'admin', 'grafana_password_file': str(STATE / 'grafana-admin-password'),
        'metrics_token_file': str(STATE / 'metrics-token')}, indent=2))


def down():
    for name, item in read_processes().items():
        if alive(item):
            os.killpg(item['pid'], signal.SIGTERM)
            print('Stopped managed ' + name)


if __name__ == '__main__':
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['up', 'verify', 'status', 'down'])
    parser.add_argument('--demo', action='store_true', help='Start the PHP app with isolated synthetic state and private tokens.')
    args = parser.parse_args()
    try:
        if args.command == 'up': up(args.demo)
        elif args.command == 'verify': verify()
        elif args.command == 'status': status()
        else: down()
    except (OSError, RuntimeError, subprocess.CalledProcessError, urllib.error.URLError) as error:
        print('Monitoring: ' + str(error), file=sys.stderr)
        sys.exit(1)
