<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The service reminder form. The controller turns the two ids into the service and the template. */
final class ServiceReminderData extends FormData
{
    public const FIELDS = [
        'service_id' => 'serviceId',
        'reminder_days' => 'reminderDays',
        'template_id' => 'templateId',
        'message' => 'message',
    ];

    #[Assert\NotBlank(message: 'Choose the service.')]
    #[Assert\Regex('/^\d+$/', message: 'Choose the service from the list.')]
    public ?string $serviceId = null;

    #[Assert\NotBlank(message: 'Enter the reminder days.')]
    #[Assert\Regex('/^\d+$/', message: 'Reminder days must be a whole number.')]
    #[Assert\Range(min: 1, max: 3650, notInRangeMessage: 'Reminder days must be between {{ min }} and {{ max }}.')]
    public ?string $reminderDays = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose the template from the list.')]
    public ?string $templateId = null;

    #[Assert\Length(max: 5000)]
    public ?string $message = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $property === 'reminderDays' ? (int) $value : $value;
    }

    protected function managedElsewhere(): array
    {
        return ['serviceId', 'templateId'];
    }
}
