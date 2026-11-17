<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Entity\AdminUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** My Profile › Edit: name, phone, username and email, confirmed with the signed-in user's current password. */
final class ProfileUpdateRequest extends AccountIdentityRequest
{
    #[UserPassword(message: 'Current password is incorrect.')]
    public string $currentPassword = '';

    public static function fromUser(AdminUser $user): self
    {
        $dto = new self();
        $dto->fillFromUser($user);

        return $dto;
    }

    public static function fromRequest(Request $request): self
    {
        $dto = new self();
        $dto->fillIdentity($request);
        $dto->currentPassword = (string) $request->request->get('current_password', '');

        return $dto;
    }
}
