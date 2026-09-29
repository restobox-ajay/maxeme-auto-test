<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Schedule\ScheduleSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Schedule / Edit an appointment (legacy Appointment/form.html.twig): the vehicle, the start and
 * end picked on the calendar (shop time, "Y-m-d H:i:s"), and a note.
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

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        foreach (['vehicleId' => 'vehicle_id', 'start' => 'start', 'end' => 'end', 'note' => 'note'] as $property => $field) {
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

        return $data;
    }
}
