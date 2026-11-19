<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use App\Entity\ProductCore;
use App\Maxeme\Accounting\Money;
use App\Service\Product\ProductPicker;

/**
 * The part search of the invoice's and the repair order / service line pickers: core's product
 * search (name, SKU, barcode), Active products only (ProductCore::isSellable(); not Draft or
 * Inactive), each priced at its Sale Price, else its Original Price.
 */
final class PartSearch
{
    public function __construct(private readonly ProductPicker $products)
    {
    }

    /** @return list<array{label: string, value: int|null, price: string|null}> */
    public function search(string $term): array
    {
        $items = [];
        foreach ($this->products->searchPage($term)['products'] as $product) {
            if ($product->isSellable()) {
                $items[] = ['label' => ItemLabel::product($product), 'value' => $product->getId(), 'price' => self::price($product)];
            }
        }

        return $items;
    }

    /** What a part is charged at: its Sale Price, else its Original Price. */
    public static function price(ProductCore $product): ?string
    {
        return Money::rounded($product->getDefaultPrice() ?? $product->getOriginalPrice());
    }
}
