<?php

declare(strict_types=1);

namespace App\Contract\Shipping;

use App\Entity\ProductCore;
use Symfony\Component\HttpFoundation\Request;

interface ShippingFieldProviderInterface
{
    public function renderProductFields(ProductCore $product): string;

    public function saveProductFields(ProductCore $product, Request $request): void;
}
