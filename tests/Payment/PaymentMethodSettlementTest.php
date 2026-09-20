<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Contract\Payment\PaymentMethodInterface;
use PaymentBundle\Payment\PaymentMethodResolver;
use PaymentManualBundle\Payment\ManualPaymentMethod;
use PaymentPayUponDeliveryBundle\Payment\PayUponDeliveryPaymentMethod;
use PaymentStripeBundle\Payment\StripePaymentMethod;
use PaymentStripeBundle\Service\StripeConfigProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * requiresImmediatePayment() decides whether a checkout parks its order at On Hold (unpaid, holds
 * no stock, cancelled by the sweeper if never settled) or sends it straight to Pending (worked
 * while unpaid).
 *
 * Getting it backwards is silently expensive in both directions: a card method returning false
 * would let an unpaid order be fulfilled, and a terms-based method returning true would park real
 * B2B orders at On Hold where the sweeper cancels them an hour later. Hence a test per method
 * rather than a test of the interface.
 *
 * The concrete methods are inlined by the container (ManualPaymentMethod is even constructed
 * per-configured-instance from a slug/name pair), so they are built directly here.
 */
final class PaymentMethodSettlementTest extends KernelTestCase
{
    private function stripe(): StripePaymentMethod
    {
        self::bootKernel();

        return new StripePaymentMethod(self::getContainer()->get(StripeConfigProvider::class));
    }

    public function testCardPaymentMustBeSettledUpFront(): void
    {
        self::assertTrue($this->stripe()->requiresImmediatePayment());
    }

    public function testManualPaymentIsSettledLaterSoTheOrderIsWorkedImmediately(): void
    {
        $manual = new ManualPaymentMethod('bank-transfer', 'Bank Transfer');

        self::assertFalse(
            $manual->requiresImmediatePayment(),
            'Manual/bank-transfer orders are settled out of band and must go straight to Pending.',
        );
    }

    public function testPayUponDeliveryIsSettledLaterSoTheOrderIsWorkedImmediately(): void
    {
        self::assertFalse(
            (new PayUponDeliveryPaymentMethod())->requiresImmediatePayment(),
            'Pay-on-delivery orders are unpaid by design and must go straight to Pending.',
        );
    }

    public function testNoRegisteredMethodOtherThanStripeDemandsUpFrontSettlement(): void
    {
        self::bootKernel();

        /** @var PaymentMethodResolver $resolver */
        $resolver = self::getContainer()->get(PaymentMethodResolver::class);

        $immediate = [];
        foreach ($resolver->getAllMethods() as $method) {
            if ($method instanceof PaymentMethodInterface && $method->requiresImmediatePayment()) {
                $immediate[] = $method->getSlug();
            }
        }

        self::assertSame(
            [],
            array_values(array_diff($immediate, ['stripe'])),
            'A new method demanding up-front payment parks orders at On Hold; confirm that is intended.',
        );
    }

    public function testStripeCheckoutOptionNoLongerRendersCardFields(): void
    {
        // Card entry moved to the order detail page when checkout stopped taking payment; leaving
        // the Elements mount points on the checkout page would give the customer a card form that
        // silently does nothing.
        $html = $this->stripe()->renderCheckoutOption(['selected' => 'stripe']);

        self::assertStringNotContainsString('stripe-card-number', $html);
        self::assertStringNotContainsString('data-stripe-intent-url', $html);
        self::assertStringContainsString('value="stripe"', $html);
    }

    public function testStripeValidateNoLongerDemandsAPaymentIntent(): void
    {
        // At checkout time no money has been taken and no order exists, so there is nothing to
        // validate a payment against — validate() is only a "is Stripe configured" gate now.
        // It previously rejected an empty context, which would block every card checkout.
        $result = $this->stripe()->validate([]);

        // Whether it passes depends on whether keys are configured in this environment; what must
        // never happen again is a failure that specifically demands an intent.
        if (!$result->success) {
            self::assertStringNotContainsString('card payment before placing', (string) $result->message);
        } else {
            self::assertTrue($result->success);
        }
    }
}
