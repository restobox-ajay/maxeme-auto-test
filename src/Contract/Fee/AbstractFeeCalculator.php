<?php

declare(strict_types=1);

namespace App\Contract\Fee;

/**
 * Convenience base for payment-surcharge fee calculators — matches on
 * FeeContext::$paymentMethod so a concrete calculator only has to implement
 * calculate(). Additive only: existing Fee bundles keep implementing
 * FeeCalculatorInterface directly and are not retrofitted onto this.
 */
abstract class AbstractFeeCalculator implements FeeCalculatorInterface
{
    abstract protected function supportedPaymentMethodSlug(): string;

    public function supports(FeeContext $context): bool
    {
        return $context->paymentMethod === $this->supportedPaymentMethodSlug();
    }
}
