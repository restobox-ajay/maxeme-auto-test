<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use App\Entity\ProductCore;
use App\Maxeme\Entity\AbstractCharge;

/** How a part or a charge is named in pickers and on lines: its name, then its SKU or code. */
final class ItemLabel
{
    public static function product(ProductCore $product): string
    {
        return $product->getSku() !== '' ? sprintf('%s (%s)', $product->getName(), $product->getSku()) : $product->getName();
    }

    public static function charge(AbstractCharge $charge): string
    {
        return sprintf('%s (%s)', $charge->getName(), $charge->getCode());
    }
}
