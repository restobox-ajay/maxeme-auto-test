<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

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
    #[Assert\Regex(pattern: '/^\d{1,4}$/', message: 'Year must be a number, e.g. 2016.')]
    public ?string $year = null;

    #[Assert\Length(max: 255)]
    public ?string $vin = null;

    #[Assert\Length(max: 255)]
    public ?string $licensePlate = null;

    /** Free text in the legacy app ("120,000 km", "unknown", ...). */
    #[Assert\Length(max: 255)]
    public ?string $mileage = null;

    #[Assert\Length(max: 255)]
    public ?string $color = null;

    #[Assert\Length(max: 255)]
    public ?string $note = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'year' && $value !== null ? (int) $value : $value;
    }
}
