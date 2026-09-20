<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Service\Uom\ProductAvailableUnitService;
use App\Service\Uom\ProductBaseUnitService;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Breaking a case open, and rebuilding one — conducted through the real screen (#22, #624).
 *
 * ## The distinction this whole file exists to protect
 *
 * A **unit of measure** converts a quantity WITHIN one SKU. Forty boxes of twelve is four hundred
 * and eighty of the same product: one `product_inventory` row, one balance, no movement, no date and
 * no actor. A **pack conversion** moves stock BETWEEN two SKUs — two products, two balances — and is
 * therefore a physical event recorded exactly like a transfer.
 *
 * `aUnitOfMeasureConversionOnOneSkuMovesNoStockAtAll()` is the guard that keeps the two apart, and it
 * is deliberately the SAME twelve in both halves of this file: the same product, expressed as
 * `BOX-12` on an order line, moves nothing; expressed as a declared pack between two SKUs, it moves
 * everything. `aPackCannotBeDeclaredBetweenAProductAndItself()` closes the same door from the other
 * side — the typo that would let a dropdown relocate inventory.
 *
 * ## Everything here is driven through the real screens with plain form POSTs (#624)
 *
 * Packs are declared through `/pack-conversion/declare`, conversions run through
 * `/pack-conversion/run`, and order lines through `/admin/order/edit/{id}` — every one of them via
 * `sendFormPostRequest()`, i.e. no `X-Requested-With`, which is what a browser with scripting off
 * sends. The CSRF token is scraped off the rendered form each time and posted back, so these go
 * THROUGH the app's CSRF check rather than round it. `submitForm()` cannot be used on an admin screen
 * at all — with the `admin.localhost` Host header the crawler resolves the form action as an
 * absolute URL and the module refuses it as external.
 *
 * ## Every figure is re-read from the database, by column, after the operation
 *
 * Never off an entity fetched beforehand: the identity map would happily hand back the object the
 * request already mutated, which proves nothing about what was stored. `quantity()` and `column()`
 * go to the connection.
 *
 * **No assertion in this file is `see()` on a number (#627).** This feature is nothing but
 * quantities and costs — `see('12')` would match a pack size, a unit count, a price and an id — so
 * every numeric claim is an `assertSame` against `table.column`, and the page-level assertions are
 * made on wording that carries no digit.
 *
 * ## The row that should NOT have changed
 *
 * Every scenario builds a THIRD product, stocked in the same warehouse and the same bin, that no
 * conversion names. It is asserted untouched — detail balance and `product_inventory` bucket — after
 * every operation, including the refusals.
 */
final class InventoryDepthPackConversionCest
{
    private const SCREEN = '/admin/bundles/inventory-depth/pack-conversion';

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I): string
    {
        $email = 'pack-conversion-' . uniqid() . '@example.test';

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $email;
    }

    /**
     * A warehouse of this test's own with one bin in it.
     *
     * Ids rather than entities, deliberately: a page request through the Symfony module can reset
     * the container — and with it the EntityManager — so a fixture object held across one comes back
     * DETACHED, and the next thing that persists a reference to it dies with "a new entity was found
     * through the relationship". Every helper below looks its subjects up again.
     *
     * @return array{warehouseId: int, binId: int, warehouseName: string}
     */
    private function warehouse(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Pack Conversion ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('PC-01')->setSortKey(10);
        $em->persist($bin);
        $em->flush();

        return [
            'warehouseId' => (int) $warehouse->getId(),
            'binId' => (int) $bin->getId(),
            'warehouseName' => $warehouse->getName(),
        ];
    }

    /**
     * One dimensional product holding exactly $units in this test's bin, at $costPrice.
     *
     * `product_inventory.quantity` opens at ZERO and every unit arrives through a real receipt in the
     * movement layer, so `inventory_detail` is what the conversion later reads rather than a number
     * this test wrote into a column by hand. That also makes `received_quantity` a figure with a
     * known history: it is exactly what this method put on the shelf, so a conversion's effect on it
     * is readable as a delta.
     *
     * @param array{warehouseId: int, binId: int, warehouseName: string} $where
     *
     * @return int the product id
     */
    private function stockedProduct(FunctionalTester $I, array $where, string $sku, int $units, ?string $costPrice = null): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Pack conversion ' . $sku)
            ->setCostPrice($costPrice)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist(
            (new ProductInventory())
                ->setProduct($product)
                ->setWarehouse($em->find(Warehouse::class, $where['warehouseId']))
                ->setQuantity(0)
        );
        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'pack-conversion-fixture@example.test');

        if ($units > 0) {
            $I->grabService(StockMovementService::class)->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'pack-fixture-' . uniqid(), 'Put away for the pack conversion test')
                    ->receive(
                        $product,
                        new DetailKey(
                            $em->find(Warehouse::class, $where['warehouseId']),
                            $em->find(WarehouseLocation::class, $where['binId']),
                            null,
                            null,
                            InventoryDetail::STATUS_AVAILABLE,
                        ),
                        $units,
                    )
            );
        }

        return (int) $product->getId();
    }

    // ── reading the database back ───────────────────────────────────────────────────────────

    /** One scalar, read from the connection after the operation rather than off a held entity. */
    private function column(FunctionalTester $I, string $sql, array $params = []): ?string
    {
        $value = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $params);

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * `SUM(inventory_detail.quantity)` for one (product, warehouse) at `available` — the balance.
     *
     * Cast through float then to int because the column is `NUMERIC(14, 4)` since #645 and comes back
     * as `3.0000`; a string comparison would be asserting the column's scale rather than its value.
     */
    private function available(FunctionalTester $I, int $productId, int $warehouseId): int
    {
        return (int) round((float) $this->column(
            $I,
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = 'available'",
            [$productId, $warehouseId],
        ));
    }

    /** One `product_inventory` bucket, by column name, for one pair. */
    private function bucket(FunctionalTester $I, string $column, int $productId, int $warehouseId): int
    {
        return (int) round((float) $this->column(
            $I,
            sprintf('SELECT %s FROM product_inventory WHERE product_id = ? AND warehouse_id = ?', $column),
            [$productId, $warehouseId],
        ));
    }

    /**
     * One `inventory_pack_conversion` money column as an integer count of micro-units.
     *
     * The column is `NUMERIC(18, 6)` and SQLite gives a NUMERIC column numeric affinity, so
     * `25.000000` comes back as `25` while `2.083333` comes back in full — a string comparison would
     * be asserting SQLite's storage class rather than the figure. Micro-units are also the unit the
     * residue is measured in, and it is four of them.
     */
    private function micros(FunctionalTester $I, string $column, int $groupId): int
    {
        return (int) round(
            ((float) $this->column($I, sprintf('SELECT %s FROM inventory_pack_conversion WHERE group_id = ?', $column), [$groupId])) * 1000000
        );
    }

    private function count(FunctionalTester $I, string $sql, array $params = []): int
    {
        return (int) $this->column($I, $sql, $params);
    }

    // ── driving the screens ─────────────────────────────────────────────────────────────────

    /**
     * Declares a pack through the real form and hands back its `inventory_pack_rule.id`.
     *
     * The token comes off the rendered declaration form rather than out of a service, so a form that
     * stopped emitting one would fail here rather than pass on a token the page never had.
     */
    private function declarePack(FunctionalTester $I, int $caseProductId, int $unitProductId, int $unitsPerCase, bool $rebuildAllowed = false): int
    {
        $I->amOnPage(self::SCREEN);
        $I->seeResponseCodeIsSuccessful();

        $params = [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/declare"] input[name="_token"]', 'value'),
            'id' => '0',
            'case_product_id' => (string) $caseProductId,
            'unit_product_id' => (string) $unitProductId,
            'units_per_case' => (string) $unitsPerCase,
        ];
        if ($rebuildAllowed) {
            $params['rebuild_allowed'] = '1';
        }

        $I->sendFormPostRequest(self::SCREEN . '/declare', $params);
        $I->seeResponseCodeIsSuccessful();

        return (int) $this->column(
            $I,
            'SELECT id FROM inventory_pack_rule WHERE case_product_id = ?',
            [$caseProductId],
        );
    }

    /**
     * Runs one conversion through the real form.
     *
     * @param array{warehouseId: int, binId: int, warehouseName: string} $where
     */
    private function convert(FunctionalTester $I, array $where, int $ruleId, string $direction, int $cases): void
    {
        $I->amOnPage(self::SCREEN);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::SCREEN . '/run', [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/run"] input[name="_token"]', 'value'),
            'rule_id' => (string) $ruleId,
            'warehouse_id' => (string) $where['warehouseId'],
            'direction' => $direction,
            'cases' => (string) $cases,
            'reference' => 'WO-PACK',
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    // ── 1. both balances move, and a third product does not ─────────────────────────────────

    /**
     * **Breaking 3 cases decreases the case SKU by 3 and increases the unit SKU by 36.**
     *
     * Both sides re-read by column afterwards, on `inventory_detail` (the real balance) AND on
     * `product_inventory.received_quantity` (this app's accumulated delta against the client's own
     * figure, which is where a conversion lands because the external system knows about neither
     * side). A third product, stocked in the same warehouse and the same bin and named by nothing,
     * is asserted untouched on both.
     */
    public function breakingThreeCasesMovesBothBalancesAndLeavesAnUnrelatedProductAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $caseId = $this->stockedProduct($I, $where, 'PC-CASE-A', 5, '24.000000');
        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-A', 0);
        $otherId = $this->stockedProduct($I, $where, 'PC-OTHER-A', 7, '3.000000');

        $ruleId = $this->declarePack($I, $caseId, $unitId, 12);

        $I->assertSame(5, $this->available($I, $caseId, $where['warehouseId']), 'the case SKU starts at five cases');
        $I->assertSame(0, $this->available($I, $unitId, $where['warehouseId']), 'and the unit SKU at none');

        $this->convert($I, $where, $ruleId, 'break', 3);

        $I->assertSame(2, $this->available($I, $caseId, $where['warehouseId']), 'inventory_detail.quantity: five cases less three');
        $I->assertSame(36, $this->available($I, $unitId, $where['warehouseId']), 'inventory_detail.quantity: three cases of twelve');

        // The bucket the movement layer accumulates. Five arrived through the fixture receipt and
        // three left through the conversion; thirty-six arrived at the unit SKU that nothing had
        // ever put stock into.
        $I->assertSame(2, $this->bucket($I, 'received_quantity', $caseId, $where['warehouseId']), 'product_inventory.received_quantity on the case SKU');
        $I->assertSame(36, $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']), 'product_inventory.received_quantity on the unit SKU');

        // The client's own figure is never touched by this layer, on either side.
        $I->assertSame(0, $this->bucket($I, 'quantity', $caseId, $where['warehouseId']), 'product_inventory.quantity is the imported figure and no conversion writes it');
        $I->assertSame(0, $this->bucket($I, 'quantity', $unitId, $where['warehouseId']));

        // THE ROW THAT SHOULD NOT HAVE CHANGED.
        $I->assertSame(7, $this->available($I, $otherId, $where['warehouseId']), 'the unrelated product in the same bin still holds what it held');
        $I->assertSame(7, $this->bucket($I, 'received_quantity', $otherId, $where['warehouseId']), 'and its bucket did not move either');
        $I->assertSame(
            0,
            $this->count($I, 'SELECT COUNT(*) FROM inventory_movement WHERE product_id = ? AND group_id IN (SELECT id FROM inventory_movement_group WHERE type = ?)', [$otherId, 'pack_convert']),
            'and no conversion movement names it at all',
        );
    }

    // ── 2. one group, one reason, one actor ─────────────────────────────────────────────────

    /**
     * **Both movements land under ONE group, with the same reason and the same actor.**
     *
     * Asserted on the linkage rather than on the two balances: the balances would be identical if
     * somebody wrote two unrelated groups, which is exactly the state this feature exists to replace.
     * So this checks that `inventory_movement.group_id` is the SAME id on both rows, that the group
     * carries the new type, a reason and the logged-in admin, and that
     * `inventory_pack_conversion.group_id` names that same group once.
     */
    public function bothMovementsLandUnderOneGroupCarryingOneReasonAndOneActor(FunctionalTester $I): void
    {
        $email = $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $caseId = $this->stockedProduct($I, $where, 'PC-CASE-B', 4, '24.000000');
        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-B', 0);
        $otherId = $this->stockedProduct($I, $where, 'PC-OTHER-B', 9);

        $ruleId = $this->declarePack($I, $caseId, $unitId, 12);
        $this->convert($I, $where, $ruleId, 'break', 3);

        $groupId = (int) $this->column($I, "SELECT id FROM inventory_movement_group WHERE type = 'pack_convert'");
        $I->assertGreaterThan(0, $groupId, 'one group of the new type was written');

        $I->assertSame(
            1,
            $this->count($I, "SELECT COUNT(*) FROM inventory_movement_group WHERE type = 'pack_convert'"),
            'and exactly one — a conversion is one operation, not two',
        );

        // Two movements, both under that one group.
        $I->assertSame(2, $this->count($I, 'SELECT COUNT(*) FROM inventory_movement WHERE group_id = ?', [$groupId]));
        // And the case SKU is touched by exactly two groups in its whole history — the fixture's
        // receipt and this conversion — while the unit SKU is touched by this one alone. Two
        // unrelated adjustments, which is what this feature replaces, would read as three and two.
        $I->assertSame(2, $this->count($I, 'SELECT COUNT(DISTINCT group_id) FROM inventory_movement WHERE product_id = ?', [$caseId]));
        $I->assertSame(1, $this->count($I, 'SELECT COUNT(DISTINCT group_id) FROM inventory_movement WHERE product_id = ?', [$unitId]));

        // The case side LEAVES (to_detail_id NULL); the unit side ARRIVES (from_detail_id NULL). An
        // inventory_movement row carries one product_id, so two products is two rows by construction
        // — which is why this needed no new ledger.
        $I->assertSame(
            3,
            (int) round((float) $this->column($I, 'SELECT quantity FROM inventory_movement WHERE group_id = ? AND product_id = ? AND to_detail_id IS NULL', [$groupId, $caseId])),
            'inventory_movement.quantity out of the case SKU',
        );
        $I->assertSame(
            36,
            (int) round((float) $this->column($I, 'SELECT quantity FROM inventory_movement WHERE group_id = ? AND product_id = ? AND from_detail_id IS NULL', [$groupId, $unitId])),
            'inventory_movement.quantity into the unit SKU',
        );

        // One reason and one actor, because there is one group row holding both movements.
        $I->assertSame($email, $this->column($I, 'SELECT actor FROM inventory_movement_group WHERE id = ?', [$groupId]), 'inventory_movement_group.actor is who was logged in');
        $I->assertSame('WO-PACK', $this->column($I, 'SELECT reference FROM inventory_movement_group WHERE id = ?', [$groupId]), 'inventory_movement_group.reference');
        $reason = (string) $this->column($I, 'SELECT reason FROM inventory_movement_group WHERE id = ?', [$groupId]);
        $I->assertNotSame('', $reason, 'inventory_movement_group.reason says what happened');
        $I->assertStringContainsString('Broke open', $reason);

        // And the header row, joined 1:1 to that same group.
        $I->assertSame((string) $groupId, $this->column($I, 'SELECT group_id FROM inventory_pack_conversion WHERE rule_id = ?', [$ruleId]), 'inventory_pack_conversion.group_id');
        $I->assertSame('break', $this->column($I, 'SELECT direction FROM inventory_pack_conversion WHERE group_id = ?', [$groupId]));
        $I->assertSame(3, (int) $this->column($I, 'SELECT cases FROM inventory_pack_conversion WHERE group_id = ?', [$groupId]));
        $I->assertSame(12, (int) $this->column($I, 'SELECT units_per_case FROM inventory_pack_conversion WHERE group_id = ?', [$groupId]));

        // THE ROW THAT SHOULD NOT HAVE CHANGED.
        $I->assertSame(9, $this->available($I, $otherId, $where['warehouseId']));
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_movement WHERE group_id = ? AND product_id = ?', [$groupId, $otherId]));
    }

    // ── 3. more than is on hand is refused, and NEITHER side moves ──────────────────────────

    /**
     * **Breaking 3 cases when 2 are on hand is refused, and neither balance moves.**
     *
     * The refusal has to be total, not partial: a design that decremented the case SKU and then
     * discovered the problem would leave stock nowhere at all. `StockMovementService` raises
     * `InsufficientStockException` from a pure read BEFORE its transaction opens, so nothing is
     * written to roll back — and because both sides are ONE request, the destination is never
     * reached either.
     */
    public function breakingMoreCasesThanAreOnHandIsRefusedAndNeitherBalanceMoves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $caseId = $this->stockedProduct($I, $where, 'PC-CASE-C', 2, '24.000000');
        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-C', 0);
        $otherId = $this->stockedProduct($I, $where, 'PC-OTHER-C', 6);

        $ruleId = $this->declarePack($I, $caseId, $unitId, 12);

        $this->convert($I, $where, $ruleId, 'break', 3);

        // The wording, with no digit in it — #627's rule applied to a message that is mostly numbers.
        $I->see('requested unit(s)');

        $I->assertSame(2, $this->available($I, $caseId, $where['warehouseId']), 'the case SKU still holds both cases');
        $I->assertSame(0, $this->available($I, $unitId, $where['warehouseId']), 'and not one unit was created at the other end');
        $I->assertSame(2, $this->bucket($I, 'received_quantity', $caseId, $where['warehouseId']), 'product_inventory.received_quantity on the case SKU is untouched');
        $I->assertSame(0, $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']), 'and on the unit SKU');

        $I->assertSame(
            0,
            $this->count($I, "SELECT COUNT(*) FROM inventory_movement_group WHERE type = 'pack_convert'"),
            'no group was written at all — this is a refusal, not a rollback of something half-done',
        );
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_conversion'));

        // THE ROW THAT SHOULD NOT HAVE CHANGED.
        $I->assertSame(6, $this->available($I, $otherId, $where['warehouseId']));
        $I->assertSame(6, $this->bucket($I, 'received_quantity', $otherId, $where['warehouseId']));
    }

    // ── 4. the round trip ───────────────────────────────────────────────────────────────────

    /**
     * **Break, then unbreak, and both balances AND the total value are exactly where they started.**
     *
     * The sharpest test of the feature, and the pack size is chosen so it cannot pass by luck:
     * `25.000000 / 12` is `2.083333` recurring, so twelve units carry `24.999996` and a case is short
     * by four micro-units every single time it is opened. A design that anchored the rebuild on the
     * unit figure would give the case back at `24.999996` and lose that fraction on every round trip;
     * anchoring BOTH directions on the case is what makes the two residues cancel.
     *
     * The value is computed from columns the operation actually wrote — the balances from
     * `inventory_detail`, the two costs from `inventory_pack_conversion` — in integer micro-units, so
     * "exactly" means exactly rather than "to within a float".
     */
    public function breakingAndThenRebuildingReturnsBothBalancesAndTheTotalValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $caseId = $this->stockedProduct($I, $where, 'PC-CASE-D', 5, '25.000000');
        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-D', 0);
        $otherId = $this->stockedProduct($I, $where, 'PC-OTHER-D', 4);

        $ruleId = $this->declarePack($I, $caseId, $unitId, 12, true);

        $startingCases = $this->available($I, $caseId, $where['warehouseId']);
        $startingUnits = $this->available($I, $unitId, $where['warehouseId']);
        $startingCaseBucket = $this->bucket($I, 'received_quantity', $caseId, $where['warehouseId']);
        $startingUnitBucket = $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']);

        $this->convert($I, $where, $ruleId, 'break', 1);

        $I->assertSame(4, $this->available($I, $caseId, $where['warehouseId']), 'one case was opened');
        $I->assertSame(12, $this->available($I, $unitId, $where['warehouseId']), 'and twelve units came out of it');

        $breakGroup = (int) $this->column($I, "SELECT id FROM inventory_movement_group WHERE type = 'pack_convert' ORDER BY id ASC");
        $I->assertSame(25000000, $this->micros($I, 'case_cost', $breakGroup), 'inventory_pack_conversion.case_cost is the case SKU cost at the time');
        $I->assertSame(2083333, $this->micros($I, 'unit_cost', $breakGroup), 'inventory_pack_conversion.unit_cost is that cost over twelve, half-up at six places');

        $this->convert($I, $where, $ruleId, 'rebuild', 1);

        // Balances, by column, back to the figures this test started from.
        $I->assertSame($startingCases, $this->available($I, $caseId, $where['warehouseId']), 'inventory_detail.quantity on the case SKU is back where it started');
        $I->assertSame($startingUnits, $this->available($I, $unitId, $where['warehouseId']), 'and the unit SKU holds none again');
        $I->assertSame($startingCaseBucket, $this->bucket($I, 'received_quantity', $caseId, $where['warehouseId']), 'product_inventory.received_quantity on the case SKU too');
        $I->assertSame($startingUnitBucket, $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']));

        // Two conversions, opposite directions, one rule.
        $I->assertSame(2, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_conversion WHERE rule_id = ?', [$ruleId]));
        $rebuildGroup = (int) $this->column($I, "SELECT id FROM inventory_movement_group WHERE type = 'pack_convert' ORDER BY id DESC");
        $I->assertNotSame($breakGroup, $rebuildGroup, 'the unbreak is its own operation, not an edit of the break');
        $I->assertSame('rebuild', $this->column($I, 'SELECT direction FROM inventory_pack_conversion WHERE group_id = ?', [$rebuildGroup]));

        // The rebuild derives the SAME two figures from the SAME anchor, which is what makes the
        // residues equal and opposite.
        $I->assertSame(25000000, $this->micros($I, 'case_cost', $rebuildGroup));
        $I->assertSame(2083333, $this->micros($I, 'unit_cost', $rebuildGroup));

        // THE VALUE, in integer micro-units, from the columns the operations wrote.
        //
        //   break    out 1 x 25.000000 = 25.000000   in 12 x 2.083333 = 24.999996   residue -0.000004
        //   rebuild  out 12 x 2.083333 = 24.999996   in  1 x 25.000000 = 25.000000  residue +0.000004
        //
        // A round trip that quietly created or destroyed value would show up as a non-zero sum here
        // while every balance above still matched, which is precisely the failure this asserts.
        $breakResidue = $this->micros($I, 'unit_cost', $breakGroup) * 12 - $this->micros($I, 'case_cost', $breakGroup);
        $rebuildResidue = $this->micros($I, 'case_cost', $rebuildGroup) - $this->micros($I, 'unit_cost', $rebuildGroup) * 12;

        $I->assertSame(-4, $breakResidue, 'breaking a case of twelve costing twenty-five leaves the units four micro-units short of it');
        $I->assertSame(4, $rebuildResidue, 'and rebuilding it puts exactly those four back');
        $I->assertSame(0, $breakResidue + $rebuildResidue, 'so the round trip neither created nor destroyed value');

        // And the value on the shelf, recomputed from the balances and the recorded costs.
        $caseCost = $this->micros($I, 'case_cost', $rebuildGroup);
        $unitCost = $this->micros($I, 'unit_cost', $rebuildGroup);
        $I->assertSame(
            $startingCases * $caseCost + $startingUnits * $unitCost,
            $this->available($I, $caseId, $where['warehouseId']) * $caseCost
                + $this->available($I, $unitId, $where['warehouseId']) * $unitCost,
            'the value standing in these two SKUs is the value it was before the case was ever opened',
        );

        // THE ROW THAT SHOULD NOT HAVE CHANGED, across both operations.
        $I->assertSame(4, $this->available($I, $otherId, $where['warehouseId']));
        $I->assertSame(4, $this->bucket($I, 'received_quantity', $otherId, $where['warehouseId']));
    }

    // ── 5. the guard that keeps a unit of measure out of the inventory layer ─────────────────

    /**
     * **A unit-of-measure conversion on a single SKU still moves no stock at all.**
     *
     * The same twelve, the other way round. `PC-UNIT-E` is expressed as `PACK-UOM-12` on a real order
     * line through the real order form — three boxes, which `sales_order_line.quantity` stores as
     * thirty-six base units — and not one figure in the inventory layer changes: no detail row, no
     * bucket, no movement, no movement group, no pack conversion.
     *
     * That is the whole distinction stated as an assertion. Expressed as a unit, twelve is arithmetic
     * on a document line. Expressed as a pack between two SKUs, the same twelve is a physical event
     * with a date and an actor. If a unit conversion could ever move stock, a mis-picked dropdown
     * would relocate inventory with nothing written down — so the counts below are snapshotted before
     * the line is saved and compared after.
     */
    public function aUnitOfMeasureConversionOnOneSkuMovesNoStockAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-E', 60, '2.000000');

        $em = $I->grabService(EntityManagerInterface::class);
        $each = $this->unit($I, 'EA', 'Each', '1');
        $box = $this->unit($I, 'PACK-UOM-12', 'Box of twelve', '12');

        $product = $em->find(ProductCore::class, $unitId);
        $I->grabService(ProductBaseUnitService::class)->assign($product, $each);
        $em->flush();
        $I->grabService(ProductAvailableUnitService::class)->apply($product, [(int) $box->getId()], null);
        $em->flush();

        $company = (new Company())
            ->setName('Pack Conversion Buyer ' . uniqid())
            ->setCode('PCB-' . strtoupper(substr(uniqid(), -6)))
            ->setPrimaryEmail('buyer@pack-conversion.example');
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        // Everything the inventory layer holds about this product, before the line is written.
        $beforeDetail = $this->available($I, $unitId, $where['warehouseId']);
        $beforeReceived = $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']);
        $beforeQuantity = $this->bucket($I, 'quantity', $unitId, $where['warehouseId']);
        $beforeMovements = $this->count($I, 'SELECT COUNT(*) FROM inventory_movement WHERE product_id = ?', [$unitId]);
        $beforeGroups = $this->count($I, 'SELECT COUNT(*) FROM inventory_movement_group');
        $beforeDetailRows = $this->count($I, 'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ?', [$unitId]);

        $I->amOnPage('/admin/order/create');
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-PACK-UOM',
            'lines' => [
                ['product_id' => (string) $unitId, 'sku' => 'PC-UNIT-E', 'qty' => '1', 'price' => '2.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $orderId = (int) $order->getId();
        $lineId = (int) $this->column($I, 'SELECT id FROM sales_order_line WHERE order_id = ?', [$orderId]);

        // Now say it in boxes: three of them, through the row's own unit selector.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-PACK-UOM',
            'lines' => [
                [
                    'id' => (string) $lineId,
                    'product_id' => (string) $unitId,
                    'sku' => 'PC-UNIT-E',
                    'unit_id' => (string) $box->getId(),
                    'qty' => '3',
                    'qty_rendered' => '1',
                    'price' => '24.00',
                    'price_rendered' => '2.00',
                ],
            ],
            'save_mode' => 'draft_recalc',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // The conversion genuinely happened — on the DOCUMENT.
        $I->assertSame(36, (int) round((float) $this->column($I, 'SELECT quantity FROM sales_order_line WHERE id = ?', [$lineId])), 'sales_order_line.quantity is the base figure: three boxes of twelve');
        $I->assertSame(3, (int) round((float) $this->column($I, 'SELECT quantity_entered FROM sales_order_line WHERE id = ?', [$lineId])), 'sales_order_line.quantity_entered is what the human said');
        $I->assertSame((string) $box->getId(), $this->column($I, 'SELECT unit_id FROM sales_order_line WHERE id = ?', [$lineId]), 'sales_order_line.unit_id names the term');

        // AND NOT ONE FIGURE IN THE INVENTORY LAYER MOVED.
        $I->assertSame($beforeDetail, $this->available($I, $unitId, $where['warehouseId']), 'inventory_detail.quantity is untouched by a unit conversion');
        $I->assertSame($beforeDetailRows, $this->count($I, 'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ?', [$unitId]), 'and no detail row was created either');
        $I->assertSame($beforeReceived, $this->bucket($I, 'received_quantity', $unitId, $where['warehouseId']), 'product_inventory.received_quantity is untouched');
        $I->assertSame($beforeQuantity, $this->bucket($I, 'quantity', $unitId, $where['warehouseId']), 'product_inventory.quantity is untouched');
        $I->assertSame($beforeMovements, $this->count($I, 'SELECT COUNT(*) FROM inventory_movement WHERE product_id = ?', [$unitId]), 'no movement was written');
        $I->assertSame($beforeGroups, $this->count($I, 'SELECT COUNT(*) FROM inventory_movement_group'), 'and no movement group at all');
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_conversion'), 'a unit conversion is not a pack conversion and writes none');
    }

    /**
     * The same guard from the other side: a pack between a product and ITSELF is refused.
     *
     * That is the typo the whole distinction exists to survive — one dropdown picked wrong, and a
     * conversion would take stock out of a balance and put it straight back while writing two
     * movements and a group claiming something happened. The refusal names the screen that DOES do
     * what the operator was probably after.
     */
    public function aPackCannotBeDeclaredBetweenAProductAndItself(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $productId = $this->stockedProduct($I, $where, 'PC-SELF-F', 10, '24.000000');

        $I->amOnPage(self::SCREEN);
        $I->sendFormPostRequest(self::SCREEN . '/declare', [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/declare"] input[name="_token"]', 'value'),
            'id' => '0',
            'case_product_id' => (string) $productId,
            'unit_product_id' => (string) $productId,
            'units_per_case' => '12',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('that is a unit of measure');
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_rule'), 'no declaration was stored');
        $I->assertSame(10, $this->available($I, $productId, $where['warehouseId']), 'and the product still holds exactly what it held');

        // A pack of one is refused for the same reason, with a second product this time so the
        // self-reference check cannot be what is doing the work.
        $otherId = $this->stockedProduct($I, $where, 'PC-SELF-G', 0);
        $I->amOnPage(self::SCREEN);
        $I->sendFormPostRequest(self::SCREEN . '/declare', [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/declare"] input[name="_token"]', 'value'),
            'id' => '0',
            'case_product_id' => (string) $productId,
            'unit_product_id' => (string) $otherId,
            'units_per_case' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('which is a relabelling rather than a pack');
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_rule'), 'still no declaration');
    }

    // ── 6. rebuilding is opt-in ─────────────────────────────────────────────────────────────

    /**
     * **Twelve loose units are not necessarily a case.** A pack nobody marked rebuildable refuses the
     * unbreak, and neither balance moves — then the same pack, marked rebuildable through the same
     * form, allows it. The positive half is what proves the refusal was about the declaration and not
     * about the stock, which was sitting there the whole time.
     */
    public function rebuildingIsRefusedUntilSomebodyDeclaresThePackRebuildable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);

        $caseId = $this->stockedProduct($I, $where, 'PC-CASE-H', 0, '24.000000');
        $unitId = $this->stockedProduct($I, $where, 'PC-UNIT-H', 24, '2.000000');
        $otherId = $this->stockedProduct($I, $where, 'PC-OTHER-H', 5);

        $ruleId = $this->declarePack($I, $caseId, $unitId, 12, false);

        $this->convert($I, $where, $ruleId, 'rebuild', 1);

        $I->see('is not marked as rebuildable');
        $I->assertSame(24, $this->available($I, $unitId, $where['warehouseId']), 'the loose units are all still loose');
        $I->assertSame(0, $this->available($I, $caseId, $where['warehouseId']), 'and no case was formed');
        $I->assertSame(0, $this->count($I, "SELECT COUNT(*) FROM inventory_movement_group WHERE type = 'pack_convert'"));

        // Now say the pack can be re-formed, through the same form, and do it again.
        $I->amOnPage(self::SCREEN . '?edit=' . $ruleId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest(self::SCREEN . '/declare', [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/declare"] input[name="_token"]', 'value'),
            'id' => (string) $ruleId,
            'case_product_id' => (string) $caseId,
            'unit_product_id' => (string) $unitId,
            'units_per_case' => '12',
            'rebuild_allowed' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $this->convert($I, $where, $ruleId, 'rebuild', 1);

        $I->assertSame(12, $this->available($I, $unitId, $where['warehouseId']), 'twelve units went into the case');
        $I->assertSame(1, $this->available($I, $caseId, $where['warehouseId']), 'and one case came out');
        $I->assertSame(1, $this->count($I, "SELECT COUNT(*) FROM inventory_movement_group WHERE type = 'pack_convert'"), 'exactly one conversion happened across the two attempts');

        // THE ROW THAT SHOULD NOT HAVE CHANGED.
        $I->assertSame(5, $this->available($I, $otherId, $where['warehouseId']));
        $I->assertSame(5, $this->bucket($I, 'received_quantity', $otherId, $where['warehouseId']));
    }

    /**
     * The global term, created once per suite run and reused.
     *
     * Built as a fixture rather than through the Units of Measure screen because the subject here is
     * the INVENTORY layer, not the unit form — `UnitOfMeasureScreensCest` conducts that — and because
     * the caller assigns it to a product and flushes in the same breath, where a page request in
     * between would hand back an entity the next EntityManager does not know.
     */
    private function unit(FunctionalTester $I, string $code, string $name, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }

        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName($name)
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision('1');
        $I->haveInRepository($unit);

        return $unit;
    }
}
