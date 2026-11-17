<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Security\StaffRole;
use App\Validation\Dto\NewPasswordRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/** Manage Admins › Add a new admin (legacy cns_user_registration form), with the account's role. */
final class StaffAccountRequest extends AccountIdentityRequest
{
    /** Core's shared new-password rules (length, confirmation). */
    #[Assert\Valid]
    public NewPasswordRequest $password;

    /** @var list<StaffRole> one or more (mockup 3d5095e) */
    #[Assert\Count(min: 1, minMessage: 'Pick at least one role.')]
    public array $roles = [];

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
        $dto->roles = StaffRole::listFrom($request->request->all()['roles'] ?? []);

        return $dto;
    }
}
