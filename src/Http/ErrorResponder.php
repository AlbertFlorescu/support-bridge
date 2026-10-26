<?php

declare(strict_types=1);

namespace Bridge\Http;

use Bridge\Application\ConfigurationFailure;
use Bridge\Exception\NotFound;
use Bridge\Exception\StateConflict;
use Bridge\Exception\WorkerBusy;
use Bridge\Infrastructure\Logging\JsonLineWriter;
use Bridge\RemoteConflict;
use Bridge\RemoteFailure;
use Throwable;

final class ErrorResponder
{
    public function __construct(private readonly JsonLineWriter $writer = new JsonLineWriter())
    {
    }

    public function fromException(Throwable $error, string $requestId): Response
    {
        return match (true) {
            $error instanceof HttpFailure => $this->error(
                $error->status,
                $error->errorCode,
                $error->getMessage(),
                $requestId,
                $error->headers
            ),
            $error instanceof NotFound => $this->error(404, 'not_found', 'The linked pair does not exist.', $requestId),
            $error instanceof WorkerBusy => $this->error(
                409,
                'worker_busy',
                'A reconciliation or operator command is already running.',
                $requestId,
                ['Retry-After' => '1']
            ),
            $error instanceof StateConflict, $error instanceof RemoteConflict => $this->error(
                409,
                'state_conflict',
                'The command conflicts with current state. Inspect the pair before trying again.',
                $requestId
            ),
            $error instanceof ConfigurationFailure => $this->error(
                503,
                'service_not_configured',
                'The service environment is incomplete or invalid.',
                $requestId
            ),
            $error instanceof \InvalidArgumentException => $this->error(
                422,
                'validation_failed',
                'Configuration or operation values are invalid. Check the documented contract.',
                $requestId
            ),
            $error instanceof RemoteFailure => $this->error(
                $error->retryable ? 503 : 502,
                'upstream_failure',
                'The provider request failed. The operator command was not completed; inspect current state.',
                $requestId
            ),
            default => $this->error(
                500,
                'internal_error',
                'The request could not be completed. Consult service logs using the request ID.',
                $requestId
            ),
        };
    }

    private function error(int $status, string $code, string $message, string $requestId, array $headers = []): Response
    {
        ($this->writer)(['event' => 'http_error', 'request_id' => $requestId, 'code' => $code, 'status' => $status]);
        return new Response(
            $status,
            [
                'error' => [
                    'code'      => $code,
                    'message'   => $message,
                    'requestId' => $requestId,
                ],
            ],
            ['X-Request-Id' => $requestId] + $headers
        );
    }
}
