<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\InvoicePaymentApplication;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The derived payment status is recalculated on every write, whatever performed it (#539 stage 4).
 *
 * InvoicePaymentStatusDeriverTest covers the rules; this covers the guarantee that they are applied,
 * and — the part neither could show alone — that the invoice's derivation and the ORDER's compose.
 * Closed is "fully invoiced and every invoice paid", so the last payment against the last invoice
 * has to move two documents while touching neither of their rows.
 *
 * Against the real kernel so the real listeners are registered, for the reason
 * SalesOrderDerivedStatusSubscriberTest is.
 */
final class InvoicePaymentStatusSubscriberTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private int $sequence = 0;

    /** The invoice every case asserts did NOT move. Nothing in any test touches it. */
    private Invoice $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        [, $this->bystander] = $this->invoicedOrder('100.00');
    }

    public function testAnInvoiceWithNoPaymentsIsBornNotPaid(): void
    {
        [, $invoice] = $this->invoicedOrder('100.00');

        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());
    }

    public function testPartOfTheTotalMovesTheInvoiceToPartiallyPaid(): void
    {
        [$order, $invoice] = $this->invoicedOrder('100.00');

        $this->pay($invoice, '40.00');

        self::assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
        // Part-paid is not paid: the order stays open.
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testThePaymentThatCoversTheTotalClosesTheOrderToo(): void
    {
        [$order, $invoice] = $this->invoicedOrder('100.00');

        $this->pay($invoice, '40.00');
        $this->pay($invoice, '60.00');

        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        // Neither the order row nor the invoice row was written by the caller — both statuses are
        // derived, and they compose: the invoice's from its payments, the order's from its invoices.
        self::assertSame(SalesOrderStatus::Closed, $order->getStatusEnum());
    }

    public function testDeletingThePaymentReopensBothDocuments(): void
    {
        [$order, $invoice] = $this->invoicedOrder('100.00');
        $application = $this->pay($invoice, '100.00');

        self::assertSame(SalesOrderStatus::Closed, $order->getStatusEnum());

        $invoice->withdrawApplication(DocumentActor::system(), $application);
        $this->em->flush();

        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    /**
     * A payment moved from one invoice to another has to move BOTH derived statuses.
     *
     * The move is performed through `Invoice::moveApplication()`, which is now the only legitimate
     * way a sell-side move happens (#708 turned it into withdraw-then-reapply on the same pool). The
     * point of the test is what the SUBSCRIBER does with it: nothing about that sequence writes the
     * losing invoice's own row directly, so before the original fix it was never queued and it went
     * on calling itself Paid while holding nothing.
     *
     * Read back by COLUMN on both rows, not through the entities: the entities are what the
     * subscriber was working from, so asking them would be asking the accused.
     */
    public function testMovingAPaymentRederivesTheInvoiceItLeftAsWellAsTheOneItReached(): void
    {
        [, $losing] = $this->invoicedOrder('100.00');
        [, $gaining] = $this->invoicedOrder('100.00');
        $application = $this->pay($losing, '100.00');

        self::assertSame('Paid', $this->paymentStatusColumn($losing), 'precondition: the payment is on the losing invoice');
        self::assertSame('Not Paid', $this->paymentStatusColumn($gaining), 'precondition: the gaining invoice has nothing');

        $this->movePayment($application, $losing, $gaining);

        self::assertSame(
            'Not Paid',
            $this->paymentStatusColumn($losing),
            'the invoice the payment LEFT holds no payments and must not still say Paid',
        );
        self::assertSame(
            'Paid',
            $this->paymentStatusColumn($gaining),
            'the invoice the payment REACHED is covered in full',
        );

        // The row that must not change: a third invoice nobody touched.
        self::assertSame('Not Paid', $this->paymentStatusColumn($this->bystander));
    }

    /**
     * The half that already worked, kept as the positive control on the same column: a payment
     * deleted off an invoice is not a move, and `getInvoice()` still names the right document, so
     * the losing end was always queued. Proving that here is what makes the case above a statement
     * about MOVES rather than about payments in general.
     */
    public function testDeletingAPaymentRederivesTheInvoiceByColumn(): void
    {
        [, $invoice] = $this->invoicedOrder('100.00');
        $application = $this->pay($invoice, '100.00');
        self::assertSame('Paid', $this->paymentStatusColumn($invoice));

        $invoice->withdrawApplication(DocumentActor::system(), $application);
        $this->em->flush();

        self::assertSame('Not Paid', $this->paymentStatusColumn($invoice));
        self::assertSame('Not Paid', $this->paymentStatusColumn($this->bystander));
    }

    /** The other half: an amended amount is an update to the payment, whose invoice is unchanged. */
    public function testAmendingAPaymentDownRederivesTheInvoiceByColumn(): void
    {
        [, $invoice] = $this->invoicedOrder('100.00');
        $application = $this->pay($invoice, '100.00');
        self::assertSame('Paid', $this->paymentStatusColumn($invoice));

        $invoice->amendApplication(
            DocumentActor::system(),
            $application,
            new \DateTimeImmutable('2026-08-21'),
            'Cheque',
            '40.00',
            null,
        );
        $this->em->flush();

        self::assertSame('Partially Paid', $this->paymentStatusColumn($invoice));
        self::assertSame('Not Paid', $this->paymentStatusColumn($this->bystander));
    }

    public function testRepricingTheInvoiceRederivesItWithoutAPaymentBeingTouched(): void
    {
        [$order, $invoice] = $this->invoicedOrder('100.00');
        $this->pay($invoice, '100.00');

        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());

        // Nothing about the payments changed — only what they are measured against. This is why the
        // subscriber watches Invoice as well as InvoicePayment.
        $invoice->setTotal('150.00');
        $this->em->flush();

        self::assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testOneUnpaidInvoiceHoldsTheOrderOpen(): void
    {
        [$order, $first] = $this->invoicedOrder('100.00', '4.00', '2.00');
        $second = $this->invoiceFor($order, '2.00', '100.00');
        $this->em->persist($second);
        $second->issue(DocumentActor::system());
        $this->em->flush();

        $this->pay($first, '100.00');

        self::assertSame(InvoicePaymentStatus::Paid, $first->getPaymentStatus());
        self::assertSame(InvoicePaymentStatus::NotPaid, $second->getPaymentStatus());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());

        $this->pay($second, '100.00');

        self::assertSame(SalesOrderStatus::Closed, $order->getStatusEnum());
    }

    public function testThePaymentStatusTransitionIsOnTheInvoicesTimeline(): void
    {
        [, $invoice] = $this->invoicedOrder('100.00');
        $this->pay($invoice, '100.00');

        $comments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $this->em->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'Invoice', 'entityId' => $invoice->getId(), 'actorType' => 'document'],
            ),
        );

        self::assertContains('Payment of $100.00 via Bank Transfer recorded.', $comments);
        self::assertContains('Payment status changed from Not Paid to Paid.', $comments);
    }

    /**
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(string $total, string $orderQuantity = '4.00', ?string $invoiceQuantity = null): array
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->sequence)
            ->setDocumentDate('2026-08-20')
            ->setTotal($total);
        $order->addLine((new SalesOrderLine())->setName('Widget')->setQuantity($orderQuantity)->setPrice('25.00'));
        $this->em->persist($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = $this->invoiceFor($order, $invoiceQuantity ?? $orderQuantity, $total);
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        return [$order, $invoice];
    }

    private function invoiceFor(SalesOrder $order, string $quantity, string $total): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . $order->getOrderNumber() . '-' . $order->getInvoices()->count())
            ->setDocumentDate('2026-08-20')
            ->setTotal($total);
        $order->addInvoice($invoice);

        $orderLine = $order->getLines()->first();
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setName($orderLine->getName())
                ->setQuantity($quantity)
                ->setPrice($orderLine->getPrice()),
        );

        return $invoice;
    }

    /**
     * `invoice.payment_status` as the DATABASE holds it for $invoice.
     *
     * Raw SQL rather than the entity: the derived column is what the subscriber writes, and an
     * assertion made through the same object graph the subscriber worked from cannot tell a written
     * column from an unwritten one.
     */
    private function paymentStatusColumn(Invoice $invoice): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT payment_status FROM invoice WHERE id = ?',
            [$invoice->getId()],
        );
    }

    /** Moves a persisted claim from one invoice to another through the real action. */
    private function movePayment(InvoicePaymentApplication $application, Invoice $from, Invoice $to): void
    {
        $from->moveApplication(DocumentActor::system(), $application, $to);
        $this->em->flush();
    }

    private function pay(Invoice $invoice, string $amount): InvoicePaymentApplication
    {
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($amount);
        $invoice->recordPayment(DocumentActor::system(), $payment);
        $this->em->persist($payment);
        $this->em->flush();

        return $invoice->getApplications()->last();
    }
}
