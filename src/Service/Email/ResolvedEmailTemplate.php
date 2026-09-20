<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * One email template as the application should actually use it — the shipped definition with any
 * admin edits laid over it (#507).
 *
 * Callers get content, not a row. Whether a field came from ShippedEmailTemplates or from an
 * email_template row is deliberately not something a sender has to think about, because every
 * sender got that wrong in the same way: fourteen call sites each did their own
 * findOneBy(['code' => …]) and each decided for itself what to do when it came back null.
 */
final readonly class ResolvedEmailTemplate
{
    public function __construct(
        public string $code,
        public string $module,
        public string $sentTo,
        public string $subject,
        public ?string $description,
        public string $status,
        public string $body,
        /** Row id when an email_template row exists for this code, null when it resolves purely from code. */
        public ?int $id,
        /** Whether ShippedEmailTemplates carries this code at all — false for admin-authored templates. */
        public bool $isShipped,
        /**
         * Whether a shipped template has been changed from what ships.
         *
         * Advisory, not authoritative. It is a field-by-field comparison against the shipped
         * definition, so whitespace an editor introduced reads as a modification. That is the right
         * trade for what it drives — a badge in the template list and the visibility of a "revert"
         * action — because being wrong there costs an admin one glance, whereas the same comparison
         * inside a migration deciding what to delete costs them their edits. #507 moved it here for
         * exactly that reason.
         */
        public bool $isCustomized,
    ) {}

    public function isActive(): bool
    {
        return strcasecmp($this->status, 'Active') === 0;
    }
}
