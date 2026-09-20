<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Repository\ApiCredentialRepository;

/**
 * Generates API keys with a CSPRNG. The legacy system this replaces used PHP's rand(), which is
 * seeded and predictable — recovering the seed from a couple of issued keys yields every other key
 * it will ever mint. random_bytes() is the fix, and the reason this is a class rather than a
 * one-liner at the call site.
 *
 * Regenerates on collision rather than trusting 160 bits blindly, since the uniqueness is a
 * database constraint and a duplicate would surface as a fatal insert error at the worst moment.
 */
final class ApiKeyGenerator
{
    public function __construct(
        private readonly ApiCredentialRepository $apiCredentialRepository,
    ) {}

    public function generate(): string
    {
        do {
            $key = bin2hex(random_bytes(20));
        } while ($this->apiCredentialRepository->apiKeyExists($key));

        return $key;
    }
}
