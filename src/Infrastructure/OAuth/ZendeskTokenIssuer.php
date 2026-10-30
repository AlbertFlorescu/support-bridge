<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\OAuth;

use Closure;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Narrow token-endpoint transport; never invokes a ticket or reconciliation endpoint. */
final readonly class ZendeskTokenIssuer
{
    private ?Closure $transport;

    /** @param null|callable(string,array):array $transport Controlled offline transport. */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function issue(OAuthClientCredentials $client, int $startedAt): OAuthTokenBundle
    {
        $url = 'https://' . $client->subdomain . '.zendesk.com/oauth/tokens';
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientId(),
            'client_secret' => $client->clientSecret(),
            'scope' => OAuthTokenBundle::SCOPE,
            'expires_in' => OAuthTokenBundle::LIFETIME,
        ];
        try {
            $response = $this->transport === null ? $this->request($url, $payload) : ($this->transport)($url, $payload);
            return OAuthTokenBundle::fromResponse($response, $client, $startedAt);
        } catch (Throwable) {
            throw new RuntimeException('Zendesk token issuance failed; the previous credential is retained.');
        }
    }

    /** @param array<string,int|string> $payload @return array<string,mixed> */
    private function request(string $url, #[SensitiveParameter] array $payload): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('The OAuth HTTPS request is unavailable.');
        }
        $body = '';
        $headerBytes = 0;
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT => 'SupportBridge/1.0',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes): int {
                    $headerBytes += strlen($line);
                    return $headerBytes > 16384 ? 0 : strlen($line);
                },
            ]);
            if (
                curl_exec($handle) === false
                || !in_array(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), [200, 201], true)
            ) {
                throw new RuntimeException('The OAuth HTTPS request failed.');
            }
        } finally {
            curl_close($handle);
        }
        $response = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('The OAuth response is invalid.');
        }
        return $response;
    }
}
