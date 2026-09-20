<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Transfer;

use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * A transfer's two buckets, asserted as COLUMNS, with ProcurementBundle Inactive (#584).
 *
 * ## The bug this test would have caught
 *
 * `transfer_out` used to mean "on a truck right now" — a cached sum of the `in_transit` detail rows
 * — so the receipt that consumed those rows dropped it back to zero and handed the source its
 * availability back for stock now standing in another building. The permanent loss was pushed into
 * `received_quantity` as a negative, and the arrival was credited to the destination's `received`.
 * Both are WarehouseOpsBundle facts living in a bucket BundleBucketAvailabilityGate gates on
 * ProcurementBundle:
 *
 *                   quantity  received  transfer_out  AVAILABLE
 *   before   West      50        0          0           50   ok
 *   dispatch West      50        0         10           40   ok
 *   arrival  West      50      -10          0           50   WRONG, holds 40
 *   arrival  East       0       10          0            0   WRONG, holds 10
 *
 * `inventory_detail` was correct throughout. Only the bucket arithmetic was wrong.
 *
 * ## Why this asserts columns and not availability
 *
 * Because the entire class of bug here is availability looking plausible while the columns disagree
 * with `inventory_detail`. With ProcurementBundle Active the sum above comes out right, and every
 * pre-existing transfer test — all of which assert availability — passed throughout. A test that
 * only added up the row would have gone on passing after the fix too, and would have told nobody
 * which column carries the fact.
 *
 * So each case names all four numbers on both warehouse rows, and then, separately, states the
 * invariant against the detail rows.
 *
 * ## Why ProcurementBundle is Inactive
 *
 * It is the configuration that exposes the entanglement, and it is an ordinary one: an instance can
 * run internal transfers without running purchase orders. Inactive means the gate leaves `received`
 * out of availability entirely, so anything a transfer wrote there simply vanishes from the sum —
 * which is why the source read 50 of 50 sellable while its shelf held 40.
 */
final class CumulativeTransferBucketsTest extends WarehouseOpsTestCase
{
    private TransferOrderService $transfers;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfers = self::getContainer()->get(TransferOrderService::class);

        $east = (new FulfillmentRegion())->setName('East');
        $this->em->persist($east);
        $this->destination = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($east, 'BC', 'CA');

        // The configuration under test. A bundle is Inactive when its status row says so — and, since
        // the default flipped, also when it has no row at all. Written explicitly through the one
        // activation path, because every installed bundle now carries an Active row from setUp().
        self::getContainer()->get(BundleStatusRepository::class)->deactivate('ProcurementBundle');
    }

    /**
     * 50 units at West, counted by the client's own system.
     *
     * Stock arrives the only way stock ever arrives — a movement — which leaves it in `received`,
     * because `quantity` is the imported figure and the movement layer does not write it. Then the
     * import is simulated: `quantity` becomes 50 and `received` returns to 0, exactly as a run with
     * `clear_received_balance` leaves the row. That is the starting state the issue describes, and
     * it matters that `received` starts at zero — a bucket that is already non-zero would let a
     * wrong write hide inside it.
     */
    private function seedWestWithFiftyCountedUnits(): void
    {
        $this->receive(50, $this->binA);

        $west = $this->inventoryRow();
        self::assertInstanceOf(ProductInventory::class, $west);
        $west->setQuantity(50)->setReceivedQuantity(0);
        $this->em->flush();

        // Cleared so that every ProductInventory below is LOADED rather than found in the identity
        // map, which is the only way BundleBucketAvailabilityGate's postLoad runs and the only way
        // ProcurementBundle being Inactive can affect anything. A row this test created in memory
        // would keep the entity's default flags and quietly test the wrong configuration.
        $this->reload();

        self::assertSame(50, $this->availableDetail($this->warehouse), 'the shelf holds 50 before anything moves');
    }

    /** Detaches everything and re-fetches the fixtures by id, so later loads pass through the gate. */
    private function reload(): void
    {
        $productId = $this->product->getId();
        $westId = $this->warehouse->getId();
        $eastId = $this->destination->getId();

        $this->em->flush();
        $this->em->clear();

        $product = $this->em->getRepository(ProductCore::class)->find($productId);
        $west = $this->em->getRepository(Warehouse::class)->find($westId);
        $east = $this->em->getRepository(Warehouse::class)->find($eastId);

        self::assertInstanceOf(ProductCore::class, $product);
        self::assertInstanceOf(Warehouse::class, $west);
        self::assertInstanceOf(Warehouse::class, $east);

        $this->product = $product;
        $this->warehouse = $west;
        $this->destination = $east;
    }

    private function draft(int $quantity, string $number = 'TR-000001'): TransferOrder
    {
        $transfer = (new TransferOrder())
            ->setNumber($number)
            ->setFromWarehouse($this->warehouse)
            ->setToWarehouse($this->destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);

        $line = (new TransferOrderLine())
            ->setProduct($this->product)
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setQuantityRequested($quantity);

        $transfer->addLine($line);
        $this->em->persist($transfer);
        $this->em->persist($line);
        $this->em->flush();

        return $transfer;
    }

    private function availableDetail(Warehouse $warehouse): int
    {
        return $this->wholeUnits($this->details->availableTotal($this->product, $warehouse));
    }

    private function statusDetail(Warehouse $warehouse, string $status): int
    {
        return $this->wholeUnits(
            $this->details->findExisting($this->product, $warehouse, null, null, null, $status)?->getQuantity() ?? '0',
        );
    }

    /**
     * Every column on one warehouse's row, in one assertion, so a failure prints the whole picture
     * rather than the first number that happens to be wrong.
     *
     * @param array{quantity: int, received: int, out: int, in: int} $expected
     */
    private function assertColumns(Warehouse $warehouse, array $expected, string $when): void
    {
        $row = $this->inventoryRow($this->product, $warehouse);
        self::assertInstanceOf(ProductInventory::class, $row, sprintf('%s should have a stock row %s', $warehouse->getName(), $when));

        // The four columns as whole units, so the expectations above stay readable ints now that
        // the columns read as decimal strings. wholeUnits() fails rather than truncates, so a
        // fraction reaching one of these buckets is a failure here and not a silent rounding.
        self::assertSame(
            $expected,
            [
                'quantity' => $this->wholeUnits($row->getQuantity()),
                'received' => $this->wholeUnits($row->getReceivedQuantity()),
                'out' => $this->wholeUnits($row->getTransferOutQuantity()),
                'in' => $this->wholeUnits($row->getTransferInQuantity()),
            ],
            sprintf('%s columns %s', $warehouse->getName(), $when),
        );
    }

    /**
     * The invariant the detail check runs, stated here against the same two rows:
     *
     *     SUM(available detail) == quantity + received + transfer_in − transfer_out
     *
     * with the write-off, quarantine and sold terms omitted because no test here creates any. It is
     * asserted separately from the columns above deliberately: the columns say WHERE the fact is
     * recorded, this says the recording adds up. The old code satisfied neither at the source and
     * the second one only by accident at the destination.
     */
    private function assertDetailAgreesWithColumns(Warehouse $warehouse, string $when): void
    {
        $row = $this->inventoryRow($this->product, $warehouse);
        self::assertInstanceOf(ProductInventory::class, $row);

        self::assertSame(
            $this->availableDetail($warehouse),
            $this->wholeUnits($row->getQuantity())
                + $this->wholeUnits($row->getReceivedQuantity())
                + $this->wholeUnits($row->getTransferInQuantity())
                - $this->wholeUnits($row->getTransferOutQuantity()),
            sprintf('%s: the buckets must reconcile to its detail rows %s', $warehouse->getName(), $when),
        );
    }

    public function testAnArrivedTransferLeavesTheSourceShortAndTheDestinationLong(): void
    {
        $this->seedWestWithFiftyCountedUnits();

        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 0, 'in' => 0], 'before anything moves');
        self::assertSame(50, $this->coreQuantity(), 'all fifty are sellable at West to start with');

        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-584-1', 'tester');

        // In flight: the old definition and the new one agree here, because nothing has arrived, so
        // dispatched and in-transit are the same number. This is the half that was never broken.
        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 10, 'in' => 0], 'while the truck is out');
        self::assertSame(40, $this->coreQuantity(), 'the source cannot sell what is on the truck');
        self::assertSame(10, $this->statusDetail($this->warehouse, InventoryDetail::STATUS_IN_TRANSIT), 'and the units are locatable, on the source warehouse\'s in-transit row');

        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $this->transfers->receive($transfer, [$line->getId() => 10], [$line->getId() => null], 'tr-584-1', 'tester');

        // The case the issue is about. `transfer_out` STAYS at 10 — the units are gone from West for
        // good — and `received` is untouched, where it used to hold −10 that ProcurementBundle's
        // flag then excluded from the sum.
        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 10, 'in' => 0], 'after the goods arrived at East');
        self::assertSame(0, $this->statusDetail($this->warehouse, InventoryDetail::STATUS_IN_TRANSIT), 'the in-transit row is consumed by the receipt, which is exactly why it cannot define the bucket');
        self::assertSame(40, $this->availableDetail($this->warehouse), 'West\'s shelf holds forty');
        self::assertSame(40, $this->coreQuantity(), 'and West may sell forty, not the fifty it sprang back to before #584');

        // The destination's arrival is `transfer_in`, not `received`: it came from another warehouse
        // of this business, not from a vendor, and crediting both would count it twice.
        $this->assertColumns($this->destination, ['quantity' => 0, 'received' => 0, 'out' => 0, 'in' => 10], 'after the goods arrived at East');
        self::assertSame(10, $this->availableDetail($this->destination), 'East\'s shelf holds ten');
        self::assertSame(10, $this->coreQuantity($this->product, $this->destination), 'and East may sell them, with ProcurementBundle Inactive');

        $this->assertDetailAgreesWithColumns($this->warehouse, 'after the arrival');
        $this->assertDetailAgreesWithColumns($this->destination, 'after the arrival');
    }

    /**
     * Dispatched 10, received 8. The two that never turned up stay missing — they are still on
     * West's in-transit row, unsellable, until somebody writes them off deliberately.
     *
     * This is the case that proves the buckets read two different columns of the document rather
     * than one: `transfer_out` is what LEFT and `transfer_in` is what ARRIVED, and the gap between
     * them is the loss. A single figure would have to choose, and either choice invents stock or
     * pretends the shortfall did not happen.
     */
    public function testAPartialArrivalWithholdsAllTenAtTheSourceAndCreditsOnlyEightAtTheDestination(): void
    {
        $this->seedWestWithFiftyCountedUnits();

        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-584-2', 'tester');

        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $this->transfers->receive($transfer, [$line->getId() => 8], [$line->getId() => null], 'tr-584-2', 'tester');

        self::assertSame('10.0000', $line->getQuantityDispatched());
        self::assertSame('8.0000', $line->getQuantityReceived());

        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 10, 'in' => 0], 'after a short arrival');
        self::assertSame(2, $this->statusDetail($this->warehouse, InventoryDetail::STATUS_IN_TRANSIT), 'the two that never arrived are still on the source\'s in-transit row');
        self::assertSame(40, $this->coreQuantity(), 'all ten stay withheld at West: eight are at East and two are lost, and neither is sellable here');

        $this->assertColumns($this->destination, ['quantity' => 0, 'received' => 0, 'out' => 0, 'in' => 8], 'after a short arrival');
        self::assertSame(8, $this->coreQuantity($this->product, $this->destination), 'East is credited with what arrived, not with what was sent');

        $this->assertDetailAgreesWithColumns($this->warehouse, 'after a short arrival');
        $this->assertDetailAgreesWithColumns($this->destination, 'after a short arrival');
    }

    /**
     * Two transfers of the same product on the same route accumulate; neither recomputation
     * forgets the other.
     *
     * The buckets are rewritten whole from every line of that product, so this would fail loudly if
     * the recomputation were scoped to the transfer being processed — the shape an incremented
     * counter degrades into when somebody "optimises" the query.
     */
    public function testASecondTransferAddsToTheBucketsRatherThanReplacingThem(): void
    {
        $this->seedWestWithFiftyCountedUnits();

        foreach ([['tr-584-3', 6], ['tr-584-4', 4]] as [$operation, $quantity]) {
            $transfer = $this->draft($quantity, 'TR-' . $operation);
            $this->transfers->dispatch($transfer, $operation, 'tester');

            /** @var TransferOrderLine $line */
            $line = $transfer->getLines()->first();
            $this->transfers->receive($transfer, [$line->getId() => $quantity], [$line->getId() => null], $operation, 'tester');
        }

        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 10, 'in' => 0], 'after two transfers');
        $this->assertColumns($this->destination, ['quantity' => 0, 'received' => 0, 'out' => 0, 'in' => 10], 'after two transfers');

        $this->assertDetailAgreesWithColumns($this->warehouse, 'after two transfers');
        $this->assertDetailAgreesWithColumns($this->destination, 'after two transfers');
    }

    /**
     * Switching WarehouseOpsBundle off takes both terms out of the sum and leaves both columns
     * exactly where they were.
     *
     * The pair has ONE flag, so this is also the assertion that they cannot be half-gated: West
     * gives its ten back and East loses its ten in the same load, and the columns still say what
     * happened. A zeroed column and an excluded term produce identical availability, so only
     * reading the columns separates "not counted" from "destroyed".
     */
    public function testTurningTheTransferBundleOffExcludesBothTermsAndDestroysNeither(): void
    {
        $this->seedWestWithFiftyCountedUnits();

        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-584-5', 'tester');

        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $this->transfers->receive($transfer, [$line->getId() => 10], [$line->getId() => null], 'tr-584-5', 'tester');

        self::getContainer()->get(BundleStatusRepository::class)->deactivate('WarehouseOpsBundle');
        $this->reload();

        self::assertSame(50, $this->coreQuantity(), 'with transfers untracked, West stops withholding for trucks nobody is following');
        self::assertSame(0, $this->coreQuantity($this->product, $this->destination), 'and East stops being credited by the same document');

        $this->assertColumns($this->warehouse, ['quantity' => 50, 'received' => 0, 'out' => 10, 'in' => 0], 'with the bundle Inactive');
        $this->assertColumns($this->destination, ['quantity' => 0, 'received' => 0, 'out' => 0, 'in' => 10], 'with the bundle Inactive');
    }
}
