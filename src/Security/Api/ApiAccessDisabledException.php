<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A recognised, un-revoked key belonging to a company or user an administrator has switched off.
 *
 * Its own class purely so ApiKeyAuthenticator::onAuthenticationFailure() can answer 403 with the
 * reason rather than folding it into the generic 401 — a caller has to be able to tell "your key is
 * wrong" from "your access was turned off", because only one of those is fixed by asking support.
 */
final class ApiAccessDisabledException extends AuthenticationException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function getMessageKey(): string
    {
        return $this->reason;
    }
}
