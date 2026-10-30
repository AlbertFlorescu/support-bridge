<?php

declare(strict_types=1);

namespace Bridge;

/** HTTPS JSON transport with an injectable boundary for entirely offline tests. */
class HttpClient
{
    private const MAX_BODY_BYTES = 1_048_576;
    private const MAX_HEADER_BYTES = 65_536;
    private readonly ?\Closure $transport;

    /** @param null|callable(string,string,array,?array):HttpResponse $transport */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?array $json = null): HttpResponse
    {
        $this->validateUrl($url);
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'PATCH', 'PUT', 'POST'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method.');
        }
        if (
            $method === 'POST' && (
            !preg_match('~^https://api\.github\.com/repos/(?!\.{1,2}/)[A-Za-z0-9_.-]+/'
                . '(?!\.{1,2}/)[A-Za-z0-9_.-]+/issues$~D', $url)
            || $json === null || count($json) !== 2
            || !is_string($json['title'] ?? null) || !is_string($json['body'] ?? null)
            )
        ) {
            throw new \InvalidArgumentException('POST is limited to creating a GitHub issue with title and body.');
        }
        foreach ($headers as $name => $value) {
            if (
                !is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D', $name)
                || !is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new \InvalidArgumentException('Invalid HTTP header.');
            }
        }
        if ($this->transport !== null) {
            try {
                $response = ($this->transport)($method, $url, $headers, $json);
                if (!$response instanceof HttpResponse) {
                    throw new \LogicException('Transport must return HttpResponse.');
                }
                return $response;
            } catch (RemoteFailure | RemoteConflict $error) {
                throw $error;
            } catch (\Throwable) {
                throw new RemoteFailure('HTTPS transport failed; the write outcome may be unknown.', true);
            }
        }
        return $this->curlRequest($method, $url, $headers, $json);
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $officialHost = $host === 'api.github.com'
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.zendesk\.com$/D', $host) === 1;
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !$officialHost
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        ) {
            throw new \InvalidArgumentException('Only official GitHub and Zendesk HTTPS API hosts are allowed.');
        }
    }

    /** @param array<string, string> $headers */
    private function curlRequest(string $method, string $url, array $headers, ?array $json): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new RemoteFailure('The PHP cURL extension is required for live requests.');
        }
        $encoded = null;
        if ($json !== null) {
            try {
                $encoded = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                throw new RemoteFailure('The request could not be encoded as JSON.');
            }
            $headers['Content-Type'] = 'application/json';
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $body = '';
        $responseHeaders = [];
        $headerBytes = 0;
        $tooLarge = false;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RemoteFailure('HTTPS transport could not initialize.', true);
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_USERAGENT      => 'SupportEngineeringBridge/1.0',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS      => 0,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function (
                    $handle,
                    string $line
                ) use (
                    &$responseHeaders,
                    &$headerBytes,
                    &$tooLarge
                ): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > self::MAX_HEADER_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = []; // Discard interim/proxy response headers.
                    } elseif (str_contains($line, ':')) {
                        [
                            $name,
                            $value,
                        ] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            if ($encoded !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
            }
            $success = curl_exec($handle);
            if ($tooLarge) {
                throw new RemoteFailure('Remote response exceeded the configured size limit.');
            }
            if ($success === false) {
                // curl_error() can contain request URLs; deliberately expose no transport details.
                throw new RemoteFailure('HTTPS transport failed; the write outcome may be unknown.', true);
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        $decoded = [];
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \JsonException('Expected object.');
                }
            } catch (\JsonException) {
                if ($status >= 200 && $status < 300) {
                    throw new RemoteFailure('Remote service returned an invalid JSON response.', true);
                }
                // Preserve the HTTP classification for e.g. HTML rate-limit/error pages.
                $decoded = [];
            }
        }
        return new HttpResponse($status, $responseHeaders, $decoded);
    }
}
