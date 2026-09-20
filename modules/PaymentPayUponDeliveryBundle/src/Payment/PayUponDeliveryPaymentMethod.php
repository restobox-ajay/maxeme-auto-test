<?php

declare(strict_types=1);

namespace PaymentPayUponDeliveryBundle\Payment;

use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Payment\PaymentValidationResult;

final class PayUponDeliveryPaymentMethod implements PaymentMethodInterface
{
    public const SLUG = 'pay-upon-delivery';

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function getName(): string
    {
        return 'Pay Upon Delivery';
    }

    /** Settled when the order arrives, so the order is worked while still unpaid. */
    public function requiresImmediatePayment(): bool
    {
        return false;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function renderCheckoutOption(array $context): string
    {
        $checked = ($context['selected'] ?? null) === self::SLUG ? ' checked' : '';

        return '<label><input type="radio" name="payment_method" value="' . self::SLUG . '"' . $checked . '> Pay Upon Delivery — pay when your order arrives.</label>';
    }

    public function validate(array $context): PaymentValidationResult
    {
        return new PaymentValidationResult(success: true);
    }
}
