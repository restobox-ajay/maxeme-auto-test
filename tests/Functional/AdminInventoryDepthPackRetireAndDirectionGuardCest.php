<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
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
 * Two independent guards on the pack-conversion screens (#776), each conducted through the real
 * screens exactly as `InventoryDepthPackConversionCest` is (plain form POSTs, a scraped CSRF token).
 *
 * ## Bug 1: retiring a pack with recorded conversions 500ed
 *
 * `PackConversionController::retire()` counted the rule's conversions, removed the rule, and flushed.
 * `inventory_pack_conversion.rule_id` is `ON DELETE SET NULL` at the DB level, but the conversions
 * `retire()` had just loaded (to count them) were still MANAGED in Doctrine's identity map, still
 * pointing at the `ProductPackRule` object that flush had just deleted. `AuditLogSubscriber`'s
 * `postFlush()` runs a second, nested flush for the audit-log row, and that second flush re-scans the
 * identity map: it finds those stale, still-associated rows and throws
 * `ORMInvalidArgumentException` — an unhandled 500. The fix nulls the association on the ORM side,
 * on every row this request loaded, before removing the rule.
 *
 * ## Bug 2: `direction=sideways` ran as a break
 *
 * `run()` computed `$rebuild = $direction === 'rebuild'`, so anything that was not literally
 * `'rebuild'` — a typo, a stale value, a tampered request — silently executed as `'break'`. Refused
 * now, against `PackConversion::directions()`, before either side of the ledger is touched.
 */
final class AdminInventoryDepthPackRetireAndDirectionGuardCest
{
    private const SCREEN = '/admin/bundles/inventory-depth/pack-conversion';

    public function retiringAPackWithARecordedConversionSucceedsInsteadOf500ing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);
        $caseProductId = $this->stockedProduct($I, $where, 'PRG-CASE-' . uniqid(), 3);
        $unitProductId = $this->stockedProduct($I, $where, 'PRG-UNIT-' . uniqid(), 0);
        $ruleId = $this->declarePack($I, $caseProductId, $unitProductId, 12);

        $this->convert($I, $where, $ruleId, 'break', 2);
        $groupId = $this->count($I, 'SELECT group_id FROM inventory_pack_conversion WHERE rule_id = ?', [$ruleId]);
        $I->assertGreaterThan(0, $groupId, 'guard: the conversion this test retires against was never recorded');

        $token = (string) $I->grabAttributeFrom('form[action$="/' . $ruleId . '/retire"] input[name="_token"]', 'value');
        $I->sendFormPostRequest(self::SCREEN . '/' . $ruleId . '/retire', ['_token' => $token]);

        // The bug: this used to be a bare 500 whenever $conversions > 0.
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeResponseCodeIs(500);
        $I->see('Stopped declaring');
        $I->see('keep their rows');

        $I->assertNull($this->column($I, 'SELECT id FROM inventory_pack_rule WHERE id = ?', [$ruleId]), 'the rule row was not removed');
        // The row that should NOT be gone: the conversion itself, cases and cost intact, only its
        // pointer to the now-deleted rule cleared.
        $I->assertSame(
            '2',
            $this->column($I, 'SELECT cases FROM inventory_pack_conversion WHERE group_id = ?', [$groupId]),
            'the retained conversion lost its own recorded figures',
        );
        $I->assertNull(
            $this->column($I, 'SELECT rule_id FROM inventory_pack_conversion WHERE group_id = ?', [$groupId]),
            'the retained conversion still points at a rule row that no longer exists',
        );
    }

    public function runningWithAnUnrecognisedDirectionIsRefusedRatherThanTreatedAsABreak(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);
        $caseProductId = $this->stockedProduct($I, $where, 'PRG-CASE-DIR-' . uniqid(), 5);
        $unitProductId = $this->stockedProduct($I, $where, 'PRG-UNIT-DIR-' . uniqid(), 0);
        $ruleId = $this->declarePack($I, $caseProductId, $unitProductId, 12);

        $I->amOnPage(self::SCREEN);
        $token = (string) $I->grabAttributeFrom('form[action$="/pack-conversion/run"] input[name="_token"]', 'value');
        $I->sendFormPostRequest(self::SCREEN . '/run', [
            '_token' => $token,
            'rule_id' => (string) $ruleId,
            'warehouse_id' => (string) $where['warehouseId'],
            'direction' => 'sideways',
            'cases' => '2',
            'reference' => 'WO-SIDEWAYS',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('Choose a direction');
        // Never ran as a break: neither balance moved, and no conversion row was written at all —
        // the bug ran 'sideways' as 'break' and moved both sides.
        $I->assertSame(0, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_conversion WHERE rule_id = ?', [$ruleId]));
        $I->assertSame(5, $this->available($I, $caseProductId, $where['warehouseId']), 'the case SKU moved despite the refusal');
        $I->assertSame(0, $this->available($I, $unitProductId, $where['warehouseId']), 'the unit SKU moved despite the refusal');
    }

    /** Positive control: a real, valid direction still runs after the guard was added. */
    public function aRecognisedDirectionStillRunsAfterTheGuardWasAdded(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $where = $this->warehouse($I);
        $caseProductId = $this->stockedProduct($I, $where, 'PRG-CASE-OK-' . uniqid(), 5);
        $unitProductId = $this->stockedProduct($I, $where, 'PRG-UNIT-OK-' . uniqid(), 0);
        $ruleId = $this->declarePack($I, $caseProductId, $unitProductId, 12);

        $this->convert($I, $where, $ruleId, 'break', 2);

        $I->assertSame(3, $this->available($I, $caseProductId, $where['warehouseId']));
        $I->assertSame(24, $this->available($I, $unitProductId, $where['warehouseId']));
        $I->assertSame(1, $this->count($I, 'SELECT COUNT(*) FROM inventory_pack_conversion WHERE rule_id = ?', [$ruleId]));
    }

    // ── fixtures, copied from InventoryDepthPackConversionCest's own (kept file-local so this
    //    stays a small, directed addition rather than a change to that file) ────────────────

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('pack-retire-guard-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{warehouseId: int, binId: int, warehouseName: string} */
    private function warehouse(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Pack Retire Guard ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('PRG-01')->setSortKey(10);
        $em->persist($bin);
        $em->flush();

        return [
            'warehouseId' => (int) $warehouse->getId(),
            'binId' => (int) $bin->getId(),
            'warehouseName' => $warehouse->getName(),
        ];
    }

    /** @param array{warehouseId: int, binId: int, warehouseName: string} $where */
    private function stockedProduct(FunctionalTester $I, array $where, string $sku, int $units): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $product = (new ProductCore())->setSku($sku)->setName('Pack retire guard ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist(
            (new ProductInventory())
                ->setProduct($product)
                ->setWarehouse($em->find(Warehouse::class, $where['warehouseId']))
                ->setQuantity(0)
        );
        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'pack-retire-guard@example.test');

        if ($units > 0) {
            $I->grabService(StockMovementService::class)->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'pack-retire-guard-' . uniqid(), 'Put away for the guard test')
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

    private function declarePack(FunctionalTester $I, int $caseProductId, int $unitProductId, int $unitsPerCase): int
    {
        $I->amOnPage(self::SCREEN);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::SCREEN . '/declare', [
            '_token' => (string) $I->grabAttributeFrom('form[action$="/pack-conversion/declare"] input[name="_token"]', 'value'),
            'id' => '0',
            'case_product_id' => (string) $caseProductId,
            'unit_product_id' => (string) $unitProductId,
            'units_per_case' => (string) $unitsPerCase,
        ]);
        $I->seeResponseCodeIsSuccessful();

        return (int) $this->column($I, 'SELECT id FROM inventory_pack_rule WHERE case_product_id = ?', [$caseProductId]);
    }

    /** @param array{warehouseId: int, binId: int, warehouseName: string} $where */
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
            'reference' => 'WO-PRG',
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function column(FunctionalTester $I, string $sql, array $params = []): ?string
    {
        $value = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $params);

        return $value === false || $value === null ? null : (string) $value;
    }

    private function available(FunctionalTester $I, int $productId, int $warehouseId): int
    {
        return (int) round((float) $this->column(
            $I,
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = 'available'",
            [$productId, $warehouseId],
        ));
    }

    private function count(FunctionalTester $I, string $sql, array $params = []): int
    {
        return (int) $this->column($I, $sql, $params);
    }
}
