<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Reorder levels and the low-stock screen (#597), through the real kernel.
 *
 * The arithmetic itself is proved without a kernel in
 * `InventoryDepthBundle\Tests\Reorder\ReorderPointRuleTest`. What is only reachable here is the
 * half that is a SCREEN: that a level can be set with JavaScript off, that the row it writes is the
 * row it claims to, that a covered row and a short row are told apart in the markup, and that
 * switching procurement off changes what the screen says rather than what it stores.
 *
 * Every POST goes through sendFormPostRequest() — a plain browser form post with no
 * X-Requested-With header — because this bundle ships no JavaScript and every one of these forms
 * has to work with it turned off.
 *
 * The assertions are about rows and figures: what `inventory_reorder_rule.reorder_point` holds
 * afterwards, and what the screen says against `product_inventory.incoming_quantity`. Seeing a
 * flash message is not evidence that anything was written.
 */
final class InventoryDepthReorderPointsCest
{
    private const URL = '/admin/bundles/inventory-depth/low-stock';

    /**
     * The grid, as opposed to the page.
     *
     * Every assertion about whether a product is LISTED is made against this and not against the
     * whole document, because the create form above the grid holds a product picker whose select
     * carries the catalogue under the inline limit (#8) — so `see($sku)` on the page would pass for
     * a product the list is deliberately hiding, and `dontSee($sku)` would fail for one it is
     * showing. Assert the cell, not the page (#627).
     */
    private const GRID = '.table-card table';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('reorder-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * One warehouse, two products.
     *
     * `incoming_quantity` is set on the fixture rather than driven through a purchase order:
     * ProcurementBundle's own IncomingStockReconcilerTest proves the column is written correctly
     * (#583), and what is under test here is what this screen makes of the figure once it is there.
     *
     * @return array{warehouse: Warehouse, short: ProductCore, covered: ProductCore}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Reorder Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $suffix = strtoupper(substr(uniqid(), -6));

        $short = (new ProductCore())
            ->setSku('SHORT-' . $suffix)
            ->setName('Nothing On Order')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($short);
        // 4 sellable, nothing coming.
        $em->persist((new ProductInventory())->setProduct($short)->setWarehouse($warehouse)->setQuantity(4));

        $covered = (new ProductCore())
            ->setSku('COVERED-' . $suffix)
            ->setName('Already On Order')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($covered);
        // 4 sellable and 50 on a purchase order that has not landed.
        $em->persist(
            (new ProductInventory())->setProduct($covered)->setWarehouse($warehouse)->setQuantity(4)->setIncomingQuantity(50)
        );

        $em->flush();

        return ['warehouse' => $warehouse, 'short' => $short, 'covered' => $covered];
    }

    private function token(FunctionalTester $I): string
    {
        return (string) $I->grabAttributeFrom('form.form-grid input[name="_token"]', 'value');
    }

    /** Sets a level the way the form does: a plain POST, no JavaScript anywhere in the path. */
    private function setLevel(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, int $point, array $extra = []): void
    {
        $I->amOnPage(self::URL);

        $I->sendFormPostRequest(self::URL . '/save', array_merge([
            '_token' => $this->token($I),
            'id' => 0,
            'product_id' => (string) $product->getId(),
            'warehouse_id' => (string) $warehouse->getId(),
            'reorder_point' => (string) $point,
        ], $extra));
    }

    private function levelRow(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): array|false
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return $em->getConnection()->fetchAssociative(
            'SELECT reorder_point, reorder_quantity, safety_stock_quantity FROM inventory_reorder_rule WHERE product_id = ? AND warehouse_id = ?',
            [$product->getId(), $warehouse->getId()],
        );
    }

    // ------------------------------------------------------------------ setting a level

    /**
     * The screen is empty on the day it ships.
     *
     * #597's central worry: a level defaulted to 0 would put every product in the catalogue on this
     * screen. Nothing is inferred, so a warehouse full of stock with no levels set lists nothing at
     * all.
     */
    public function nothingIsListedUntilSomebodySetsALevel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage(self::URL);
        $I->seeResponseCodeIsSuccessful();
        // Scoped to the grid, not the page. The create form above it carries a product picker
        // whose <select> lists the whole catalogue under the inline limit (#8), so every SKU is on
        // this page whether or not it is on the LIST — and the list is what this test is about.
        // Same reason #627 gives for asserting the cell instead of the page.
        $I->dontSee($seed['short']->getSku(), self::GRID);
        $I->dontSee($seed['covered']->getSku(), self::GRID);
        $I->see('A pair only appears once somebody has set a reorder level for it');

        $I->assertFalse(
            $this->levelRow($I, $seed['short'], $seed['warehouse']),
            'no inventory_reorder_rule row exists for a product nobody has managed',
        );
    }

    /** A level is set with a plain form POST, and the row it writes carries the number that was typed. */
    public function aLevelIsSetWithJavaScriptOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10, [
            'reorder_quantity' => '144',
            'safety_stock_quantity' => '3',
        ]);

        $row = $this->levelRow($I, $seed['short'], $seed['warehouse']);

        $I->assertNotFalse($row, 'the POST wrote an inventory_reorder_rule row');
        $I->assertSame(10, (int) $row['reorder_point']);
        $I->assertSame(144, (int) $row['reorder_quantity']);
        $I->assertSame(3, (int) $row['safety_stock_quantity']);
    }

    /**
     * An empty level box is refused, not read as 0.
     *
     * 0 is a real level meaning "tell me when there is nothing left". Defaulting a blank box to it
     * would set that level on whatever product happened to be in the form.
     */
    public function aBlankLevelIsRefusedRatherThanReadAsZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage(self::URL);
        $I->sendFormPostRequest(self::URL . '/save', [
            '_token' => $this->token($I),
            'id' => 0,
            'product_id' => (string) $seed['short']->getId(),
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'reorder_point' => '',
        ]);

        $I->assertFalse(
            $this->levelRow($I, $seed['short'], $seed['warehouse']),
            'nothing was written — a blank box is not a level of zero',
        );
    }

    /**
     * #777: a negative reorder quantity or safety stock is refused, not silently dropped to blank.
     *
     * Before this, a negative or garbage value in either box was coerced to null exactly like an
     * empty one — the row still saved, just with the field quietly reading "not set" instead of
     * carrying the mistyped number back to the admin. Neither figure has any more physical meaning
     * negative than the reorder level itself does, which this screen already refuses (the test
     * above).
     */
    public function aNegativeReorderOrSafetyStockQuantityIsRefusedRatherThanDropped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10, [
            'reorder_quantity' => '-7',
            'safety_stock_quantity' => '3',
        ]);
        $I->assertFalse(
            $this->levelRow($I, $seed['short'], $seed['warehouse']),
            'a negative reorder quantity refuses the whole save, same as a negative reorder level would',
        );

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10, [
            'reorder_quantity' => '5',
            'safety_stock_quantity' => '-3',
        ]);
        $I->assertFalse(
            $this->levelRow($I, $seed['short'], $seed['warehouse']),
            'a negative safety stock refuses the whole save too, not just the field it names',
        );

        // Positive control: the same pair, the same screen, both quantities merely made positive.
        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10, [
            'reorder_quantity' => '7',
            'safety_stock_quantity' => '3',
        ]);
        $row = $this->levelRow($I, $seed['short'], $seed['warehouse']);
        $I->assertNotFalse($row, 'the identical save with positive quantities goes through');
        $I->assertSame(7, (int) $row['reorder_quantity']);
        $I->assertSame(3, (int) $row['safety_stock_quantity']);
    }

    /** One pair, one level. A second create for the same pair edits the first rather than duplicating it. */
    public function aSecondLevelForTheSamePairIsSentToTheRowThatAlreadyExists(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10);
        $this->setLevel($I, $seed['short'], $seed['warehouse'], 99);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $I->assertSame(
            1,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM inventory_reorder_rule WHERE product_id = ? AND warehouse_id = ?',
                [$seed['short']->getId(), $seed['warehouse']->getId()],
            ),
            'the pair is unique — the second attempt must not fork a row',
        );

        $I->assertSame(10, (int) $this->levelRow($I, $seed['short'], $seed['warehouse'])['reorder_point'],
            'and it did not silently overwrite the level either; it sent the admin to the existing row');
    }

    // ------------------------------------------------------------------ the screen

    /**
     * The whole point of the issue, on one screen.
     *
     * SHORT-x: 4 available, 0 incoming, level 10 — flagged short by 6.
     * COVERED-x: 4 available, 50 incoming, level 10 — below its level, but the order already covers
     * it, so it is NOT on the list of things to act on.
     */
    public function aShortRowIsFlaggedAndOneCoveredByAPurchaseOrderIsNot(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10);
        $this->setLevel($I, $seed['covered'], $seed['warehouse'], 10);

        // The default view is the one somebody has to act on.
        $I->amOnPage(self::URL);
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['short']->getSku(), self::GRID);
        $I->dontSee($seed['covered']->getSku(), self::GRID);

        // Both are below their level; the screen says which is which rather than hiding one.
        $I->amOnPage(self::URL . '?filters%5Bshow%5D=below');
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['short']->getSku(), self::GRID);
        $I->see($seed['covered']->getSku(), self::GRID);
        $I->see('Covered by incoming');

        $I->amOnPage(self::URL . '?filters%5Bshow%5D=covered');
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['covered']->getSku(), self::GRID);
        $I->dontSee($seed['short']->getSku(), self::GRID);
    }

    /** Filter state is in the URL, so a copied link reproduces the exact result. */
    public function everyFilterLivesInTheUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        // Both are managed, so the filter is the only thing that can be keeping one off the page.
        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10);
        $this->setLevel($I, $seed['covered'], $seed['warehouse'], 10);

        $I->amOnPage(self::URL . '?filters%5Bproduct%5D=' . $seed['short']->getSku() . '&filters%5Bshow%5D=all&limit=20&sort=available&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('filters[product]', $seed['short']->getSku());
        $I->see($seed['short']->getSku(), self::GRID);
        // The covered row is managed and would be listed, so only the product filter can be
        // keeping it out of the grid. Scoped to the grid because the picker's <select> puts every
        // SKU on the page regardless of the filter (#8).
        $I->dontSee($seed['covered']->getSku(), self::GRID);
    }

    /**
     * A product filter that matches nothing gives the empty state, with the column count the header
     * and filter rows agree on.
     */
    public function theEmptyStateSpansEveryColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I);

        $I->amOnPage(self::URL . '?filters%5Bproduct%5D=NOTHING-MATCHES-THIS');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('td.empty-table-cell[colspan="9"]');
        $I->seeNumberOfElements('thead tr:first-child th', 9);
        $I->seeNumberOfElements('thead tr.filter-row th', 9);
    }

    /** A real submit button, or implicit submission is off and the filter row cannot be used without JavaScript. */
    public function theFilterRowHasARealSubmitButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I);

        $I->amOnPage(self::URL);
        $I->seeElement('form#low-stock-filters');
        $I->seeElement('tr.filter-row button[type="submit"]');
        // Server-side paging, and no JavaScript pager on a list screen.
        $I->seeElement('section.table-card.no-paginate');
    }

    // ------------------------------------------------------------------ procurement off

    /**
     * With ProcurementBundle Inactive, `incoming_quantity` is no longer maintained — so it is read
     * as 0, and the row that was covered a moment ago is short again.
     *
     * The COLUMN is untouched. `IncomingStockReconciler` zeroes nothing on the way out and neither
     * does this; what changes is whether the figure is trusted, which is the same distinction
     * `BundleBucketAvailabilityGate` draws for `received`. Asserting the column separately is the
     * only way to tell "not counted" from "destroyed".
     */
    public function withProcurementOffACoveredRowIsShortAgainAndTheColumnIsUntouched(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['covered'], $seed['warehouse'], 10);

        $I->amOnPage(self::URL);
        // Covered by the 50 on order, so not on the list of things to act on.
        $I->dontSee($seed['covered']->getSku(), self::GRID);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $status = $em->getRepository(BundleStatus::class)->findOneBy(['source' => 'ProcurementBundle']);
        if (!$status instanceof BundleStatus) {
            $status = (new BundleStatus())->setSource('ProcurementBundle');
            $em->persist($status);
        }
        $status->setStatus(BundleStatus::STATUS_INACTIVE);
        $em->flush();
        // The gate stamps its flags on postLoad. Without this, the next query returns the same
        // identity-map instance carrying the flag it was built with and the toggle appears to do
        // nothing — the trap InventoryReceivedBucketToggleCest records.
        $em->clear();

        $I->amOnPage(self::URL);
        $I->seeResponseCodeIsSuccessful();
        // Nothing is counting what is coming, so the gap is real again.
        $I->see($seed['covered']->getSku(), self::GRID);
        $I->see('Nothing is maintaining');
        $I->see('not counted');

        $I->assertSame(
            50,
            (int) $em->getConnection()->fetchOne(
                'SELECT incoming_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
                [$seed['covered']->getId(), $seed['warehouse']->getId()],
            ),
            'the column still holds 50 — it is excluded from the reading, not destroyed',
        );
    }

    // ------------------------------------------------------------------ stopping

    /** Stopping management deletes the level and changes no stock figure. */
    public function stoppingManagementDeletesTheLevelAndTouchesNoStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $this->setLevel($I, $seed['short'], $seed['warehouse'], 10);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $ruleId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM inventory_reorder_rule WHERE product_id = ? AND warehouse_id = ?',
            [$seed['short']->getId(), $seed['warehouse']->getId()],
        );

        $I->amOnPage(self::URL);
        $token = (string) $I->grabAttributeFrom('#stop-managing-' . $ruleId . ' input[name="_token"]', 'value');
        $I->sendFormPostRequest(self::URL . '/' . $ruleId . '/delete', ['_token' => $token]);

        $I->assertFalse($this->levelRow($I, $seed['short'], $seed['warehouse']), 'the level is gone');
        $I->assertSame(
            4,
            (int) $em->getConnection()->fetchOne(
                'SELECT quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
                [$seed['short']->getId(), $seed['warehouse']->getId()],
            ),
            'and the stock figure it was measured against is exactly as it was',
        );
    }

    /** The bundle's kill-switch reaches this screen like every other one here. */
    public function turningTheBundleInactiveHidesTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $status = $em->getRepository(BundleStatus::class)->findOneBy(['source' => 'InventoryDepthBundle']);
        if (!$status instanceof BundleStatus) {
            $status = (new BundleStatus())->setSource('InventoryDepthBundle');
            $em->persist($status);
        }
        $status->setStatus(BundleStatus::STATUS_INACTIVE);
        $em->flush();

        $I->amOnPage(self::URL);
        $I->seeResponseCodeIs(404);
    }
}
