<?php

declare(strict_types=1);

namespace App\Contract\Payment;

/**
 * For a bundle where one tagged service backs N admin-created payment methods
 * (e.g. PaymentManualBundle) rather than one tagged service per method
 * (e.g. PaymentPayUponDeliveryBundle). Mirrors how FeeFieldProviderInterface is
 * an additional, optional tag alongside FeeCalculatorInterface — this is additive,
 * PaymentMethodInterface itself is unchanged.
 */
interface PaymentMethodProviderInterface
{
    /** @return PaymentMethodInterface[] */
    public function getPaymentMethods(): array;
}
