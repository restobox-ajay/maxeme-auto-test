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
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A mistaken vendor return can be dropped without first being agreed with the vendor — and nothing
 * past Requested became editable or reopenable on the way.
 *
 * ## The defect these tests pin down
 *
 * `VendorReturn::decline()` used to start at Authorised. `close()` starts at Shipped, `ship()` at
 * Authorised, and there is no delete route — so a Requested return had exactly one exit: authorise
 * it, then decline it. Killing a document typed against the wrong vendor meant first recording a
 * vendor agreement that never happened.
 *
 * The sharp case is the empty one, and `theEmptyReturnThatUsedToHaveNoExitAtAll()` below is written
 * for it specifically: `authorise()` refuses a return with no units on it, while `save()` never
 * required a line. So submitting the blank form produced a document that could not be authorised,
 * therefore could not be declined, therefore could not be closed, and could not be deleted. There
 * was no sequence of actions that disposed of it.
 *
 * ## Why declining is the abandon route, rather than a new Cancelled state
 *
 * `App\Enum\SalesReturnStatus` makes the argument on the sell side and it is mirrored rather than
 * re-litigated: "a return the customer changed their mind about is Declined, with the reason saying
 * so", because two terminal not-happening states are a choice with no consequence attached to it.
 * `decline()` records the from-state in `notes`, so a draft dropped before anybody agreed and a
 * parcel refused at the vendor's dock stay legible as the different events they are.
 *
 * ## Both directions are asserted, per #624
 *
 * The second test is the one that matters most: a fix that made everything cancellable and editable
 * would pass the first test alone and be a far worse defect than the one being fixed. So it proves
 * the refusals that must survive — lines frozen past Requested, terminal states staying terminal,
 * and `ship()` still demanding Authorised so no draft can ever reach the stock ledger.
 *
 * Every assertion re-reads `vendor_return` / `vendor_return_line` / `inventory_detail` from the
 * database rather than trusting an entity fetched beforehand, and every test carries an untouched
 * control document.
 */
final class VendorReturnDraftCancellationCest
{
    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here survives the rollback and is read by whatever runs next. Each return
     * allocates a number through PurchaseDocumentNumberGenerator, which reads its prefix through
     * that cache — so this clears it for the same reason VendorReturnAndDebitMemoCest does.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vr-cancel-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor, two dimensional products with ten units of each on the shelf, and a receipt naming
     * both — the delivery these returns are raised against.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} warehouseId, vendorId, productAId, productBId, receiptId
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('VR Cancel Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Cancellable Supply ' . uniqid());
        $em->persist($vendor);

        $productA = (new ProductCore())
            ->setSku('VRC-A-' . random_int(1000, 9999))
            ->setName('Cancel Widget A')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $productB = (new ProductCore())
            ->setSku('VRC-B-' . random_int(1000, 9999))
            ->setName('Cancel Widget B')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        foreach ([[$productA, 'a'], [$productB, 'b']] as [$product, $tag]) {
            $movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'vrc-seed-' . $tag . '-' . uniqid())
                    ->receive($product, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
            );
        }
        $em->flush();

        $receipt = (new GoodsReceipt())
            ->setReceiptNumber('RC-VRC-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->setWarehouse($warehouse);
        $em->persist($receipt);

        foreach ([$productA, $productB] as $product) {
            $line = (new GoodsReceiptLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('10.00');
            $receipt->addLine($line);
            $em->persist($line);
        }
        $em->flush();

        return [
            (int) $warehouse->getId(),
            (int) $vendor->getId(),
            (int) $productA->getId(),
            (int) $productB->getId(),
            (int) $receipt->getId(),
        ];
    }

    /**
     * Raise a return through the real form and hand back its id.
     *
     * `$lines` is posted exactly as the screen's inputs are named, so passing `[]` submits the blank
     * form — which is how the empty return in the second test gets created, rather than by
     * constructing an entity the screen could not have produced.
     *
     * @param array<int, array<string, string>> $lines
     */
    private function raiseReturn(FunctionalTester $I, int $vendorId, int $receiptId, string $reason, array $lines): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $before = $this->returnIds($em);

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $vendorId,
            'goods_receipt_id' => (string) $receiptId,
            'reason' => $reason,
            'lines' => $lines,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $created = array_values(array_diff($this->returnIds($em), $before));
        $I->assertCount(1, $created, 'the save form created exactly one vendor return');

        return $created[0];
    }

    /** @return list<int> */
    private function returnIds(EntityManagerInterface $em): array
    {
        return array_map('intval', $em->getConnection()->fetchFirstColumn('SELECT id FROM vendor_return'));
    }

    /** The document's own row, read back from the database rather than from a held entity. */
    private function returnRow(EntityManagerInterface $em, int $id): array
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT status, authorised_at, shipped_at, warehouse_id, notes, reason FROM vendor_return WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            throw new \RuntimeException('No vendor_return row with id ' . $id);
        }

        return $row;
    }

    /** Line quantities for one return, in row order, formatted past SQLite's NUMERIC affinity. */
    private function lineQuantities(EntityManagerInterface $em, int $id): array
    {
        return array_map(
            static fn ($q): string => number_format((float) $q, 2, '.', ''),
            $em->getConnection()->fetchFirstColumn(
                'SELECT quantity FROM vendor_return_line WHERE vendor_return_id = ? ORDER BY sort_order ASC, id ASC',
                [$id],
            ),
        );
    }

    /**
     * A Requested return is declined where it stands, and the return beside it does not move.
     *
     * The control return is the cheap half of #624 and it is doing real work here: `decline()`
     * reaches a status column and appends to a notes column, and a guard written against the wrong
     * subject would hit every Requested row in the table.
     */
    public function aRequestedReturnIsDeclinedWithoutBeingAuthorisedFirst(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [$warehouseId, $vendorId, $productAId, $productBId, $receiptId] = $this->seed($I);

        $mistake = $this->raiseReturn($I, $vendorId, $receiptId, 'Raised against the wrong vendor.', [
            0 => ['product_id' => (string) $productAId, 'name' => 'Cancel Widget A', 'sku' => 'VRC-A', 'quantity' => '4.00', 'reason' => 'Overstock'],
        ]);
        $control = $this->raiseReturn($I, $vendorId, $receiptId, 'A genuine return, must be untouched.', [
            0 => ['product_id' => (string) $productBId, 'name' => 'Cancel Widget B', 'sku' => 'VRC-B', 'quantity' => '7.00', 'reason' => 'Damaged'],
        ]);

        // --- Before: both are Requested, agreed with nobody -----------------------------------------
        $em->clear();
        $mistakeBefore = $this->returnRow($em, $mistake);
        $controlBefore = $this->returnRow($em, $control);
        $I->assertSame('Requested', $mistakeBefore['status']);
        $I->assertSame('Requested', $controlBefore['status']);
        $I->assertNull($mistakeBefore['authorised_at'], 'guard: nothing has been agreed with the vendor yet');
        $I->assertSame(['7.00'], $this->lineQuantities($em, $control), 'guard: the control return names seven units before anything happens');

        // --- The screen offers the way out, without going through Authorise first --------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $mistake);
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the two assertions below: this heading proves the detail screen
        // rendered at all, so a missing element is a missing element and not a blank response.
        $I->see('Cancel Widget A');
        $I->seeElement('form[action$="/action/decline"]');
        $I->seeElement('form[action$="/action/authorise"]');

        $token = $I->grabAttributeFrom('form[action$="/action/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $mistake . '/action/decline', [
            '_token' => $token,
            'reason' => 'Typed against the wrong vendor.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- After: declined, and never authorised on the way ----------------------------------------
        $em->clear();
        $mistakeAfter = $this->returnRow($em, $mistake);
        $I->assertSame('Declined', $mistakeAfter['status'], 'vendor_return.status moved straight from Requested to Declined');
        $I->assertNull($mistakeAfter['authorised_at'], 'vendor_return.authorised_at is still NULL — no vendor agreement was fabricated to get here');
        $I->assertNull($mistakeAfter['shipped_at'], 'vendor_return.shipped_at is still NULL — declining moves no goods');
        $I->assertNull($mistakeAfter['warehouse_id'], 'no warehouse was stamped: nothing left any building');
        $I->assertStringContainsString('was Requested', (string) $mistakeAfter['notes'], 'the note records the state it was dropped from, so this stays distinguishable from a refusal at the vendor dock');
        $I->assertStringContainsString('Typed against the wrong vendor.', (string) $mistakeAfter['notes'], 'the typed reason is kept');

        // --- The row that must NOT have changed --------------------------------------------------------
        $controlAfter = $this->returnRow($em, $control);
        $I->assertSame('Requested', $controlAfter['status'], 'the unrelated return is still Requested');
        $I->assertNull($controlAfter['authorised_at']);
        $I->assertNull($controlAfter['notes'], 'nothing was appended to the control return’s notes');
        $I->assertSame($controlBefore['reason'], $controlAfter['reason'], 'and its reason is byte-for-byte what it was');
        $I->assertSame(['7.00'], $this->lineQuantities($em, $control), 'its line still names seven units');

        // --- And no stock moved for either ---------------------------------------------------------------
        foreach ([$productAId, $productBId] as $productId) {
            $inventory = $this->inventoryFor($em, $productId, $warehouseId);
            $I->assertSame('10.0000', $inventory->getAvailableQuantity(), 'declining is paperwork: the shelf is untouched');
            $I->assertSame('0.0000', $inventory->getWriteOffQuantity(), 'nothing was written off');
        }
    }

    /**
     * The empty return: the case that previously had no exit at all.
     *
     * Submitting the blank form is a real thing a person does, and `save()` has never required a
     * line. `authorise()` refuses a return with no units — so before this change the document was
     * permanent. The refusal that creates the trap is asserted here as well as the escape, because
     * the trap is the whole reason the escape has to exist.
     */
    public function theEmptyReturnThatUsedToHaveNoExitAtAll(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [, $vendorId, $productAId, , $receiptId] = $this->seed($I);

        // Submitting the form with every line row left blank, exactly as the screen posts them.
        $empty = $this->raiseReturn($I, $vendorId, $receiptId, 'Opened by mistake.', [
            0 => ['product_id' => '', 'name' => '', 'sku' => '', 'quantity' => '', 'reason' => ''],
            1 => ['product_id' => '', 'name' => '', 'sku' => '', 'quantity' => '', 'reason' => ''],
        ]);
        $control = $this->raiseReturn($I, $vendorId, $receiptId, 'A real return, must be untouched.', [
            0 => ['product_id' => (string) $productAId, 'name' => 'Cancel Widget A', 'sku' => 'VRC-A', 'quantity' => '3.00', 'reason' => 'Overstock'],
        ]);

        $em->clear();
        $I->assertSame([], $this->lineQuantities($em, $empty), 'the blank form really did produce a return with no lines');
        $I->assertSame('Requested', $this->returnRow($em, $empty)['status']);

        // --- The trap: it cannot be authorised, because there is nothing on it ------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $empty);
        $I->seeResponseCodeIsSuccessful();
        // Positive control: the empty state renders, so the page is really the return's detail screen.
        $I->see('This return names nothing');
        $authToken = $I->grabAttributeFrom('form[action$="/action/authorise"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $empty . '/action/authorise', ['_token' => $authToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Requested', $this->returnRow($em, $empty)['status'], 'authorise() still refuses a return with no units — that refusal is correct and is kept');

        // --- The exit that now exists ------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $empty);
        $I->seeElement('form[action$="/action/decline"]');
        $declineToken = $I->grabAttributeFrom('form[action$="/action/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $empty . '/action/decline', [
            '_token' => $declineToken,
            'reason' => 'Opened by mistake, nothing on it.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $emptyAfter = $this->returnRow($em, $empty);
        $I->assertSame('Declined', $emptyAfter['status'], 'an empty return is disposable without units on it — decline() carries no units check, and must not gain one');
        $I->assertNull($emptyAfter['authorised_at'], 'and without ever being authorised, which it could not have been anyway');

        // --- The row that must NOT have changed ----------------------------------------------------------
        $controlAfter = $this->returnRow($em, $control);
        $I->assertSame('Requested', $controlAfter['status'], 'the unrelated return is untouched by either the refused authorise or the decline');
        $I->assertNull($controlAfter['notes']);
        $I->assertSame(['3.00'], $this->lineQuantities($em, $control), 'and still names three units');
    }

    /**
     * The negative half, and the one that matters most: nothing past Requested loosened.
     *
     * A change that made a draft disposable by making everything disposable would pass both tests
     * above. So this drives an Authorised return and a Shipped one and proves the refusals that were
     * already there are still there:
     *
     *  - lines are frozen once authorised — `save()` refuses, and the quantity in the database is
     *    unchanged after a POST that tried to rewrite it;
     *  - `ship()` still demands Authorised, so a Requested return cannot reach the stock ledger;
     *  - Closed and Declined are still terminal and do not accept a further decline.
     */
    public function nothingPastRequestedBecameEditableOrReopenable(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [$warehouseId, $vendorId, $productAId, $productBId, $receiptId] = $this->seed($I);

        $authorised = $this->raiseReturn($I, $vendorId, $receiptId, 'Agreed with the vendor.', [
            0 => ['product_id' => (string) $productAId, 'name' => 'Cancel Widget A', 'sku' => 'VRC-A', 'quantity' => '4.00', 'reason' => 'Overstock'],
        ]);
        $stillDraft = $this->raiseReturn($I, $vendorId, $receiptId, 'Never agreed with anybody.', [
            0 => ['product_id' => (string) $productBId, 'name' => 'Cancel Widget B', 'sku' => 'VRC-B', 'quantity' => '2.00', 'reason' => 'Overstock'],
        ]);

        // --- A Requested return cannot be shipped: stock is still only reachable via Authorise --------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $stillDraft);
        $I->seeResponseCodeIsSuccessful();
        // Positive control paired with the absence assertion: the page rendered, and the ship form
        // is genuinely not on it rather than the whole response being empty.
        $I->see('Cancel Widget B');
        $I->dontSeeElement('form[action$="/ship"]');

        $shipToken = $I->grabAttributeFrom('form[action$="/action/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $stillDraft . '/ship', [
            '_token' => $shipToken,
            'warehouse_id' => (string) $warehouseId,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $draftRow = $this->returnRow($em, $stillDraft);
        $I->assertSame('Requested', $draftRow['status'], 'a Requested return still cannot be shipped — being droppable did not make it dispatchable');
        $I->assertNull($draftRow['shipped_at']);
        $I->assertSame('10.0000', $this->inventoryFor($em, $productBId, $warehouseId)->getAvailableQuantity(), 'and no units left the shelf for it');

        // --- Authorise the other one, then try to rewrite its lines -------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $authorised);
        $authToken = $I->grabAttributeFrom('form[action$="/action/authorise"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $authorised . '/action/authorise', ['_token' => $authToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Authorised', $this->returnRow($em, $authorised)['status'], 'guard: it really is authorised now');
        $I->assertSame(['4.00'], $this->lineQuantities($em, $authorised), 'guard: four units before the edit is attempted');

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $receiptId);
        $saveToken = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $saveToken,
            'id' => (string) $authorised,
            'vendor_id' => (string) $vendorId,
            'goods_receipt_id' => (string) $receiptId,
            'reason' => 'Rewritten after the vendor agreed.',
            'lines' => [
                0 => ['product_id' => (string) $productAId, 'name' => 'Cancel Widget A', 'sku' => 'VRC-A', 'quantity' => '99.00', 'reason' => 'Rewritten'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $authorisedRow = $this->returnRow($em, $authorised);
        $I->assertSame(['4.00'], $this->lineQuantities($em, $authorised), 'vendor_return_line.quantity is untouched: an authorised return is what the vendor agreed to take back');
        $I->assertSame('Agreed with the vendor.', $authorisedRow['reason'], 'and its header did not move either');
        $I->assertSame('Authorised', $authorisedRow['status']);

        // --- Ship it, then prove a shipped return is still frozen ----------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $authorised);
        $realShipToken = $I->grabAttributeFrom('form[action$="/ship"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $authorised . '/ship', [
            '_token' => $realShipToken,
            'warehouse_id' => (string) $warehouseId,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Shipped', $this->returnRow($em, $authorised)['status'], 'guard: shipping still works from Authorised');
        $I->assertSame('6.0000', $this->inventoryFor($em, $productAId, $warehouseId)->getAvailableQuantity(), 'guard: four units really left');

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $receiptId);
        $saveToken2 = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $saveToken2,
            'id' => (string) $authorised,
            'vendor_id' => (string) $vendorId,
            'goods_receipt_id' => (string) $receiptId,
            'reason' => 'Rewritten after the goods left.',
            'lines' => [
                0 => ['product_id' => (string) $productAId, 'name' => 'Cancel Widget A', 'sku' => 'VRC-A', 'quantity' => '1.00', 'reason' => 'Rewritten'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame(['4.00'], $this->lineQuantities($em, $authorised), 'a shipped return is a record of what physically left and stays frozen');
        $I->assertSame('Agreed with the vendor.', $this->returnRow($em, $authorised)['reason']);

        // --- Close it, and prove a terminal return still refuses a decline --------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $authorised);
        $closeToken = $I->grabAttributeFrom('form[action$="/action/close"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $authorised . '/action/close', ['_token' => $closeToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $closedRow = $this->returnRow($em, $authorised);
        $I->assertSame('Closed', $closedRow['status'], 'guard: closed from Shipped');

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $authorised);
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the absence below.
        $I->see('Cancel Widget A');
        $I->dontSeeElement('form[action$="/action/decline"]');

        // Forced past the screen, because the refusal has to hold in the entity and not only in Twig.
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $stillDraft);
        $forcedToken = $I->grabAttributeFrom('form[action$="/action/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $authorised . '/action/decline', [
            '_token' => $forcedToken,
            'reason' => 'Trying to reopen a settled matter.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $stillClosed = $this->returnRow($em, $authorised);
        $I->assertSame('Closed', $stillClosed['status'], 'Closed is still terminal: declining it is refused');
        $I->assertSame($closedRow['notes'], $stillClosed['notes'], 'and nothing was appended to its notes');

        // A second decline of an already-declined return is refused the same way.
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $stillDraft);
        $dropToken = $I->grabAttributeFrom('form[action$="/action/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $stillDraft . '/action/decline', [
            '_token' => $dropToken,
            'reason' => 'Dropped.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $declinedOnce = $this->returnRow($em, $stillDraft);
        $I->assertSame('Declined', $declinedOnce['status'], 'guard: the draft dropped cleanly');

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $authorised);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $stillDraft . '/action/decline', [
            '_token' => $dropToken,
            'reason' => 'Dropped twice.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $declinedTwice = $this->returnRow($em, $stillDraft);
        $I->assertSame($declinedOnce['notes'], $declinedTwice['notes'], 'Declined is terminal too: a second decline appends nothing');
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
