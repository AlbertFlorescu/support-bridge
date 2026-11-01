<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

use Bridge\Http\HttpFailure;
use Bridge\Http\Request;

/** Verify only the selected session against our fixed internal Grafana, never a supplied URL. */
final class GrafanaSession
{
    private readonly \Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = \Closure::fromCallable($transport ?? $this->request(...));
    }

    public function authenticate(Request $request, Configuration $configuration): Session
    {
        $values = [];
        foreach (explode(';', $request->header('Cookie')) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if ($parts[0] === $configuration->cookieName) {
                $values[] = $parts[1] ?? '';
            }
        }
        if (count($values) !== 1 || !$this->validCookie($values[0])) {
            throw new HttpFailure(401, 'grafana_login_required', 'Sign in to the selected Grafana instance first.');
        }
        $result = ($this->transport)($configuration->cookieName, $values[0]);
        if ($result['status'] === 401 || $result['status'] === 403) {
            throw new HttpFailure(401, 'grafana_login_required', 'Your Grafana session is no longer valid.');
        }
        if ($result['status'] !== 200) {
            throw new HttpFailure(503, 'grafana_unavailable', 'Grafana sign-in verification is unavailable.');
        }
        try {
            $user = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpFailure(503, 'grafana_unavailable', 'Grafana returned invalid sign-in evidence.');
        }
        if (
            !is_array($user) || !is_string($user['login'] ?? null)
            || ($user['isGrafanaAdmin'] ?? false) !== true || ($user['isDisabled'] ?? false) === true
            || !in_array($user['login'], $configuration->logins, true)
        ) {
            throw new HttpFailure(403, 'review_forbidden', 'This Grafana account is not an enabled bridge reviewer.');
        }
        $current = $values[0];
        foreach ($result['cookies'] ?? [] as $cookie) {
            if (str_starts_with($cookie, $configuration->cookieName . '=')) {
                $value = explode(';', substr($cookie, strlen($configuration->cookieName) + 1), 2)[0];
                if (!$this->validCookie($value)) {
                    throw new HttpFailure(503, 'grafana_unavailable', 'Grafana returned invalid session evidence.');
                }
                $current = $value;
            }
        }
        return new Session($user['login'], $values[0], $current);
    }

    private function validCookie(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $value) === 1;
    }

    private function request(string $name, string $value): array
    {
        $body = '';
        $cookies = [];
        $handle = curl_init('http://grafana:3000/api/user');
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Cookie: ' . $name . '=' . $value, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$cookies): int {
                if (stripos($line, 'Set-Cookie:') === 0) {
                    $cookies[] = trim(substr($line, 11));
                }
                return strlen($line);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false) {
            throw new HttpFailure(503, 'grafana_unavailable', 'Grafana sign-in verification is unavailable.');
        }
        return ['status' => $status, 'body' => $body, 'cookies' => $cookies];
    }
}
