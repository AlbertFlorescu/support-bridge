#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Read-only checks against the actual Compose network; no provider or operator writes. */
final class StackVerification
{
    private const API = 'http://api:8080';
    private const PROMETHEUS = 'http://prometheus:9090';
    private const GRAFANA = 'http://grafana:3000';
    private const OUTCOMES = ['synced', 'retry', 'conflict', 'dead', 'skipped', 'awaiting_approval'];
    private const STATUSES = ['new', 'synced', 'retry', 'conflict', 'dead', 'awaiting_approval'];
    private const BUCKETS = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5', '10', '+Inf'];
    private const DEMO_CASES = ['CSV export', 'Webhook delivery', 'SSO sign-in'];
    private const CASE_METRICS = [
        'support_bridge_demo_case_present',
        'support_bridge_demo_case_status',
        'support_bridge_demo_case_resolution',
        'support_bridge_demo_case_pending_plan',
        'support_bridge_demo_case_attempts',
        'support_bridge_demo_case_last_success_timestamp_seconds',
    ];

    private readonly float $deadline;
    private readonly string $operatorToken;
    private readonly string $metricsToken;
    private readonly string $grafanaPassword;

    public function __construct()
    {
        $this->deadline = microtime(true) + 45;
        $this->operatorToken = $this->secret('/run/secrets/operator/token');
        $this->metricsToken = $this->secret('/run/secrets/metrics/token');
        $this->grafanaPassword = $this->secret('/run/secrets/grafana/password');
        $this->check($this->operatorToken !== $this->metricsToken, 'Operator and metrics credentials must differ.');
    }

    public function run(): void
    {
        $this->wait(function (): bool {
            $health = $this->get(self::API . '/health');
            return $health['status'] === 200 && ($health['json']['status'] ?? null) === 'ok';
        }, 'The API did not become ready.');
        $health = $this->get(self::API . '/health');
        $this->check(($health['json']['service'] ?? null) === 'support-bridge', 'Unexpected API service.');
        $this->check(($health['headers']['cache-control'] ?? null) === 'no-store', 'API liveness must not be cached.');
        $this->passed('API liveness is available without credentials');

        $docs = $this->get(self::API . '/docs');
        $specification = $this->get(self::API . '/openapi.json');
        $initializer = $this->get(self::API . '/assets/swagger-ui/initializer.js');
        $this->check(
            $docs['status'] === 200 && str_contains($docs['body'], '/assets/swagger-ui/swagger-ui-bundle.js'),
            'Swagger must use the locally served bundle.'
        );
        $this->check(
            $specification['status'] === 200 && ($specification['json']['openapi'] ?? null) === '3.1.0'
            && isset($specification['json']['paths']['/api/pairs/{id}/approve']),
            'The published API contract is incomplete.'
        );
        $this->check(
            $initializer['status'] === 200 && str_contains($initializer['body'], 'validatorUrl: null')
            && str_contains($initializer['body'], 'persistAuthorization: false')
            && str_contains($initializer['body'], 'supportedSubmitMethods: []'),
            'Swagger must remain a read-only local reference.'
        );
        foreach (['swagger-ui.css', 'swagger-ui-bundle.js'] as $asset) {
            $loaded = $this->get(self::API . '/assets/swagger-ui/' . $asset);
            $this->check(
                $loaded['status'] === 200 && strlen($loaded['body']) > 100,
                'A vendored Swagger asset is unavailable.'
            );
        }
        $this->passed('Swagger, its assets and the OpenAPI contract are served locally');

        foreach ([
            $this->get(self::API . '/api/pairs'),
            $this->get(self::API . '/metrics'),
            $this->get(self::API . '/metrics', 'Bearer ' . $this->operatorToken),
            $this->get(self::API . '/api/pairs', 'Bearer ' . $this->metricsToken),
        ] as $denied) {
            $this->check(
                $denied['status'] === 401 && ($denied['json']['error']['code'] ?? null) === 'unauthorized',
                'An unauthorized or cross-authority request was accepted.'
            );
        }
        $this->passed('Operator and scrape credentials cannot authorize each other');

        $listing = $this->get(self::API . '/api/pairs', 'Bearer ' . $this->operatorToken);
        $items = $listing['json']['items'] ?? null;
        $this->check(
            $listing['status'] === 200 && is_array($items) && $items !== [],
            'The initialized local link set is unavailable.'
        );
        $id = $items[0]['id'] ?? null;
        $this->check(is_string($id), 'A linked pair has no identifier.');
        $inspection = $this->get(self::API . '/api/pairs/' . rawurlencode($id), 'Bearer ' . $this->operatorToken);
        $this->check(
            $inspection['status'] === 200
            && in_array($inspection['json']['pair']['status'] ?? null, self::STATUSES, true)
            && ($inspection['json']['governance']['policy_version'] ?? null) === 'escalation-state-v1'
            && is_bool($inspection['json']['governance']['requires_approval'] ?? null),
            'Operator inspection does not expose the persisted workflow and governance state.'
        );
        $this->passed('Authenticated inspection exposes workflow and governance evidence');

        foreach (['/.env', '/README.md', '/src/Engine.php', '/var/local/local.sqlite'] as $path) {
            $private = $this->get(self::API . $path);
            $this->check(
                in_array($private['status'], [400, 404], true)
                && !str_contains($private['body'], '<?php') && !str_contains($private['body'], 'SQLite format'),
                'A private source or state path was served.'
            );
        }
        $this->passed('The HTTP server does not expose private source or state paths');

        $exporter = $this->get(self::API . '/metrics', 'Bearer ' . $this->metricsToken);
        $this->check(
            $exporter['status'] === 200
            && str_starts_with($exporter['headers']['content-type'] ?? '', 'text/plain; version=0.0.4')
            && str_ends_with($exporter['body'], "\n"),
            'The authenticated exporter is unavailable or has the wrong format.'
        );
        $samples = $this->samples($exporter['body']);
        $count = $this->sample($samples, 'support_bridge_reconciliation_duration_seconds_count');
        $total = 0.0;
        foreach (self::OUTCOMES as $outcome) {
            $total += $this->sample($samples, 'support_bridge_reconciliations_total{outcome="' . $outcome . '"}');
        }
        $this->check($total === $count && $count >= 1, 'Exported outcome and histogram counts are inconsistent.');
        $previous = 0.0;
        foreach (self::BUCKETS as $bucket) {
            $value = $this->sample($samples, 'support_bridge_reconciliation_duration_seconds_bucket{le="' .
                $bucket . '"}');
            $this->check($value >= $previous && $value <= $count, 'Exported histogram buckets are inconsistent.');
            $previous = $value;
        }
        $this->check($previous === $count, 'The infinite histogram bucket must equal the observation count.');
        $this->checkDemoCases($samples);
        foreach ([$this->operatorToken, $this->metricsToken, $this->grafanaPassword, $id,
            $inspection['json']['pair']['baseline']['title'] ?? null] as $private) {
            if (is_string($private) && $private !== '') {
                $this->check(
                    !str_contains($exporter['body'], $private),
                    'The exporter disclosed private record or credential data.'
                );
            }
        }
        $this->passed('Exporter counters, cumulative histogram and fixed labels are consistent');

        $this->wait(function (): bool {
            $targets = $this->get(self::PROMETHEUS . '/api/v1/targets');
            foreach ($targets['json']['data']['activeTargets'] ?? [] as $target) {
                if (($target['labels']['job'] ?? null) === 'support_bridge' && ($target['health'] ?? null) === 'up') {
                    return $this->promValue('up{job="support_bridge"}') === 1.0;
                }
            }
            return false;
        }, 'Prometheus did not establish an authenticated healthy scrape.');
        $this->check(
            $this->promValue('sum(support_bridge_reconciliations_total)') >= 1,
            'Prometheus has no reconciliation observations.'
        );
        $this->check($this->promValue('sum(support_bridge_pairs)') >= 1, 'Prometheus has no linked-pair gauge.');
        $this->check(
            $this->promValue('sum(support_bridge_pairs{status="awaiting_approval"})') >= 0,
            'Prometheus has no approval-wait gauge.'
        );
        $this->wait(
            fn (): bool => $this->promValue('count(support_bridge_demo_case_status)') === 3.0,
            'Prometheus did not receive the bounded three-case metric contract.'
        );
        $this->passed('Prometheus performs real authenticated scrapes and returns application metrics');

        $rules = $this->get(self::PROMETHEUS . '/api/v1/rules');
        $loaded = [];
        foreach ($rules['json']['data']['groups'] ?? [] as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                $loaded[$rule['name'] ?? ''] = $rule;
            }
        }
        $this->check(
            count($loaded) === 3
            && isset(
                $loaded['SupportBridgeScrapeUnavailable'],
                $loaded['SupportBridgeOperatorAttention'],
                $loaded['SupportBridgePendingRecovery']
            )
            && str_contains($loaded['SupportBridgeOperatorAttention']['query'] ?? '', 'awaiting_approval'),
            'Prometheus did not load the expected operator-attention rules.'
        );
        $this->passed('All three operating rules are loaded, including pending approvals');

        $grafanaAuth = 'Basic ' . base64_encode('admin:' . $this->grafanaPassword);
        $this->wait(function () use ($grafanaAuth): bool {
            $health = $this->get(self::GRAFANA . '/api/datasources/uid/supportbridge-prometheus/health', $grafanaAuth);
            return $health['status'] === 200 && ($health['json']['status'] ?? null) === 'OK';
        }, 'Grafana did not establish its provisioned Prometheus datasource.');
        $datasource = $this->get(self::GRAFANA . '/api/datasources/uid/supportbridge-prometheus', $grafanaAuth);
        $this->check(
            $datasource['status'] === 200 && ($datasource['json']['url'] ?? null) === self::PROMETHEUS,
            'Grafana must query Prometheus through its Compose service address.'
        );
        $dashboard = $this->get(self::GRAFANA . '/api/dashboards/uid/supportbridge-overview', $grafanaAuth);
        $panels = $dashboard['json']['dashboard']['panels'] ?? null;
        $this->check(
            $dashboard['status'] === 200 && ($dashboard['json']['meta']['provisioned'] ?? false) === true
            && is_array($panels) && count($panels) === 5,
            'Grafana did not load the provisioned five-panel case dashboard.'
        );
        $attention = array_filter($panels, static fn (array $panel): bool => ($panel['id'] ?? null) === 3);
        $expression = array_values($attention)[0]['targets'][0]['expr'] ?? '';
        $this->check(
            str_contains($expression, 'awaiting_approval'),
            'The dashboard must include plans awaiting approval.'
        );
        $casePanels = array_values(array_filter($panels, static fn (array $panel): bool =>
            in_array($panel['id'] ?? null, [4, 5], true)));
        $this->check(
            count($casePanels) === 2 && $casePanels[0]['type'] === 'table'
            && $casePanels[1]['type'] === 'state-timeline',
            'The dashboard must show the case table and sampled workflow history.'
        );
        $caseExpressions = array_column($casePanels[0]['targets'] ?? [], 'expr');
        $this->check(
            $caseExpressions === [
                'support_bridge_demo_case_status',
                'support_bridge_demo_case_resolution',
                'support_bridge_demo_case_pending_plan',
                'support_bridge_demo_case_attempts',
                '1000 * support_bridge_demo_case_last_success_timestamp_seconds',
            ]
            && ($casePanels[1]['targets'][0]['expr'] ?? null) === 'support_bridge_demo_case_status',
            'The case dashboard must use stored evidence without synthetic counts or timestamps.'
        );
        $timelineDefaults = $casePanels[1]['fieldConfig']['defaults'] ?? [];
        $timelineMappings = $timelineDefaults['mappings'][0] ?? [];
        $this->check(
            ($timelineDefaults['color']['mode'] ?? null) === 'palette-classic'
            && !isset($timelineDefaults['thresholds'])
            && ($timelineMappings['type'] ?? null) === 'value'
            && count($timelineMappings['options'] ?? []) === 8,
            'Workflow history must use discrete value mappings instead of numeric threshold ranges.'
        );
        $stateLegend = [
            ['Not linked', 'gray'], ['Ready for sweep', 'blue'], ['Synced', 'green'],
            ['Awaiting approval', 'orange'], ['Conflict', 'red'], ['Retry scheduled', 'yellow'],
            ['Needs intervention', 'red'], ['Unknown stored state', 'red'],
        ];
        foreach ($stateLegend as $code => [$label, $color]) {
            $this->check(
                ($timelineMappings['options'][$code]['text'] ?? null) === $label
                && ($timelineMappings['options'][$code]['color'] ?? null) === $color,
                'Workflow history is missing a declared state label or color.'
            );
        }
        $this->passed('Grafana authenticates, queries its datasource and provisions the approval-aware dashboard');

        echo "9 Compose integration checks passed; no operator or provider writes were performed.\n";
    }

    private function secret(string $path): string
    {
        $value = @file_get_contents($path);
        $value = $value === false ? '' : trim($value);
        $this->check(
            strlen($value) >= 32 && preg_match('/[\x00-\x20\x7f]/', $value) === 0,
            'A required generated credential is missing or invalid.'
        );
        return $value;
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
    private function get(string $url, ?string $authorization = null): array
    {
        $remaining = (int) (($this->deadline - microtime(true)) * 1000);
        $this->check($remaining > 0, 'The Compose verification deadline expired.');
        $handle = curl_init($url);
        $headers = [];
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(1500, $remaining), CURLOPT_TIMEOUT_MS => min(3000, $remaining),
            CURLOPT_HTTPHEADER => $authorization === null ? [] : ['Authorization: ' . $authorization],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $this->check(
            is_string($body) && strlen($body) <= 4_194_304,
            'An internal HTTP read failed or exceeded the verification limit.'
        );
        $json = null;
        if (str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $json = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'json' => $json];
    }

    private function wait(callable $probe, string $failure): void
    {
        do {
            try {
                if ($probe()) {
                    return;
                }
            } catch (RuntimeException) {
                // Only bounded read probes retry while services establish their connections.
            }
            usleep(200_000);
        } while (microtime(true) < $this->deadline);
        throw new RuntimeException($failure);
    }

    private function promValue(string $expression): float
    {
        $response = $this->get(self::PROMETHEUS . '/api/v1/query?' . http_build_query(['query' => $expression]));
        $value = $response['json']['data']['result'][0]['value'][1] ?? null;
        $this->check(
            $response['status'] === 200 && ($response['json']['status'] ?? null) === 'success'
            && is_string($value) && is_numeric($value) && is_finite((float) $value),
            'An expected Prometheus query result is unavailable.'
        );
        return (float) $value;
    }

    /** @return array<string,float> */
    private function samples(string $text): array
    {
        $samples = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->check(
                preg_match('/^(([a-z_]+)(?:\{([a-z]+)="([^"]+)"\})?) ([0-9.eE+-]+)$/D', $line, $matches) === 1,
                'The exporter emitted an unexpected sample shape.'
            );
            $identity = $matches[1];
            $name = $matches[2];
            $label = $matches[3] ?? '';
            $value = $matches[4] ?? '';
            $allowed = match ($name) {
                'support_bridge_reconciliations_total' => $label === 'outcome' && in_array(
                    $value,
                    self::OUTCOMES,
                    true
                ),
                'support_bridge_pairs' => $label === 'status' && in_array($value, self::STATUSES, true),
                'support_bridge_reconciliation_duration_seconds_bucket' => $label === 'le' && in_array(
                    $value,
                    self::BUCKETS,
                    true
                ),
                'support_bridge_reconciliation_duration_seconds_sum',
                'support_bridge_reconciliation_duration_seconds_count',
                'support_bridge_telemetry_storage_available', 'support_bridge_pending_plans',
                'support_bridge_last_success_timestamp_seconds' => $label === '',
                default => in_array($name, self::CASE_METRICS, true)
                    && $label === 'case' && in_array($value, self::DEMO_CASES, true),
            };
            $this->check($allowed, 'The exporter emitted an unexpected name or label.');
            $numeric = (float) end($matches);
            $this->check(
                !isset($samples[$identity]) && is_finite($numeric) && $numeric >= 0,
                'The exporter emitted a duplicate or invalid sample.'
            );
            $samples[$identity] = $numeric;
        }
        return $samples;
    }

    /** @param array<string,float> $samples */
    private function checkDemoCases(array $samples): void
    {
        foreach (self::DEMO_CASES as $case) {
            $values = [];
            foreach (self::CASE_METRICS as $metric) {
                $value = $this->sample($samples, $metric . '{case="' . $case . '"}');
                $this->check(floor($value) === $value, 'A case gauge must contain an integer observation.');
                $values[$metric] = $value;
            }
            $present = $values['support_bridge_demo_case_present'];
            $status = $values['support_bridge_demo_case_status'];
            $this->check(
                $present <= 1 && $status <= 7
                && $values['support_bridge_demo_case_resolution'] <= 2
                && $values['support_bridge_demo_case_pending_plan'] <= 1,
                'A case gauge is outside the declared code range.'
            );
            if ($present === 0.0) {
                $this->check(array_sum($values) === 0.0, 'A missing case must not report invented observations.');
            } else {
                $this->check($status >= 1, 'A linked case must report its actual or unknown stored state.');
            }
        }
    }

    private function sample(array $samples, string $identity): float
    {
        $this->check(array_key_exists($identity, $samples), 'An expected exporter sample is missing.');
        return $samples[$identity];
    }

    private function check(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new RuntimeException($failure);
        }
    }

    private function passed(string $message): void
    {
        echo 'PASS ' . $message . PHP_EOL;
    }
}

try {
    (new StackVerification())->run();
} catch (Throwable $error) {
    // Responses, credentials and record contents are deliberately absent from diagnostics.
    fwrite(STDERR, 'FAIL Compose verification: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
