<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ProductCore;
use App\Service\Product\ProductBarcodeSearchResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `product_barcode_search_data(products)` — one `product.id => "code1 code2 …"` map, ready to drop
 * into each option's `data-barcodes` attribute in `templates/admin/_partials/product_field.html.twig`.
 *
 * Tier 1 of the product picker (under `App\Service\Product\ProductPicker::INLINE_LIMIT`) renders
 * the whole catalog inline and searches it in the browser with no request at all — the point of
 * that tier — so it cannot ask `ProductBarcodeSearchResolver::matchingProductIds()` the way tier 2's
 * remote search does. This is its equivalent: the codes travel with the option as search data the
 * existing client-side filter already knows how to check (`app.js`'s `filter()`, which already
 * reads `data-sku` the same way).
 *
 * A Twig function and not a template-side loop calling the resolver once per product, for the same
 * N+1 reason `ProductPicker::search()` (in the ProcurementBundle product namespace) resolves vendor
 * costs for a whole page in one query rather than one per row.
 */
final class ProductBarcodeSearchExtension extends AbstractExtension
{
    public function __construct(private readonly ProductBarcodeSearchResolver $resolver) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_barcode_search_data', [$this, 'searchData']),
        ];
    }

    /**
     * @param iterable<ProductCore> $products
     *
     * @return array<int, string>
     */
    public function searchData(iterable $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = $product->getId();
            }
        }

        if ($ids === []) {
            return [];
        }

        $codesByProduct = $this->resolver->codesForProducts($ids);

        $data = [];
        foreach ($codesByProduct as $productId => $codes) {
            $data[$productId] = implode(' ', $codes);
        }

        return $data;
    }
}
