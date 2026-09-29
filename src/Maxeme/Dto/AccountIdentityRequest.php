<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The two login names every staff-account form edits. Uniqueness is checked against the database
 * by StaffAccountService, which is why it is not a constraint here.
 */
abstract class AccountIdentityRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Username is required.')]
    #[Assert\Length(min: 2, max: 180)]
    #[Assert\Regex(pattern: '/^\S+$/', message: 'Username cannot contain spaces.')]
    public string $username = '';

    protected function fillIdentity(Request $request): void
    {
        $this->email = trim((string) $request->request->get('email', ''));
        $this->username = trim((string) $request->request->get('username', ''));
    }
}
