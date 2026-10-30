<?php

declare(strict_types=1);

namespace Bridge\Provider;

use InvalidArgumentException;

/** Builds one explicit Zendesk authentication scheme without exposing credentials in errors. */
final readonly class ZendeskAuthentication implements ZendeskAuthorization
{
    private string $authorization;

    public function __construct(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $token,
        string $mode = 'api-token'
    ) {
        if (!in_array($mode, ['api-token', 'oauth'], true)) {
            throw new InvalidArgumentException('Zendesk authentication mode must be api-token or oauth.');
        }
        if (
            $token === '' || strlen($token) > 4096
            || preg_match('/[\p{C}\s]/u', $token) !== 0
        ) {
            throw new InvalidArgumentException('A valid Zendesk credential is required.');
        }
        if ($mode === 'api-token' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid Zendesk email is required for API-token authentication.');
        }

        $this->authorization = $mode === 'oauth'
            ? 'Bearer ' . $token
            : 'Basic ' . base64_encode($email . '/token:' . $token);
    }

    public function authorizationHeader(): string
    {
        return $this->authorization;
    }
}
