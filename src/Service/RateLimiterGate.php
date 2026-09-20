<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Whether a rate limiter should actually consume/enforce for the given request (#356).
 *
 * Dev/test Cests running the same endpoint dozens of times in one suite run tripped every
 * limiter in the app unpredictably, since none of them cared what environment they were in.
 * The old fix was blunt (config/packages/test/security.yaml sets login_throttling's
 * max_attempts to a million, breaking any test that actually wants to see throttling happen).
 *
 * This is a header instead: dev/test defaults every limiter OFF, so ordinary Cests run
 * unthrottled and never spend quota on each other's requests, but a request carrying
 * X-Test-Rate-Limiter-On: true still gets the real limiter — the one Cest that exists to prove
 * throttling works sends it. In prod the header is never consulted at all; a limiter is always
 * enforced there regardless of what any request claims.
 */
final class RateLimiterGate
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function shouldEnforce(Request $request): bool
    {
        if (!\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            return true;
        }

        return $request->headers->get('X-Test-Rate-Limiter-On') === 'true';
    }
}
