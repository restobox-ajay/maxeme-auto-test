<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Password-reset / account-setup tokens must never be stored or looked up in plaintext —
 * a DB read (backup leak, injection, etc.) would otherwise hand over live account-takeover
 * tokens directly. generate() returns the raw token to put in the emailed URL; hash() is
 * what actually gets persisted to AdminUser/CustomerUser::resetToken and what lookups must
 * query by. SHA-256 (not a slow password hash) is appropriate here: the token itself is
 * already 256 bits of random entropy, so the threat is DB exposure, not brute force.
 */
final class ResetTokenService
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
