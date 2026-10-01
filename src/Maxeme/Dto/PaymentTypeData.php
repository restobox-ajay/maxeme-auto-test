<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The payment type form: name, list order (blank = last) and Active. */
final class PaymentTypeData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'position' => 'position',
        'active' => 'active',
    ];

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 60)]
    public ?string $name = null;

    #[Assert\Regex('/^\d{1,4}$/', message: 'Order must be a whole number.')]
    public ?string $position = null;

    /** The Active checkbox: "1" when ticked. */
    public ?string $active = '1';

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'name' => (string) $value,
            'position' => (int) $value,
            'active' => $value === '1',
            default => $value,
        };
    }

    protected function managedElsewhere(): array
    {
        // A blank order puts a new one last; the controller decides.
        return $this->position === null ? ['position'] : [];
    }
}
