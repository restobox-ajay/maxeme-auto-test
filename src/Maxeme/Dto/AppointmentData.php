<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\AppointmentType;
use App\Maxeme\Schedule\ScheduleSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Schedule / Edit an appointment (legacy Appointment/form.html.twig): what it is for (a repair
 * order, Bodywork, Buying/selling cars, Other: mockup 689a4a1), the vehicle (none for Buying/selling
 * and Other), the start and end picked on the calendar (shop time, "Y-m-d H:i:s"), a note, the
 * repair order it schedules ("new" makes one, mockup 25368c7) and, when editing, its status.
 */
final class AppointmentData
{
    #[Assert\Choice(callback: [self::class, 'types'], message: 'Choose what the appointment is for.')]
    public ?string $type = null;

    /** A Vehicle id, or blank for none (Buying/selling cars and Other only). */
    #[Assert\Regex('/^\d+$/', message: 'Choose the vehicle.')]
    public ?string $vehicleId = null;

    #[Assert\NotBlank(message: 'Pick a start time from the calendar.')]
    #[Assert\DateTime(format: 'Y-m-d H:i:s', message: 'Pick a start time from the calendar.')]
    public ?string $start = null;

    #[Assert\NotBlank(message: 'The duration is missing.')]
    #[Assert\DateTime(format: 'Y-m-d H:i:s', message: 'The duration is missing.')]
    public ?string $end = null;

    #[Assert\Length(max: 65535)]
    public ?string $note = null;

    /** A RepairOrder id, "new" to make one, or blank for none. */
    #[Assert\Regex('/^(\d+|new)$/', message: 'Choose a repair order from the list.')]
    public ?string $repairOrderId = null;

    /** An AppointmentStatus value; blank keeps it (a new appointment is New). */
    #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Choose a status from the list.')]
    public ?string $status = null;

    /** The repair order select's value that makes a new repair order. */
    public const NEW_REPAIR_ORDER = 'new';

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        foreach (['type' => 'type', 'vehicleId' => 'vehicle_id', 'start' => 'start', 'end' => 'end', 'note' => 'note', 'repairOrderId' => 'repair_order_id', 'status' => 'status'] as $property => $field) {
            $value = trim((string) $request->request->get($field, ''));
            $data->{$property} = $value !== '' ? $value : null;
        }

        return $data;
    }

    /** The edit form's values, in shop time. */
    public static function fromAppointment(Appointment $appointment, ScheduleSettings $settings): self
    {
        $data = new self();
        $data->type = $appointment->getType()->value;
        $data->vehicleId = $appointment->getVehicle()?->getId() !== null ? (string) $appointment->getVehicle()->getId() : null;
        $data->start = $settings->toLocal($appointment->getStartTime())->format('Y-m-d H:i:s');
        $data->end = $settings->toLocal($appointment->getEndTime())->format('Y-m-d H:i:s');
        $data->note = $appointment->getNote();
        $data->repairOrderId = $appointment->getRepairOrder()?->getId() !== null ? (string) $appointment->getRepairOrder()->getId() : null;
        $data->status = $appointment->getStatus()->value;

        return $data;
    }

    public function appointmentType(): AppointmentType
    {
        return AppointmentType::tryFrom((string) $this->type) ?? AppointmentType::Repair;
    }

    /** A Repair order or Bodywork appointment needs its vehicle; the length is half an hour to a day. */
    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if ($this->vehicleId === null && $this->appointmentType()->needsVehicle()) {
            $context->buildViolation(sprintf('Choose the vehicle: a %s appointment needs one.', $this->appointmentType()->label()))->atPath('vehicleId')->addViolation();
        }
        $start = $this->start !== null ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->start) : false;
        $end = $this->end !== null ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->end) : false;
        if ($start !== false && $end !== false) {
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            if ($seconds < 1800 || $seconds > 86400) {
                $context->buildViolation('The duration must be between 0.5 and 24 hours.')->atPath('end')->addViolation();
            }
        }
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (AppointmentStatus $status): string => $status->value, AppointmentStatus::cases());
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (AppointmentType $type): string => $type->value, AppointmentType::cases());
    }
}
