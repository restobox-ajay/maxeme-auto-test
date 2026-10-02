<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\RepairOrder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything the repair order page posts, as typed: its own fields, a customer and a vehicle typed
 * in (new_client[...], new_vehicle[...]), its services with their lines, its custom fees and
 * discounts, and its upcoming appointments. A refused save shows it again as it was.
 */
final class RepairOrderForm
{
    /**
     * @param list<RepairOrderJobData>         $jobs
     * @param list<DocumentChargeData>      $charges
     * @param list<RepairOrderAppointmentData> $appointments
     */
    public function __construct(
        public readonly RepairOrderData $data,
        public readonly ClientData $newClient,
        public readonly VehicleData $newVehicle,
        public readonly array $jobs,
        public readonly array $charges,
        public readonly array $appointments,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $posted = $request->request->all();

        return new self(
            RepairOrderData::fromArray($posted),
            ClientData::fromArray(is_array($posted['new_client'] ?? null) ? $posted['new_client'] : []),
            VehicleData::fromArray(is_array($posted['new_vehicle'] ?? null) ? $posted['new_vehicle'] : []),
            RepairOrderJobData::listFromRequest($posted['jobs'] ?? []),
            DocumentChargeData::listFromRequest($posted['charges'] ?? []),
            RepairOrderAppointmentData::listFromRequest($posted['appointments'] ?? []),
        );
    }

    /** The saved repair order, for the page (its appointments are shown from the entity). */
    public static function fromEntity(RepairOrder $repairOrder): self
    {
        return new self(
            RepairOrderData::fromEntity($repairOrder),
            new ClientData(),
            new VehicleData(),
            array_map(RepairOrderJobData::fromEntity(...), $repairOrder->getJobs()),
            array_map(DocumentChargeData::fromEntity(...), $repairOrder->getCharges()),
            [],
        );
    }
}
