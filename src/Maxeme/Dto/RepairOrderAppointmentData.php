<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One appointment row on the repair order page (appointments[n][id|start|promised]), times in shop
 * time as a datetime-local input posts them ("2026-09-24T15:00"). A new appointment lasts an hour;
 * a moved one keeps its length (the calendar changes it). Past appointments are not posted: they
 * are shown, not edited.
 */
final class RepairOrderAppointmentData
{
    private const LOCAL_TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/';

    public ?string $id = null;

    #[Assert\NotBlank(message: 'Enter the appointment time.')]
    #[Assert\Regex(self::LOCAL_TIME, message: 'Enter the appointment time as a date and time.')]
    public ?string $start = null;

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
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = static function (string $key) use ($row): ?string {
                $text = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';

                return $text !== '' ? $text : null;
            };

            $appointment = new self();
            $appointment->id = $value('id');
            $appointment->start = $value('start');
            $appointment->promised = $value('promised');
            if ($appointment->id === null && $appointment->start === null && $appointment->promised === null) {
                continue;
            }
            $appointments[] = $appointment;
        }

        return $appointments;
    }
}
