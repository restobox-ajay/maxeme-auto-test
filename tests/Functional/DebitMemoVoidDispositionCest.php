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
 * Voiding a debit memo that took stock out to the vendor: the three answers, and the cancel.
 *
 * Issuing a restock memo moves units OUT. Voiding it afterwards is not by itself a statement about
 * where those units are, and the three real answers need three different ledger entries — so the
 * screen asks and the app refuses to guess. These tests drive that choice through the real screens
 * per #624, with plain form POSTs carrying a scraped CSRF token, and assert `table.column` before
 * and after by re-reading from the database.
 *
 * A bystander product in the same warehouse and a second, unrelated memo are asserted untouched in
 * every case.
 */
final class DebitMemoVoidDispositionCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('memo-void-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * @return array{warehouseId: int, vendorId: int, productAId: int, productBId: int, skuA: string}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Memo Void Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Voidable Supply ' . uniqid());
        $em->persist($vendor);

        $productA = (new ProductCore())
            ->setSku('VD-A-' . random_int(1000, 9999))
            ->setName('Voidable Widget A')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $productB = (new ProductCore())
            ->setSku('VD-B-' . random_int(1000, 9999))
            ->setName('Voidable Widget B — must not move')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'vd-seed-a-' . uniqid())
                ->receive($productA, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
        );
        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'vd-seed-b-' . uniqid())
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
     * Raise a memo through the real form, restock flag as given, and issue it.
     *
     * @param array{warehouseId: int, vendorId: int, productAId: int, productBId: int, skuA: string} $context
     */
    private function raiseAndIssue(FunctionalTester $I, array $context, bool $restock, float $quantity = 4.0): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?vendor=' . $context['vendorId']);
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $params = [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'reason' => 'Damaged on arrival.',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productAId'],
                    'name' => 'Voidable Widget A',
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

        $memoId = (int) $em->getRepository(DebitMemo::class)->findOneBy(['vendor' => $context['vendorId']], ['id' => 'DESC'])->getId();

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $issueParams = ['_token' => $issueToken];
        if ($restock) {
            $issueParams['warehouse_id'] = (string) $context['warehouseId'];
        }
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', $issueParams);
        $I->seeResponseCodeIsSuccessful();

        return $memoId;
    }

    /** Post the void with a chosen answer, through the confirmation screen's own form. */
    private function voidWith(FunctionalTester $I, int $memoId, string $disposition): void
    {
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form[action$="/action/void"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/void', [
            '_token' => $token,
            'disposition' => $disposition,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * "Back in stock": the write-off is reversed, so available returns to where it started and the
     * memo voids — one transaction, one group, attributed to the memo.
     */
    public function backInStockPutsTheUnitsBackAndVoidsTheMemo(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $context, restock: false);

        // --- Guard: issuing really did take the stock out -------------------------------------------
        $em->clear();
        $afterIssue = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(6, $afterIssue->getAvailableQuantity(), 'guard: four units left on issue');
        $I->assertSame(4, $afterIssue->getWriteOffQuantity(), 'guard: and landed in the write-off bucket');

        // The confirmation names the real effect rather than "an adjustment will be created".
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="radio"][name="disposition"][value="back_in_stock"]');
        $I->seeElement('input[type="radio"][name="disposition"][value="written_off"]');
        $I->seeElement('input[type="radio"][name="disposition"][value="gone"]');
        $I->see($context['skuA'], 'form');

        $this->voidWith($I, $memoId, 'back_in_stock');

        // --- The memo voided AND the stock came back, both by column --------------------------------
        $em->clear();
        $I->assertSame('Void', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]));

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(10, $after->getAvailableQuantity(), 'the four units are sellable again');
        $I->assertSame(0, $after->getWriteOffQuantity(), 'and are out of the write-off bucket');

        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productAId']],
        ), 'nothing is described as sitting at the vendor any more');

        // --- Attributed to the memo through one group ------------------------------------------------
        $group = $em->getConnection()->fetchAssociative(
            'SELECT id, type, reason, actor, reference FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $memoId],
        );
        $I->assertNotFalse($group, 'the void wrote its own group, keyed to this memo');
        $memoNumber = (string) $em->getRepository(DebitMemo::class)->find($memoId)->getDocumentNumber();
        $I->assertSame($memoNumber, (string) $group['reference'], 'referenced back to the memo');
        $I->assertStringContainsString('sellable again', (string) $group['reason'], 'and the reason says which of the three answers was given');
        $I->assertNotEmpty($group['actor'], 'with the person who did it');

        $moved = (int) $em->getConnection()->fetchOne('SELECT COALESCE(SUM(quantity), 0) FROM inventory_movement WHERE group_id = ?', [(int) $group['id']]);
        $I->assertSame(4, $moved, 'the group moved exactly the four units the memo had taken');

        // The reversal is tied to the entry it undoes, which is what bounds it.
        $I->assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_movement WHERE group_id = ? AND reverses_movement_id IS NOT NULL',
            [(int) $group['id']],
        ), 'and names the movement it reverses');

        $this->assertBystandersUntouched($I, $em, $context, $bystanderMemoId);
    }

    /**
     * "Back, but written off": the units stop being described as at the vendor and become damaged
     * here. Available must NOT rise — that absence is the load-bearing assertion for this answer.
     */
    public function writtenOffKeepsTheUnitsOutOfSellableStock(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $context, restock: false);

        $this->voidWith($I, $memoId, 'written_off');

        $em->clear();
        $I->assertSame('Void', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]));

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        // THE assertion for this option: damaged goods do not become sellable.
        $I->assertSame(6, $after->getAvailableQuantity(), 'available did NOT go up — the goods came back broken, not saleable');
        $I->assertSame(4, $after->getWriteOffQuantity(), 'and the write-off bucket did not jump either: they were already written off');

        $damaged = (int) $em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'damaged'",
            [$context['productAId']],
        );
        $I->assertSame(4, $damaged, 'the units are now recorded as damaged, in our building');

        $stillAtVendor = (int) $em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productAId']],
        );
        $I->assertSame(0, $stillAtVendor, 'and are no longer described as sitting at the vendor, because they are not');

        $group = $em->getConnection()->fetchAssociative(
            'SELECT id, reason, actor FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $memoId],
        );
        $I->assertNotFalse($group);
        $I->assertStringContainsString('written off', (string) $group['reason']);
        $I->assertNotEmpty($group['actor']);

        $this->assertBystandersUntouched($I, $em, $context, $bystanderMemoId);
    }

    /**
     * "Gone": nothing moves, and the ledger says so rather than staying silent — a zero-quantity
     * movement under the void's own group. The zero is the point: an event that deliberately moved
     * nothing is still an event, and this is the case somebody will later be trying to explain.
     */
    public function goneMovesNothingAndSaysSoInTheLedger(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $context, restock: false);

        $this->voidWith($I, $memoId, 'gone');

        $em->clear();
        $I->assertSame('Void', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]));

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(6, $after->getAvailableQuantity(), 'inventory is exactly as the issue left it');
        $I->assertSame(4, $after->getWriteOffQuantity(), 'and so is the write-off bucket');
        $I->assertSame(4, (int) $em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productAId']],
        ), 'the units are still recorded as being at the vendor, which is where they are');

        // The entry exists, and it is a zero.
        $group = $em->getConnection()->fetchAssociative(
            'SELECT id, reason, actor, reference FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $memoId],
        );
        $I->assertNotFalse($group, 'a group was written even though nothing moved');
        $memoNumber = (string) $em->getRepository(DebitMemo::class)->find($memoId)->getDocumentNumber();
        $I->assertSame($memoNumber, (string) $group['reference']);
        $I->assertStringContainsString('stayed with the vendor', (string) $group['reason']);
        $I->assertNotEmpty($group['actor']);

        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT quantity, from_detail_id, to_detail_id, product_id FROM inventory_movement WHERE group_id = ?',
            [(int) $group['id']],
        );
        $I->assertCount(1, $rows, 'exactly one entry, for the one product the memo moved');
        $I->assertSame(0, (int) $rows[0]['quantity'], 'and its quantity is zero — it records that nothing moved');
        $I->assertNull($rows[0]['from_detail_id'], 'with no source');
        $I->assertNull($rows[0]['to_detail_id'], 'and no destination');
        $I->assertSame($context['productAId'], (int) $rows[0]['product_id'], 'named against the product it is about');

        // And it is legible on the screen that lists movements, rather than filtered out of it.
        $I->amOnPage('/admin/bundles/inventory-depth/movements?reference=' . urlencode($memoNumber));
        $I->seeResponseCodeIsSuccessful();
        $I->see($memoNumber, 'table');

        $this->assertBystandersUntouched($I, $em, $context, $bystanderMemoId);
    }

    /** Cancelling changes nothing at all — no status, no stock, no movement, no group. */
    public function cancellingTheConfirmationChangesNothing(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);

        $em->clear();
        $groupsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement_group');
        $movementsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement');

        // Open the confirmation and walk away through its own Cancel link.
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        // The way out is a plain link back to the memo — no form, so nothing can be posted by
        // taking it. Asserted as the element it is, then followed.
        $I->seeElement(sprintf('a[href$="/debit-memos/%d"]', $memoId));
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Open', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]), 'the memo is exactly as it was');

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(6, $after->getAvailableQuantity(), 'stock is exactly as it was');
        $I->assertSame(4, $after->getWriteOffQuantity());

        $I->assertSame($groupsBefore, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement_group'), 'no group was written');
        $I->assertSame($movementsBefore, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement'), 'and no movement');
        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $memoId],
        ), 'and specifically nothing attributed to this void');
    }

    /**
     * The existing behaviour, proved to have survived: a memo that never moved stock voids on one
     * post, with no question in front of it and nothing written to the ledger.
     */
    public function aMemoThatMovedNoStockVoidsWithNoQuestionAsked(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: false);

        $em->clear();
        $groupsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement_group');

        // The screen offers the plain button, not the confirmation link.
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->seeElement('form[action$="/action/void"] button');
        $I->dontSeeElement(sprintf('a[href$="/debit-memos/%d/void"]', $memoId));

        $token = $I->grabAttributeFrom('form[action$="/action/void"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/void', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Void', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]), 'it voided on a bare post, exactly as it always did');
        $I->assertSame($groupsBefore, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement_group'), 'and wrote nothing to the ledger');

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(10, $after->getAvailableQuantity(), 'stock was never involved at any point');
        $I->assertSame(0, $after->getWriteOffQuantity());
    }

    /**
     * When the stock entry cannot be made, neither it nor the void is applied.
     *
     * Forced by putting the units back once — the memo's write-off is then fully reversed — and
     * asking for them back a second time through a hand-rolled post. `ReversalPlanner`'s bound
     * refuses, and the whole transaction has to fail with it.
     */
    public function anAdjustmentThatCannotBeMadeTakesTheVoidDownWithIt(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);
        $secondMemoId = $this->raiseAndIssue($I, $context, restock: true, quantity: 2.0);

        // Put the first memo's units back, legitimately.
        $this->voidWith($I, $memoId, 'back_in_stock');
        $em->clear();
        $I->assertSame('Void', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]), 'guard: the first void worked');

        // Now take the SECOND memo's stock away underneath it, so its reversal has no source row to
        // draw from, and try to void it as "back in stock".
        $em->getConnection()->executeStatement(
            "UPDATE inventory_detail SET quantity = 0 WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$context['productAId']],
        );
        $em->clear();

        $statusBefore = (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$secondMemoId]);
        $I->assertSame('Open', $statusBefore, 'guard: the second memo is still open');
        $movementsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement');

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $secondMemoId . '/void');
        $token = $I->grabAttributeFrom('form[action$="/action/void"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $secondMemoId . '/action/void', [
            '_token' => $token,
            'disposition' => 'back_in_stock',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Open', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$secondMemoId]),
            'the memo did NOT void, because the stock entry it depends on could not be made');
        $I->assertSame($movementsBefore, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movement'),
            'and no movement was written by the attempt');
        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $secondMemoId],
        ), 'not even the group');
    }

    /** Voiding a stock-moving memo without answering the question is refused outright. */
    public function voidingAStockMovingMemoWithNoAnswerIsRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $context, restock: true);

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/void');
        $token = $I->grabAttributeFrom('form[action$="/action/void"] input[name="_token"]', 'value');
        // A hand-rolled post with the choice left out — the browser's `required` is not the guard.
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/void', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Open', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]),
            'no answer, no void — the app does not pick one of the three on the operator\'s behalf');

        $after = $this->inventoryFor($em, $context['productAId'], $context['warehouseId']);
        $I->assertSame(6, $after->getAvailableQuantity(), 'and nothing moved');
        $I->assertSame(4, $after->getWriteOffQuantity());
    }

    /**
     * The bystanders, asserted in every outcome: the product the memo never named, and a second
     * memo for the same vendor that has nothing to do with any of this.
     *
     * @param array{warehouseId: int, vendorId: int, productAId: int, productBId: int, skuA: string} $context
     */
    private function assertBystandersUntouched(FunctionalTester $I, EntityManagerInterface $em, array $context, int $bystanderMemoId): void
    {
        $bystander = $this->inventoryFor($em, $context['productBId'], $context['warehouseId']);
        $I->assertSame(10, $bystander->getAvailableQuantity(), 'the product in the same warehouse that was never named is still whole');
        $I->assertSame(0, $bystander->getWriteOffQuantity(), 'and nothing wrote it off');
        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status IN (?, ?)',
            [$context['productBId'], InventoryDetail::STATUS_RETURNED_TO_VENDOR, InventoryDetail::STATUS_DAMAGED],
        ), 'and no write-off row exists for it at all');

        $I->assertSame('Open', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$bystanderMemoId]),
            'the unrelated memo was not voided along the way');
        $I->assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_movement_group WHERE client_operation_id = ?',
            ['debit-memo-void-' . $bystanderMemoId],
        ), 'and nothing was written against it');
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
