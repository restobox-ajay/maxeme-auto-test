<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Enum\PartType;
use App\Maxeme\Validation\Money;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The part form (legacy cns_parts_info_form), and one field of it for an inline cell edit.
 *
 * The quantity is shown and posted here, but PartService moves it through the StockLedger so the
 * change is recorded, instead of applyTo() writing it.
 */
final class PartData extends FormData
{
    public const FIELDS = [
        'vin' => 'vin',
        'name' => 'name',
        'manufacturer' => 'manufacturer',
        'type' => 'type',
        'description' => 'description',
        'vendor' => 'vendor',
        'unit_price' => 'unitPrice',
        'sale_price' => 'salePrice',
        'quantity' => 'quantity',
        'notes' => 'notes',
    ];

    #[Assert\Length(max: 255)]
    public ?string $vin = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 255)]
    public ?string $manufacturer = null;

    #[Assert\Choice(callback: [self::class, 'typeValues'], message: 'Choose Unit, Kit or Consumables.')]
    public ?string $type = PartType::Unit->value;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    #[Assert\Length(max: 255)]
    public ?string $vendor = null;

    #[Money]
    public ?string $unitPrice = null;

    #[Money]
    public ?string $salePrice = null;

    #[Assert\Regex(pattern: '/^-?\d{1,9}$/', message: 'Quantity must be a whole number.')]
    public ?string $quantity = null;

    #[Assert\Length(max: 65535)]
    public ?string $notes = null;

    /** @return list<string> */
    public static function typeValues(): array
    {
        return array_column(PartType::cases(), 'value');
    }

    public function quantityValue(): ?int
    {
        return $this->quantity !== null ? (int) $this->quantity : null;
    }

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'type' && $value !== null ? PartType::from($value) : $value;
    }

    protected function managedElsewhere(): array
    {
        return ['quantity'];
    }
}
