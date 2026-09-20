<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\ProductImport\ProductImportService;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Support\FunctionalTester;

/**
 * Receiving and importing a dimensional product, as ONE sequence.
 *
 * Written as six sequential steps rather than independent cases, because the mistakes this is
 * guarding against are only visible across steps. Every branching version of this walkthrough hid
 * at least one of them.
 *
 * The three rules being pinned:
 *
 *  1. **`received` accumulates.** A receipt of 50 writes `received += 50`, because the receipt has
 *     the number in its hand. It is NOT re-derived as `detail sum − quantity` — that makes it a
 *     residual that silently absorbs any change to the detail rows, and makes the invariant below
 *     true by construction rather than worth checking.
 *  2. **`quantity` is always written by an import.** Every time, mechanically, straight from the
 *     file. It is the client's number and the import is the only thing that sets it. The checkbox
 *     has nothing to do with it.
 *  3. **The checkbox governs `received` and nothing else.** Ticked means clear it. Unticked means
 *     DO NOT TOUCH IT — not "it happened to be zero", not "clear it if it looks stale". Leave it
 *     exactly as it is, whatever it holds.
 *
 * And the invariant, which is a CHECK run afterwards, never the algorithm:
 *
 *     SUM(inventory_detail.quantity WHERE available) == quantity + received
 *
 * Step 6 is the one that earns the whole test. Step 4 also imports with the box unticked, but
 * `received` is 0 there, so "untouched" and "cleared" are indistinguishable. Step 5 puts a real 50
 * into the bucket first, so step 6 can tell them apart.
 */
final class DimensionalReceivingAndImportSequenceCest
{
    private const SKU = 'SEQ-1';

    private ?int $productId = null;
    private ?int $warehouseId = null;

    public function _before(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->persist($I->grabService(BundleStatusRepository::class)->ensureBySource('ProcurementBundle')->setStatus(BundleStatus::STATUS_ACTIVE));

        $region = (new FulfillmentRegion())->setName('West');
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku(self::SKU)
            ->setName('Sequence Widget')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($product);

        // The opening balance a mode switch leaves behind: their last imported figure, and one
        // detail row holding it with the bin unspecified.
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(100));
        $em->flush();

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('A-01');
        $em->persist($bin);
        $em->flush();

        $opening = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($bin)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE);
        $opening->setQuantity(100)->touch();
        $em->persist($opening);
        $em->flush();

        $this->productId = $product->getId();
        $this->warehouseId = $warehouse->getId();
        $em->clear();
    }

    /**
     * Six steps, one flow. Asserted after each step rather than only at the end, so a failure names
     * the step that broke rather than the accumulated wreckage.
     */
    public function receivingAndImportingAcrossSixSteps(FunctionalTester $I): void
    {
        // ── 1 ── receive 50
        $this->receive($I, 50);
        $this->assertState($I, step: 1, quantity: 100, received: 50, bin: 150, sentinel: null);

        // ── 2 ── receive 30. received ACCUMULATES: 50 + 30, not 180 − 100.
        $this->receive($I, 30);
        $this->assertState($I, step: 2, quantity: 100, received: 80, bin: 180, sentinel: null);

        // ── 3 ── their file catches up and says 180, box TICKED.
        //         quantity takes it literally; received is cleared because the box said so;
        //         the sentinel is the plug that makes the detail table agree: 180 − 180 = 0.
        $this->import($I, declared: 180, clearReceived: true);
        $this->assertState($I, step: 3, quantity: 180, received: 0, bin: 180, sentinel: 0);

        // ── 4 ── their file says 100, box UNTICKED.
        //         quantity STILL takes it literally — that is not conditional on the checkbox.
        //         The 80 units we believe are on the shelf and they do not are now a visible
        //         −80 sitting in the sentinel row, which is the design working.
        $this->import($I, declared: 100, clearReceived: false);
        $this->assertState($I, step: 4, quantity: 100, received: 0, bin: 180, sentinel: -80);

        // ── 5 ── another PO lands. received goes 0 → 50. Nothing else moves.
        $this->receive($I, 50);
        $this->assertState($I, step: 5, quantity: 100, received: 50, bin: 230, sentinel: -80);

        // ── 6 ── THE STEP THIS TEST EXISTS FOR.
        //         Import with the box unticked while received is NON-ZERO. Step 4 could not tell
        //         "untouched" from "cleared" because the bucket was empty either way.
        //         quantity takes 150 literally; received must still be 50; the sentinel re-plugs to
        //         (150 + 50) − 230 = −30.
        $this->import($I, declared: 150, clearReceived: false);
        $this->assertState($I, step: 6, quantity: 150, received: 50, bin: 230, sentinel: -30);
    }

    /** A receipt into A-01, through the same movement service ProcurementBundle's receiving calls. */
    private function receive(FunctionalTester $I, int $quantity): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        [$product, $warehouse] = $this->refs($em);

        $bin = $em->getRepository(WarehouseLocation::class)->findOneBy(['warehouse' => $warehouse, 'code' => 'A-01']);

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_RECEIPT,
            'seq-receipt-' . $quantity . '-' . bin2hex(random_bytes(4)),
            'Purchase order receipt',
            'test',
            null,
        );
        $request->receive($product, new DetailKey($warehouse, $bin, null, null, InventoryDetail::STATUS_AVAILABLE), $quantity);

        $I->grabService(StockMovementService::class)->apply($request);
        $em->clear();
    }

    private function import(FunctionalTester $I, int $declared, bool $clearReceived): void
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $path = tempnam(sys_get_temp_dir(), 'seq_import_');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['sku', 'name', 'fulfillment_region__west'], ',', '"', '\\');
        fputcsv($handle, [self::SKU, 'Sequence Widget', (string) $declared], ',', '"', '\\');
        fclose($handle);

        $I->grabService(ProductImportService::class)->import(
            new UploadedFile($path, 'seq.csv', 'text/csv', null, true),
            $em,
            [
                'primary_key' => 'sku',
                'missing_rows' => 'do_nothing',
                'clear_received_balance' => $clearReceived,
            ],
        );

        $em->clear();
    }

    /**
     * Reads the columns straight out of the database. No accessor, no service, nothing that could
     * present a value differently from how it is stored.
     */
    private function assertState(FunctionalTester $I, int $step, int $quantity, int $received, int $bin, ?int $sentinel): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $connection = $em->getConnection();

        $core = $connection->fetchAssociative(
            'SELECT quantity, received_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
            [$this->productId, $this->warehouseId],
        );

        $I->assertSame($quantity, (int) $core['quantity'], sprintf('step %d: product_inventory.quantity', $step));
        $I->assertSame($received, (int) $core['received_quantity'], sprintf('step %d: product_inventory.received_quantity', $step));

        // NOT `static`: the closure reads $this->productId, and a static closure has no $this.
        $rowFor = fn (string $code): ?int => ($value = $connection->fetchOne(
            'SELECT d.quantity FROM inventory_detail d
             JOIN warehouse_location l ON l.id = d.location_id
             WHERE d.product_id = ? AND l.code = ? AND d.status = ?',
            [$this->productId, $code, InventoryDetail::STATUS_AVAILABLE],
        )) === false ? null : (int) $value;

        $I->assertSame($bin, $rowFor('A-01'), sprintf('step %d: inventory_detail A-01', $step));
        $I->assertSame($sentinel, $rowFor('[PENDING]'), sprintf('step %d: inventory_detail [PENDING]', $step));

        // The invariant, as a CHECK — run after the fact against two independently maintained
        // numbers. It is deliberately not how either of them is computed.
        $detailSum = (int) $connection->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = ?',
            [$this->productId, $this->warehouseId, InventoryDetail::STATUS_AVAILABLE],
        );

        $I->assertSame(
            $quantity + $received,
            $detailSum,
            sprintf('step %d: SUM(available detail) must equal quantity + received', $step),
        );
    }

    /** @return array{0: ProductCore, 1: Warehouse} */
    private function refs(EntityManagerInterface $em): array
    {
        return [$em->find(ProductCore::class, $this->productId), $em->find(Warehouse::class, $this->warehouseId)];
    }
}
