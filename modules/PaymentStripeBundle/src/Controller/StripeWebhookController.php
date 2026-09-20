<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Controller;

use App\Entity\SalesOrder;
use App\Security\Csrf\Attribute\CsrfExempt;
use App\Service\StripeOrderPaymentApplier;
use Doctrine\ORM\EntityManagerInterface;
use PaymentStripeBundle\Service\StripeConfigProvider;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Stripe's asynchronous notification that a PaymentIntent was captured.
 *
 * Without this, an order is only ever marked paid by the customer's browser posting back after
 * confirmCardPayment(). Close the tab, lose connectivity, or have the request time out between the
 * charge and the post, and the money is taken while the order sits unpaid — and the nightly sweeper
 * would eventually cancel a paid order. This endpoint is what makes payment state converge
 * regardless of what the browser does.
 *
 * The route is deliberately public (see config/packages/security.yaml): Stripe is not a logged-in
 * user and cannot carry a CSRF token. Authenticity comes solely from the signature check below, so
 * that check is not optional — a missing webhook secret makes the endpoint refuse everything rather
 * than trust an unsigned payload, which would otherwise be an unauthenticated "mark this order
 * paid" API.
 */
#[Route('/webhook/stripe')]
#[CsrfExempt(reason: 'Called by Stripe, which cannot know our token. Authenticated instead by verifying the Stripe-Signature header against the webhook secret; fails closed when that secret is unset.')]
final class StripeWebhookController
{
    public function __construct(
        private readonly StripeConfigProvider $configProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeOrderPaymentApplier $paymentApplier,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'payment_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $config = $this->configProvider->getConfig();
        if ($config['webhookSecret'] === '') {
            // Fail closed. Returning 500 (not 2xx) also makes Stripe retry, so events are not lost
            // while the secret is still being configured.
            $this->logger->error('Stripe webhook received but no signing secret is configured; refusing the event.');

            return new JsonResponse(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature', ''),
                $config['webhookSecret'],
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            // 400, not 500: the payload is bad, so a retry would be bad too.
            $this->logger->warning('Rejected a Stripe webhook with an invalid signature or payload.', [
                'exception' => $e->getMessage(),
            ]);

            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        if ((string) $event->type !== 'payment_intent.succeeded') {
            // Everything else is acknowledged and ignored, so Stripe stops retrying it.
            return new JsonResponse(['ok' => true, 'ignored' => (string) $event->type]);
        }

        $intent = $event->data->object;
        $orderId = (int) trim((string) ($intent->metadata['order_id'] ?? ''));
        if ($orderId <= 0) {
            $this->logger->warning('Stripe payment_intent.succeeded carried no order_id metadata.', [
                'payment_intent' => (string) ($intent->id ?? ''),
            ]);

            return new JsonResponse(['ok' => true, 'ignored' => 'no order_id']);
        }

        $order = $this->entityManager->find(SalesOrder::class, $orderId);
        if (!$order instanceof SalesOrder) {
            $this->logger->warning('Stripe webhook referenced an order that does not exist.', [
                'order_id' => $orderId,
                'payment_intent' => (string) ($intent->id ?? ''),
            ]);

            return new JsonResponse(['ok' => true, 'ignored' => 'unknown order']);
        }

        [$applied, $error] = $this->paymentApplier->apply($order, $intent, (float) $order->getTotal());

        if ($error !== null) {
            // A signed event that does not match the order it names is a genuine anomaly worth
            // investigating (amount mismatch, wrong company, currency). Acknowledged so Stripe does
            // not retry something that will never succeed, but logged loudly.
            $this->logger->error('Stripe webhook could not be applied to its order.', [
                'order_id' => $orderId,
                'payment_intent' => (string) ($intent->id ?? ''),
                'reason' => $error,
            ]);

            return new JsonResponse(['ok' => true, 'ignored' => 'validation failed']);
        }

        if ($applied) {
            $this->entityManager->flush();
            $this->logger->info('Stripe webhook marked an order paid.', [
                'order_id' => $orderId,
                'payment_intent' => (string) ($intent->id ?? ''),
            ]);
        }

        return new JsonResponse(['ok' => true, 'applied' => $applied]);
    }
}
