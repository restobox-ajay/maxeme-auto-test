<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Payment;

use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Payment\PaymentValidationResult;
use PaymentStripeBundle\Service\StripeConfigProvider;

final class StripePaymentMethod implements PaymentMethodInterface
{
    public const SLUG = 'stripe';
    private const PRIORITY = 30;

    public function __construct(private readonly StripeConfigProvider $configProvider)
    {
    }

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function getName(): string
    {
        return 'Credit Card (Stripe)';
    }

    /** Card payment is taken at checkout, so the order waits at On Hold until Stripe confirms. */
    public function requiresImmediatePayment(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function renderCheckoutOption(array $context): string
    {
        $checked = ($context['selected'] ?? null) === self::SLUG ? ' checked' : '';

        if (!$this->configProvider->getConfig()['enabled']) {
            return '<label class="checkout-payment-disabled"><input type="radio" name="payment_method" value="' . self::SLUG . '" disabled> Credit Card (Stripe) — not configured yet.</label>';
        }

        // No card fields here: checkout only records the choice. The order is persisted at
        // On Hold and the customer pays on the order detail page, whose panel creates a
        // PaymentIntent tied to that order.
        return '<label><input type="radio" name="payment_method" value="' . self::SLUG . '"' . $checked
            . '> Credit Card (Stripe)</label>'
            . '<p class="checkout-payment-hint">You will be taken to your order to enter card details after placing it.</p>';
    }

    /**
     * Checkout-time gate only: it answers "may this order be placed against Stripe", not "has it
     * been paid". No money has been taken at this point — the order is persisted at
     * InvoiceStatus::OnHold and settled afterwards by Customer\OrderController's payment routes,
     * which verify the PaymentIntent's status, metadata.order_id, metadata.company_id, captured
     * amount and currency against the persisted order.
     *
     * This deliberately does NOT retrieve an intent. It used to, and a succeeded status plus a
     * matching company was the whole check — with no order in existence there was no total to
     * compare the captured amount against, so an intent confirmed for a cheaper cart could be
     * submitted against an expensive one.
     */
    public function validate(array $context): PaymentValidationResult
    {
        if (!$this->configProvider->getConfig()['enabled']) {
            return new PaymentValidationResult(success: false, message: 'Card payment is not available right now.');
        }

        return new PaymentValidationResult(success: true);
    }
}
