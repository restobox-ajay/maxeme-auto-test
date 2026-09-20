<?php

declare(strict_types=1);

namespace PaymentPayUponDeliveryBundle\Fee;

use App\Contract\Fee\AbstractFeeCalculator;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Tax\TaxContext;
use PaymentPayUponDeliveryBundle\Payment\PayUponDeliveryPaymentMethod;

/**
 * The flat surcharge charged for paying on delivery, applied only when Pay Upon Delivery was the
 * payment method selected at checkout.
 *
 * It is taxed at the highest tax class present in the basket rather than at a class of its own —
 * see highestTaxClass() below.
 */
final class PayUponDeliverySurchargeFeeCalculator extends AbstractFeeCalculator
{
    private const SURCHARGE_AMOUNT = 2.00;

    protected function supportedPaymentMethodSlug(): string
    {
        return PayUponDeliveryPaymentMethod::SLUG;
    }

    public function calculate(FeeContext $context): array
    {
        return [new FeeLine(
            null,
            'pay-upon-delivery-surcharge',
            'Pay Upon Delivery Surcharge',
            $this->highestTaxClass($context),
            self::SURCHARGE_AMOUNT,
        )];
    }

    /**
     * Which tax class the surcharge is taxed at: the highest one present in the basket — S beats G
     * beats E. The class was hard-coded to 'G' before, which under-taxed the surcharge on a basket
     * of 'S' goods and taxed it at all on an all-exempt basket.
     *
     * Same rule and same known simplification as CouponFeeCalculator: on a mixed-class basket the
     * whole surcharge is taxed at the highest class rather than apportioned across the classes by
     * their share of the subtotal. Apportioning needs the per-line split, not just the highest
     * class, so it is left alone here for the same reason it is left alone there.
     */
    private function highestTaxClass(FeeContext $context): string
    {
        $codes = [];
        foreach ($context->cartItems as $item) {
            $product = $item['product'] ?? null;
            $codes[] = $product?->getSalesTaxCode();
        }

        return TaxContext::resolveHighestTaxClass($codes);
    }
}
