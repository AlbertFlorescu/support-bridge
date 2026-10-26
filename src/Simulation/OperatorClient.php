<?php

declare(strict_types=1);

namespace Bridge\Simulation;

final readonly class OperatorClient
{
    public function __construct(private string $token)
    {
        if (strlen($token) < 32 || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('A valid local operator credential is required.');
        }
    }

    /** @return array{status:int,body:array} */
    public function request(string $method, string $path, array $body = []): array
    {
        if (
            !in_array($method, ['GET', 'POST'], true)
            || preg_match('~^/api/(?:sync|pairs(?:/traffic-[1-8](?:/(?:approve|resolve))?)?)$~D', $path) !== 1
        ) {
            throw new \InvalidArgumentException('Traffic can call only its fixed operator routes.');
        }
        $handle = curl_init('http://api:8080' . $path);
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS        => 5000,
            CURLOPT_HTTPHEADER        => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_WRITEFUNCTION     => static function (
                $curl,
                string $chunk
            ) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 1_048_576) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_THROW_ON_ERROR));
        }
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($result === false || $status >= 500) {
            throw new TrafficUnavailable('The local operator API is temporarily unavailable.');
        }
        try {
            $data = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The local operator API returned invalid JSON.');
        }
        if (!is_array($data)) {
            throw new \RuntimeException('The local operator API returned an invalid response.');
        }
        return [
            'status' => $status,
            'body'   => $data,
        ];
    }
}
