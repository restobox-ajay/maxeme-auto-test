<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Inventory;

use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Inventory\IncomingStockReconciler;

/**
 * `product_inventory.incoming_quantity` against what the open purchase orders actually say (#583).
 *
 * The forecast half of the positive pair. It is deliberately absent from availability — a forecast,
 * not stock, the positive twin of `backordered` — so nothing else in the app would contradict a
 * wrong value here. That is exactly why it is recomputed from the orders on every touch rather than
 * incremented and decremented: a drifted forecast is invisible.
 */
final class IncomingStockReconcilerTest extends DoctrineIntegrationTestCase
{
    private IncomingStockReconciler $reconciler;
    private Warehouse $warehouse;
    private Vendor $vendor;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = self::getContainer()->get(IncomingStockReconciler::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('Acme Supply')->setCurrency('CAD');
        $this->em->persist($this->vendor);

        $this->product = (new ProductCore())
            ->setSku('BUY-1')->setName('Buyable Thing')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->em->persist((new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10));
        $this->em->flush();
    }

    /** @param array<int, string> $quantities line quantities ordered */
    private function order(array $quantities): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        foreach ($quantities as $i => $quantity) {
            $line = (new PurchaseOrderLine())
                ->setProduct($this->product)
                ->setName('Buyable Thing')
                ->setSku('BUY-1')
                ->setQuantityOrdered($quantity)
                ->setUnitCost('4.5000')
                ->setSubtotal('0.00')
                ->setSortOrder($i);
            $order->addLine($line);
            $this->em->persist($line);
        }

        $this->em->flush();

        return $order;
    }

    private function row(): ?ProductInventory
    {
        return $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
    }

    /** A decimal string since the quantity columns started reading as decimals in PHP. */
    private function incoming(): string
    {
        $row = $this->row();

        return $row instanceof ProductInventory ? $row->getIncomingQuantity() : '0.0000';
    }

    private function reconcile(PurchaseOrder $order): void
    {
        $this->reconciler->reconcileForOrder($order);
    }

    /** @return list<InventoryBucketChangeLog> */
    private function incomingLog(): array
    {
        return $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(
            ['bucket' => InventoryBucketChangeLog::BUCKET_INCOMING],
            ['id' => 'ASC'],
        );
    }

    private function deactivateProcurement(): void
    {
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row in setUp() (DoctrineIntegrationTestCase),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        self::getContainer()->get(BundleStatusRepository::class)->deactivate('ProcurementBundle');
    }

    /** A draft has been sent to nobody, so nothing is expected from anybody. */
    public function testADraftExpectsNothing(): void
    {
        $this->reconcile($this->order(['40.00']));

        self::assertSame('0.0000', $this->incoming());
    }

    public function testIssuingAnOrderPutsItsLinesIntoIncoming(): void
    {
        $order = $this->order(['40.00', '5.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        self::assertSame('45.0000', $this->incoming());
    }

    public function testReceivingSomeOfItLeavesOnlyTheRemainder(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        $order->getLines()->first()->setQuantityReceived('15.00');
        $this->reconcile($order);

        self::assertSame('25.0000', $this->incoming());
    }

    /** Cancelled says nothing was ever ordered. */
    public function testCancellingReleasesTheWholeForecast(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);
        self::assertSame('40.0000', $this->incoming());

        $order->setStatus('Cancelled', DocumentActor::system(), 'Purchase order cancelled: changed our minds');
        $this->reconcile($order);

        self::assertSame('0.0000', $this->incoming());
    }

    /**
     * Closed short is the interesting one: the remainder was deliberately written off, so it is no
     * longer expected even though it was never delivered.
     *
     * A late delivery against it is still recordable — that is why `refusesReceipts()` is a narrower
     * set than the one this reconciler counts — but it is not something to plan around, and leaving
     * it in the forecast would keep a written-off shortfall quietly promising stock forever.
     */
    public function testClosingShortTakesTheWrittenOffRemainderOutOfTheForecast(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $order->getLines()->first()->setQuantityReceived('30.00');
        $this->reconcile($order);
        self::assertSame('10.0000', $this->incoming());

        $order->closeShort(DocumentActor::system(), 'the rest is never coming');
        $this->reconcile($order);

        self::assertSame('0.0000', $this->incoming());
    }

    /** Two orders for the same product in the same warehouse both count, and neither erases the other. */
    public function testTwoOpenOrdersAreSummed(): void
    {
        $first = $this->order(['40.00']);
        $first->setStatus('Issued', DocumentActor::system());
        $this->reconcile($first);

        $second = $this->order(['7.00']);
        $second->setStatus('Issued', DocumentActor::system());
        $this->reconcile($second);

        self::assertSame('47.0000', $this->incoming());

        // And reconciling the FIRST one again recomputes the whole figure rather than subtracting
        // its own contribution from it — the property an increment/decrement pair would not have.
        $this->reconcile($first);
        self::assertSame('47.0000', $this->incoming());
    }

    /**
     * Over-receipt floors at zero per line, not on the sum.
     *
     * "We are owed minus three" is not a forecast anyone can act on, and an over-received line must
     * not eat another line's genuine shortfall.
     */
    public function testOverReceiptDoesNotDriveTheForecastNegativeOrEatAnotherLinesShortfall(): void
    {
        $order = $this->order(['10.00', '10.00']);
        $order->setStatus('Issued', DocumentActor::system());

        $lines = $order->getLines()->toArray();
        $lines[0]->setQuantityReceived('13.00');
        $lines[1]->setQuantityReceived('4.00');

        $this->reconcile($order);

        self::assertSame('6.0000', $this->incoming(), 'six still owed on the second line; the first being over does not cancel it');
    }

    /** Fractional purchase quantities floor, because a fraction is what receiving refuses to book in. */
    /**
     * A fractional outstanding is forecast in full, and this test used to assert that it was floored.
     *
     * The flooring was `intdiv($outstanding, 100)` in `IncomingStockReconciler::recompute()`, and it
     * was there because `product_inventory.incoming_quantity` read as an `int` in PHP — so half a
     * drum on order forecast nothing and ten and a half forecast ten. The column has been
     * `NUMERIC(14, 4)` since #645 and now reads as a decimal, so the forecast is exactly what is
     * outstanding: nothing is owed in whole units that was not ordered in them.
     */
    public function testAFractionalOutstandingIsForecastInFull(): void
    {
        $order = $this->order(['10.50']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        self::assertSame('10.5000', $this->incoming());
    }

    /** A free-text line buys something the catalogue does not carry; there is no row for it to be incoming to. */
    public function testAFreeTextLineWithNoProductIsIgnored(): void
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct(null)
            ->setName('Pallet wrap, one roll')
            ->setQuantityOrdered('3.00')
            ->setUnitCost('12.0000')
            ->setSubtotal('36.00')
            ->setSortOrder(0);
        $order->addLine($line);
        $this->em->persist($line);
        $this->em->flush();

        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        self::assertSame('0.0000', $this->incoming());
    }

    /**
     * Nothing expected and no inventory row: a purchase order for a product never stocked here that
     * has already been fully received or written off. A row of zeroes saying nothing is a row this
     * app would have to explain later.
     */
    public function testNoRowIsCreatedToRecordThatNothingIsExpected(): void
    {
        $elsewhere = (new ProductCore())
            ->setSku('BUY-2')->setName('Other Thing')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($elsewhere);
        $this->em->flush();

        $this->reconciler->reconcile($elsewhere, $this->warehouse);

        self::assertNull($this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $elsewhere,
            'warehouse' => $this->warehouse,
        ]));
    }

    /**
     * The forecast never reaches availability, in any bundle state (#564, restated by #583).
     *
     * quantity 10, incoming 40, available 10. An open purchase order is not stock, and a formula
     * that counted it would let this warehouse sell forty units nobody has.
     */
    public function testTheForecastIsNotAvailability(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        $row = $this->row();
        self::assertInstanceOf(ProductInventory::class, $row);
        self::assertSame('40.0000', $row->getIncomingQuantity());
        self::assertSame('10.0000', $row->getAvailableQuantity(), 'quantity only — the forecast is not in the sum');
    }

    /**
     * The write logs itself, and nobody had to ask it to (#582).
     *
     * `InventoryBucketChangeLogger` reads the ambient operation at FLUSH time, so the assertion that
     * matters is the action string: an operation that closed before the flush would leave these rows
     * reading 'unattributed'.
     */
    public function testEveryMoveIsLoggedUnderTheOperationThatCausedIt(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        $order->getLines()->first()->setQuantityReceived('15.00');
        $this->reconcile($order);

        $log = $this->incomingLog();
        self::assertCount(2, $log);

        self::assertSame('0.0000', $log[0]->getPreviousQuantity());
        self::assertSame('40.0000', $log[0]->getNewQuantity());
        self::assertSame('40.0000', $log[1]->getPreviousQuantity());
        self::assertSame('25.0000', $log[1]->getNewQuantity());

        foreach ($log as $entry) {
            self::assertSame(IncomingStockReconciler::CHANGE_ACTION, $entry->getAction());
            // No stock moved — a purchase order merely started or stopped expecting some — so there
            // is no movement group behind these, by construction rather than by omission.
            self::assertNull($entry->getGroupId());
        }
    }

    /** A recompute that agrees with the column writes nothing, so it logs nothing. */
    public function testARecomputeThatChangesNothingLeavesNoLogRow(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);
        self::assertCount(1, $this->incomingLog());

        $this->reconcile($order);
        $this->reconcile($order);

        self::assertCount(1, $this->incomingLog(), 'still one row: 40 -> 40 is not a change');
    }

    /**
     * With ProcurementBundle Inactive nothing writes the column — and nothing zeroes it either.
     *
     * The bucket belongs to the bundle that fills it, exactly as `received` does, and off means the
     * writer stops rather than the rows being destroyed. `incoming` is not a term in the
     * availability sum in either state, so availability is the same number before and after; only
     * reading the column separates "not maintained" from "erased".
     */
    public function testWithTheBundleInactiveNothingIsWritten(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);
        self::assertSame('40.0000', $this->incoming());

        $this->deactivateProcurement();

        // A receipt's worth of progress on the order, which would have taken the forecast to 25.
        $order->getLines()->first()->setQuantityReceived('15.00');
        $this->reconcile($order);

        $row = $this->row();
        self::assertInstanceOf(ProductInventory::class, $row);
        self::assertSame('40.0000', $row->getIncomingQuantity(), 'left exactly as the active period left it');
        self::assertSame('10.0000', $row->getAvailableQuantity(), 'unchanged, because the forecast was never in the sum');
        self::assertCount(1, $this->incomingLog(), 'the deactivated writer wrote nothing to log');
    }

    /** Switching back on needs no recount and no re-import: the next action recomputes from the orders. */
    public function testReactivatingResumesFromTheOrdersWithNoRecountStep(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        $this->reconcile($order);

        $this->deactivateProcurement();
        $order->getLines()->first()->setQuantityReceived('15.00');
        $this->reconcile($order);
        self::assertSame('40.0000', $this->incoming(), 'stale while off, deliberately');

        $status = $this->em->getRepository(BundleStatus::class)->findOneBy(['source' => 'ProcurementBundle']);
        self::assertInstanceOf(BundleStatus::class, $status);
        $status->setStatus(BundleStatus::STATUS_ACTIVE);
        $this->em->flush();

        $this->reconcile($order);

        self::assertSame('25.0000', $this->incoming(), 'caught up in one recompute, from the order');
    }

    /**
     * The single-row entry point is gated the same way; there is no back door round the bundle.
     *
     * Rewritten around a positive control and a moving target (#594). It used to issue one order,
     * deactivate, call reconcile() and assert `incoming_quantity` was 0 — but 0 is what the fixture
     * starts at, nothing proved the (product, warehouse) entry point writes anything at all, and
     * deactivation does not zero the column anyway (this class's docblock says the rows keep
     * whatever the last active period left on them). An early return, a wrong-warehouse filter or a
     * missing flush inside reconcile() all passed.
     */
    public function testTheSingleProductEntryPointIsGatedToo(): void
    {
        $order = $this->order(['40.00']);
        $order->setStatus('Issued', DocumentActor::system());
        // Flushed, because recompute() reads the outstanding total out of the DATABASE — an
        // in-memory issue() leaves the row saying Draft and forecasts nothing.
        $this->em->flush();

        // First with the bundle ON: the entry point has to do its job, or "it did nothing with the
        // bundle off" is not a statement about the gate.
        $this->reconciler->reconcile($this->product, $this->warehouse);
        self::assertSame('40.0000', $this->incoming(), 'the single-row entry point forecasts the outstanding 40');

        $this->deactivateProcurement();

        // A second issued order the gate must NOT let through, so the column has something to move
        // TO if the gate leaks.
        $second = $this->order(['25.00']);
        $second->setStatus('Issued', DocumentActor::system());
        $this->em->flush();

        $this->reconciler->reconcile($this->product, $this->warehouse);

        self::assertSame('40.0000', $this->incoming(), 'the gate stopped the write: 65 is outstanding and the column did not move');
    }
}
