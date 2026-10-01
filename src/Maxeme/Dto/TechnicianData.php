<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The technician form: name and Active. */
final class TechnicianData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'active' => 'active',
    ];

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    /** The Active checkbox: "1" when ticked. */
    public ?string $active = '1';

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'name' => (string) $value,
            'active' => $value === '1',
            default => $value,
        };
    }
}
