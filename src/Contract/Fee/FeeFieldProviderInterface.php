<?php

declare(strict_types=1);

namespace App\Contract\Fee;

use App\Entity\ProductCore;
use Symfony\Component\HttpFoundation\Request;

interface FeeFieldProviderInterface
{
    public function renderProductFields(ProductCore $product): string;

    public function saveProductFields(ProductCore $product, Request $request): void;
}
