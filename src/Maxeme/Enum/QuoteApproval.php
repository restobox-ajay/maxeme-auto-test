<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** How the customer approved a quote (mockup aa322bb): asked when Approve is pressed on the quote. */
enum QuoteApproval: string
{
    case Phone = 'phone';
    case Email = 'email';
    case InPerson = 'in_person';
    case Signed = 'signed';

    /** "By phone", for the Approve dialog. */
    public function label(): string
    {
        return match ($this) {
            self::Phone => 'By phone',
            self::Email => 'By email',
            self::InPerson => 'In person',
            self::Signed => 'On the signed quote',
        };
    }

    /** "by phone", inside a sentence ("Quote approved by phone."). */
    public function phrase(): string
    {
        return lcfirst($this->label());
    }
}
