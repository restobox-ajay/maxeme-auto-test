<?php

declare(strict_types=1);

namespace App\Maxeme\Validation;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** An optional dollar amount typed into a text field: "12", "12.5" or "12.50". */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Money extends Compound
{
    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount in dollars, e.g. 12.50.'),
        ];
    }
}
