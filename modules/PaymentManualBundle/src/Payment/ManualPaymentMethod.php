<?php

declare(strict_types=1);

namespace PaymentManualBundle\Payment;

use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Payment\PaymentValidationResult;

/**
 * One instance represents one admin-created row (App\Entity\PaymentMethod where
 * source = self::SOURCE) — see ManualPaymentMethodProvider, which is the actual
 * app.payment_method_provider-tagged service and turns each such row into one of
 * these at resolve time.
 */
final class ManualPaymentMethod implements PaymentMethodInterface
{
    public const SOURCE = 'PaymentManualBundle';
    private const PRIORITY = 20;

    public function __construct(
        private readonly string $slug,
        private readonly string $name,
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Settled out of band (transfer/cheque/terms), so the order is worked while still unpaid. */
    public function requiresImmediatePayment(): bool
    {
        return false;
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function renderCheckoutOption(array $context): string
    {
        $slug = htmlspecialchars($this->slug, ENT_QUOTES);
        $name = htmlspecialchars($this->name, ENT_QUOTES);
        $checked = ($context['selected'] ?? null) === $this->slug ? ' checked' : '';

        return "<label><input type=\"radio\" name=\"payment_method\" value=\"{$slug}\"{$checked}> {$name}</label>";
    }

    public function validate(array $context): PaymentValidationResult
    {
        return new PaymentValidationResult(success: true);
    }
}
