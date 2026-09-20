<?php

declare(strict_types=1);

namespace ShippingAmazonFBABundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;
use App\Service\AppSettings;

final class AmazonFBAShippingCalculator implements ShippingOptionInterface
{
    public const SETTING_KEY = 'shipping_amazon_fba_product_ids';

    public function __construct(private readonly AppSettings $appSettings) {}

    public function supports(AbstractSalesDocument $document): bool
    {
        $eligible = array_flip($this->eligibleProductIds());

        if (empty($eligible)) {
            return false;
        }

        $hasItems = false;
        foreach ($document->getCartItems() as ['product' => $product, 'qty' => $qty]) {
            if ($qty <= 0) {
                continue;
            }
            $hasItems = true;
            if (!isset($eligible[$product->getId()])) {
                return false;
            }
        }

        return $hasItems;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        $totalQty = 0;
        foreach ($document->getCartItems() as ['qty' => $qty]) {
            $totalQty += $qty;
        }

        return [
            new ShippingOption(
                id:           'amazon-fba',
                label:        'Delivery by Amazon',
                description:  'Fast fulfilment through Amazon FBA.',
                amount:       round(6.0 + 1.0 * $totalQty, 2),
                deliveryDays: 1,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }

    /** @return int[] */
    public function eligibleProductIds(): array
    {
        $raw = $this->appSettings->get(self::SETTING_KEY);
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}
