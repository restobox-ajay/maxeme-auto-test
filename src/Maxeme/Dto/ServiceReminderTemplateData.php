<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The service reminder template form: Name, Subject, Body (HTML with tags). */
final class ServiceReminderTemplateData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'subject' => 'subject',
        'body' => 'body',
    ];

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Subject is required.')]
    #[Assert\Length(max: 255)]
    public ?string $subject = null;

    #[Assert\NotBlank(message: 'Body is required.')]
    #[Assert\Length(max: 65535)]
    public ?string $body = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return (string) $value;
    }
}
