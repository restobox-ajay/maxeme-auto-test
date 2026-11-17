<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One appointment row on the repair order page (appointments[n][id|start|duration|promised]), times
 * in shop time as a datetime-local input posts them ("2026-09-24T15:00"), the duration in hours
 * (mockup 2371126: a new appointment lasts two hours). Past appointments are not posted: they are
 * shown, not edited.
 */
final class RepairOrderAppointmentData
{
    private const LOCAL_TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/';

    public ?string $id = null;

    #[Assert\NotBlank(message: 'Enter the appointment time.')]
    #[Assert\Regex(self::LOCAL_TIME, message: 'Enter the appointment time as a date and time.')]
    public ?string $start = null;

    /** Hours, 0.5 to 24; blank keeps a booked appointment's length (2 hours for a new one). */
    #[Assert\Regex('/^\d{1,2}(\.\d+)?$/', message: 'Enter the duration in hours, e.g. 1.5.')]
    #[Assert\Range(min: 0.5, max: 24, notInRangeMessage: 'The duration must be between 0.5 and 24 hours.')]
    public ?string $duration = null;

    #[Assert\Regex(self::LOCAL_TIME, message: 'Enter the promised time as a date and time.')]
    public ?string $promised = null;

    /**
     * @param mixed $rows the posted appointments[] array
     *
     * @return list<self> the rows as typed, blank new ones left out
     */
    public static function listFromRequest(mixed $rows): array
    {
        $appointments = [];
        foreach (PostedRows::of($rows) as $row) {
            $value = PostedRows::reader($row);

            $appointment = new self();
            $appointment->id = $value('id');
            $appointment->start = $value('start');
            $appointment->duration = $value('duration');
            $appointment->promised = $value('promised');
            if ($appointment->id === null && $appointment->start === null && $appointment->promised === null) {
                continue;
            }
            $appointments[] = $appointment;
        }

        return $appointments;
    }
}
