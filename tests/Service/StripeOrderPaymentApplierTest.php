<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Service\StripeOrderPaymentApplier;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The applier is the only thing standing between "Stripe says something succeeded" and an order
 * being marked paid, so every rejection path is asserted explicitly. A stand-in object is used for
 * the PaymentIntent because \Stripe\PaymentIntent is only ever produced by a live API call.
 *
 * Since #539 the fulfilment status payment releases belongs to the INVOICE: the applier moves the
 * card invoice off On Hold and marks it paid, and the ORDER's own status follows from its invoice
 * set through SalesOrderStatusDeriver. Both halves are asserted, because releasing the invoice is
 * not cosmetic — On Hold is the only status the stale-unpaid sweep cancels.
 */
final class StripeOrderPaymentApplierTest extends DoctrineIntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Wholesale');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    private function applier(): StripeOrderPaymentApplier
    {
        return self::getContainer()->get(StripeOrderPaymentApplier::class);
    }

    /**
     * An approved order carrying one invoice, raised through OrderInvoicingService so the fixture
     * matches what checkout really writes. AwaitingPayment is the card-checkout case: an issued
     * invoice sitting On Hold until the money lands.
     */
    private function order(string $total = '125.00', InvoiceIssueIntent $intent = InvoiceIssueIntent::AwaitingPayment): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setSubtotal($total)
            ->setTotal($total);

        $order->addLine(
            (new SalesOrderLine())
                ->setName('Widget')->setSku('WIDGET-1')
                ->setQuantity('1.00')->setPrice($total)->setSubtotal($total),
        );

        $this->em->persist($order);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        self::getContainer()->get(OrderInvoicingService::class)
            ->invoiceInFull($order, $this->em, DocumentActor::system(), $intent);
        $this->em->flush();

        // Guards every "nothing moved" case from passing vacuously against an order that was never
        // live in the first place.
        self::assertTrue($order->getStatusEnum()?->isApprovedOrLater() ?? false);

        return $order;
    }

    private function invoiceOf(SalesOrder $order): Invoice
    {
        $invoice = $order->getInvoices()->first();
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    /** @param array<string, mixed> $overrides */
    private function intent(SalesOrder $order, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'pi_' . uniqid(),
            'status' => 'succeeded',
            'amount_received' => 12500,
            'amount' => 12500,
            'currency' => 'cad',
            'metadata' => [
                'order_id' => (string) $order->getId(),
                'company_id' => (string) $this->company->getId(),
            ],
        ], $overrides);
    }

    public function testAppliesMatchingPaymentAndReleasesTheOnHoldInvoice(): void
    {
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        self::assertSame('On Hold', $invoice->getStatus());

        [$applied, $error] = $this->applier()->apply($order, $this->intent($order), 125.00);
        $this->em->flush();

        self::assertTrue($applied);
        self::assertNull($error);
        self::assertSame(
            'Pending',
            $invoice->getStatus(),
            'On Hold holds the invoice for the card payment; leaving it there would let the sweep cancel a paid invoice.',
        );
        // Derived from the payment row this just recorded, not written by the applier (#539 stage 4).
        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        // The order is not written by the applier at all any more — this status is derived from the
        // invoice set: fully invoiced, and every one of those invoices paid.
        self::assertSame(SalesOrderStatus::Closed->value, $order->getStatus());
        self::assertCount(1, $invoice->getApplications());
        self::assertSame('125.00', $invoice->getApplications()->first()->getAmount());
    }

    public function testRecordsWhatStripeCapturedRatherThanTheExpectedTotal(): void
    {
        $order = $this->order();

        // amount_received is authoritative; amount is only what was requested.
        $intent = $this->intent($order, ['amount_received' => 12500, 'amount' => 999999]);
        $this->applier()->apply($order, $intent, 125.00);
        $this->em->flush();

        self::assertSame('125.00', $this->invoiceOf($order)->getApplications()->first()->getAmount());
    }

    public function testRejectsWhenCapturedAmountIsLessThanTheOrderTotal(): void
    {
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $orderStatus = $order->getStatus();

        // The headline defect: an intent confirmed against a cheap cart, submitted for an
        // expensive order.
        [$applied, $error] = $this->applier()->apply($order, $this->intent($order, ['amount_received' => 100]), 125.00);

        self::assertFalse($applied);
        self::assertNotNull($error);
        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());
        self::assertSame('On Hold', $invoice->getStatus());
        self::assertSame($orderStatus, $order->getStatus());
        self::assertCount(0, $invoice->getApplications());
    }

    public function testRejectsIntentBelongingToAnotherOrder(): void
    {
        $order = $this->order();
        $other = $this->order();

        [$applied, $error] = $this->applier()->apply(
            $order,
            $this->intent($order, ['metadata' => ['order_id' => (string) $other->getId(), 'company_id' => (string) $this->company->getId()]]),
            125.00,
        );

        self::assertFalse($applied);
        self::assertNotNull($error);
        self::assertSame('On Hold', $this->invoiceOf($order)->getStatus());
    }

    public function testRejectsIntentWithStrippedMetadata(): void
    {
        $order = $this->order();

        [$applied] = $this->applier()->apply($order, $this->intent($order, ['metadata' => []]), 125.00);

        self::assertFalse($applied);
        self::assertSame('On Hold', $this->invoiceOf($order)->getStatus());
    }

    public function testRejectsIntentInAnotherCurrency(): void
    {
        $order = $this->order();

        [$applied, $error] = $this->applier()->apply($order, $this->intent($order, ['currency' => 'usd']), 125.00);

        self::assertFalse($applied);
        self::assertNotNull($error);
        self::assertSame('On Hold', $this->invoiceOf($order)->getStatus());
    }

    public function testRejectsIntentThatHasNotSucceeded(): void
    {
        $order = $this->order();

        [$applied] = $this->applier()->apply($order, $this->intent($order, ['status' => 'requires_payment_method']), 125.00);

        self::assertFalse($applied);
        self::assertSame('On Hold', $this->invoiceOf($order)->getStatus());
    }

    public function testRejectsZeroTotalOrder(): void
    {
        $order = $this->order('0.00');

        [$applied] = $this->applier()->apply($order, $this->intent($order, ['amount_received' => 0]), 0.0);

        self::assertFalse($applied);
        self::assertSame('On Hold', $this->invoiceOf($order)->getStatus());
    }

    public function testIsIdempotentSoTheBrowserAndWebhookCannotDoubleRecord(): void
    {
        $order = $this->order();
        $intent = $this->intent($order);

        [$first] = $this->applier()->apply($order, $intent, 125.00);
        $this->em->flush();
        [$second, $error] = $this->applier()->apply($order, $intent, 125.00);
        $this->em->flush();

        self::assertTrue($first);
        self::assertFalse($second, 'A repeat of the same intent must not record a second payment.');
        self::assertNull($error, 'A repeat is a no-op, not an error.');
        self::assertCount(1, $this->invoiceOf($order)->getApplications());
        // The second call must not attempt the On Hold -> Pending release again; the invoice has
        // already been released, and repeating the transition would throw.
        self::assertSame('Pending', $this->invoiceOf($order)->getStatus());
    }

    public function testAnInvoiceIssuedOutrightIsSimplyMarkedPaid(): void
    {
        // The credit case: the invoice was never On Hold, so there is no release to perform — but
        // the payment still lands on it, and the order still closes.
        $order = $this->order('125.00', InvoiceIssueIntent::Issue);
        $invoice = $this->invoiceOf($order);

        $this->applier()->apply($order, $this->intent($order), 125.00);
        $this->em->flush();

        self::assertSame('Pending', $invoice->getStatus());
        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        self::assertSame(SalesOrderStatus::Closed->value, $order->getStatus());
    }

    public function testDoesNotDowngradeAnAlreadyCompletedInvoice(): void
    {
        $order = $this->order('125.00', InvoiceIssueIntent::Issue);
        $invoice = $this->invoiceOf($order);
        $invoice->startProcessing(DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        $this->applier()->apply($order, $this->intent($order), 125.00);
        $this->em->flush();

        self::assertSame('Completed', $invoice->getStatus());
        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
    }

    /**
     * A cancelled invoice collects nothing, and there is nothing else to collect it — so the capture
     * is refused rather than being parked on a withdrawn document.
     *
     * The money is Stripe's to refund at that point. Inventing an invoice to hold it would bill
     * goods nobody agreed to bill, and recording it against the cancelled one would claim a
     * withdrawn document had been settled.
     */
    public function testRefusesWhenTheOnlyInvoiceHasBeenCancelled(): void
    {
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $invoice->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: customer changed their mind');
        $this->em->flush();

        [$applied, $error] = $this->applier()->apply($order, $this->intent($order), 125.00);
        $this->em->flush();

        self::assertFalse($applied);
        self::assertNotNull($error);
        self::assertSame('Cancelled', $invoice->getStatus());
        self::assertCount(0, $invoice->getApplications(), 'A cancelled invoice collects nothing.');
    }
}
