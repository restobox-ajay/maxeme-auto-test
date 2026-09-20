<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Ticking "the goods physically went back" on a standalone debit memo moves stock, provably.
 *
 * Conducted per #624: driven through the real screens with plain form POSTs carrying a scraped CSRF
 * token, asserting the actual `product_inventory` and `inventory_detail` columns before and after by
 * re-reading them from the database, and — the half that makes the rest mean anything — asserting
 * the product that must NOT have moved, in the same warehouse, on both paths.
 *
 * The defect this covers: `DebitMemo::isRestock()` had zero callers anywhere in the repository. The
 * tick saved, the memo issued, and the warehouse went on counting units that had left the building.
 */
final class DebitMemoRestockCest
{
    /** Same reason VendorReturnAndDebitMemoCest clears it: the number generator reads its prefix through a cache that outlives the per-test transaction. */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('debit-restock-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Two dimensional products, ten units of each on the shelf of ONE warehouse, booked the way
     * stock actually arrives. Product B exists only to be left alone.
     *
     * @return array{warehouseId: int, vendorId: int, productAId: int, productBId: int, skuA: string}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Debit Restock Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Restocking Supply ' . uniqid());
        $em->persist($vendor);

        $productA = (new ProductCore())
            ->setSku('DM-A-' . random_int(1000, 9999))
            ->setName('Debit Memo Widget A')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $productB = (new ProductCore())
            ->setSku('DM-B-' . random_int(1000, 9999))
            ->setName('Debit Memo Widget B — must not move')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'dm-seed-a-' . uniqid())
                ->receive($productA, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
        );
        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'dm-seed-b-' . uniqid())
                ->receive($productB, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
        );
        $em->flush();

        return [
            'warehouseId' => (int) $warehouse->getId(),
            'vendorId' => (int) $vendor->getId(),
            'productAId' => (int) $productA->getId(),
            'productBId' => (int) $productB->getId(),
            'skuA' => (string) $productA->getSku(),
        ];
    }

    /**
     * Raise a standalone memo through the real form and return its id.
     *
     * @param array{warehouseId: int, vendorId: int, productAId: int, productBId: int, skuA: string} $context
     */
    private function raiseStandaloneMemo(FunctionalTester $I, array $context, bool $restock, float $quantity = 4.0): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?vendor=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $params = [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'reason' => $restock ? 'Damaged on arrival, sent back.' : 'Pricing correction only.',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productAId'],
                    'name' => 'Debit Memo Widget A',
                    'sku' => $context['skuA'],
                    'quantity' => number_format($quantity, 2, '.', ''),
                    'unit_cost' => '5.0000',
                ],
            ],
        ];
        if ($restock) {
            $params['restock'] = '1';
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', $params);
        $I->seeResponseCodeIsSuccessful();

        $memo = $em->getRepository(DebitMemo::class)->findOneBy(['vendor' => $context['vendorId']], ['id' => 'DESC']);
        $I->assertNotNull($memo, 'the save form created the memo');

        return (int) $memo->getId();
    }

    /**
     * The whole defect, end to end: the tick is stored, and issuing the memo takes the units out of
     * the named warehouse.
     */
    public function tickingRestockOnAStandaloneMemoMovesTheStockItClaimsMoved(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseStandaloneMemo($I, $context, restock: true);

        // The flag really reached the column, read back by name rather than inferred from the form.
        $storedFlag = $em->getConnection()->fetchOne('SELECT restock FROM debit_memo WHERE id = ?', [$memoId]);
        $I->assertSame(1, (int) $storedFlag, 'debit_memo.restock is set on the row');

        // And the line really carries the product, which is what gives the restock something to move.
        $storedProduct = $em->getConnection()->fetchOne('SELECT product_id FROM debit_memo_line WHERE debit_memo_id = ?', [$memoId]);
        $I->assertSame($context['productAId'], (int) $storedProduct, 'debit_memo_line.product_id names the product that went back');

        // --- Before: both products whole, re-read from the database -------------------------------
        $em->clear();
        $beforeA = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $beforeB = $this->inventoryFor($em, $context['productBId'], $context['warehouseId']);
        $I->assertSame(10, $beforeA->getAvailableQuantity(), 'guard: saving a draft moves nothing');
        $I->assertSame(0, $beforeA->getWriteOffQuantity(), 'guard: nothing written off yet');
        $I->assertSame(10, $beforeB->getAvailableQuantity(), 'guard: the untouched product starts whole');

        // --- Issue it, from the real screen, naming the warehouse ----------------------------------
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->seeElement('form[action$="/action/issue"] select[name="warehouse_id"]');
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', [
            '_token' => $issueToken,
            'warehouse_id' => (string) $context['warehouseId'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- After: product A moved, by column, re-read ---------------------------------------------
        $em->clear();
        $afterA = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(6, $afterA->getAvailableQuantity(), 'four units left the shelf because the memo said they had');
        $I->assertSame(4, $afterA->getWriteOffQuantity(), 'and landed in the write_off bucket STATUS_RETURNED_TO_VENDOR folds into');
        $I->assertSame(10, $afterA->getReceivedQuantity(), 'received is a lifetime count of what arrived and does not move on the way back out');

        $row = $em->getConnection()->fetchAssociative(
            "SELECT quantity, warehouse_id, location_id FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productAId']],
        );
        $I->assertNotFalse($row, 'a real inventory_detail row was written at the terminal status');
        $I->assertSame(4, (int) $row['quantity']);
        $I->assertSame($context['warehouseId'], (int) $row['warehouse_id'], 'in the warehouse named on the issue form, not merely somewhere');
        $I->assertNull($row['location_id'], 'terminal statuses drop their bin — the goods left the building');

        // The movement carries the memo's own number and a client operation id derived from the
        // memo's identity, so the ledger answers "where did these go?" and a retried request finds
        // the existing group rather than moving the units twice.
        $group = $em->getConnection()->fetchAssociative(
            'SELECT reference, type FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-restock-' . $memoId],
        );
        $I->assertNotFalse($group, 'the movement group is keyed to this memo, so it is idempotent by construction');
        $memoNumber = (string) $em->getRepository(DebitMemo::class)->find($memoId)->getDocumentNumber();
        $I->assertSame($memoNumber, (string) $group['reference'], 'and referenced back to the memo that caused it');
        $I->assertSame(InventoryMovementGroup::TYPE_VENDOR_RETURN, (string) $group['type'], 'recorded as the same physical event a shipped vendor return records');

        // --- The negative: product B, same warehouse, completely untouched ----------------------------
        $afterB = $this->inventoryFor($em, $context['productBId'], $context['warehouseId']);
        $I->assertSame(10, $afterB->getAvailableQuantity(), 'the product the memo never named is still whole');
        $I->assertSame(0, $afterB->getWriteOffQuantity(), 'and nothing wrote it off');
        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productBId']],
        ), 'no row was written for it at all');

        $memo = $em->getRepository(DebitMemo::class)->find($memoId);
        $I->assertSame('Open', $memo->getStatus()->value, 'and the memo really did issue');
    }

    /**
     * The other half of the same claim, and the one that stops "it moves stock" from being achieved
     * by moving stock always: a memo with the box UNticked issues and moves nothing.
     */
    public function notTickingRestockMovesNothingAtAll(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseStandaloneMemo($I, $context, restock: false);

        $storedFlag = $em->getConnection()->fetchOne('SELECT restock FROM debit_memo WHERE id = ?', [$memoId]);
        $I->assertSame(0, (int) $storedFlag, 'debit_memo.restock is clear on the row');

        // No warehouse question is even asked, because none is needed.
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->dontSeeElement('form[action$="/action/issue"] select[name="warehouse_id"]');
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', ['_token' => $issueToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $memo = $em->getRepository(DebitMemo::class)->find($memoId);
        $I->assertSame('Open', $memo->getStatus()->value, 'a plain billing correction still issues, with no warehouse involved');

        $afterA = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(10, $afterA->getAvailableQuantity(), 'the product the memo named is untouched — the money moved, the goods did not');
        $I->assertSame(0, $afterA->getWriteOffQuantity(), 'nothing was written off');

        $afterB = $this->inventoryFor($em, $context['productBId'], $context['warehouseId']);
        $I->assertSame(10, $afterB->getAvailableQuantity(), 'and neither is its neighbour in the same warehouse');
        $I->assertSame(0, $afterB->getWriteOffQuantity());

        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inventory_detail WHERE product_id IN (?, ?) AND status = 'returned_to_vendor'",
            [$context['productAId'], $context['productBId']],
        ), 'no returned-to-vendor row exists for either product');
    }

    /**
     * A restock memo naming no warehouse is refused, and refused INTACT — the memo is still a draft
     * and no stock has moved. This is why the movement shares a transaction with the transition
     * instead of being dispatched after the flush the way the sell side's subscriber is.
     */
    public function aRestockMemoWithNoWarehouseIsRefusedAndStaysADraft(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseStandaloneMemo($I, $context, restock: true);

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', ['_token' => $issueToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $status = (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]);
        $I->assertSame('Draft', $status, 'the refusal left the memo exactly where it was');

        $afterA = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(10, $afterA->getAvailableQuantity(), 'and moved nothing on the way past');
        $I->assertSame(0, $afterA->getWriteOffQuantity());
    }

    /**
     * A restock memo whose lines are money only — a fee, a freight correction, no product — is
     * refused rather than issued silently. Issuing it would put the document back to claiming goods
     * moved while nothing moved, which is the defect, not a lesser version of it.
     */
    public function aRestockMemoThatNamesNoProductIsRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?vendor=' . $context['vendorId']);
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'restock' => '1',
            'reason' => 'Restocking fee.',
            'lines' => [
                0 => ['product_id' => '', 'name' => 'Restocking fee', 'sku' => '', 'quantity' => '1.00', 'unit_cost' => '25.0000'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $memo = $em->getRepository(DebitMemo::class)->findOneBy(['vendor' => $context['vendorId']], ['id' => 'DESC']);
        $memoId = (int) $memo->getId();

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', [
            '_token' => $issueToken,
            'warehouse_id' => (string) $context['warehouseId'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $status = (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]);
        $I->assertSame('Draft', $status, 'a memo claiming goods moved with no goods named does not issue');

        $afterA = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(10, $afterA->getAvailableQuantity(), 'and took nothing with it');
    }

    /**
     * The exclusivity that was already written and passing stays written: a memo carrying a vendor
     * return may not also restock, because that return's own ship() is what moved the goods. Proved
     * through the screen rather than at the entity, because the screen is where the tick lives.
     */
    public function aMemoRaisedFromAVendorReturnIsNotOfferedTheTickAtAll(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);

        $vendorReturn = (new \ProcurementBundle\Entity\VendorReturn())
            ->setDocumentNumber('VR-EXCL-' . random_int(1000, 9999))
            ->setVendor($em->getRepository(Vendor::class)->find($context['vendorId']));
        $em->persist($vendorReturn);
        $em->flush();

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?vendor_return=' . $vendorReturn->getId());
        $I->seeResponseCodeIsSuccessful();
        // Present first, so the absence below means something (#627's ordering rule).
        $I->seeElement('form input[name="vendor_return_id"]');
        $I->dontSeeElement('input[type="checkbox"][name="restock"]');
    }

    private function inventoryFor(EntityManagerInterface $em, int $productId, int $warehouseId): ProductInventory
    {
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy(['product' => $productId, 'warehouse' => $warehouseId]);
        if (!$inventory instanceof ProductInventory) {
            throw new \RuntimeException('No product_inventory row for that product/warehouse pair.');
        }

        return $inventory;
    }
}
