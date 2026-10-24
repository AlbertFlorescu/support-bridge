<?php
declare(strict_types=1);

namespace Bridge\Http;

final readonly class Request
{
    public function __construct(public string $method, public string $path, public array $headers = [],
        public string $body = '', public array $query = [])
    {
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_string($value) ? $value : '';
            }
        }
        return '';
    }

    public function json(array $required): array
    {
        if (strlen($this->body) > 16_384) {
            throw new HttpFailure(413, 'body_too_large', 'JSON bodies are limited to 16 KiB.');
        }
        if (strtolower(trim(explode(';', $this->header('Content-Type'))[0])) !== 'application/json') {
            throw new HttpFailure(415, 'unsupported_media_type', 'Use Content-Type: application/json.');
        }
        try {
            $object = json_decode($this->body, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpFailure(400, 'invalid_json', 'The request body must be a JSON object.');
        }
        if (!$object instanceof \stdClass) {
            throw new HttpFailure(400, 'invalid_json', 'The request body must be a JSON object.');
        }
        $body = (array) $object;
        if (array_diff(array_keys($body), $required) !== [] || array_diff($required, array_keys($body)) !== []) {
            throw new HttpFailure(422, 'invalid_fields', 'The JSON fields must match this operation exactly.');
        }
        foreach ($body as $value) {
            if (!is_string($value)) {
                throw new HttpFailure(422, 'invalid_fields', 'Operation fields must be strings.');
            }
        }
        return $body;
    }
}
