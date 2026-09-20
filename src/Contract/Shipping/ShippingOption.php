<?php

declare(strict_types=1);

namespace App\Contract\Shipping;

final class ShippingOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly float $amount,
        public readonly ?int $deliveryDays,
        public readonly string $taxClass,
        public readonly bool $forcesQuote = false,
    ) {}
}
