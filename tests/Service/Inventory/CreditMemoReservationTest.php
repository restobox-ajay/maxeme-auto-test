<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * A credit note releasing the hold the invoice it credits was keeping (#586).
 *
 * ## The thing being proved
 *
 * That crediting units back reduces `invoice_inventory_reservation.quantity` and the
 * `pending`/`approved` bucket behind it, WITHOUT a third reservation entity and without a second
 * copy of InventoryReservationReconciler's diff. The credit note is never a subject; it is a
 * subtraction inside the invoice's own target, exactly as #548 made backorder a subtraction inside
 * the order's rather than a third ledger.
 *
 * Driven through persist-and-flush, deliberately, for the reason BackorderReservationTest states:
 * the wiring — InventoryReconciliationSubscriber noticing that a credit note write is an invoice
 * write in disguise — is precisely what a test calling the reconciler by hand would never catch a
 * regression in.
 */
final class CreditMemoReservationTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private ProductInventory $inventory;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('Credit Region');
        $this->em->persist($this->region);

        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SKU-CN')->setName('Creditable Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->company = (new Company())->setName('Credit Co')->setCode('CRD');
        $this->em->persist($this->company);

        // Twenty on the shelf. Every scenario bills ten of them.
        $this->inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(20);
        $this->em->persist($this->inventory);

        $this->em->flush();
    }

    /**
     * The headline: credit four of ten billed units and the invoice stops holding four of them.
     *
     * `approved_quantity` follows through the reconciler that already existed, and the ledger row it
     * is derived from follows too — asserted separately, because a bucket that moved while its
     * ledger did not is the exact failure InventoryReservationSubject's docblock warns about.
     */
    public function testCreditingUnitsReleasesTheInvoicesHoldOnThem(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');

        self::assertSame('10.0000', $this->refreshed()->getApprovedQuantity(), 'guard: the invoice holds all ten before anything is credited');

        $this->issuedCreditNoteFor($invoice, 4);

        self::assertSame(
            '6.0000',
            $this->refreshed()->getApprovedQuantity(),
            'crediting four of ten billed units leaves the invoice holding six',
        );
        self::assertSame(
            '6.0000',
            $this->reservationQuantity($invoice),
            'and the ledger row the bucket is derived from says six as well — a bucket that moved alone would be the drift this design exists to prevent',
        );
        self::assertSame(
            '14.0000',
            $this->refreshed()->getAvailableQuantity(),
            'twenty on the shelf less the six still held: the four that came back are no longer reserved for a shipment that already happened',
        );
    }

    /** A draft credits nothing. Only issuing releases the hold. */
    public function testADraftCreditNoteReleasesNothing(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');

        $memo = $this->draftCreditNoteFor($invoice, 4);
        $this->em->flush();

        self::assertSame(
            '10.0000',
            $this->refreshed()->getApprovedQuantity(),
            'a draft is not a document yet, so the invoice goes on holding everything it billed',
        );

        $memo->issue();
        $this->em->flush();

        self::assertSame('6.0000', $this->refreshed()->getApprovedQuantity(), 'and issuing is what releases it');
    }

    /** Voiding a note puts the hold back: the credit is being said not to have happened. */
    public function testVoidingACreditNotePutsTheHoldBack(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');
        $memo = $this->issuedCreditNoteFor($invoice, 4);

        self::assertSame('6.0000', $this->refreshed()->getApprovedQuantity(), 'guard: the credit released four');

        $memo->setStatus('Void', DocumentActor::system());
        $this->em->flush();

        self::assertSame(
            '10.0000',
            $this->refreshed()->getApprovedQuantity(),
            'a voided note credits nothing, so the invoice holds all ten again',
        );
    }

    /** TWO NOTES, ONE INVOICE LINE. The credited units add up rather than the last one winning. */
    public function testTwoCreditNotesAgainstOneInvoiceBothRelease(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');

        $this->issuedCreditNoteFor($invoice, 3);
        $this->issuedCreditNoteFor($invoice, 2);

        self::assertSame(
            '5.0000',
            $this->refreshed()->getApprovedQuantity(),
            'three credited by one note and two by another leaves five held — the two notes accumulate, they do not overwrite each other',
        );
    }

    /**
     * ONE NOTE, TWO INVOICES. Each invoice's hold drops by what that note credited against IT, and
     * neither reads the other's figure.
     */
    public function testOneCreditNoteSpanningTwoInvoicesReleasesEachSeparately(): void
    {
        $first = $this->processingInvoiceFor('6.00');
        $second = $this->processingInvoiceFor('4.00');

        self::assertSame('10.0000', $this->refreshed()->getApprovedQuantity(), 'guard: ten held across the two invoices');

        // One note, two lines, each pointing at a different invoice's line. The header names the
        // first invoice as provenance; the release follows the LINES, which is the whole point.
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-SPAN')
            ->setDocumentDate('2026-08-23')
            ->setInvoice($first)
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');

        $memo->addLine($this->creditLine($first->getLines()->first(), 2));
        $memo->addLine($this->creditLine($second->getLines()->first(), 3));
        $this->em->persist($memo);
        $memo->issue();
        $this->em->flush();

        self::assertSame(
            '4.0000',
            $this->reservationQuantity($first),
            'the first invoice billed six and had two credited, so it holds four',
        );
        self::assertSame(
            '1.0000',
            $this->reservationQuantity($second),
            'the second billed four and had three credited, so it holds one — and it is credited by a note whose header names the other invoice entirely',
        );
        self::assertSame('5.0000', $this->refreshed()->getApprovedQuantity(), 'five held in total across both');
    }

    /** Crediting everything an invoice billed removes its ledger row outright, rather than leaving a zero. */
    public function testCreditingAnInvoiceInFullRemovesItsReservationRow(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');

        $this->issuedCreditNoteFor($invoice, 10);

        self::assertSame('0.0000', $this->refreshed()->getApprovedQuantity(), 'nothing is held once everything billed has been credited back');
        self::assertNull(
            $this->em->getRepository(InvoiceInventoryReservation::class)->findOneBy(['invoice' => $invoice]),
            'and the ledger row is removed rather than left holding zero, exactly as it is when an invoice is cancelled',
        );
    }

    /**
     * A STANDALONE note — no invoice on the header, no invoice line on its rows — nets nothing out
     * of anything, and does not go looking for an invoice to guess at.
     */
    public function testAStandaloneCreditNoteNetsNothingOutOfAnyReservation(): void
    {
        $invoice = $this->processingInvoiceFor('10.00');

        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-STANDALONE')
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('25.00')
            ->setTax('0.00')
            ->setTotal('25.00');

        // A line naming the same PRODUCT, deliberately: if anything went looking for an invoice by
        // SKU rather than by the invoice_line_id it was given, this is the fixture that would catch
        // it.
        $memo->addLine(
            (new CreditMemoLine())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setLocation($this->region->getName())
                ->setQuantity('5.00')
                ->setPrice('5.00')
                ->setSubtotal('25.00'),
        );

        $this->em->persist($memo);
        $memo->issue();
        $this->em->flush();

        self::assertSame(
            '10.0000',
            $this->refreshed()->getApprovedQuantity(),
            'no invoice is holding these units, so there is nothing to release and nothing is released',
        );
        self::assertSame(
            '10.0000',
            $this->reservationQuantity($invoice),
            'the unrelated invoice keeps its ledger row intact',
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    /** An approved order, invoiced in full, moved to Processing so the invoice holds `approved`. */
    private function processingInvoiceFor(string $quantity): Invoice
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('CN-ORD-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName());

        $orderLine = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setSku($this->product->getSku())
            ->setQuantity($quantity)
            ->setLocation($this->region->getName());
        $order->addLine($orderLine);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-INV-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setQuantity($quantity)
                ->setLocation($this->region->getName()),
        );
        $this->em->persist($invoice);
        $this->em->flush();

        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        return $invoice;
    }

    private function draftCreditNoteFor(Invoice $invoice, int $units): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setInvoice($invoice)
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('10.00')
            ->setTax('0.00')
            ->setTotal('10.00');

        $memo->addLine($this->creditLine($invoice->getLines()->first(), $units));
        $this->em->persist($memo);

        return $memo;
    }

    private function issuedCreditNoteFor(Invoice $invoice, int $units): CreditMemo
    {
        $memo = $this->draftCreditNoteFor($invoice, $units);
        $memo->issue();
        $this->em->flush();

        return $memo;
    }

    private function creditLine(InvoiceLine $invoiceLine, int $units): CreditMemoLine
    {
        return (new CreditMemoLine())
            ->setInvoiceLine($invoiceLine)
            ->setProduct($invoiceLine->getProduct())
            ->setName($invoiceLine->getName())
            ->setLocation($invoiceLine->getLocation())
            ->setQuantity(number_format($units, 2, '.', ''))
            ->setPrice('1.00')
            ->setSubtotal(number_format($units, 2, '.', ''));
    }

    /** The one ledger row the invoice owns for this product, or 0 when it owns none. */
    private function reservationQuantity(Invoice $invoice): string
    {
        $reservation = $this->em->getRepository(InvoiceInventoryReservation::class)->findOneBy([
            'invoice' => $invoice,
            'product' => $this->product,
        ]);

        return $reservation?->getQuantity() ?? 0;
    }

    /**
     * Refreshed rather than the identity map's copy: the buckets are written by the reconciler
     * during other documents' flushes, so an in-memory instance can be behind the row.
     */
    private function refreshed(): ProductInventory
    {
        $this->em->refresh($this->inventory);

        return $this->inventory;
    }
}
