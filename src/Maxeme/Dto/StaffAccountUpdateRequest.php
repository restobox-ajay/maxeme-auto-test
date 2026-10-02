<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use App\Validation\Dto\NewPasswordRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Manage Admins › Edit: the same fields as Add a new admin, except the password is optional
 * (blank keeps the current one) and a Tech Support account keeps its role (it is not assignable).
 */
final class StaffAccountUpdateRequest extends AccountIdentityRequest
{
    /** Null when both password boxes were left blank; otherwise core's new-password rules apply. */
    #[Assert\Valid]
    public ?NewPasswordRequest $password = null;

    #[Assert\NotBlank(message: 'First name is required.')]
    #[Assert\Length(max: 120)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Last name is required.')]
    #[Assert\Length(max: 120)]
    public string $lastName = '';

    /** Optional. */
    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public string $phoneNumber = '';

    #[Assert\NotNull(message: 'Choose a role.')]
    public ?StaffRole $role = null;

    public static function fromUser(AdminUser $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = (string) $user->getUsername();
        $dto->firstName = (string) $user->getFirstName();
        $dto->lastName = (string) $user->getLastName();
        $dto->phoneNumber = (string) $user->getPhoneNumber();
        $dto->role = StaffRole::of($user);

        return $dto;
    }

    public static function fromRequest(Request $request, AdminUser $user): self
    {
        $dto = new self();
        $dto->fillIdentity($request);
        $dto->firstName = trim((string) $request->request->get('first_name', ''));
        $dto->lastName = trim((string) $request->request->get('last_name', ''));
        $dto->phoneNumber = trim((string) $request->request->get('phone_number', ''));

        $current = StaffRole::of($user);
        $dto->role = $current === StaffRole::TechSupport
            ? $current
            : StaffRole::tryFrom((string) $request->request->get('role', ''));
        if ($dto->role === StaffRole::TechSupport && $current !== StaffRole::TechSupport) {
            $dto->role = null;
        }

        $password = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('password_confirm', '');
        if ($password !== '' || $confirm !== '') {
            $dto->password = new NewPasswordRequest($password, $confirm);
        }

        return $dto;
    }
}
