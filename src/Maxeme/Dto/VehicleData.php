<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The vehicle form (legacy cns_vehicle_info_form). Every field is optional, as in the legacy app. */
final class VehicleData extends FormData
{
    public const FIELDS = [
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'year' => 'year',
        'vin' => 'vin',
        'license_plate' => 'licensePlate',
        'mileage' => 'mileage',
        'color' => 'color',
        'note' => 'note',
    ];

    #[Assert\Length(max: 255)]
    public ?string $manufacturer = null;

    #[Assert\Length(max: 255)]
    public ?string $model = null;

    /** Typed as text, stored as a number (the legacy column is an integer). */
    #[Assert\Regex(pattern: '/^\d{4}$/', message: 'Year must be four digits, e.g. 2016.')]
    #[Assert\Callback([self::class, 'validateYear'])]
    public ?string $year = null;

    #[Formatted(InputRule::Vin)]
    public ?string $vin = null;

    #[Formatted(InputRule::LicensePlate)]
    public ?string $licensePlate = null;

    /** Kilometres or miles as a whole number ("150000" or "150,000"); legacy rows also hold "84436 MILES". */
    #[Formatted(InputRule::Mileage)]
    public ?string $mileage = null;

    #[Assert\Length(max: 255)]
    public ?string $color = null;

    #[Assert\Length(max: 255)]
    public ?string $note = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'year' && $value !== null ? (int) $value : $value;
    }

    /** The first cars to the year after this one (next year's models are on sale now). */
    public static function maxYear(): int
    {
        return (int) date('Y') + 1;
    }

    public static function validateYear(?string $year, ExecutionContextInterface $context): void
    {
        if ($year !== null && ctype_digit($year) && ((int) $year < 1900 || (int) $year > self::maxYear())) {
            $context->buildViolation(sprintf('Year must be between 1900 and %d.', self::maxYear()))->addViolation();
        }
    }
}
