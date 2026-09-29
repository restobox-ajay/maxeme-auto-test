<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Validation\Dto\NewPasswordRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/** Manage Admins › Add a new admin (legacy cns_user_registration form). New accounts are Staff. */
final class StaffAccountRequest extends AccountIdentityRequest
{
    /** Core's shared new-password rules (length, confirmation). */
    #[Assert\Valid]
    public NewPasswordRequest $password;

    #[Assert\NotBlank(message: 'First name is required.')]
    #[Assert\Length(max: 120)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Last name is required.')]
    #[Assert\Length(max: 120)]
    public string $lastName = '';

    public function __construct()
    {
        $this->password = new NewPasswordRequest('', '');
    }

    public static function fromRequest(Request $request): self
    {
        $dto = new self();
        $dto->fillIdentity($request);
        $dto->password = new NewPasswordRequest(
            (string) $request->request->get('password', ''),
            (string) $request->request->get('password_confirm', ''),
        );
        $dto->firstName = trim((string) $request->request->get('first_name', ''));
        $dto->lastName = trim((string) $request->request->get('last_name', ''));

        return $dto;
    }
}
