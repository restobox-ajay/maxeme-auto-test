<?php

namespace App\Security;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusResolver
{
    public function getBlockMessage(UserInterface $user): ?string
    {
        if ($user instanceof AdminUser) {
            return $user->getStatus() === 'Active'
                ? null
                : 'Your admin account is disabled.';
        }

        if (!$user instanceof CustomerUser) {
            return null;
        }

        $company = $user->getCompany();
        if ($company === null) {
            return 'Your account is not assigned to a company. Please contact an administrator.';
        }

        $companyStatus = strtolower(trim($company->getStatus()));
        if (in_array($companyStatus, ['review', 'pending', 'pending approval', 'awaiting approval'], true)) {
            return 'Your company registration is pending approval. Please wait for an administrator to review it.';
        }

        if ($companyStatus !== 'active') {
            return 'Your company account is disabled.';
        }

        $userStatus = strtolower(trim($user->getStatus()));
        if ($userStatus !== 'active') {
            return 'Your account is disabled.';
        }

        return null;
    }
}
