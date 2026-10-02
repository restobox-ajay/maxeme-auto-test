<?php

declare(strict_types=1);

namespace App\Maxeme\Validation;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** An optional field that must match one of the shared InputRule formats: #[Formatted(InputRule::Phone)]. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Formatted extends Compound
{
    public InputRule $rule;

    #[HasNamedArguments]
    public function __construct(InputRule $rule, ?array $groups = null, mixed $payload = null)
    {
        $this->rule = $rule;
        parent::__construct([], $groups, $payload);
    }

    protected function getConstraints(array $options): array
    {
        return [new Assert\Regex(pattern: $this->rule->regex(), message: $this->rule->message())];
    }
}
