<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Enum\MileageUnit;
use App\Maxeme\Validation\ContactFormat;
use App\Maxeme\Enum\RepairOrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The repair order page's own fields. Every one is optional. The customer, vehicle, advisor and
 * master technician are ids the RepairOrderWriter looks up ("new" for a customer or vehicle typed
 * in on the page); its services, charges and appointments are posted alongside.
 */
final class RepairOrderData extends FormData
{
    public const FIELDS = [
        'status' => 'status',
        'client_id' => 'clientId',
        'vehicle_id' => 'vehicleId',
        'mileage' => 'mileage',
        'mileage_unit' => 'mileageUnit',
        'advisor_id' => 'advisorId',
        'tag_key' => 'tagKey',
        'master_technician_id' => 'masterTechnicianId',
        'name' => 'name',
        'concern' => 'concern',
        'recommendations' => 'recommendations',
        'pickup_address_id' => 'pickupAddressId',
        'gst_rate' => 'gstRate',
        'pst_rate' => 'pstRate',
    ];

    /** The value of the customer / vehicle select that means "the one typed in below". */
    public const NEW = 'new';

    #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Choose a status from the list.')]
    public ?string $status = null;

    #[Assert\Regex('/^(\d+|new)$/', message: 'Choose a customer from the list.')]
    public ?string $clientId = null;

    #[Assert\Regex('/^(\d+|new)$/', message: 'Choose a vehicle from the list.')]
    public ?string $vehicleId = null;

    #[Assert\Length(max: 20)]
    public ?string $mileage = null;

    #[Assert\Choice(choices: ['km', 'miles'], message: 'Choose KM or miles.')]
    public ?string $mileageUnit = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose an advisor from the list.')]
    public ?string $advisorId = null;

    #[Assert\Length(max: 60)]
    public ?string $tagKey = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose an assigned technician from the list.')]
    public ?string $masterTechnicianId = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 5000)]
    public ?string $concern = null;

    /** For the customer, printed on the work order and copied onto the invoice (mockup 169b599). */
    #[Assert\Length(max: 2000)]
    public ?string $recommendations = null;

    /** One of the client's addresses, or blank for none (mockup a38e7a2). */
    #[Assert\Regex('/^\d+$/', message: 'Choose a pickup address from the list.')]
    public ?string $pickupAddressId = null;

    /** This repair order's GST and PST, whole percent 0 to 100, editable in its Totals box (mockup 55f432c; 0 for none). */
    #[Assert\NotBlank(message: 'Enter the GST rate (0 for none).')]
    #[Assert\Regex('/^\d{1,3}$/', message: 'The GST rate is a whole percent, 0 to 100.')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'The GST rate is a whole percent, 0 to 100.')]
    public ?string $gstRate = null;

    #[Assert\NotBlank(message: 'Enter the PST rate (0 for none).')]
    #[Assert\Regex('/^\d{1,3}$/', message: 'The PST rate is a whole percent, 0 to 100.')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'The PST rate is a whole percent, 0 to 100.')]
    public ?string $pstRate = null;

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (RepairOrderStatus $status): string => $status->value, RepairOrderStatus::cases());
    }

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'mileageUnit' => MileageUnit::fromValue($value),
            'mileage' => ContactFormat::normalizeMileage($value),
            'gstRate', 'pstRate' => (int) $value,
            default => $value,
        };
    }

    protected function managedElsewhere(): array
    {
        return ['status', 'clientId', 'vehicleId', 'advisorId', 'masterTechnicianId', 'pickupAddressId'];
    }
}
