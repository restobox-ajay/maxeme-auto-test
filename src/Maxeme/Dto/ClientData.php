<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client form (legacy cns_client_info_form): Add New Person, Edit the Client, and the profile's
 * Client Profile tab. Every field is optional, as in the legacy app.
 */
final class ClientData extends FormData
{
    public const FIELDS = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'preferred_name' => 'preferredName',
        'email' => 'email',
        'home_number' => 'homeNumber',
        'work_number' => 'workNumber',
        'cell_number' => 'cellNumber',
        'address' => 'address',
        'note' => 'note',
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
    public ?string $homeNumber = null;

    #[Assert\Length(max: 255)]
    public ?string $workNumber = null;

    #[Assert\Length(max: 255)]
    public ?string $cellNumber = null;

    #[Assert\Length(max: 255)]
    public ?string $address = null;

    #[Assert\Length(max: 65535)]
    public ?string $note = null;
}
