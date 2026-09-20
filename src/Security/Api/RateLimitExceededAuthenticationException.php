<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class RateLimitExceededAuthenticationException extends AuthenticationException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Rate limit exceeded.');
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function getMessageKey(): string
    {
        return 'Rate limit exceeded.';
    }
}
