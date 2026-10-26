<?php

declare(strict_types=1);

namespace Bridge\Provider;

/** Validate provider identities before either persistence or credential-bearing requests. */
final class Identity
{
    public static function repository(string $repository): string
    {
        if (
            !preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}$~D', $repository)
            || in_array(explode('/', $repository)[1] ?? '', ['.', '..'], true)
        ) {
            throw new \InvalidArgumentException('GitHub repository must be an owner/repository pair.');
        }
        return $repository;
    }

    public static function subdomain(string $subdomain): string
    {
        $subdomain = strtolower($subdomain);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)) {
            throw new \InvalidArgumentException('Zendesk subdomain must be a single hostname label.');
        }
        return $subdomain;
    }
}
