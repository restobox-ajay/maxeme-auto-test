<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Event\CreditMemoIssuedEvent;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Goods coming back on a credit note, and the one column that must not move when they do (#586).
 *
 * ## The arithmetic under test, written out
 *
 *     receive 10      received 10  approved 0  quarantine 0   available 10
 *     sell + ship 2   received 10  approved 2  quarantine 0   available  8
 *     return 2        received 10  approved 2  quarantine 2   available  6
 *     credit applied  received 10  approved 0  quarantine 2   available  8   correct
 *
 * Ten physical units, two held pending inspection, eight sellable — and `received_quantity` never
 * moves. It must not, because the shipment never decremented it either: the invoice's `approved`
 * hold absorbed the departure, and this app has no mechanism that decrements Starting Inventory on
 * shipment. Crediting `received` on the way back would count the same units as arriving twice.
 *
 * The assertions below are on the COLUMNS, not on availability. Availability is a subtraction over
 * six buckets, and it can come out right with two of them wrong in opposite directions — which is
 * precisely the failure that would be invisible if this test only checked the total.
 *
 * ## Two of the four steps are one act here
 *
 * "return 2" and "credit applied" are separate lines in the table above because they are separate
 * things conceptually. In this implementation they happen together: issuing the note is what writes
 * the `returned` rows AND what makes InvoiceLine::getCreditedUnits() start netting the units out of
 * the invoice's hold. So the middle row of the table is never observed — the run goes straight from
 * the third state to the fourth. That is by design, not an omission: a note that had put the goods
 * back but not yet released the hold would be a document in a state nothing else in the app models.
 *
 * ## The shipment is conducted, not assumed
 *
 * The `available → sold` movement in the fixture is what makes the physical count add up: eight on
 * the shelf plus two returned is ten units, which is how many there are. Without it the detail rows
 * would say twelve for ten physical units, and every figure below would still be arithmetically
 * "right" while describing a warehouse that does not exist. SellingADimensionalProductTest conducts
 * the same transaction for the same reason.
 */
final class CreditMemoRestockTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private EventDispatcherInterface $events;
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->events = self::getContainer()->get(EventDispatcherInterface::class);

        $this->region = (new FulfillmentRegion())->setName('Restock Region');
        $this->em->persist($this->region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Returning Buyer Ltd');
        $this->em->persist($this->company);

        $this->product = (new ProductCore())
            ->setSku('RESTOCK-DIM-1')
            ->setName('Returnable Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);
        $this->em->flush();

        // Ten on the shelf, booked the way stock actually arrives.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'restock-seed')
                ->receive($this->product, new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10)
        );
        $this->em->flush();
    }

    /**
     * The headline, asserted column by column.
     *
     * `received_quantity` is asserted at every step, not only at the end, so a failure says WHICH
     * crossing moved it.
     */
    public function testARestockRaisesQuarantineAndLeavesReceivedQuantityUntouched(): void
    {
        $before = $this->inventory();
        self::assertSame('10.0000', $before->getReceivedQuantity(), 'guard: ten arrived');
        self::assertSame('0.0000', $before->getQuarantineQuantity(), 'guard: nothing is in quarantine yet');
        self::assertSame('10.0000', $before->getAvailableQuantity(), 'guard: all ten are sellable');

        // Sold AND shipped: an invoice for two, processing so it holds `approved`, and the warehouse
        // moving the two units off the shelf into `sold` the way a shipment does.
        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $afterSale = $this->inventory();
        self::assertSame('10.0000', $afterSale->getReceivedQuantity(), 'shipping is not an arrival and not a write-off — received is untouched by the sale');
        self::assertSame('2.0000', $afterSale->getApprovedQuantity(), 'the invoice holds the two it billed');
        self::assertSame('8.0000', $afterSale->getAvailableQuantity(), 'eight left to sell');
        self::assertSame(8, $this->unitsWithStatus(InventoryDetail::STATUS_AVAILABLE), 'and eight physically on the shelf');

        // The customer sends them back, on a credit note that says so.
        $memo = $this->issuedRestockNoteFor($invoice, 2);

        $afterReturn = $this->inventory();

        $report = sprintf(
            "\n  received   : %d"
            . "\n  quarantine : %d"
            . "\n  approved   : %d"
            . "\n  write_off  : %d"
            . "\n  quantity   : %d"
            . "\n  available  : %d"
            . "\n  returned detail rows : %d\n",
            $afterReturn->getReceivedQuantity(),
            $afterReturn->getQuarantineQuantity(),
            $afterReturn->getApprovedQuantity(),
            $afterReturn->getWriteOffQuantity(),
            $afterReturn->getQuantity(),
            $afterReturn->getAvailableQuantity(),
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
        );

        // THE COLUMN THIS TEST EXISTS FOR.
        self::assertSame(
            '10.0000',
            $afterReturn->getReceivedQuantity(),
            'received_quantity MUST NOT MOVE on a restock: the shipment never decremented it, so adding on the way back counts those units as arriving twice' . $report,
        );

        self::assertSame(
            '2.0000',
            $afterReturn->getQuarantineQuantity(),
            'the two returned units are in quarantine — present in the building, not sellable until somebody inspects them' . $report,
        );
        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'and they carry the `returned` status specifically, so a return stays distinguishable from a unit that failed inspection' . $report,
        );
        self::assertSame(
            8,
            $this->unitsWithStatus(InventoryDetail::STATUS_AVAILABLE),
            'the eight still on the shelf were not touched by the return — returned goods do not go straight back into the sellable pool' . $report,
        );
        self::assertSame(
            '0.0000',
            $afterReturn->getApprovedQuantity(),
            'and the invoice has stopped holding the units that came back' . $report,
        );
        self::assertSame(
            '8.0000',
            $afterReturn->getAvailableQuantity(),
            'ten physical, two held pending inspection, eight sellable' . $report,
        );

        self::assertTrue($memo->countsTowardCreditedQuantity(), 'guard: the note is live, which is why the hold was released');
    }

    /** A financial-only note moves no stock at all. */
    public function testACreditNoteWithoutRestockMovesNoStock(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');

        $memo = $this->creditNoteFor($invoice, 2, restock: false);
        $memo->issue();
        $this->em->flush();
        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        $after = $this->inventory();

        self::assertSame(0, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'restock unticked: the goods did not come back, so no returned row exists');
        self::assertSame('0.0000', $after->getQuarantineQuantity(), 'and nothing is in quarantine');
        self::assertSame('10.0000', $after->getReceivedQuantity(), 'received is untouched, as it is on every path here');
        // The hold is still released: crediting the money says those units are no longer this
        // invoice's to ship, whether or not they physically came back.
        self::assertSame('0.0000', $after->getApprovedQuantity(), 'the invoice still stops holding what it credited — releasing a hold and putting stock back are two different things');
    }

    /**
     * A STANDALONE note with restock still puts stock back.
     *
     * The `returned` rows do not depend on an invoice existing. What does NOT happen is any net-out
     * of `invoice_inventory_reservation` — there is no invoice holding these units, and nothing goes
     * looking for one to guess at.
     */
    public function testAStandaloneRestockStillCreatesReturnedRowsAndNetsNothingOut(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        self::assertSame('2.0000', $this->inventory()->getApprovedQuantity(), 'guard: an unrelated invoice is holding two');

        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-STANDALONE-RESTOCK')
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName())
            ->setRestock(true)
            ->setSubtotal('3.00')
            ->setTax('0.00')
            ->setTotal('3.00');

        $memo->addLine(
            (new CreditMemoLine())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setLocation($this->region->getName())
                ->setQuantity('3.00')
                ->setPrice('1.00')
                ->setSubtotal('3.00'),
        );

        $this->em->persist($memo);
        $memo->issue();
        $this->em->flush();
        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        $after = $this->inventory();

        self::assertSame(3, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'a note with no invoice behind it still records that three units came back');
        self::assertSame('3.0000', $after->getQuarantineQuantity(), 'and they sit in quarantine like any other return');
        self::assertSame('10.0000', $after->getReceivedQuantity(), 'received is untouched here too');
        self::assertSame(
            '2.0000',
            $after->getApprovedQuantity(),
            'the unrelated invoice keeps its hold: nothing nets out of a reservation this note never credited',
        );
    }

    /** Issuing twice — a retried request, a listener run twice — must not book the goods twice. */
    public function testRestockingIsIdempotentOnTheNotesIdentity(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $memo = $this->issuedRestockNoteFor($invoice, 2);

        self::assertSame(2, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'guard: two came back');

        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'a re-dispatch finds the existing movement group by the note\'s own operation id and applies nothing',
        );
        self::assertSame('10.0000', $this->inventory()->getReceivedQuantity(), 'and nothing leaks into received on the second pass either');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    private function processingInvoiceFor(string $quantity): Invoice
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('RESTOCK-ORD-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName());

        $orderLine = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->region->getName());
        $order->addLine($orderLine);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('RESTOCK-INV-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
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

    /**
     * The warehouse dispatching what the invoice billed: `available → sold`, in the same warehouse.
     *
     * Nothing in the invoice lifecycle does this by itself — StockMovementService says so, and #552
     * is where it would be wired — so the test conducts it, exactly as SellingADimensionalProductTest
     * does. `sold` is in InventoryDetail::soldStatuses() and therefore in no bucket at all, which is
     * why `received_quantity` does not move here either: the invoice's `approved` hold is what
     * accounts for these units.
     */
    private function ship(Invoice $invoice, int $units): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'ship-' . $invoice->getId(), null, null, $invoice->getDocumentNumber())
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE),
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_SOLD),
                    $units,
                )
        );
        $this->em->flush();
    }

    private function creditNoteFor(Invoice $invoice, int $units, bool $restock): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setInvoice($invoice)
            ->setFulfillmentRegion($this->region->getName())
            ->setRestock($restock)
            ->setSubtotal('10.00')
            ->setTax('0.00')
            ->setTotal('10.00');

        $memo->addLine(
            (new CreditMemoLine())
                ->setInvoiceLine($invoice->getLines()->first())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setLocation($this->region->getName())
                ->setQuantity(number_format($units, 2, '.', ''))
                ->setPrice('5.00')
                ->setSubtotal(number_format($units * 5, 2, '.', '')),
        );

        $this->em->persist($memo);

        return $memo;
    }

    /**
     * Issued the way the controller issues one: flush the status change first, then dispatch, which
     * is the ordering CreditMemoIssuedEvent's docblock requires.
     */
    private function issuedRestockNoteFor(Invoice $invoice, int $units): CreditMemo
    {
        $memo = $this->creditNoteFor($invoice, $units, restock: true);
        $memo->issue();
        $this->em->flush();

        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        return $memo;
    }

    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        // Refreshed rather than the identity map's copy: these buckets are written by the movement
        // service and by the reservation reconciler, so an in-memory instance can be behind the row.
        $this->em->refresh($row);

        return $row;
    }

    private function unitsWithStatus(string $status): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$this->product->getId(), $status],
        );
    }
}
