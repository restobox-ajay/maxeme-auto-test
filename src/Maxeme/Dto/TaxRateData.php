<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** One row of the Tax Rates form (`rates[id][name]`, `rates[id][rate]`): its name and rate in percent. */
final class TaxRateData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'rate' => 'rate',
    ];

    #[Assert\NotBlank(message: 'Each tax needs a name.')]
    #[Assert\Length(max: 60)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter each tax rate.')]
    #[Assert\Regex('/^\d{1,2}$|^100$/', message: 'A tax rate is a whole percent from 0 to 100.')]
    public ?string $rate = null;

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'rate' => (int) $value,
            default => (string) $value,
        };
    }
}
