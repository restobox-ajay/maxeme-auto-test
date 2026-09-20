<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;

/**
 * Who performed a document action (#539 stage 2).
 *
 * The named actions on SalesOrder and Invoice each write their own timeline entry, and an entity
 * cannot reach the security context to find out who to credit it to. So the actor is passed in,
 * explicitly, at every call — which is not merely plumbing: it is what lets the stale-unpaid sweep
 * attribute its cancellations and voids to a scheduled job rather than to whichever user happened
 * to be signed in, without either action growing an "is this the sweep?" branch.
 *
 * Two names, deliberately, because the app already had two and they are not interchangeable:
 *
 *  - $auditName is the audit log's shape, "First Last (email)", written by AuditLogger since long
 *    before this class existed.
 *  - $displayName is the document timeline's shape, "First Last, email (id)", which
 *    EstimateController::adminDisplayName() and CheckoutController::documentActorName() both
 *    produced independently.
 *
 * Collapsing them into one would silently rewrite one of the two columns for every row written
 * after this change, so both are carried and each caller keeps the shape it already had.
 */
final class DocumentActor
{
    public const TYPE_ADMIN = 'admin';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_SYSTEM = 'system';

    /**
     * Automated work that is nobody's action. Distinct from TYPE_SYSTEM on purpose: "System" is
     * what an unattributed write falls back to, and #539 requires an admin looking at a voided
     * order to be able to tell at a glance that a scheduled job did it — which a value that also
     * means "we did not know" cannot say.
     */
    public const TYPE_AUTOMATION = 'automation';

    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly string $auditName,
        public readonly string $displayName,
    ) {
    }

    public static function forAdmin(AdminUser $user): self
    {
        return new self(
            self::TYPE_ADMIN,
            $user->getId(),
            self::auditNameFor($user->getFirstName(), $user->getLastName(), $user->getEmail()),
            self::displayNameFor($user->getFirstName(), $user->getLastName(), $user->getEmail(), $user->getId()),
        );
    }

    public static function forCustomer(CustomerUser $user): self
    {
        return new self(
            self::TYPE_CUSTOMER,
            $user->getId(),
            self::auditNameFor($user->getFirstName(), $user->getLastName(), $user->getEmail()),
            self::displayNameFor($user->getFirstName(), $user->getLastName(), $user->getEmail(), $user->getId()),
        );
    }

    /** Nobody was signed in — a console command, a queued message, a migration. */
    public static function system(): self
    {
        return new self(self::TYPE_SYSTEM, null, 'System', 'System');
    }

    /**
     * A caller that already holds a display name and nothing else.
     *
     * EstimateConversionService is the case: it is called from the admin side, from the customer
     * portal and from tests, has no security context of its own, and has always taken whatever name
     * its caller knew. Wrapping that name is better than having the service resolve an ambient user
     * that may not exist — but the identity behind it is genuinely unknown here, so the type stays
     * system rather than claiming an admin or a customer the id could not back up.
     */
    public static function named(string $displayName): self
    {
        $name = trim($displayName);

        return $name === '' ? self::system() : new self(self::TYPE_SYSTEM, null, $name, $name);
    }

    /**
     * A named scheduled job. $label is the job as a person would name it; the "(automated)" suffix
     * is added here so every automated entry reads the same way whoever wrote it.
     */
    public static function automation(string $label): self
    {
        $name = trim($label) !== '' ? trim($label) . ' (automated)' : 'Automated';

        return new self(self::TYPE_AUTOMATION, null, $name, $name);
    }

    private static function auditNameFor(?string $firstName, ?string $lastName, string $email): string
    {
        $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        return $name !== '' ? sprintf('%s (%s)', $name, $email) : $email;
    }

    private static function displayNameFor(?string $firstName, ?string $lastName, string $email, ?int $id): string
    {
        $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        $name = $name !== '' ? $name . ', ' . $email : $email;

        return $name . ' (' . $id . ')';
    }
}
