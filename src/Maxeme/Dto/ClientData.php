<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The client form (legacy cns_client_info_form): Add New Person, Edit the Client, and the profile's
 * Client Profile tab. Every field is optional, as in the legacy app. Addresses and notes are their
 * own records (ClientAddressData, NoteBook). A client needs a name of some kind; the email and phones
 * must look like one.
 */
final class ClientData extends FormData
{
    public const FIELDS = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'preferred_name' => 'preferredName',
        'email' => 'email',
        'phone_1' => 'phone1',
        'phone_2' => 'phone2',
        'phone_3' => 'phone3',
        'phone_4' => 'phone4',
    ];

    #[Assert\Length(max: 255)]
    public ?string $firstName = null;

    #[Assert\Length(max: 255)]
    public ?string $lastName = null;

    #[Assert\Length(max: 255)]
    public ?string $preferredName = null;

    #[Assert\Length(max: 255)]
    #[Assert\Email(message: 'Enter a valid email address, e.g. name@example.com.')]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public ?string $phone1 = null;

    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public ?string $phone2 = null;

    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public ?string $phone3 = null;

    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public ?string $phone4 = null;

    #[Assert\Callback]
    public function validateName(ExecutionContextInterface $context): void
    {
        if ($this->firstName === null && $this->lastName === null && $this->preferredName === null) {
            $context->buildViolation('Enter a first name, last name or preferred name.')->atPath('firstName')->addViolation();
        }
    }
}
