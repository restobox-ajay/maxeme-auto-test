<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Inventory;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\DebitMemo\DebitMemoStockService;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\DebitMemoLine;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Entity\VendorReturnLine;
use ProcurementBundle\Receiving\ReceiptVoidService;
use ProcurementBundle\Receiving\ReceivingException;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;
use ProcurementBundle\VendorReturn\VendorReturnShipService;

/**
 * The inbound/outbound ledger balances for a SIMPLE-inventory product, not just a dimensional one.
 *
 * ## What went wrong, and why no existing test saw it
 *
 * `docs/plans/2026-09-15-simple-inventory-bucket-parity.md` made receiving credit
 * `product_inventory.received_quantity` for a simple-mode line — a line that writes no
 * `inventory_detail` row and is marked `movementApplied = false`. The plan's own tests assert that
 * credit and they pass. What nothing asserted was the ROUND TRIP: every path that takes those goods
 * back out was still gated on `isDimensional()` or on `isMovementApplied()`, so it skipped the line
 * entirely and the credit stood forever. `getAvailableQuantity()` adds `received`, so the product
 * over-reported its stock by the whole delivery — silently, permanently, and only for the inventory
 * mode most products are on.
 *
 * Three paths were wrong in the same way and are covered here together, because they are one defect
 * seen three times rather than three defects:
 *
 *  - `ReceiptVoidService` — withdrawing the receipt;
 *  - `DebitMemoStockService` — a restocking debit memo sending the goods back;
 *  - `VendorReturnShipService` — a vendor return shipping them back.
 *
 * ## The shape of each test is the same, deliberately
 *
 * Read the bucket, move the goods in, read it, move them out, read it — and assert the figure comes
 * back to where it started. An assertion on the intermediate value is as load-bearing as the final
 * one: a pair of paths that BOTH did nothing would also return to zero, and would pass a test that
 * only looked at the end.
 *
 * Each case is run for a simple product and again for a dimensional one, so the fix cannot be a
 * behaviour change in disguise — the dimensional numbers were already right and have to stay so.
 */
final class SimpleInventoryLedgerRoundTripTest extends DoctrineIntegrationTestCase
{
    private ReceivingService $receiving;
    private ReceiptVoidService $voids;
    private DebitMemoStockService $debitMemos;
    private VendorReturnShipService $vendorReturns;
    private Warehouse $warehouse;
    private Vendor $vendor;
    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiving = self::getContainer()->get(ReceivingService::class);
        $this->voids = self::getContainer()->get(ReceiptVoidService::class);
        $this->debitMemos = self::getContainer()->get(DebitMemoStockService::class);
        $this->vendorReturns = self::getContainer()->get(VendorReturnShipService::class);

        $region = (new FulfillmentRegion())->setName('Round Trip Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('Round Trip Supply')->setCurrency('CAD');
        $this->em->persist($this->vendor);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('R-01')->setSortKey(10);
        $this->em->persist($this->bin);

        $this->em->flush();
    }

    /* ------------------------------------------------------------------------------------------
     * Voiding the receipt
     * ---------------------------------------------------------------------------------------- */

    public function testVoidingAReceiptReturnsTheReceivedBucketToZeroForASimpleProduct(): void
    {
        $product = $this->product('RT-SIMPLE-1', ProductCore::INVENTORY_MODE_SIMPLE);

        self::assertSame('0.0000', $this->received($product), 'Precondition: nothing received yet.');

        $receipt = $this->receive($product, '10.00');

        // The parity plan's credit, which is correct and stays.
        self::assertSame('10.0000', $this->received($product), 'Receiving must credit `received` for a simple line.');
        // ...and it wrote no detail row, which is what made the void skip it.
        self::assertFalse(
            $receipt->getLines()->first()->isMovementApplied(),
            'A simple line is recorded without an inventory_detail row; if this ever becomes true the'
            . ' test is no longer exercising the case it was written for.',
        );

        $this->voids->void($receipt, 'Booked against the wrong order.', 'tester');
        $this->em->flush();

        self::assertSame(
            '0.0000',
            $this->received($product),
            'Voiding the receipt must debit the `received` it credited. Left standing, availability'
            . ' over-reports by the whole delivery for ever.',
        );
    }

    public function testVoidingAReceiptReturnsTheReceivedBucketToZeroForADimensionalProduct(): void
    {
        $product = $this->product('RT-DIM-1', ProductCore::INVENTORY_MODE_DIMENSIONAL);

        $receipt = $this->receive($product, '10.00');
        self::assertSame('10.0000', $this->received($product));
        self::assertTrue($receipt->getLines()->first()->isMovementApplied());

        $this->voids->void($receipt, 'Booked against the wrong order.', 'tester');
        $this->em->flush();

        self::assertSame('0.0000', $this->received($product), 'The dimensional round trip was already right and must stay right.');
    }

    /* ------------------------------------------------------------------------------------------
     * Sending the goods back to the vendor on a restocking debit memo
     * ---------------------------------------------------------------------------------------- */

    public function testARestockingDebitMemoTakesASimpleProductBackOffAvailability(): void
    {
        $product = $this->product('RT-SIMPLE-2', ProductCore::INVENTORY_MODE_SIMPLE);

        $this->receive($product, '10.00');
        $availableAfterReceipt = $this->available($product);
        self::assertSame('10.0000', $availableAfterReceipt, 'The delivery is on hand.');

        $this->debitMemos->issue($this->restockingMemo($product, '10.00'), $this->warehouse, 'tester');
        $this->em->flush();

        self::assertSame(
            '0.0000',
            $this->available($product),
            'The goods physically went back to the vendor, so they must stop counting as available.'
            . ' While this path skipped simple products the units stayed on hand after they had gone.',
        );
    }

    public function testARestockingDebitMemoTakesADimensionalProductBackOffAvailabilityTheSameWay(): void
    {
        $product = $this->product('RT-DIM-2', ProductCore::INVENTORY_MODE_DIMENSIONAL);

        $this->receive($product, '10.00');
        self::assertSame('10.0000', $this->available($product));

        $this->debitMemos->issue($this->restockingMemo($product, '10.00'), $this->warehouse, 'tester');
        $this->em->flush();

        // The same figure by the same arithmetic as the simple case above: `returned_to_vendor` is a
        // writeOffStatuses() member either way, so `write_off` takes the units and `received` is
        // left alone. The two modes agreeing here is the property the fix is for.
        self::assertSame('0.0000', $this->available($product));
    }

    /* ------------------------------------------------------------------------------------------
     * Shipping the goods back on a vendor return
     * ---------------------------------------------------------------------------------------- */

    public function testShippingAVendorReturnTakesASimpleProductBackOffAvailability(): void
    {
        $product = $this->product('RT-SIMPLE-6', ProductCore::INVENTORY_MODE_SIMPLE);

        $this->receive($product, '10.00');
        self::assertSame('10.0000', $this->available($product), 'The delivery is on hand.');

        $this->vendorReturns->ship($this->authorisedReturn($product, '10.00'), $this->warehouse, 'tester');
        $this->em->flush();

        self::assertSame(
            '0.0000',
            $this->available($product),
            'The goods left on a vendor return, so they must stop counting as available — the third'
            . ' outbound path that used to skip a simple product outright.',
        );
    }

    public function testShippingAVendorReturnTakesADimensionalProductBackOffAvailabilityTheSameWay(): void
    {
        $product = $this->product('RT-DIM-3', ProductCore::INVENTORY_MODE_DIMENSIONAL);

        $this->receive($product, '10.00');
        self::assertSame('10.0000', $this->available($product));

        $this->vendorReturns->ship($this->authorisedReturn($product, '10.00'), $this->warehouse, 'tester');
        $this->em->flush();

        self::assertSame('0.0000', $this->available($product));
    }

    /* ------------------------------------------------------------------------------------------
     * A fractional quantity, which is now ordinary rather than refused
     * ---------------------------------------------------------------------------------------- */

    /**
     * These two used to be `testAFractionalQuantityIsRefusedForASimpleProductRatherThanTruncated`
     * and `testTheRefusalLeavesNothingBehindInTheBucket`, and the refusal they pinned was real: the
     * receiving service turned a fractional line away by name because `MovementRequest::move()` took
     * an `int` and `product_inventory.received_quantity` read as one.
     *
     * Neither was a fact about the database. `App\Doctrine\Type\QuantityType` extended `IntegerType`
     * while the columns had been `NUMERIC(14, 4)` since #645, so 2.50 could be stored perfectly and
     * was thrown away on read. With the type widened there is nothing left to protect against, and
     * inbound — which was the one direction in which a fraction could not be expressed at all —
     * behaves like every other direction.
     */
    public function testAFractionalQuantityIsBookedInFullForASimpleProduct(): void
    {
        $product = $this->product('RT-SIMPLE-3', ProductCore::INVENTORY_MODE_SIMPLE);

        $this->receive($product, '2.50');

        self::assertSame('2.5000', $this->received($product), 'the half is booked in, not refused and not truncated');
    }

    public function testTheFractionReachesTheBucketAndNotJustThePaperwork(): void
    {
        $product = $this->product('RT-SIMPLE-4', ProductCore::INVENTORY_MODE_SIMPLE);

        $this->receive($product, '2.50');

        // The old version of this test asserted 0 here, because the receipt had been refused. What
        // it was really guarding is that the bucket and the purchase order line agree, and they do:
        // this booked 2.50 where the pre-#645 behaviour booked 2 and credited the line 2.50.
        self::assertSame('2.5000', $this->received($product));
        self::assertSame('2.5000', $this->available($product));
    }

    public function testAWholeQuantityIsStillAcceptedForASimpleProduct(): void
    {
        $product = $this->product('RT-SIMPLE-5', ProductCore::INVENTORY_MODE_SIMPLE);

        $this->receive($product, '3.00');

        self::assertSame('3.0000', $this->received($product));
    }

    /* ------------------------------------------------------------------------------------------
     * Fixtures
     * ---------------------------------------------------------------------------------------- */

    private function product(string $sku, string $mode): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Round Trip Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode($mode);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /**
     * Receives $quantity of $product against a freshly issued single-line purchase order.
     *
     * A bin is passed only for a dimensional product: a simple line's key carries no bin because
     * none of that identity means anything for it, and a required-bin rule is not what these tests
     * are about.
     */
    private function receive(ProductCore $product, string $quantity): \ProcurementBundle\Entity\GoodsReceipt
    {
        $order = $this->issuedOrder($product, $quantity);
        $dimensional = $product->getInventoryMode() === ProductCore::INVENTORY_MODE_DIMENSIONAL;

        return $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add(
                $product,
                $quantity,
                $order->getLines()->first(),
                null,
                null,
                null,
                $dimensional ? $this->bin : null,
                '4.5000',
            ),
            'tester',
        );
    }

    private function issuedOrder(ProductCore $product, string $quantity): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantityOrdered($quantity)
            ->setUnitCost('4.5000')
            ->setSubtotal('0.00')
            ->setSortOrder(0);
        $order->addLine($line);
        $this->em->persist($line);

        $this->em->flush();
        $order->setStatus('Issued', DocumentActor::system());
        $this->em->flush();

        return $order;
    }

    /** A debit memo that says the goods physically went back, which is what makes issuing it move stock. */
    private function restockingMemo(ProductCore $product, string $quantity): DebitMemo
    {
        $memo = (new DebitMemo())
            ->setDocumentNumber('DM-' . random_int(100000, 999999));
        $memo->setVendor($this->vendor)->setCurrency('CAD');
        $memo->setRestock(true);
        $this->em->persist($memo);

        $line = (new DebitMemoLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity($quantity)
            ->setUnitCost('4.5000')
            // A real figure, because issue() refuses a memo totalling zero — "there is nothing to
            // debit" — and recalculateTotals() derives the document total from the lines' subtotals.
            ->setSubtotal(number_format((float) $quantity * 4.5, 2, '.', ''))
            ->setSortOrder(0);
        $memo->addLine($line);
        $this->em->persist($line);
        $memo->recalculateTotals();

        $this->em->flush();

        return $memo;
    }

    /** Authorised is the only state ship() accepts, so the fixture walks Requested -> Authorised. */
    private function authorisedReturn(ProductCore $product, string $quantity): VendorReturn
    {
        $return = (new VendorReturn())
            ->setDocumentNumber('VR-' . random_int(100000, 999999))
            ->setVendor($this->vendor);
        $this->em->persist($return);

        $line = (new VendorReturnLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity($quantity)
            ->setSortOrder(0);
        $return->addLine($line);
        $this->em->persist($line);

        $this->em->flush();
        $return->authorise();
        $this->em->flush();

        return $return;
    }

    /**
     * The row as the DATABASE has it, refreshed rather than read out of the identity map.
     *
     * The buckets are moved by StockMovementService inside its own transaction and the managed copy
     * this test holds can be a version behind it. Refreshed rather than reached by em->clear(),
     * which would detach the product and receipt the test is still holding and is what made the
     * first run of this file fail on a cascade error rather than on its assertions.
     */
    private function row(ProductCore $product): ?ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);

        if ($row instanceof ProductInventory) {
            $this->em->refresh($row);
        }

        return $row;
    }

    /** A decimal string since the quantity columns started reading as decimals in PHP. */
    private function received(ProductCore $product): string
    {
        return $this->row($product)?->getReceivedQuantity() ?? '0.0000';
    }

    private function available(ProductCore $product): string
    {
        return $this->row($product)?->getAvailableQuantity() ?? '0.0000';
    }
}
