<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Adversarial, conducted coverage for the 2026-09-18 fix to StockMovementService/
 * InventoryModeSwitcher: "dimensional" gates lot/serial/expiry identity, not WHERE stock is. A
 * `simple` product now resolves a real InventoryDetail row for every movement — bin included,
 * unspecified (all null) when nobody names one — the same row shape a dimensional product has
 * always had. This file drives the real screens end to end and ties every number back to
 * `product_inventory` exactly, in bcmath-precise decimal strings, never a loose numeric compare.
 *
 * Two boundaries, both driven through real HTTP POSTs and one real service call
 * (InventoryModeSwitcher — the same seam InventoryAdjustmentReasonsCest's own seed() already uses,
 * "so the opening balance is written the way the app writes it"):
 *
 *  1. A `simple` product's detail rows tie exactly to `product_inventory`, including the refusal
 *     a withdrawal now gets when the row genuinely does not hold enough — something that COULD NOT
 *     happen before this fix, because a simple product had no row to check sufficiency against at
 *     all.
 *  2. A product that accumulates real transaction history while `simple`, then switches to
 *     `dimensional`, then keeps transacting — the number must never double, `received` must never
 *     be erased on a warehouse the switch did not touch, and pre-switch and post-switch history
 *     must add up together.
 */
final class InventoryDetailAlwaysTracksLocationCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('detail-tracks-location-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->haveSeededReferenceData();
    }

    /** @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, binB: WarehouseLocation} */
    private function seedSimple(FunctionalTester $I, int $quantity = 0): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Detail Tracks Location ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('DETAIL-LOC-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Detail Tracks Location Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('DL-A')->setSortKey(10);
        $binB = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('DL-B')->setSortKey(20);
        $em->persist($bin);
        $em->persist($binB);
        $em->flush();

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'binB' => $binB];
    }

    private function openForm(FunctionalTester $I, ProductCore $product, string $reason): string
    {
        $I->amOnPage(sprintf('/admin/bundles/inventory-depth/adjust?product=%d&reason=%s', $product->getId(), $reason));
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    private function stockFound(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, ?WarehouseLocation $bin, int $quantity): void
    {
        $token = $this->openForm($I, $product, InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $fields = [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $warehouse->getId(),
            'quantity' => (string) $quantity,
        ];
        if ($bin instanceof WarehouseLocation) {
            $fields['location_id'] = (string) $bin->getId();
        }

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', $fields);
    }

    private function damaged(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, int $quantity): void
    {
        $token = $this->openForm($I, $product, InventoryAdjustmentReason::CODE_DAMAGED);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'reason' => InventoryAdjustmentReason::CODE_DAMAGED,
            'warehouse_id' => (string) $warehouse->getId(),
            'quantity' => (string) $quantity,
        ]);
    }

    private function em(FunctionalTester $I): \Doctrine\ORM\EntityManagerInterface
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        return $em;
    }

    private function coreRow(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): ProductInventory
    {
        $row = $this->em($I)->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);
        \PHPUnit\Framework\Assert::assertInstanceOf(ProductInventory::class, $row);

        return $row;
    }

    /** Exact decimal string, straight off the table — never a loose (int)/(float) compare. */
    private function availableDetailTotal(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): string
    {
        $total = $this->em($I)->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = ?',
            [$product->getId(), $warehouse->getId(), InventoryDetail::STATUS_AVAILABLE],
        );

        return number_format((float) $total, 4, '.', '');
    }

    // ---------------------------------------------------------------------------------------
    // Boundary 1: a simple product's detail rows tie exactly to product_inventory.
    // ---------------------------------------------------------------------------------------

    /**
     * Two real Stock Found submissions for the SAME simple product — one into a named bin, one
     * into no bin at all — and the detail total must equal quantity + received to the exact
     * decimal, the same invariant a dimensional product has always been held to.
     */
    public function simpleProductDetailTotalTiesExactlyToProductInventory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 20);

        $this->stockFound($I, $seed['product'], $seed['warehouse'], $seed['bin'], 5);
        $this->stockFound($I, $seed['product'], $seed['warehouse'], null, 3);

        $row = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('20.0000', $row->getQuantity(), 'the import baseline is never touched by any of this');
        $I->assertSame('8.0000', $row->getReceivedQuantity());

        // Not quantity + received: the 20-unit import baseline was never given a shape by any
        // movement (only InventoryModeSwitcher::toDimensional() does that, and this product never
        // switched) — so it has no detail row of its own. Only what actually moved through
        // StockMovementService (the 8 found) does. This is the same "unspecified until specified"
        // rule, just stated the other way round: nothing UNSHAPES the import figure into a row
        // either.
        $I->assertSame('8.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']), 'SUM(available detail) ties to received alone until the product is switched to dimensional');

        // And split correctly across the two rows it actually went into.
        $binTotal = $this->em($I)->getConnection()->fetchOne(
            'SELECT quantity FROM inventory_detail WHERE product_id = ? AND location_id = ? AND status = ?',
            [$seed['product']->getId(), $seed['bin']->getId(), InventoryDetail::STATUS_AVAILABLE],
        );
        $unspecifiedTotal = $this->em($I)->getConnection()->fetchOne(
            'SELECT quantity FROM inventory_detail WHERE product_id = ? AND location_id IS NULL AND status = ?',
            [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
        );
        $I->assertSame('5.0000', number_format((float) $binTotal, 4, '.', ''));
        $I->assertSame('3.0000', number_format((float) $unspecifiedTotal, 4, '.', ''));
    }

    /**
     * Adversarial: before this fix, a simple product had no InventoryDetail row to check
     * sufficiency against at all, so this refusal simply could not happen — a write-off for more
     * than was ever received would silently succeed. Now it is refused exactly like a dimensional
     * product's would be, and refused cleanly: nothing moves, nothing partially applies.
     */
    public function simpleProductWithdrawalBeyondWhatIsThereIsRefusedNotSilentlyAccepted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 0);

        $this->stockFound($I, $seed['product'], $seed['warehouse'], null, 5);

        $rowBefore = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $detailBefore = $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('5.0000', $detailBefore);

        // Only 5 were ever found; asking to write off 500 must be refused, not silently trimmed
        // to what is there and not silently accepted against the aggregate alone.
        $this->damaged($I, $seed['product'], $seed['warehouse'], 500);

        // AutomaticSourcePicker's own refusal (the picker plans the whole withdrawal before any
        // detail row is touched, so this is what a simple product's write-off gets now that it
        // draws from real rows the same way a dimensional product's does) — not
        // InsufficientStockException::forKey()'s race-condition message, which only fires from
        // inside the transaction when a DIFFERENT request already took the stock in between.
        $I->see('are available in');

        $rowAfter = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame($rowBefore->getReceivedQuantity(), $rowAfter->getReceivedQuantity(), 'the refused withdrawal changed nothing');
        $I->assertSame('0.0000', $rowAfter->getWriteOffQuantity(), 'nothing was written off');
        $I->assertSame($detailBefore, $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']), 'the detail row is exactly what it was before the refused request');
    }

    /**
     * Two warehouses, one simple product: stock found into a named bin at one, into no bin at all
     * at the other. Each warehouse's own total must tie to its own product_inventory row, and
     * decrementing one must never touch the other's.
     */
    public function simpleProductAcrossTwoWarehousesNeverLeaksBetweenThem(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 0);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $regionB = (new FulfillmentRegion())->setName('Detail Tracks Location B ' . uniqid());
        $em->persist($regionB);
        $warehouseB = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($regionB, 'ON', 'CA');
        $em->persist((new ProductInventory())->setProduct($seed['product'])->setWarehouse($warehouseB)->setQuantity(0));
        $em->flush();

        $this->stockFound($I, $seed['product'], $seed['warehouse'], $seed['bin'], 10);
        $this->stockFound($I, $seed['product'], $warehouseB, null, 4);

        $I->assertSame('10.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));
        $I->assertSame('4.0000', $this->availableDetailTotal($I, $seed['product'], $warehouseB));

        // Draining warehouse A down to exactly zero must not touch B at all.
        $this->damaged($I, $seed['product'], $seed['warehouse'], 10);

        $I->assertSame('0.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));
        $I->assertSame('4.0000', $this->availableDetailTotal($I, $seed['product'], $warehouseB), 'the other warehouse must be completely unaffected');
    }

    /**
     * Not just the adjustment screen's "Stock Found" reason — the plain inline "Starting" box on
     * `/admin/inventory` writes the exact same `product_inventory.quantity` column an import does,
     * through the same CoreInventoryTotalCountService::setCount(), so it gets the same fix: a
     * number typed there is real, withdrawable stock the moment it saves, not a figure sitting
     * beside an empty detail table until something else happens to shape it.
     */
    public function theInlineStartingBoxLeavesAWithdrawableRowTheSameWayImportDoes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 0);

        $I->amOnPage('/admin/inventory');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('table.wide-price-table', 'data-inventory-update-token');

        $I->sendAjaxPostRequest('/admin/inventory/update', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '25',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('25.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));

        // The whole point: a real write-off can now draw on it — exactly what used to be refused
        // ("holds 0 unit(s)") for a product whose quantity only ever arrived through this box.
        $this->damaged($I, $seed['product'], $seed['warehouse'], 10);

        $I->assertSame('15.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));
        $I->assertSame('25.0000', $this->coreRow($I, $seed['product'], $seed['warehouse'])->getQuantity(), 'the box wrote quantity directly, same as it always has');
    }

    /**
     * The Product Edit form's per-region Starting box was the one call site the original
     * unification missed (ProductController::syncProductInventory() still wrote
     * product_inventory.quantity by hand, no inventory_detail row behind it) — same defect,
     * different screen. Proven the same way: a real write-off can draw on stock that only ever
     * arrived through this box.
     */
    public function theProductFormStartingBoxLeavesAWithdrawableRowTheSameWayImportDoes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 0);
        $regionName = $I->grabService(WarehouseFulfillmentRegionService::class)->regionForWarehouse($seed['warehouse'])->getName();

        $I->amOnPage('/admin/product/inventory/update/' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $seed['product']->getId(), [
            '_token' => $token,
            'name' => $seed['product']->getName(),
            'sku' => $seed['product']->getSku(),
            'inventory' => [$regionName => '25'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('25.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));

        $this->damaged($I, $seed['product'], $seed['warehouse'], 10);

        $I->assertSame('15.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']));
        $I->assertSame('25.0000', $this->coreRow($I, $seed['product'], $seed['warehouse'])->getQuantity());
    }

    // ---------------------------------------------------------------------------------------
    // Boundary 2: simple, real transactions, switch to dimensional, more transactions.
    // ---------------------------------------------------------------------------------------

    /**
     * The scenario the fix exists for: a product accumulates REAL history while `simple` (through
     * the real screen, real movements, real detail rows) on top of an import baseline that has
     * never been given a shape, then switches to `dimensional`. The switch must open exactly the
     * still-unshaped gap — not the whole `quantity` again on top of the real activity (that would
     * double it), and not nothing at all (that would leave `quantity`'s own portion permanently
     * unshaped) — see InventoryModeSwitcherTest's own adversarial coverage of this exact mixed case
     * at the unit level; this drives it through the real screens.
     */
    public function simpleProductAccumulatesRealHistoryThenSwitchesWithoutDoublingAnything(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 50);

        $this->stockFound($I, $seed['product'], $seed['warehouse'], $seed['bin'], 12);
        $this->damaged($I, $seed['product'], $seed['warehouse'], 4);

        $beforeRow = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('50.0000', $beforeRow->getQuantity());
        $I->assertSame('12.0000', $beforeRow->getReceivedQuantity());
        $I->assertSame('4.0000', $beforeRow->getWriteOffQuantity());
        $beforeDetail = $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']);
        // Not 50 + 12 - 4: the 50-unit import baseline has no detail row of its own until
        // something shapes it (only InventoryModeSwitcher::toDimensional() does, below) — only
        // what moved through StockMovementService is reflected here.
        $I->assertSame('8.0000', $beforeDetail, '12 found - 4 damaged');

        $product = $this->em($I)->find(ProductCore::class, $seed['product']->getId());
        $I->assertInstanceOf(ProductCore::class, $product);
        $group = $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');
        $I->assertNotNull($group, 'the 50-unit import baseline was still entirely unshaped — there IS a gap to open');

        $afterRow = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('50.0000', $afterRow->getQuantity(), 'still never touched');
        $I->assertSame('12.0000', $afterRow->getReceivedQuantity(), 'real, earned received survives — only the gap-opening addition was written back out');
        $I->assertSame('4.0000', $afterRow->getWriteOffQuantity());
        // held (50 + 12 - 4 = 58) now equals SUM(available detail) exactly — the true invariant a
        // dimensional product is held to, restored rather than left at the pre-switch 8.
        $I->assertSame('58.0000', $this->availableDetailTotal($I, $seed['product'], $seed['warehouse']), 'the real 8 plus exactly the 50 that was still unshaped');
    }

    /**
     * Now dimensional: more transactions land on top of the pre-switch history, into a SECOND bin
     * the product never touched while simple, and the running total must add up across both eras.
     */
    public function dimensionalProductAfterSwitchAddsPostSwitchTransactionsOnTopOfPriorHistory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 0);

        $this->stockFound($I, $seed['product'], $seed['warehouse'], $seed['bin'], 20);

        $product = $this->em($I)->find(ProductCore::class, $seed['product']->getId());
        $I->assertInstanceOf(ProductCore::class, $product);
        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');

        // Post-switch: a fresh Stock Found into the bin the product never used before switching.
        $product = $this->em($I)->find(ProductCore::class, $seed['product']->getId());
        $warehouse = $this->em($I)->find(Warehouse::class, $seed['warehouse']->getId());
        $binB = $this->em($I)->find(WarehouseLocation::class, $seed['binB']->getId());
        $I->assertInstanceOf(ProductCore::class, $product);
        $I->assertInstanceOf(Warehouse::class, $warehouse);
        $I->assertInstanceOf(WarehouseLocation::class, $binB);

        $this->stockFound($I, $product, $warehouse, $binB, 7);

        $I->assertSame('27.0000', $this->availableDetailTotal($I, $product, $warehouse), '20 pre-switch + 7 post-switch');

        $binATotal = $this->em($I)->getConnection()->fetchOne(
            'SELECT quantity FROM inventory_detail WHERE product_id = ? AND location_id = ?',
            [$product->getId(), $seed['bin']->getId()],
        );
        $binBTotal = $this->em($I)->getConnection()->fetchOne(
            'SELECT quantity FROM inventory_detail WHERE product_id = ? AND location_id = ?',
            [$product->getId(), $binB->getId()],
        );
        $I->assertSame('20.0000', number_format((float) $binATotal, 4, '.', ''), 'pre-switch history keeps its own bin, untouched by the switch');
        $I->assertSame('7.0000', number_format((float) $binBTotal, 4, '.', ''), 'post-switch activity lands in the bin actually named this time');
    }

    /**
     * Switching back to `simple` and then to `dimensional` again must be idempotent: the rows go
     * dormant, not deleted, and the second toDimensional() call must skip the opening balance
     * again for the same reason the first one's mixed-warehouse case does — the detail rows are
     * still there.
     */
    public function switchingBackAndForthNeverDuplicatesOrLosesStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, quantity: 15);
        $productId = $seed['product']->getId();
        $warehouseId = $seed['warehouse']->getId();

        // availableDetailTotal() below calls through em($I), which clears the identity map on
        // every call — so ANY entity held across one of its calls is detached afterward, and
        // mutating a detached entity through the switcher next silently does nothing (flush() only
        // ever processes MANAGED entities). Re-fetched fresh, right before each switch call, using
        // a plain grabService() with no clearing side effect of its own — never a reference carried
        // across an availableDetailTotal() call.
        $refetch = static function () use ($I, $productId, $warehouseId): array {
            $em = $I->grabService('doctrine.orm.entity_manager');

            return [$em->find(ProductCore::class, $productId), $em->find(Warehouse::class, $warehouseId)];
        };

        $switcher = $I->grabService(InventoryModeSwitcher::class);
        [$product, $warehouse] = $refetch();
        $switcher->toDimensional($product, 'cest@example.test');

        $onceDimensional = $this->availableDetailTotal($I, $product, $warehouse);
        $I->assertSame('15.0000', $onceDimensional);
        $I->assertSame(ProductCore::INVENTORY_MODE_DIMENSIONAL, $product->getInventoryMode());

        [$product, $warehouse] = $refetch();
        $switcher->toSimple($product);
        $I->assertSame(ProductCore::INVENTORY_MODE_SIMPLE, $product->getInventoryMode());
        $I->assertSame($onceDimensional, $this->availableDetailTotal($I, $product, $warehouse), 'switching back changes no number, and the rows stay — dormant, not deleted');

        [$product, $warehouse] = $refetch();
        $secondGroup = $switcher->toDimensional($product, 'cest@example.test');
        $I->assertNull($secondGroup, 'the rows never went away, so the second switch has nothing left to open');
        $I->assertSame(ProductCore::INVENTORY_MODE_DIMENSIONAL, $product->getInventoryMode());
        $I->assertSame($onceDimensional, $this->availableDetailTotal($I, $product, $warehouse), 'still exactly what it was — not doubled by the second switch');
    }
}
