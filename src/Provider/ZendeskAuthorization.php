<?php

declare(strict_types=1);

namespace Bridge\Provider;

/** Supplies authorization for one request; implementations may observe credential rotation. */
interface ZendeskAuthorization
{
    public function authorizationHeader(): string;
}
