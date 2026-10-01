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
    #[Assert\NotBlank(message: 'First name is required.')]
    #[Assert\Length(max: 120)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Last name is required.')]
    #[Assert\Length(max: 120)]
    public string $lastName = '';

    /** Optional. */
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[0-9+()\-.\s]*$/', message: 'Enter a valid phone number.')]
    public string $phoneNumber = '';

    #[UserPassword(message: 'Current password is incorrect.')]
    public string $currentPassword = '';

    public static function fromUser(AdminUser $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = (string) $user->getUsername();
        $dto->firstName = (string) $user->getFirstName();
        $dto->lastName = (string) $user->getLastName();
        $dto->phoneNumber = (string) $user->getPhoneNumber();

        return $dto;
    }

    public static function fromRequest(Request $request): self
    {
        $dto = new self();
        $dto->fillIdentity($request);
        $dto->firstName = trim((string) $request->request->get('first_name', ''));
        $dto->lastName = trim((string) $request->request->get('last_name', ''));
        $dto->phoneNumber = trim((string) $request->request->get('phone_number', ''));
        $dto->currentPassword = (string) $request->request->get('current_password', '');

        return $dto;
    }
}
