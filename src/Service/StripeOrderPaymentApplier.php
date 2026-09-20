<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Enum\InvoiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single place a Stripe PaymentIntent is turned into a paid order.
 *
 * Two callers reach this: the customer confirming a card on the order page, and
 * PaymentStripeBundle's webhook when Stripe reports the capture asynchronously (the browser may
 * never come back — closed tab, dead connection — and the order must still get paid). Keeping one
 * implementation is the point: when checkout and the order page each had their own version, only
 * one of them compared the captured amount to the order total, and the other silently recorded a
 * payment for an amount Stripe had not collected.
 *
 * Every call is idempotent. invoice_payment.stripe_payment_intent_id is UNIQUE, and apply()
 * additionally short-circuits on an intent already recorded against any of the order's invoices, so
 * the webhook and the browser racing on the same intent cannot double-record.
 *
 * Since #539 stage 4 the payment lands on the INVOICE, not the order — see payableInvoice() for
 * which one, and why one intent never splits across several.
 */
final class StripeOrderPaymentApplier
{
    /** Label written to Invoice::paymentMethod and InvoicePayment::method. */
    public const METHOD_LABEL = 'Visa/Mastercard Credit Card Checkout';

    /** Every intent this app creates is denominated in CAD; anything else is refused outright. */
    public const CURRENCY = 'cad';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentActorResolver $actorResolver,
    ) {
    }

    /**
     * Verify $intent really paid $order, and if so record the payment and advance the order.
     *
     * Does NOT flush — the caller owns the transaction boundary.
     *
     * @param object $intent  a Stripe PaymentIntent (\Stripe\PaymentIntent, or the equivalent
     *                        object off a webhook event)
     * @param float  $expectedTotal the amount this order is being charged, in major units
     *
     * @return array{0: bool, 1: ?string} [applied, error]. applied=false with error=null means the
     *         payment was already recorded and there was nothing to do.
     */
    public function apply(SalesOrder $order, object $intent, float $expectedTotal): array
    {
        $intentId = trim((string) ($intent->id ?? ''));
        if ($intentId === '') {
            return [false, 'Could not verify your card payment. Please try again.'];
        }

        if ((string) ($intent->status ?? '') !== 'succeeded') {
            return [false, 'Your card payment is not complete yet. Please finish the payment and try again.'];
        }

        // Both metadata values are set on every intent this app creates, so a missing one is
        // treated as a mismatch rather than waved through — otherwise an intent created some other
        // way, or with stripped metadata, could be applied to any order.
        $metadataOrderId = trim((string) ($intent->metadata['order_id'] ?? ''));
        if ($metadataOrderId === '' || $metadataOrderId !== (string) ($order->getId() ?? '')) {
            return [false, 'This card payment does not belong to this order.'];
        }

        $metadataCompanyId = trim((string) ($intent->metadata['company_id'] ?? ''));
        if ($metadataCompanyId === '' || $metadataCompanyId !== (string) ($order->getCompany()?->getId() ?? '')) {
            return [false, 'This card payment does not belong to your company.'];
        }

        // Smallest-currency-unit integers, never a float comparison. amount_received is what Stripe
        // actually collected; amount is only the requested figure, so it is a fallback for intents
        // that predate capture reporting.
        $capturedAmount = (int) ($intent->amount_received ?? $intent->amount ?? 0);
        $expectedAmount = (int) round(max(0.0, $expectedTotal) * 100);
        if ($expectedAmount <= 0 || $capturedAmount !== $expectedAmount) {
            return [false, 'Your card payment amount does not match the order total. Please try again.'];
        }

        // An intent in another currency compares equal on the integer amount while being worth
        // something entirely different.
        if (strtolower(trim((string) ($intent->currency ?? ''))) !== self::CURRENCY) {
            return [false, 'Your card payment was made in a different currency. Please try again.'];
        }

        foreach ($order->getInvoices() as $invoice) {
            foreach ($invoice->getApplications() as $existingApplication) {
                if ($existingApplication->getPayment()->getStripePaymentIntentId() === $intentId) {
                    return [false, null];
                }
            }
        }

        $invoice = $this->payableInvoice($order);
        if (!$invoice instanceof Invoice) {
            // Nothing to pay against. An ecom order always has its invoice — checkout writes the two
            // atomically — so this is an order invoiced entirely by hand and then cancelled, or one
            // never invoiced at all. Refusing is the only honest answer: the money is Stripe's to
            // refund, and inventing an invoice to hold it would bill goods nobody agreed to bill.
            return [false, 'This order has no open invoice to apply your payment to. Please contact us.'];
        }

        $actor = $this->actorResolver->resolve();

        // user is nullable: this is a customer-initiated payment, not one an admin recorded. The
        // invoice must never read as Paid without a corresponding payment row, so this always
        // records rather than skipping when no active AdminUser exists.
        $payment = (new InvoicePayment())
            ->setUser($this->recordingAdminUser())
            ->setReceivedAt(new \DateTimeImmutable('today'))
            ->setMethod(self::METHOD_LABEL)
            ->setAmount(number_format($capturedAmount / 100, 2, '.', ''))
            ->setComment(sprintf('Customer Stripe payment. Stripe PaymentIntent: %s', $intentId))
            ->setStripePaymentIntentId($intentId);

        // The named action, the same one the admin payments screen calls: it attaches the payment,
        // writes the invoice's timeline entry, and refuses a cancelled invoice. The derived payment
        // status follows at flush; nothing here writes 'Paid' anywhere (#539 stage 4).
        $invoice->recordPayment($actor, $payment);
        $this->entityManager->persist($payment);

        $invoice->setPaymentMethod(self::METHOD_LABEL);

        // The order's own status is not written here at all (#539 stage 2). Since the fulfilment
        // statuses moved to Invoice, what payment moves is the INVOICE — and the order's status
        // follows from that on its own, through SalesOrderStatusDeriver, which is what turns a
        // fully-invoiced order Closed once every invoice is paid.
        //
        // On Hold exists precisely to hold a card invoice until this moment, and releasing it is not
        // cosmetic: On Hold is the only status CancelStaleUnpaidOrdersCommand sweeps, so an invoice
        // left there after the money arrived would be cancelled by the next sweep.
        if ($invoice->isStatus('On Hold')) {
            $invoice->paymentReceived($actor);
        }

        $note = sprintf('Stripe PaymentIntent: %s', $intentId);
        $existingInstructions = $order->getSpecialInstructions();
        if ($existingInstructions === null || !str_contains($existingInstructions, $note)) {
            $order->setSpecialInstructions($existingInstructions ? ($existingInstructions . ' | ' . $note) : $note);
        }

        return [true, null];
    }

    /**
     * The one invoice this intent settles.
     *
     * One PaymentIntent becomes exactly one InvoicePayment row, never a split across several: the
     * unique index on stripe_payment_intent_id says so, and it says so for a good reason — two rows
     * carrying one intent id is how a webhook retry ends up recording the money twice.
     *
     * So this picks: the oldest non-cancelled invoice that still has a balance, falling back to the
     * oldest non-cancelled one when everything is already settled (which makes a duplicate capture
     * land somewhere real rather than nowhere). For the ecom path — the only path that reaches this
     * today — the order has exactly one invoice and this is simply it.
     */
    private function payableInvoice(SalesOrder $order): ?Invoice
    {
        $fallback = null;

        foreach ($order->getInvoices() as $invoice) {
            // Cancelled OR draft: InvoiceStatus::acceptsPayment() is the one statement of which
            // invoices money may be recorded against, and recordPayment() refuses the rest (#31).
            // Picking one of them here would turn a customer's card payment into a \DomainException
            // mid-checkout. A draft is not this order's open invoice in any case — nobody has been
            // asked to pay it — so it is skipped for the same reason a cancelled one always was.
            if (!$invoice->acceptsPayment()) {
                continue;
            }

            $fallback ??= $invoice;

            if (!$invoice->paymentCoversTotal()) {
                return $invoice;
            }
        }

        return $fallback;
    }

    private function recordingAdminUser(): ?AdminUser
    {
        return $this->entityManager->getRepository(AdminUser::class)
            ->findOneBy(['status' => 'Active'], ['id' => 'ASC']);
    }
}
