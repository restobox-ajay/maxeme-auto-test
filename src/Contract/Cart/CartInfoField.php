<?php

declare(strict_types=1);

namespace App\Contract\Cart;

final class CartInfoField
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
    ) {}
}
