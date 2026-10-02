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
        'category_id' => 'categoryId',
        'tax_class_id' => 'taxClassId',
        'colour' => 'ownColour',
    ];

    /** The legacy column is NOT NULL, and a blank name was a 500 error there. */
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 255)]
    public ?string $preferredName = null;

    #[Money]
    public ?string $price = null;

    /** A ServiceCategory id; the controller turns it into the category. */
    #[Assert\Regex('/^\d+$/', message: 'Choose a category from the list.')]
    public ?string $categoryId = null;

    /** A Config › Settings › Tax Classes id, or blank for none; the controller looks it up. */
    #[Assert\Regex('/^\d+$/', message: 'Choose a tax class from the list.')]
    public ?string $taxClassId = null;

    /** The service's own calendar colour, or blank to use its category's. */
    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/', message: 'Colour must look like #1f77b4, or be blank to use the category\'s.')]
    public ?string $ownColour = null;

    protected function managedElsewhere(): array
    {
        return ['categoryId', 'taxClassId'];
    }

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'name' ? (string) $value : $value;
    }
}
