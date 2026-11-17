<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Entity\AdminUser;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What every staff-account form edits: the two login names, the person's name and (where the form
 * shows it) the phone number. Uniqueness is checked against the database by StaffAccountService,
 * which is why it is not a constraint here.
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

    /** The posted email, username, names and phone (a form without a phone box leaves it blank). */
    protected function fillIdentity(Request $request): void
    {
        $this->email = trim((string) $request->request->get('email', ''));
        $this->username = trim((string) $request->request->get('username', ''));
        $this->firstName = trim((string) $request->request->get('first_name', ''));
        $this->lastName = trim((string) $request->request->get('last_name', ''));
        $this->phoneNumber = trim((string) $request->request->get('phone_number', ''));
    }

    /** The account's current values, for the form's first display. */
    protected function fillFromUser(AdminUser $user): void
    {
        $this->email = $user->getEmail();
        $this->username = (string) $user->getUsername();
        $this->firstName = (string) $user->getFirstName();
        $this->lastName = (string) $user->getLastName();
        $this->phoneNumber = (string) $user->getPhoneNumber();
    }
}
