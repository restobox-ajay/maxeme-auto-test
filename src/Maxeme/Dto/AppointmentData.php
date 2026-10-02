<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Schedule\ScheduleSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Schedule / Edit an appointment (legacy Appointment/form.html.twig): the vehicle, the start and
 * end picked on the calendar (shop time, "Y-m-d H:i:s"), a note, the repair order it schedules (one
 * of the client's, for the same vehicle) and, when editing, its status.
 */
final class AppointmentData
{
    #[Assert\NotBlank(message: 'Choose the vehicle.')]
    public ?string $vehicleId = null;

    #[Assert\NotBlank(message: 'Pick a start time from the calendar.')]
    #[Assert\DateTime(format: 'Y-m-d H:i:s', message: 'Pick a start time from the calendar.')]
    public ?string $start = null;

    #[Assert\NotBlank(message: 'The duration is missing.')]
    #[Assert\DateTime(format: 'Y-m-d H:i:s', message: 'The duration is missing.')]
    public ?string $end = null;

    #[Assert\Length(max: 65535)]
    public ?string $note = null;

    /** A RepairOrder id, or blank for none. */
    #[Assert\Regex('/^\d+$/', message: 'Choose a repair order from the list.')]
    public ?string $repairOrderId = null;

    /** An AppointmentStatus value; blank keeps it (a new appointment is New). */
    #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Choose a status from the list.')]
    public ?string $status = null;

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        foreach (['vehicleId' => 'vehicle_id', 'start' => 'start', 'end' => 'end', 'note' => 'note', 'repairOrderId' => 'repair_order_id', 'status' => 'status'] as $property => $field) {
            $value = trim((string) $request->request->get($field, ''));
            $data->{$property} = $value !== '' ? $value : null;
        }

        return $data;
    }

    /** The edit form's values, in shop time. */
    public static function fromAppointment(Appointment $appointment, ScheduleSettings $settings): self
    {
        $data = new self();
        $data->vehicleId = (string) $appointment->getVehicle()->getId();
        $data->start = $settings->toLocal($appointment->getStartTime())->format('Y-m-d H:i:s');
        $data->end = $settings->toLocal($appointment->getEndTime())->format('Y-m-d H:i:s');
        $data->note = $appointment->getNote();
        $data->repairOrderId = $appointment->getRepairOrder()?->getId() !== null ? (string) $appointment->getRepairOrder()->getId() : null;
        $data->status = $appointment->getStatus()->value;

        return $data;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (AppointmentStatus $status): string => $status->value, AppointmentStatus::cases());
    }
}
