<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

use App\Entity\AdminUser;
use App\Maxeme\Security\Authorization\PermissionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Makes is_granted('/area/view') work in Twig, controllers and the sidebar menu. Votes only on
 * permission names (a leading `/`), so ROLE_* and IS_AUTHENTICATED_* stay with Symfony's voters.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionChecker $checker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, '/');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof AdminUser && $this->checker->isGranted($user, $attribute);
    }
}
