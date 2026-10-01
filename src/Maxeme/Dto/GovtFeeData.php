<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The government fee form. */
final class GovtFeeData extends AbstractChargeData
{
    public const FIELDS = [
        ...self::BASE_FIELDS,
        'description' => 'description',
    ];

    #[Assert\Length(max: 5000)]
    public ?string $description = null;
}
