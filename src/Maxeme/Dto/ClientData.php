<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client form (legacy cns_client_info_form): Add New Person, Edit the Client, and the profile's
 * Client Profile tab. Every field is optional, as in the legacy app. Addresses and notes are their
 * own records (ClientAddressData, NoteBook).
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

    /** Free text: the legacy form had no format check and legacy rows hold anything. */
    #[Assert\Length(max: 255)]
    public ?string $email = null;

    #[Assert\Length(max: 255)]
    public ?string $phone1 = null;

    #[Assert\Length(max: 255)]
    public ?string $phone2 = null;

    #[Assert\Length(max: 255)]
    public ?string $phone3 = null;

    #[Assert\Length(max: 255)]
    public ?string $phone4 = null;
}
