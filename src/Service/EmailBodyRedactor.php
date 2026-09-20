<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Strips live password-reset / account-setup tokens out of a rendered email body before it is
 * archived in email_log. ResetTokenService only ever persists SHA-256 hashes of those tokens so
 * that a DB read cannot hand over account-takeover credentials; storing the emailed URL verbatim
 * would defeat that, since email_log is rendered in the admin UI and is LIKE-searchable by any
 * ROLE_ADMIN row.
 *
 * Two independent rules run, so redaction still holds if either the token format or the URL shape
 * changes later:
 *   1. any run of 64-or-more hex characters — the shape ResetTokenService::generate() produces —
 *      is masked in full (a partially masked token is still a live secret);
 *   2. the value of any token= / t= query parameter is masked whatever it looks like.
 *
 * Everything else is left byte-for-byte intact so an operator can still see what was sent.
 *
 * On a dev instance (kernel.environment === 'dev') redaction is skipped entirely, so testers can
 * open a password-reset/account-setup email in email_log and follow the live link without a real
 * mailbox. Every other environment — test, staging, prod — still redacts.
 */
final class EmailBodyRedactor
{
    public const MASK = '************';

    /**
     * Mask the value of a token= / t= query parameter, whatever shape it has. The value stops at
     * the next parameter separator (`&`, or the `&` opening an HTML-escaped `&amp;`), quote,
     * whitespace or tag boundary.
     */
    private const QUERY_PARAM_PATTERN = '/((?:\?|&|&amp;)(?:token|t)=)[^&"\'\s<>]*/i';

    /** The shape of ResetTokenService::generate(): bin2hex(random_bytes(32)). */
    private const HEX_TOKEN_PATTERN = '/[0-9a-fA-F]{64,}/';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
    }

    public function redact(string $body): string
    {
        if ($body === '' || $this->environment === 'dev') {
            return $body;
        }

        $redacted = preg_replace(
            [self::QUERY_PARAM_PATTERN, self::HEX_TOKEN_PATTERN],
            ['$1' . self::MASK, self::MASK],
            $body
        );

        // preg_replace() returns null if the engine bails out (backtrack/recursion limits). Fail
        // closed: mask the whole body rather than risk archiving an unredacted token.
        return is_string($redacted) ? $redacted : self::MASK;
    }
}
