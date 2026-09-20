<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoicePaymentStatus;
use App\Service\DocumentActor;
use App\Service\OrderPaymentRollup;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The Orders grid's payment column (#539 stage 4).
 *
 * Against a real database on purpose, unlike most of stage 4's tests: the whole point of this class
 * is a correlated subquery joined into the list query, so a unit test of the label mapping would
 * leave the only interesting part — the SQL — unexercised. It runs the expression exactly as the
 * grid does: selected for display, ordered by, and filtered on.
 */
final class OrderPaymentRollupTest extends DoctrineIntegrationTestCase
{
    private OrderPaymentRollup $rollup;
    private Company $company;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rollup = self::getContainer()->get(OrderPaymentRollup::class);
        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testAnOrderWithNothingPaidReadsAsNotPaid(): void
    {
        $order = $this->invoicedOrder([['100.00', '0.00']]);

        self::assertSame(InvoicePaymentStatus::NotPaid, $this->selectedLabel($order));
    }

    public function testAnOrderWithEveryInvoicePaidReadsAsPaid(): void
    {
        $order = $this->invoicedOrder([['100.00', '100.00'], ['50.00', '50.00']]);

        self::assertSame(InvoicePaymentStatus::Paid, $this->selectedLabel($order));
    }

    /**
     * The case a single MIN or MAX gets wrong, and the reason the rollup averages: one invoice
     * settled and another untouched is neither Paid nor Not Paid.
     */
    public function testOnePaidAndOneUnpaidInvoiceReadsAsPartiallyPaid(): void
    {
        $order = $this->invoicedOrder([['100.00', '100.00'], ['50.00', '0.00']]);

        self::assertSame(InvoicePaymentStatus::PartiallyPaid, $this->selectedLabel($order));
    }

    public function testAPartPaidInvoiceOnItsOwnReadsAsPartiallyPaid(): void
    {
        $order = $this->invoicedOrder([['100.00', '40.00']]);

        self::assertSame(InvoicePaymentStatus::PartiallyPaid, $this->selectedLabel($order));
    }

    public function testAnOrderWithNoInvoicesAtAllReadsAsNotPaid(): void
    {
        $order = $this->invoicedOrder([]);

        self::assertSame(InvoicePaymentStatus::NotPaid, $this->selectedLabel($order));
    }

    /**
     * A draft is inert and a cancellation is owed nothing, so neither may drag the column down —
     * the same set SalesOrder::getCountingInvoices() uses.
     */
    public function testDraftAndCancelledInvoicesAreLeftOutOfTheRollup(): void
    {
        $order = $this->invoicedOrder([['100.00', '100.00']]);

        $draft = $this->invoiceFor($order, '50.00');
        $this->em->persist($draft);

        $cancelled = $this->invoiceFor($order, '50.00');
        $this->em->persist($cancelled);
        $cancelled->issue(DocumentActor::system());
        $this->em->flush();
        $cancelled->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        self::assertSame(InvoicePaymentStatus::Paid, $this->selectedLabel($order));
    }

    public function testTheFilterFindsExactlyTheOrdersTheColumnLabels(): void
    {
        $paid = $this->invoicedOrder([['100.00', '100.00']]);
        $part = $this->invoicedOrder([['100.00', '40.00']]);
        $unpaid = $this->invoicedOrder([['100.00', '0.00']]);
        $noInvoice = $this->invoicedOrder([]);

        self::assertSame([$paid->getId()], $this->filteredIds('Paid'));
        self::assertSame([$part->getId()], $this->filteredIds('Partially Paid'));
        // An order nothing has been billed against belongs with the unpaid ones, which is the case
        // a NULL average would silently drop.
        self::assertSame([$unpaid->getId(), $noInvoice->getId()], $this->filteredIds('Not Paid'));
    }

    public function testAnUnrecognisedFilterValueIsIgnoredRatherThanHidingEverything(): void
    {
        $order = $this->invoicedOrder([['100.00', '0.00']]);

        self::assertSame([$order->getId()], $this->filteredIds('Nonsense'));
    }

    public function testSortingRunsFromNothingPaidToFullyPaid(): void
    {
        $paid = $this->invoicedOrder([['100.00', '100.00']]);
        $unpaid = $this->invoicedOrder([['100.00', '0.00']]);
        $part = $this->invoicedOrder([['100.00', '40.00']]);

        $qb = $this->em->getRepository(SalesOrder::class)->createQueryBuilder('o')->select('o');
        $this->rollup->addSelect($qb);
        $rows = $qb->orderBy(OrderPaymentRollup::SELECT_ALIAS, 'ASC')->addOrderBy('o.id', 'ASC')->getQuery()->getResult();

        self::assertSame(
            [$unpaid->getId(), $part->getId(), $paid->getId()],
            array_map(static fn (array $row): int => $row[0]->getId(), $rows),
        );
    }

    /** The PHP twin the single-document pages use must agree with the SQL the grids use. */
    public function testTheInMemoryRollupAgreesWithTheQuery(): void
    {
        foreach ([[['100.00', '100.00']], [['100.00', '40.00']], [['100.00', '0.00']], []] as $spec) {
            $order = $this->invoicedOrder($spec);

            self::assertSame(
                $this->selectedLabel($order),
                $this->rollup->forOrder($order),
                'forOrder() and the grid subquery must reach the same answer.',
            );
        }
    }

    private function selectedLabel(SalesOrder $order): InvoicePaymentStatus
    {
        $qb = $this->em->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->select('o')
            ->andWhere('o.id = :id')
            ->setParameter('id', $order->getId());
        $this->rollup->addSelect($qb);

        $row = $qb->getQuery()->getSingleResult();

        return $this->rollup->labelFor($row[OrderPaymentRollup::SELECT_ALIAS] ?? null);
    }

    /** @return list<int> */
    private function filteredIds(string $status): array
    {
        $qb = $this->em->getRepository(SalesOrder::class)->createQueryBuilder('o')->select('o');
        $this->rollup->applyFilter($qb, $status);

        return array_map(
            static fn (SalesOrder $order): int => $order->getId(),
            $qb->orderBy('o.id', 'ASC')->getQuery()->getResult(),
        );
    }

    /**
     * An approved order with one issued invoice per [total, paid] pair.
     *
     * @param list<array{0: string, 1: string}> $invoices
     */
    private function invoicedOrder(array $invoices): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->sequence)
            ->setDocumentDate('2026-08-20');
        $order->addLine((new SalesOrderLine())->setName('Widget')->setQuantity('4.00')->setPrice('25.00'));
        $this->em->persist($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        foreach ($invoices as [$total, $paid]) {
            $invoice = $this->invoiceFor($order, $total);
            $this->em->persist($invoice);
            $invoice->issue(DocumentActor::system());

            if ((float) $paid > 0.0) {
                $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($paid);
                $invoice->recordPayment(DocumentActor::system(), $payment);
                $this->em->persist($payment);
            }

            $this->em->flush();
        }

        return $order;
    }

    private function invoiceFor(SalesOrder $order, string $total): Invoice
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
                ->setQuantity('1.00')
                ->setPrice($orderLine->getPrice()),
        );

        return $invoice;
    }
}
