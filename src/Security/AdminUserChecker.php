<?php

namespace App\Security;

use App\Entity\AdminUser;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdminUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly AccountStatusResolver $accountStatusResolver,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof AdminUser) {
            return;
        }

        $message = $this->accountStatusResolver->getBlockMessage($user);
        if ($message !== null) {
            throw new CustomUserMessageAccountStatusException($message);
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
