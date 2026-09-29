<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Money;
use Symfony\Component\Validator\Constraints as Assert;

/** The service form (legacy cns_services_info_form). */
final class ServiceItemData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'preferred_name' => 'preferredName',
        'price' => 'price',
    ];

    /** The legacy column is NOT NULL, and a blank name was a 500 error there. */
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 255)]
    public ?string $preferredName = null;

    #[Money]
    public ?string $price = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'name' ? (string) $value : $value;
    }
}
