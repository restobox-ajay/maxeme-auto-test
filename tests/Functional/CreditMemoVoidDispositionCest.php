<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Voiding a credit note that recorded a customer return: the three answers, and the cancel
 * (item 38) — conducted per #624, anchored per #627.
 *
 * ## What was wrong
 *
 * Issuing a restocking credit note records goods arriving from the customer — `null → returned`,
 * which lands in `product_inventory.quarantine_quantity`. Voiding that note did nothing about them.
 * The note went Void and the units stayed on the books with no live document behind them: on clean
 * main, a note for four units left `quarantine_quantity` at 4 after the void, and the
 * `inventory_detail` row still held 4 in `returned`.
 *
 * ## How these assertions are made
 *
 * **By COLUMN, on `product_inventory`, one column at a time** — `quantity`, `sales_hold_quantity`,
 * `backordered_quantity`, `quarantine_quantity`, `write_off_quantity`, `received_quantity` — and
 * never through a derived getter. A derived figure lets a wrong number in one column be cancelled
 * by a wrong number in another and still read right, which is precisely what this defect class
 * hides behind. Every case also asserts the `inventory_detail` rows by status, a bystander product
 * in the same warehouse, and a second unrelated credit note.
 */
final class CreditMemoVoidDispositionCest
{
    /** Codeception reuses one Cest instance across methods, so nothing is cached on $this. */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('cm-void-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The defect itself, stated as a property of the screens rather than of any one route: after a
     * restocking credit note is voided, the units it brought in are accounted for.
     *
     * Voided through whatever the note's own detail screen offers, so the case is meaningful on
     * both sides of the fix — the one-click button it used to offer, or the confirmation it offers
     * now. On unfixed main the button voids the note and `quarantine_quantity` stays at 4: four
     * units of stock the warehouse does not have, with a void document behind them.
     */
    public function voidingARestockingNoteDoesNotLeaveTheReturnedUnitsOnTheBooks(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);

        $I->assertSame(4, (int) $this->inventoryRow($I, $c['productId'], $c['warehouseId'])['quarantine_quantity'], 'guard: issuing put four units into quarantine');

        $this->voidThroughWhateverTheScreenOffers($I, $memoId);

        $I->assertSame('Void', $this->memoStatus($I, $memoId), 'guard: the note is void either way');
        $I->assertSame(
            0,
            (int) $this->inventoryRow($I, $c['productId'], $c['warehouseId'])['quarantine_quantity'],
            'the four units the note brought in are still counted as quarantine stock after it was voided',
        );
        $I->assertSame(0, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_RETURNED), 'and the returned detail row still holds them');
    }

    /**
     * "Not here": the receipt is undone, so the units come off the books and quarantine falls back
     * to where it started. THE case this defect is about.
     */
    public function notHereTakesTheReturnedUnitsBackOffTheBooks(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $c, restock: false);

        // --- Guard: issuing really did put the goods on the books ----------------------------------
        $this->assertInventory($I, $c, 'after issue', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 4,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
        $I->assertSame(4, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_RETURNED));

        // The confirmation names the real effect rather than "an adjustment will be created".
        $I->amOnPage('/admin/credit-memo/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="radio"][name="disposition"][value="not_here"]');
        $I->seeElement('input[type="radio"][name="disposition"][value="written_off"]');
        $I->seeElement('input[type="radio"][name="disposition"][value="still_here"]');
        $I->see($c['sku'], '#restocked-units');
        $I->see('4', '#restocked-units-' . $c['productId']);

        $this->voidWith($I, $memoId, 'not_here');

        // --- The note voided AND the stock came off, both by column ---------------------------------
        $I->assertSame('Void', $this->memoStatus($I, $memoId));
        $this->assertInventory($I, $c, 'after void: not here', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 0,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
        $I->assertSame(0, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_RETURNED), 'nothing is described as returned any more');
        $I->assertSame(0, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_DAMAGED), 'and the units were not written off either — they are not here');
        $I->assertSame(10, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_AVAILABLE), 'the sellable row was never touched at any point');

        $this->assertVoidGroup($I, $memoId, 'taken back off the books', movedUnits: 4);
        $this->assertBystandersUntouched($I, $c, $bystanderMemoId);
    }

    /**
     * "Here, but written off": the units leave quarantine for the write-off bucket and sellable
     * stock does NOT rise. That absence is the load-bearing assertion for this answer.
     */
    public function writtenOffMovesTheUnitsToTheWriteOffBucketAndNotToTheShelf(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $c, restock: false);

        $this->voidWith($I, $memoId, 'written_off');

        $I->assertSame('Void', $this->memoStatus($I, $memoId));
        $this->assertInventory($I, $c, 'after void: written off', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            // Out of quarantine...
            'quarantine_quantity' => 0,
            // ...and into the write-off bucket, unit for unit.
            'write_off_quantity' => 4,
            'received_quantity' => 10,
        ]);
        $I->assertSame(0, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_RETURNED));
        $I->assertSame(4, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_DAMAGED), 'the units are recorded as damaged, in our building');
        $I->assertSame(10, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_AVAILABLE), 'sellable stock did NOT go up — damaged goods do not become saleable');

        $this->assertVoidGroup($I, $memoId, 'written off', movedUnits: 4);
        $this->assertBystandersUntouched($I, $c, $bystanderMemoId);
    }

    /**
     * "Still here": nothing moves, and the ledger says so rather than staying silent — one
     * zero-quantity movement under the void's own group. The zero is the point: an event that
     * deliberately moved nothing is still an event, and this is the case somebody will later be
     * trying to explain.
     */
    public function stillHereMovesNothingAndSaysSoInTheLedger(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);
        $bystanderMemoId = $this->raiseAndIssue($I, $c, restock: false);

        $this->voidWith($I, $memoId, 'still_here');

        $I->assertSame('Void', $this->memoStatus($I, $memoId));
        $this->assertInventory($I, $c, 'after void: still here', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 4,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
        $I->assertSame(4, $this->detailUnits($I, $c['productId'], InventoryDetail::STATUS_RETURNED), 'the units are still where the return put them, which is where they are');

        $group = $this->voidGroupRow($I, $memoId);
        $I->assertNotFalse($group, 'a group was written even though nothing moved');
        $I->assertStringContainsString('still here', (string) $group['reason']);
        $I->assertNotEmpty($group['actor']);
        $I->assertSame($this->memoNumber($I, $memoId), (string) $group['reference']);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT quantity, from_detail_id, to_detail_id, product_id FROM inventory_movement WHERE group_id = ?',
            [(int) $group['id']],
        );
        $I->assertCount(1, $rows, 'exactly one entry, for the one product the note moved');
        $I->assertSame(0, (int) $rows[0]['quantity'], 'and its quantity is zero — it records that nothing moved');
        $I->assertNull($rows[0]['from_detail_id'], 'with no source');
        $I->assertNull($rows[0]['to_detail_id'], 'and no destination');
        $I->assertSame($c['productId'], (int) $rows[0]['product_id'], 'named against the product it is about');

        $this->assertBystandersUntouched($I, $c, $bystanderMemoId);
    }

    /** Voiding a stock-moving note without answering the question is refused outright. */
    public function voidingAStockMovingNoteWithNoAnswerIsRefused(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);

        $I->amOnPage('/admin/credit-memo/' . $memoId . '/void');
        $token = (string) $I->grabAttributeFrom('#credit-memo-void-form input[name="_token"]', 'value');
        // A hand-rolled post with the choice left out — the browser's `required` is not the guard.
        $I->sendFormPostRequest('/admin/credit-memo/' . $memoId . '/action/void', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('Open', $this->memoStatus($I, $memoId), 'no answer, no void — the app does not pick one of the three');
        $this->assertInventory($I, $c, 'after a refused void', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 4,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
        $I->assertFalse($this->voidGroupRow($I, $memoId), 'and nothing was written against the void');
    }

    /** Cancelling the confirmation changes nothing at all — no status, no stock, no group. */
    public function cancellingTheConfirmationChangesNothing(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);

        $groupsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement_group');
        $movementsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement');

        $I->amOnPage('/admin/credit-memo/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        // The way out is a plain link back to the note — no form, so nothing can be posted by
        // taking it. Asserted as the element it is, then followed.
        $I->seeElement(sprintf('a[href$="/admin/credit-memo/%d"]', $memoId));
        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('Open', $this->memoStatus($I, $memoId), 'the note is exactly as it was');
        $this->assertInventory($I, $c, 'after cancelling', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 4,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
        $I->assertSame($groupsBefore, (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement_group'), 'no group was written');
        $I->assertSame($movementsBefore, (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement'), 'and no movement');
    }

    /**
     * The existing behaviour, proved to have survived: a note that never moved stock voids on one
     * post, with no question in front of it and nothing written to the ledger.
     */
    public function aNoteThatMovedNoStockVoidsWithNoQuestionAsked(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: false);

        $groupsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement_group');

        // The screen offers the plain button, not the confirmation link.
        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#void-form button');
        $I->dontSeeElement('#void-confirm-link');

        $token = (string) $I->grabAttributeFrom('#void-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/credit-memo/' . $memoId . '/action/void', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('Void', $this->memoStatus($I, $memoId), 'it voided on a bare post, exactly as it always did');
        $I->assertSame($groupsBefore, (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM inventory_movement_group'), 'and wrote nothing to the ledger');
        $this->assertInventory($I, $c, 'a financial-only note', [
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 0,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ]);
    }

    /**
     * A stock-moving note offers the confirmation link and NOT the one-click post, and the note's
     * own screen says what became of the goods once it is voided.
     *
     * The positive control for `dontSeeElement('#void-form button')` above is the previous test,
     * which asserts that same element IS there on a note that moved nothing.
     */
    public function theDetailScreenAsksFirstAndAfterwardsSaysWhatBecameOfTheGoods(FunctionalTester $I): void
    {
        $c = $this->seed($I);
        $memoId = $this->raiseAndIssue($I, $c, restock: true);

        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#void-confirm-link');
        $I->dontSeeElement('#void-form button');
        $I->see('recorded goods coming back into stock', '#void-needs-an-answer');
        $I->dontSeeElement('#void-outcome');

        $this->voidWith($I, $memoId, 'written_off');

        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('written off', '#void-outcome');
        $I->dontSeeElement('#void-confirm-link');
    }

    // -------------------------------------------------------------------------------- the plumbing

    /**
     * A warehouse with 10 sellable units of one product, a bystander product beside it, and a
     * customer to raise notes for.
     *
     * @return array{warehouseId: int, companyId: int, productId: int, bystanderProductId: int, sku: string, region: string}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('CM Void Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $company = (new Company())
            ->setName('Returns Wholesale')
            ->setCode('RET-' . uniqid());
        $em->persist($company);

        $product = (new ProductCore())
            ->setSku('CMV-A-' . random_int(1000, 9999))
            ->setName('Returnable Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $bystander = (new ProductCore())
            ->setSku('CMV-B-' . random_int(1000, 9999))
            ->setName('Bystander Widget — must not move')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($product);
        $em->persist($bystander);
        $em->flush();

        foreach ([$product, $bystander] as $index => $seeded) {
            $movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'cmv-seed-' . $index . '-' . uniqid())
                    ->receive($seeded, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
            );
        }
        $em->flush();

        return [
            'warehouseId' => (int) $warehouse->getId(),
            'companyId' => (int) $company->getId(),
            'productId' => (int) $product->getId(),
            'bystanderProductId' => (int) $bystander->getId(),
            'sku' => (string) $product->getSku(),
            'region' => (string) $region->getName(),
        ];
    }

    /**
     * Raise a credit note through the real form, restock flag as given, and issue it.
     *
     * @param array{warehouseId: int, companyId: int, productId: int, bystanderProductId: int, sku: string, region: string} $c
     */
    private function raiseAndIssue(FunctionalTester $I, array $c, bool $restock): int
    {
        $url = '/admin/credit-memo/new?company=' . $c['companyId'];
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $params = [
            '_token' => $token,
            'document_date' => '2026-08-20',
            'reason' => 'Customer sent four back.',
            'tax' => '0.00',
            'lines' => [
                0 => [
                    'product_id' => (string) $c['productId'],
                    'name' => 'Returnable Widget',
                    'sku' => $c['sku'],
                    'location' => $c['region'],
                    'quantity' => '4.00',
                    'price' => '5.00',
                ],
            ],
        ];
        if ($restock) {
            $params['restock'] = '1';
        }
        $I->sendFormPostRequest($url, $params);
        $I->seeResponseCodeIsSuccessful();

        $memoId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM credit_memo WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$c['companyId']],
        );

        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();
        $issueToken = (string) $I->grabAttributeFrom('#issue-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/credit-memo/' . $memoId . '/action/issue', ['_token' => $issueToken]);
        $I->seeResponseCodeIsSuccessful();

        return $memoId;
    }

    /**
     * Voids through whichever affordance the detail screen actually offers.
     *
     * The confirmation link when it is there, answering "not here" — the answer that matches the
     * one-click void's own claim, which is that the note and everything it recorded are withdrawn.
     * The plain button otherwise, which is all unfixed main has.
     */
    private function voidThroughWhateverTheScreenOffers(FunctionalTester $I, int $memoId): void
    {
        $I->amOnPage('/admin/credit-memo/' . $memoId);
        $I->seeResponseCodeIsSuccessful();

        if ($I->grabMultiple('#void-confirm-link', 'href') !== []) {
            $this->voidWith($I, $memoId, 'not_here');

            return;
        }

        $token = (string) $I->grabAttributeFrom('#void-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/credit-memo/' . $memoId . '/action/void', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** Post the void with a chosen answer, through the confirmation screen's own form. */
    private function voidWith(FunctionalTester $I, int $memoId, string $disposition): void
    {
        $I->amOnPage('/admin/credit-memo/' . $memoId . '/void');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('#credit-memo-void-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/credit-memo/' . $memoId . '/action/void', [
            '_token' => $token,
            'disposition' => $disposition,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * Every bucket on `product_inventory`, one column at a time, re-read from the database.
     *
     * @param array{warehouseId: int, companyId: int, productId: int, bystanderProductId: int, sku: string, region: string} $c
     * @param array<string, int> $expected
     */
    private function assertInventory(FunctionalTester $I, array $c, string $stage, array $expected): void
    {
        $row = $this->inventoryRow($I, $c['productId'], $c['warehouseId']);
        foreach ($expected as $column => $value) {
            $I->assertSame($value, (int) $row[$column], sprintf('%s: product_inventory.%s', $stage, $column));
        }
    }

    /** @return array<string, mixed> */
    private function inventoryRow(FunctionalTester $I, int $productId, int $warehouseId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT quantity, sales_hold_quantity, backordered_quantity, quarantine_quantity,'
            . ' write_off_quantity, received_quantity'
            . ' FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        );

        if (!\is_array($row)) {
            throw new \RuntimeException('No product_inventory row for that product/warehouse pair.');
        }

        return $row;
    }

    private function detailUnits(FunctionalTester $I, int $productId, string $status): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$productId, $status],
        );
    }

    private function memoStatus(FunctionalTester $I, int $memoId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT status FROM credit_memo WHERE id = ?', [$memoId]);
    }

    private function memoNumber(FunctionalTester $I, int $memoId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT document_number FROM credit_memo WHERE id = ?', [$memoId]);
    }

    /** @return array<string, mixed>|false */
    private function voidGroupRow(FunctionalTester $I, int $memoId): array|false
    {
        return $this->connection($I)->fetchAssociative(
            'SELECT id, type, reason, actor, reference FROM inventory_movement_group WHERE client_operation_id = ?',
            ['credit-memo-void-' . $memoId],
        );
    }

    private function assertVoidGroup(FunctionalTester $I, int $memoId, string $reasonFragment, int $movedUnits): void
    {
        $group = $this->voidGroupRow($I, $memoId);
        $I->assertNotFalse($group, 'the void wrote its own group, keyed to this note');
        $I->assertSame($this->memoNumber($I, $memoId), (string) $group['reference'], 'referenced back to the note');
        $I->assertStringContainsString($reasonFragment, (string) $group['reason'], 'and the reason says which answer was given');
        $I->assertNotEmpty($group['actor'], 'with the person who did it');

        $moved = (int) $this->connection($I)->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_movement WHERE group_id = ?',
            [(int) $group['id']],
        );
        $I->assertSame($movedUnits, $moved, 'the group moved exactly the units the note had brought in');
    }

    /**
     * The bystanders, asserted in every outcome: the product the note never named, and a second
     * note for the same customer that has nothing to do with any of this.
     *
     * @param array{warehouseId: int, companyId: int, productId: int, bystanderProductId: int, sku: string, region: string} $c
     */
    private function assertBystandersUntouched(FunctionalTester $I, array $c, int $bystanderMemoId): void
    {
        $row = $this->inventoryRow($I, $c['bystanderProductId'], $c['warehouseId']);
        foreach ([
            'quantity' => 0,
            'sales_hold_quantity' => 0,
            'backordered_quantity' => 0,
            'quarantine_quantity' => 0,
            'write_off_quantity' => 0,
            'received_quantity' => 10,
        ] as $column => $value) {
            $I->assertSame($value, (int) $row[$column], sprintf('bystander product: product_inventory.%s', $column));
        }
        $I->assertSame(10, $this->detailUnits($I, $c['bystanderProductId'], InventoryDetail::STATUS_AVAILABLE));
        $I->assertSame(0, $this->detailUnits($I, $c['bystanderProductId'], InventoryDetail::STATUS_RETURNED));
        $I->assertSame(0, $this->detailUnits($I, $c['bystanderProductId'], InventoryDetail::STATUS_DAMAGED));

        $I->assertSame('Open', $this->memoStatus($I, $bystanderMemoId), 'the unrelated note was not voided along the way');
        $I->assertFalse($this->voidGroupRow($I, $bystanderMemoId), 'and nothing was written against it');
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
