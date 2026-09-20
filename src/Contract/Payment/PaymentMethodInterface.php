<?php

declare(strict_types=1);

namespace App\Contract\Payment;

interface PaymentMethodInterface
{
    public function getSlug(): string;

    public function getName(): string;

    public function getPriority(): int;

    /**
     * Whether an order paid by this method must be settled before it is worked.
     *
     * True for card-style methods, where checkout raises the invoice at InvoiceStatus::OnHold
     * until the money actually arrives (and CancelStaleUnpaidOrdersCommand reclaims it if it
     * never does). False for methods that are settled later by arrangement — pay on delivery,
     * bank transfer, anything on trade credit — whose orders go straight to Pending and are
     * fulfilled while unpaid.
     */
    public function requiresImmediatePayment(): bool;

    /** @param array<string, mixed> $context */
    public function renderCheckoutOption(array $context): string;

    /** @param array<string, mixed> $context */
    public function validate(array $context): PaymentValidationResult;
}
