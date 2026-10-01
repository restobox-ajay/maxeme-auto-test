<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Money;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields Labour and Government Fees share. A subclass's FIELDS starts with BASE_FIELDS. */
abstract class AbstractChargeData extends FormData
{
    public const BASE_FIELDS = [
        'code' => 'code',
        'name' => 'name',
        'price' => 'price',
        'tax_class' => 'taxClassId',
        'active' => 'active',
    ];

    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex('/^[A-Za-z0-9_-]+$/', message: 'Code can have letters, digits, - and _ only.')]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter the price.')]
    #[Money]
    public ?string $price = null;

    /** A Config › Settings › Tax Classes id; the controller looks it up (see managedElsewhere()). */
    #[Assert\NotBlank(message: 'Choose a tax class.')]
    #[Assert\Regex('/^\d+$/', message: 'Choose a tax class from the list.')]
    public ?string $taxClassId = null;

    /** The Active checkbox: "1" when ticked. */
    public ?string $active = '1';

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'code', 'name', 'price' => (string) $value,
            'active' => $value === '1',
            default => $value,
        };
    }

    protected function managedElsewhere(): array
    {
        return ['taxClassId'];
    }
}
