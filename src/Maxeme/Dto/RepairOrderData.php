<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

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
        'advisor_id' => 'advisorId',
        'tag_key' => 'tagKey',
        'master_technician_id' => 'masterTechnicianId',
        'name' => 'name',
        'concern' => 'concern',
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

    #[Assert\Regex('/^\d+$/', message: 'Choose an advisor from the list.')]
    public ?string $advisorId = null;

    #[Assert\Length(max: 60)]
    public ?string $tagKey = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose a master technician from the list.')]
    public ?string $masterTechnicianId = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 5000)]
    public ?string $concern = null;

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (RepairOrderStatus $status): string => $status->value, RepairOrderStatus::cases());
    }

    protected function managedElsewhere(): array
    {
        return ['status', 'clientId', 'vehicleId', 'advisorId', 'masterTechnicianId'];
    }
}
