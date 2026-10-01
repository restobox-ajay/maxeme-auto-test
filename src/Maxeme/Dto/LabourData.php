<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Enum\LabourUnit;
use Symfony\Component\Validator\Constraints as Assert;

/** The labour form. */
final class LabourData extends AbstractChargeData
{
    public const FIELDS = [
        ...self::BASE_FIELDS,
        'unit' => 'unit',
        'sublet' => 'sublet',
    ];

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'units'], message: 'Choose hours or each.')]
    public ?string $unit = LabourUnit::Hour->value;

    /** The Sublet checkbox: "1" when ticked. */
    public ?string $sublet = null;

    /** @return list<string> */
    public static function units(): array
    {
        return array_map(static fn (LabourUnit $unit): string => $unit->value, LabourUnit::cases());
    }

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'unit' => LabourUnit::from((string) $value),
            'sublet' => $value === '1',
            default => parent::toEntityValue($property, $value),
        };
    }
}
