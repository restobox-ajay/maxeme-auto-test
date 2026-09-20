<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The lots and bins forms, on the edit path they never had (#590).
 *
 * Both forms hardcoded `<input type="hidden" name="id" value="0">` and no row carried an Edit link,
 * so every save took the create branch. They failed differently and the lot one was the dangerous
 * half:
 *
 *  - **lots** — the create branch deliberately allows a repeated `(product, code)`, so correcting a
 *    wrong expiry date **forked the batch**. A second `inventory_lot` row appeared with the right
 *    date while every `inventory_detail.lot_id` stayed on the old wrong-dated one, and the picker's
 *    earliest-expiry ordering kept using the date nobody could see any more.
 *  - **bins** — the create branch's duplicate check refused the save, so `code`, `type`, `sort_key`
 *    and `status` were write-once. A bin could not be renamed, re-sorted, taken out of service, or
 *    put back into it.
 *
 * Every POST here goes through sendFormPostRequest(), i.e. a plain browser form post with no
 * `X-Requested-With` header, because this bundle ships no JavaScript and these forms have to work
 * with it turned off. The Edit affordance is a plain `<a href>` for the same reason.
 *
 * The assertions are about rows: what `inventory_lot.id` the save landed on, and whether
 * `warehouse_location.status` moved. Seeing a flash message is not evidence that a row changed.
 */
final class InventoryDepthLotAndBinEditingCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('lot-bin-edit-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A dimensional product with one bin and one lot. The opening balance the switcher writes has
     * no location, so the bin starts EMPTY — stock only reaches it when a test puts it there.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, lot: InventoryLot}
     */
    private function seed(FunctionalTester $I, int $quantity = 40): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Lot Edit Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('LOTEDIT-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Lot Edit Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('E-01')->setSortKey(10);
        $em->persist($bin);

        $lot = (new InventoryLot())
            ->setProduct($product)
            ->setCode('BATCH-9')
            ->setExpiry(new \DateTimeImmutable('2027-06-30'));
        $em->persist($lot);

        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'lot-bin-edit@example.test');

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'lot' => $lot];
    }

    /** Puts real stock into a bin, through the service that writes the movement. */
    private function receiveInto(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, WarehouseLocation $bin, ?InventoryLot $lot, int $quantity): void
    {
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'cest-bin-' . uniqid(), 'Put away into the bin')
                ->receive($product, new DetailKey($warehouse, $bin, $lot, null, InventoryDetail::STATUS_AVAILABLE), $quantity)
        );
    }

    private function token(FunctionalTester $I): string
    {
        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    // ---------------------------------------------------------------- lots

    /**
     * The affordance itself. Without a link that carries the row id, the id on the form can only
     * ever be 0 and every save is a create — which is the whole of this bug.
     */
    public function theLotsListLinksEachRowIntoTheFormAndFillsItFromThatRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $lotId = (int) $seed['lot']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $I->seeResponseCodeIsSuccessful();

        // A plain anchor, not a button that needs scripting.
        $I->seeElement('a[href*="edit=' . $lotId . '"]');
        $I->assertSame('0', $I->grabAttributeFrom('input[name="id"]', 'value'),
            'with nothing being edited the form must add, not silently target a row');

        $I->amOnPage('/admin/bundles/inventory-depth/lots?edit=' . $lotId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame((string) $lotId, $I->grabAttributeFrom('input[name="id"]', 'value'),
            'the hidden id is what makes the save an update rather than a fork');
        $I->seeInField('code', 'BATCH-9');
        $I->seeInField('expiry', '2027-06-30');

        // The product is not a field on the edit path: detail rows, movements and the availability
        // sum all hang off (product, lot), so moving a lot would reassign every one of them.
        $I->dontSeeElement('input[name="product_id"]');
    }

    /**
     * The fork, fixed. Correcting the expiry date must land on the same `inventory_lot.id`, leaving
     * `inventory_detail.lot_id` pointing at a row whose date is now right.
     */
    public function correctingALotsExpiryUpdatesThatRowInsteadOfForkingIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $productId = (int) $seed['product']->getId();
        $lotId = (int) $seed['lot']->getId();

        // Stock on the lot, so the row this must not fork is a row that actually holds something.
        $this->receiveInto($I, $seed['product'], $seed['warehouse'], $seed['bin'], $seed['lot'], 12);

        $I->amOnPage('/admin/bundles/inventory-depth/lots?edit=' . $lotId);
        $token = $this->token($I);

        // A plain form post: no X-Requested-With, exactly what a browser with JS off sends.
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $token,
            'id' => (string) $lotId,
            'code' => 'BATCH-9',
            'expiry' => '2028-01-31',
            'received_at' => '2026-01-05',
            'source' => 'Corrected from the delivery note',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $I->assertSame(1, $em->getRepository(InventoryLot::class)->count(['product' => $productId]),
            'the save must not have added a second inventory_lot row beside the one it edited');

        $lot = $em->find(InventoryLot::class, $lotId);
        $I->assertInstanceOf(InventoryLot::class, $lot);
        $I->assertSame('2028-01-31', $lot->getExpiry()?->format('Y-m-d'),
            'inventory_lot.expiry on the original row must carry the correction');
        $I->assertSame('Corrected from the delivery note', $lot->getSource());

        // And the stock is still on it — nothing was moved, split or re-pointed.
        $held = $em->getRepository(InventoryDetail::class)->count(['lot' => $lotId]);
        $I->assertGreaterThan(0, $held, 'inventory_detail.lot_id must still point at the row that was edited');
    }

    /**
     * A stale Edit link names a row that is gone. Falling through to the create branch there would
     * be the same fork one step removed, so it is refused.
     */
    public function aLotIdThatNamesNoRowIsRefusedRatherThanCreatingOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $productId = (int) $seed['product']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $token,
            'id' => '99999999',
            'code' => 'GHOST',
            'expiry' => '2029-01-01',
            'received_at' => '',
            'source' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $I->assertSame(1, $em->getRepository(InventoryLot::class)->count(['product' => $productId]),
            'a save aimed at a row that does not exist must write nothing at all');
        $I->assertSame(0, $em->getRepository(InventoryLot::class)->count(['code' => 'GHOST']));
    }

    /**
     * Duplicates are REPORTED, never merged.
     *
     * A repeated `(product, code)` is legitimate — vendors reuse batch codes across production runs
     * with different dates — so the save is allowed and both rows keep their own expiry. What the
     * screen owes is to say so, next to the row ids, rather than leaving the ambiguity silent.
     */
    public function twoLotsSharingACodeAreReportedAndBothRowsAreLeftAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $productId = (int) $seed['product']->getId();
        $firstId = (int) $seed['lot']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $token = $this->token($I);

        // The create branch, with a code the product already carries. Allowed on purpose.
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $token,
            'product_id' => (string) $productId,
            'id' => '0',
            'code' => 'BATCH-9',
            'expiry' => '2029-12-31',
            'received_at' => '',
            'source' => 'Second production run',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Said at the moment the ambiguity is created, which is the only moment anybody knows
        // whether they meant it.
        $I->see('Nothing was merged');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $lots = $em->getRepository(InventoryLot::class)->findBy(['product' => $productId], ['id' => 'ASC']);
        $I->assertCount(2, $lots, 'both rows must survive — merging them would lose one of the two dates');
        $I->assertSame($firstId, (int) $lots[0]->getId());
        $I->assertSame('2027-06-30', $lots[0]->getExpiry()?->format('Y-m-d'),
            'the older row must be untouched: nothing may write to it on the strength of a shared code');
        $I->assertSame('2029-12-31', $lots[1]->getExpiry()?->format('Y-m-d'));

        // And the list reports the pair standing on the screen, not only in a flash that scrolls away.
        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $I->see('Lot codes used more than once on the same product');
        $I->see('row ' . $firstId);
        $I->see('row ' . $lots[1]->getId());
    }

    // ---------------------------------------------------------------- bins

    /**
     * Bins were write-once: the create branch's duplicate check refused every save, so a bin could
     * never be renamed or re-sorted. `sort_key` is the picker's tiebreak after expiry, so this is
     * an operational number, not decoration.
     */
    public function renamingAndResortingABinLandsOnTheSameRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $binId = (int) $seed['bin']->getId();
        $warehouseId = (int) $seed['warehouse']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame((string) $binId, $I->grabAttributeFrom('input[name="id"]', 'value'));
        $I->seeInField('code', 'E-01');
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-02',
            'type' => WarehouseLocation::TYPE_STAGING,
            'sort_key' => '55',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $I->assertSame(1, $em->getRepository(WarehouseLocation::class)->count(['warehouse' => $warehouseId]),
            'the rename must land on the existing row, not add a second bin beside it');

        $bin = $em->find(WarehouseLocation::class, $binId);
        $I->assertInstanceOf(WarehouseLocation::class, $bin);
        $I->assertSame('E-02', $bin->getCode());
        $I->assertSame(55, $bin->getSortKey());
        $I->assertSame(WarehouseLocation::TYPE_STAGING, $bin->getType());
    }

    /**
     * Closing a bin refuses while stock is still inside it.
     *
     * Every bin dropdown in the bundle filters `status = 'Active'`, so an Inactive bin holding stock
     * is stock that cannot be named as a movement source, cycle counted, or scanned. It is stranded.
     */
    public function closingABinThatStillHoldsStockIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $binId = (int) $seed['bin']->getId();
        $this->receiveInto($I, $seed['product'], $seed['warehouse'], $seed['bin'], $seed['lot'], 9);

        // The number is on the screen before the refusal, so it is never a surprise.
        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Holds 9 unit(s)');
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-01',
            'type' => WarehouseLocation::TYPE_PICK,
            'sort_key' => '10',
            'status' => 'Inactive',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('cannot be closed');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $bin = $em->find(WarehouseLocation::class, $binId);
        $I->assertInstanceOf(WarehouseLocation::class, $bin);
        $I->assertSame('Active', $bin->getStatus(),
            'warehouse_location.status must not have moved to Inactive under 9 units');
    }

    /**
     * The refusal is about the transition, not about the bin. A stocked bin still has to be
     * renameable and re-sortable while it stays Active, or the refusal becomes its own trap.
     */
    public function aStockedBinCanStillBeRenamedWhileItStaysOpen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $binId = (int) $seed['bin']->getId();
        $this->receiveInto($I, $seed['product'], $seed['warehouse'], $seed['bin'], $seed['lot'], 4);

        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-01-RELABELLED',
            'type' => WarehouseLocation::TYPE_PICK,
            'sort_key' => '11',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $bin = $em->find(WarehouseLocation::class, $binId);
        $I->assertInstanceOf(WarehouseLocation::class, $bin);
        $I->assertSame('E-01-RELABELLED', $bin->getCode());
        $I->assertSame('Active', $bin->getStatus());
    }

    /** An empty bin closes, and — the half that was never possible at all — reopens again. */
    public function anEmptyBinClosesAndCanBeReopened(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $binId = (int) $seed['bin']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-01',
            'type' => WarehouseLocation::TYPE_PICK,
            'sort_key' => '10',
            'status' => 'Inactive',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $I->assertSame('Inactive', $em->find(WarehouseLocation::class, $binId)?->getStatus());

        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-01',
            'type' => WarehouseLocation::TYPE_PICK,
            'sort_key' => '10',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $I->assertSame('Active', $em->find(WarehouseLocation::class, $binId)?->getStatus(),
            'a bin taken out of service has to be able to come back into it');
    }

    /**
     * Two bins in one warehouse cannot share a code — that part was always right, and renaming onto
     * an occupied code must still be turned away rather than hitting uniq_wh_location.
     */
    public function renamingABinOntoAnotherBinsCodeIsStillRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $other = (new WarehouseLocation())->setWarehouse($seed['warehouse'])->setCode('E-99')->setSortKey(99);
        $em->persist($other);
        $em->flush();

        $binId = (int) $seed['bin']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/bins?edit=' . $binId);
        $token = $this->token($I);

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/save', [
            '_token' => $token,
            'id' => (string) $binId,
            'code' => 'E-99',
            'type' => WarehouseLocation::TYPE_PICK,
            'sort_key' => '10',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('already has a bin called E-99');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $I->assertSame('E-01', $em->find(WarehouseLocation::class, $binId)?->getCode());
    }
}
