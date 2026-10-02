<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;

/** One pickup address in a client's address book (Client Profile › Address Book). */
final class ClientAddressData extends FormData
{
    public const FIELDS = [
        'label' => 'label',
        'address_line1' => 'addressLine1',
        'address_line2' => 'addressLine2',
        'city' => 'city',
        'province' => 'province',
        'postal_code' => 'postalCode',
        'phone' => 'phone',
        'delivery_instructions' => 'deliveryInstructions',
    ];

    /** Field => label, in form order. */
    public const LABELS = [
        'label' => 'Label',
        'address_line1' => 'Address',
        'address_line2' => 'Address line 2',
        'city' => 'City',
        'province' => 'Province',
        'postal_code' => 'Postal code',
        'phone' => 'Phone at this address',
        'delivery_instructions' => 'Pickup instructions',
    ];

    /** "Home", "Work", "Mom's place": what the client calls it. */
    #[Assert\Length(max: 80)]
    public ?string $label = null;

    #[Assert\NotBlank(message: 'Enter the address.')]
    #[Assert\Length(max: 255)]
    public ?string $addressLine1 = null;

    #[Assert\Length(max: 255)]
    public ?string $addressLine2 = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Length(max: 120)]
    public ?string $province = null;

    #[Assert\Length(max: 20)]
    #[Formatted(InputRule::PostalCode)]
    public ?string $postalCode = null;

    #[Assert\Length(max: 40)]
    #[Formatted(InputRule::Phone)]
    public ?string $phone = null;

    #[Assert\Length(max: 65535)]
    public ?string $deliveryInstructions = null;
}
