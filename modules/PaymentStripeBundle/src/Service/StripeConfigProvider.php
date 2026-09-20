<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Service;

use App\Service\AppSettings;

/**
 * Single source of Stripe credentials for the whole app — both this bundle's
 * checkout-time payment method and App\Controller\Customer\OrderController's
 * post-order invoice-payment flow read Stripe config through here instead of
 * each keeping their own copy (see plan3.md Section 10 phase 10-D).
 */
final class StripeConfigProvider
{
    public const KEY_MODE = 'payment_stripe_mode';
    public const KEY_PUBLISHABLE_TEST = 'payment_stripe_publishable_key_test';
    public const KEY_SECRET_TEST = 'payment_stripe_secret_key_test';
    public const KEY_PUBLISHABLE_LIVE = 'payment_stripe_publishable_key_live';
    public const KEY_SECRET_LIVE = 'payment_stripe_secret_key_live';
    public const KEY_WEBHOOK_SECRET_TEST = 'payment_stripe_webhook_secret_test';
    public const KEY_WEBHOOK_SECRET_LIVE = 'payment_stripe_webhook_secret_live';

    public function __construct(private readonly AppSettings $appSettings) {}

    /**
     * webhookSecret is the endpoint's signing secret (whsec_...) from the Stripe dashboard. It is
     * separate from the API secret key and is the only thing that makes a webhook payload
     * trustworthy — an unsigned POST to the webhook route is just an attacker-supplied JSON blob
     * claiming an order was paid, so StripeWebhookController refuses to act without it.
     *
     * @return array{enabled: bool, testMode: bool, publishableKey: string, secretKey: string, webhookSecret: string}
     */
    public function getConfig(): array
    {
        $testMode = trim((string) ($this->appSettings->get(self::KEY_MODE, 'test') ?? 'test')) !== 'live';

        $publishableKey = trim((string) ($this->appSettings->get(
            $testMode ? self::KEY_PUBLISHABLE_TEST : self::KEY_PUBLISHABLE_LIVE,
            '',
        ) ?? ''));
        $secretKey = trim((string) ($this->appSettings->get(
            $testMode ? self::KEY_SECRET_TEST : self::KEY_SECRET_LIVE,
            '',
        ) ?? ''));

        $webhookSecret = trim((string) ($this->appSettings->get(
            $testMode ? self::KEY_WEBHOOK_SECRET_TEST : self::KEY_WEBHOOK_SECRET_LIVE,
            '',
        ) ?? ''));

        return [
            'enabled' => $publishableKey !== '' && $secretKey !== '',
            'webhookSecret' => $webhookSecret,
            'testMode' => $testMode,
            'publishableKey' => $publishableKey,
            'secretKey' => $secretKey,
        ];
    }
}
