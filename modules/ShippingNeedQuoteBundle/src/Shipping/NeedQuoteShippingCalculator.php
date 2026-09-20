<?php

declare(strict_types=1);

namespace ShippingNeedQuoteBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;

final class NeedQuoteShippingCalculator implements ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool
    {
        return true;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        return [
            new ShippingOption(
                id:           'need-quote',
                label:        'Need Quote',
                description:  'This destination is not covered by our standard shipping options. We will contact you with a shipping quote.',
                amount:       0.0,
                deliveryDays: null,
                taxClass:     $document->getHighestTaxClass(),
                forcesQuote:  true,
            ),
        ];
    }
}
