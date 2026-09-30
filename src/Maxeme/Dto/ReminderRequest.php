<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/** Schedule › Reminders › "+": a reminder added by hand for one of the client's vehicles. */
final class ReminderRequest
{
    #[Assert\NotNull(message: 'Choose a vehicle.')]
    public ?int $vehicleId = null;

    #[Assert\NotBlank(message: 'Choose the day to call.')]
    #[Assert\Date(message: 'Enter a valid date.')]
    public string $reminderDate = '';

    #[Assert\Length(max: 65535)]
    public ?string $note = null;

    public static function fromRequest(Request $request): self
    {
        $dto = new self();
        $vehicleId = (string) $request->request->get('vehicle_id', '');
        $dto->vehicleId = ctype_digit($vehicleId) ? (int) $vehicleId : null;
        $dto->reminderDate = trim((string) $request->request->get('reminder_date', ''));
        $dto->note = trim((string) $request->request->get('note', '')) ?: null;

        return $dto;
    }
}
