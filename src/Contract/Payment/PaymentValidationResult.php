<?php

declare(strict_types=1);

namespace App\Contract\Payment;

final class PaymentValidationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
    ) {}
}
