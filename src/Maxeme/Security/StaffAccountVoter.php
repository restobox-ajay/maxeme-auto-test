<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

use App\Entity\AdminUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may change another staff account in Manage Admins (de-activate it, and any later edit or
 * delete): whoever holds Permission::STAFF_EDIT, except that only a Super Admin (or Tech Support)
 * may touch a Super Admin or Tech Support account.
 *
 * @extends Voter<string, AdminUser>
 */
final class StaffAccountVoter extends Voter
{
    public const MANAGE = 'MAXEME_STAFF_MANAGE';

    /** The accounts only a Super Admin may change. */
    private const PROTECTED_ROLES = [StaffRole::TechSupport, StaffRole::SuperAdmin];

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof AdminUser;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$this->decisions->decide($token, [Permission::STAFF_EDIT])) {
            return false;
        }

        return !in_array(StaffRole::of($subject), self::PROTECTED_ROLES, true)
            || $this->decisions->decide($token, [StaffRole::SuperAdmin->value]);
    }
}
