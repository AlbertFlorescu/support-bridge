<?php

declare(strict_types=1);

namespace Bridge\Http;

final class BearerAuthentication
{
    public function __construct(private readonly string $token, private readonly string $metricsToken = '')
    {
    }

    public function metrics(Request $request): void
    {
        if ($this->tokensOverlap()) {
            throw new HttpFailure(
                503,
                'metrics_not_configured',
                'Configure distinct operator and metrics bearer tokens.'
            );
        }
        $this->authenticateBearer(
            $request,
            $this->metricsToken,
            'metrics_not_configured',
            'Configure a strong BRIDGE_METRICS_TOKEN before using the metrics exporter.'
        );
    }

    public function operator(Request $request): void
    {
        if ($this->tokensOverlap()) {
            throw new HttpFailure(503, 'api_not_configured', 'Configure distinct operator and metrics bearer tokens.');
        }
        $this->authenticateBearer(
            $request,
            $this->token,
            'api_not_configured',
            'Configure a strong BRIDGE_API_TOKEN before using the operator API.'
        );
    }

    private function authenticateBearer(
        Request $request,
        string $token,
        string $configurationCode,
        string $configurationMessage
    ): void {
        if (strlen($token) < 32 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new HttpFailure(503, $configurationCode, $configurationMessage);
        }
        $header = $request->header('Authorization');
        if (!preg_match('/^Bearer (\S+)$/iD', $header, $match) || !hash_equals($token, $match[1])) {
            throw new HttpFailure(
                401,
                'unauthorized',
                'A valid bearer token is required.',
                ['WWW-Authenticate' => 'Bearer']
            );
        }
    }

    private function tokensOverlap(): bool
    {
        return $this->metricsToken !== '' && hash_equals($this->token, $this->metricsToken);
    }
}
