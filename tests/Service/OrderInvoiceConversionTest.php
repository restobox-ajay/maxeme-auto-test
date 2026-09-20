<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Convert to Invoice — invoicing part of an order (#539 stage 2).
 *
 * OrderInvoicingServiceTest covers the 1:1 shadow every creation path raises; this covers the other
 * half, which is what the plan's partial-invoicing case actually needs. The rules being pinned here
 * are the ones settled with the client: a line may not exceed what is left to invoice, prices are
 * editable per invoice, and "fully invoiced" is decided by quantity and never by amount.
 *
 * Against a real EntityManager, because the numbering goes through DocumentNumberAllocator's raw
 * SQL and because the derived status this produces comes from a real Doctrine listener.
 */
final class OrderInvoiceConversionTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private OrderInvoicingService $service;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        $this->service = self::getContainer()->get(OrderInvoicingService::class);
    }

    public function testAnUnapprovedOrderCannotBeInvoicedAgainst(): void
    {
        $order = $this->persistedOrder(['Widget' => '10.00']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Approve it before invoicing against it');

        $this->service->invoiceFromOrder($order, $this->allRemaining($order), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
    }

    public function testInvoicingPartOfALineLeavesTheRestUninvoiced(): void
    {
        $order = $this->approvedOrder(['Widget' => '10.00']);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $this->em->flush();

        self::assertSame('Pending', $invoice->getStatus());
        self::assertSame('4.00', $invoice->getLines()->first()->getQuantity());
        self::assertSame('6.0000', $order->uninvoicedQuantityFor($line));
        self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());
        // Attributed to the order row it came from, so the remainder is derived rather than guessed
        // by matching SKUs after the fact.
        self::assertSame($line, $invoice->getLines()->first()->getSalesOrderLine());
    }

    public function testASecondInvoiceForTheRemainderCompletesTheOrder(): void
    {
        $order = $this->approvedOrder(['Widget' => '10.00']);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder($order, [['line' => $line, 'quantity' => '4.00', 'price' => null]], $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $this->service->invoiceFromOrder($order, [['line' => $line, 'quantity' => '6.00', 'price' => null]], $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('0.0000', $order->uninvoicedQuantityFor($line));
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
        self::assertCount(2, $order->getInvoices());
    }

    public function testALineMayNotExceedWhatIsLeftToInvoice(): void
    {
        $order = $this->approvedOrder(['Widget' => '10.00']);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder($order, [['line' => $line, 'quantity' => '7.00', 'price' => null]], $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('only 3 left to invoice');

        $this->service->invoiceFromOrder($order, [['line' => $line, 'quantity' => '4.00', 'price' => null]], $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
    }

    public function testAZeroQuantityRowIsLeftOffRatherThanRefused(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00', 'Gadget' => '6.00']);
        [$widget, $gadget] = $order->getLines()->toArray();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [
                ['line' => $widget, 'quantity' => '4.00', 'price' => null],
                // The screen pre-fills every line; clearing one is how an admin says "not this time".
                ['line' => $gadget, 'quantity' => '0', 'price' => null],
            ],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $this->em->flush();

        self::assertCount(1, $invoice->getLines());
        self::assertSame('6.0000', $order->uninvoicedQuantityFor($gadget));
    }

    public function testAnInvoiceWithNothingOnItIsRefused(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Nothing left to invoice');

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $order->getLines()->first(), 'quantity' => '0', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
    }

    public function testAPriceMayBeOverriddenAtInvoiceLevel(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => '7.50']],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $this->em->flush();

        self::assertSame('7.50', $invoice->getLines()->first()->getPrice());
        self::assertSame('30.00', $invoice->getLines()->first()->getSubtotal());
        // The order is untouched: what was agreed is what was agreed.
        self::assertSame('5.00', $line->getPrice());
    }

    public function testAnOrderIsFullyInvoicedByQuantityAndNeverByAmount(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);
        $line = $order->getLines()->first();

        // Discounted to a fraction of what the order charged. Fully invoiced all the same.
        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => '0.01']],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $this->em->flush();

        self::assertTrue($order->isFullyInvoiced());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testADraftInvoiceLeavesTheOrderWhereItWas(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::KeepDraft,
        );
        $this->em->flush();

        self::assertSame('Draft', $invoice->getStatus());
        self::assertSame('4.0000', $order->uninvoicedQuantityFor($line), 'A draft invoice is inert.');
        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
    }

    public function testTheInvoiceTakesTheOrdersFrozenIdentityRatherThanTheLiveCompany(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);

        $invoice = $this->service->invoiceFromOrder($order, $this->allRemaining($order), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $this->company->setName('Renamed Holdings Ltd');
        $this->em->flush();

        // The record of who was billed does not change after the fact — the same rule
        // EstimateConversionService follows, for the same reason.
        self::assertSame('Acme Co', $invoice->getCompanyIdentity()->getName());
    }

    public function testAPartialInvoiceCarriesTheChargesItWasAskedFor(): void
    {
        $order = $this->approvedOrder(['Widget' => '10.00']);
        $order->setFeeLines(json_encode([
            ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc'],
        ]));
        $order->setTax('6.50')->setTotal('66.50');
        $this->em->flush();
        $line = $order->getLines()->first();

        // #539 stage 5: a charge is a quantified row and the caller says how much of it this invoice
        // bills. Half the freight here, half left on the order. Nothing apportions anything — this
        // figure is the one that was asked for. See InvoiceChargeQuantitiesTest for the rule itself.
        $partial = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '0.5000', 'amount' => null]],
        );
        $this->em->flush();

        self::assertSame('20.00', $partial->getSubtotal());
        self::assertSame(5.0, $partial->chargeAmountFor('shipping'));
        self::assertSame('25.00', $partial->getTotal());
        self::assertSame('0.5000', $order->uninvoicedChargeQuantityFor('shipping'));

        // Tax is computed for a part-invoice like any other document, through the same
        // OrderTaxBreakdownService call the order form makes, against the invoice's OWN lines. This
        // fixture has no tax bundle active so the figure is zero — what is asserted is that the
        // invoice went through the calculator and snapshotted the breakdown it returned, rather
        // than carrying a hardcoded zero with no tax_lines at all, which is what it used to do.
        self::assertSame('0.00', $partial->getTax());
        self::assertNotNull($partial->getTaxLines(), 'a part-invoice snapshots the breakdown it was given');

        $comments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $this->narrativeLogs('Invoice', $partial->getId()));
        self::assertEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'Tax is not recomputed')),
            'nothing should warn about tax any more',
        );
    }

    public function testAnInvoiceThatBillsTheWholeOrderCarriesItsChargeSnapshotVerbatim(): void
    {
        $order = $this->approvedOrder(['Widget' => '10.00']);
        $order->setFeeLines(json_encode([
            ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc'],
        ]));
        $order->setSubtotal('50.00')->setTax('6.50')->setTotal('66.50');
        $this->em->flush();

        $invoice = $this->service->invoiceFromOrder($order, $this->allRemaining($order), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame($order->getFeeLines(), $invoice->getFeeLines());
        self::assertSame('50.00', $invoice->getSubtotal());
        self::assertSame('6.50', $invoice->getTax());
        self::assertSame('66.50', $invoice->getTotal());
    }

    public function testTheOrdersTimelineRecordsTheInvoiceItRaised(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);

        $invoice = $this->service->invoiceFromOrder($order, $this->allRemaining($order), $this->em, DocumentActor::named('Priya Admin'), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $entry = null;
        foreach ($this->narrativeLogs('SalesOrder', $order->getId()) as $log) {
            if (str_contains($log->getSummary(), 'raised against this order')) {
                $entry = $log;
            }
        }

        self::assertNotNull($entry, 'A partial invoice is an event in the order\'s life and belongs on its timeline.');
        self::assertSame(sprintf('Invoice %s raised against this order.', $invoice->getDocumentNumber()), $entry->getSummary());
        self::assertSame('Priya Admin', $entry->getActorName());
    }

    public function testALineFromAnotherOrderIsNotSilentlyBilled(): void
    {
        $order = $this->approvedOrder(['Widget' => '4.00']);
        $other = $this->approvedOrder(['Someone else\'s widget' => '4.00']);

        // uninvoicedQuantityFor() would quote the foreign line's own ordered quantity back — it reads
        // the line, and the line does not know it is a stranger here — so the refusal has to be
        // explicit rather than a happy consequence of the remainder arithmetic.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('is not a line on order');

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $other->getLines()->first(), 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
    }

    /** @param array<string, string> $lines name => quantity */
    /** @return list<AuditLog> the narrative timeline for one document, oldest first */
    private function narrativeLogs(string $entityType, int $entityId): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => $entityType, 'entityId' => $entityId, 'actorType' => 'document'],
            ['id' => 'ASC'],
        );
    }

    private function persistedOrder(array $lines): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->sequence)
            ->setDocumentDate('2026-08-20');
        $order->copyCompanySnapshotFrom($order);

        $total = 0.0;
        foreach ($lines as $name => $quantity) {
            $order->addLine((new SalesOrderLine())->setName($name)->setQuantity($quantity)->setPrice('5.00'));
            $total += (float) $quantity * 5.0;
        }

        // Totalled, because since #539 stage 4 an invoice is fully paid when its payments cover its
        // total: an order left at 0.00 raises a 0.00 invoice, which owes nothing and derives its
        // order straight to Closed.
        $order->setSubtotal(number_format($total, 2, '.', ''))->setTotal(number_format($total, 2, '.', ''));

        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    /** @param array<string, string> $lines */
    private function approvedOrder(array $lines): SalesOrder
    {
        $order = $this->persistedOrder($lines);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        return $order;
    }

    /** @return list<array{line: SalesOrderLine, quantity: string, price: null}> */
    private function allRemaining(SalesOrder $order): array
    {
        $rows = [];
        foreach ($order->getLines() as $line) {
            $rows[] = ['line' => $line, 'quantity' => $order->uninvoicedQuantityFor($line), 'price' => null];
        }

        return $rows;
    }
}
