<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;

/**
 * The row actions the three bundle list screens never had, and the routes behind them (#613).
 *
 * The owner's complaint was "table where is the view details button, edit button, delete buton?" and
 * the answer for several of these was that there was no route to put a button on: nothing in
 * InventoryDepthBundle deleted anything, a mistyped transfer line meant cancelling the draft and
 * retyping it, and a receipt booked against the wrong purchase order could not be undone at all.
 *
 * Two rules run through everything here and both are asserted rather than assumed:
 *
 *  - **a delete refuses rather than cascades.** Every column that names a bin, a lot or a vendor is
 *    either SET NULL or NOT NULL, so issuing the DELETE and letting the database decide would either
 *    strand the row or take the documents with it. Each refusal names the table holding it.
 *  - **nothing needs JavaScript.** Every POST below goes through sendFormPostRequest(), i.e. a plain
 *    browser form post with no `X-Requested-With` header — what a browser with scripting off sends
 *    when a submit button is pressed. The markup assertions check that the buttons are real submits
 *    against real forms rather than `js-delete` hooks, and that those forms are emitted OUTSIDE the
 *    filter form: a <form> inside a <form> is invalid HTML and every parser drops the inner one, so
 *    a nested delete button would silently submit the filters instead.
 *
 * The assertions are about rows — which `inventory_lot` row survived, what
 * `purchase_order_line.quantity_received` reads afterwards. Seeing a flash message is not evidence
 * that anything changed.
 */
final class BundleRowActionsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('row-actions-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A dimensional product, one warehouse, two bins and a lot. The opening balance the switcher
     * writes carries no location, so both bins start EMPTY — stock only reaches one when a test
     * deliberately puts it there.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, spare: WarehouseLocation, lot: InventoryLot}
     */
    private function seed(FunctionalTester $I, int $quantity = 40): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Row Actions Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('ROWACT-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Row Actions Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('R-01')->setSortKey(10);
        $spare = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('R-02')->setSortKey(20);
        $em->persist($bin);
        $em->persist($spare);

        $lot = (new InventoryLot())
            ->setProduct($product)
            ->setCode('ROWBATCH-1')
            ->setExpiry(new \DateTimeImmutable('2028-01-31'));
        $em->persist($lot);

        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'row-actions@example.test');

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'spare' => $spare, 'lot' => $lot];
    }

    /** Puts real stock into a bin on a lot, through the service that writes the movement. */
    private function stockInto(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, ?WarehouseLocation $bin, ?InventoryLot $lot, int $quantity): void
    {
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'row-actions-' . uniqid(), 'Put away for a row-action test')
                ->receive($product, new DetailKey($warehouse, $bin, $lot, null, InventoryDetail::STATUS_AVAILABLE), $quantity)
        );
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $I->grabService('doctrine.orm.entity_manager');

        return $em;
    }

    // ------------------------------------------------------------------ bins

    /**
     * The markup, before any behaviour: the button has to be a real submit against a real POST form,
     * and that form has to be outside the filter form or the browser drops it.
     */
    public function theBinsListOffersViewEditAndAPlainPostDelete(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $binId = (int) $seed['bin']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/bins');
        $I->seeResponseCodeIsSuccessful();

        // View, Count and Edit all reach a route, and Edit carries the row id — without it the
        // hidden id on the form can only ever be 0 and every save is a create (#590).
        $I->seeElement('a.table-action[href*="stock/location"]');
        $I->seeElement('a.table-action[href*="edit=' . $binId . '"]');

        // The delete: a submit button pointed at a form emitted below the filter form, method POST.
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-bin-' . $binId . '"]');
        $I->seeElement('form#delete-bin-' . $binId . '[method="post"]');
        // Not a js- hook. A delete reachable only through JavaScript is a delete that does not exist
        // with scripting off, which this application's whole no-JS baseline forbids.
        $I->dontSeeElement('button.js-delete');
    }

    /** A bin nothing has ever pointed at really goes, through a plain no-JS form post. */
    public function deletingABinNothingPointsAtRemovesTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $binId = (int) $seed['spare']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/bins');
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/' . $binId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNull($em->find(WarehouseLocation::class, $binId), 'the bin survived a delete that had nothing to refuse on');
    }

    /**
     * The refused delete. `inventory_detail.location_id` is ON DELETE SET NULL, so the database
     * would have accepted this and left the stock standing nowhere — the refusal is the whole point.
     */
    public function deletingABinWithStockInItIsRefusedAndSaysWhatHoldsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $binId = (int) $seed['bin']->getId();

        $this->stockInto($I, $seed['product'], $seed['warehouse'], $seed['bin'], null, 12);

        $I->amOnPage('/admin/bundles/inventory-depth/bins');
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/bins/' . $binId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNotNull($em->find(WarehouseLocation::class, $binId), 'a bin holding stock was deleted');
        // The refusal names the table, because that is what somebody has to go and look at.
        $I->see('inventory_detail');
    }

    // ------------------------------------------------------------------ lots

    /** A lot nothing has ever named goes; the row id is what the assertion is about. */
    public function deletingALotNothingNamesRemovesThatRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $lotId = (int) $seed['lot']->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-lot-' . $lotId . '"]');
        $I->seeElement('form#delete-lot-' . $lotId . '[method="post"]');

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/' . $lotId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNull($em->find(InventoryLot::class, $lotId), 'the lot survived a delete that had nothing to refuse on');
    }

    /**
     * A lot with stock on it stays. `inventory_detail.lot_id` is SET NULL, so deleting would have
     * untracked the batch rather than refused — the mirror image of the fork #590 fixed.
     */
    public function deletingALotWithStockOnItIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $lotId = (int) $seed['lot']->getId();

        $this->stockInto($I, $seed['product'], $seed['warehouse'], $seed['bin'], $seed['lot'], 8);

        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/' . $lotId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNotNull($em->find(InventoryLot::class, $lotId), 'a lot holding stock was deleted');
        $I->see('inventory_detail');
    }

    // -------------------------------------------------------------- transfers

    /**
     * @return array{transfer: TransferOrder, line: TransferOrderLine}
     */
    private function draftTransfer(FunctionalTester $I, array $seed): array
    {
        $em = $this->em($I);

        $otherRegion = (new FulfillmentRegion())->setName('Row Actions Region B ' . uniqid());
        $em->persist($otherRegion);
        $destination = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($otherRegion, 'BC', 'CA');

        $transfer = (new TransferOrder())
            ->setNumber('TR-ROW-' . random_int(1000, 9999))
            ->setFromWarehouse($seed['warehouse'])
            ->setToWarehouse($destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);
        $em->persist($transfer);

        $line = (new TransferOrderLine())
            ->setProduct($seed['product'])
            ->setSku($seed['product']->getSku())
            ->setName($seed['product']->getName())
            ->setQuantityRequested(5);
        $transfer->addLine($line);
        $em->persist($line);
        $em->flush();

        return ['transfer' => $transfer, 'line' => $line];
    }

    /**
     * The line edit #613 asked for first: a mistyped quantity used to mean cancelling the draft and
     * starting over. The assertion is that the SAME `transfer_order_line` row changed rather than a
     * second one appearing beside it — the fork failure mode #590 found on lots.
     */
    public function editingATransferLineUpdatesThatRowRatherThanAddingASecond(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();
        $lineId = (int) $draft['line']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId . '?edit=' . $lineId);
        $I->seeResponseCodeIsSuccessful();
        // The form filled from that row, and the hidden id naming it.
        $I->seeElement('input[type="hidden"][name="line_id"][value="' . $lineId . '"]');
        $I->seeElement('input[name="quantity"][value="5"]');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/transfers/' . $transferId . '/lines/update', [
            '_token' => $I->csrfToken(),
            'line_id' => (string) $lineId,
            'quantity' => '9',
            'lot_id' => (string) $seed['lot']->getId(),
            'serial' => '',
        ]);

        $em = $this->em($I);
        $em->clear();
        $updated = $em->find(TransferOrderLine::class, $lineId);
        $I->assertNotNull($updated, 'the edit deleted the row it was supposed to update');
        $I->assertSame('9.0000', $updated->getQuantityRequested());
        $I->assertSame((int) $seed['lot']->getId(), (int) $updated->getLot()?->getId());
        $I->assertCount(1, $em->find(TransferOrder::class, $transferId)->getLines(), 'the edit forked the line instead of updating it');
    }

    /**
     * A `line_id` that names no row is refused, never treated as a create. A stale Edit link must
     * not mint a line for goods somebody was trying to correct — the exact fall-through #590 fixed
     * on lots, which is why it is pinned here too.
     */
    public function anUnknownLineIdIsRefusedRatherThanAddingALine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/transfers/' . $transferId . '/lines/update', [
            '_token' => $I->csrfToken(),
            'line_id' => '99999999',
            'quantity' => '3',
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertCount(1, $em->find(TransferOrder::class, $transferId)->getLines(), 'a stale edit link created a line');
    }

    /** Removing a draft line, through a plain form post with no JavaScript involved. */
    public function deletingADraftTransferLineRemovesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();
        $lineId = (int) $draft['line']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-line-' . $lineId . '"]');
        $I->seeElement('form#delete-line-' . $lineId . '[method="post"]');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/transfers/' . $transferId . '/lines/' . $lineId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNull($em->find(TransferOrderLine::class, $lineId));
    }

    /** transfer_cancel has existed since #552 with no way to reach it from the list. Now there is. */
    public function theTransfersListCanReachDetailEditCancelAndDelete(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a.table-action[href="/admin/bundles/warehouse-ops/transfers/' . $transferId . '"]');
        $I->seeElement('button.table-action[type="submit"][form="cancel-transfer-' . $transferId . '"]');
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-transfer-' . $transferId . '"]');
        $I->seeElement('form#cancel-transfer-' . $transferId . '[method="post"]');
        $I->seeElement('form#delete-transfer-' . $transferId . '[method="post"]');
    }

    public function deletingADraftTransferTakesItsLinesWithIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();
        $lineId = (int) $draft['line']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers');
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/transfers/' . $transferId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNull($em->find(TransferOrder::class, $transferId));
        $I->assertNull($em->find(TransferOrderLine::class, $lineId), 'the line outlived the document that cascades it');
    }

    /**
     * A dispatched transfer is not deletable. Those units are on `inventory_detail` as in-transit
     * rows and this document is the only thing saying where they were going.
     */
    public function deletingADispatchedTransferIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $draft = $this->draftTransfer($I, $seed);
        $transferId = (int) $draft['transfer']->getId();

        $em = $this->em($I);
        $draft['transfer']->setStatus(TransferOrder::STATUS_DISPATCHED)->setDispatchedAt(new \DateTimeImmutable());
        $draft['line']->setQuantityDispatched(5);
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/transfers/' . $transferId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em->clear();
        $I->assertNotNull($em->find(TransferOrder::class, $transferId), 'a dispatched transfer was deleted');
        $I->see('cannot be deleted');
    }

    // ------------------------------------------------------------- pick lists

    private function pickList(FunctionalTester $I, array $seed, string $status): PickList
    {
        $em = $this->em($I);

        $list = (new PickList())
            ->setNumber('PL-ROW-' . random_int(1000, 9999))
            ->setWarehouse($seed['warehouse'])
            ->setStatus($status);
        $em->persist($list);
        $em->flush();

        return $list;
    }

    public function deletingAClosedPickListWithNothingPickedRemovesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $list = $this->pickList($I, $seed, PickList::STATUS_CANCELLED);
        $listId = (int) $list->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists');
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-pick-' . $listId . '"]');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/' . $listId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNull($em->find(PickList::class, $listId));
    }

    /** An open round is one somebody may be walking. Close or cancel it first. */
    public function deletingAnOpenPickListIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $list = $this->pickList($I, $seed, PickList::STATUS_RELEASED);
        $listId = (int) $list->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists');
        // No button on an open round at all — the refusal is the backstop, not the affordance.
        $I->dontSeeElement('button[form="delete-pick-' . $listId . '"]');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/' . $listId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertNotNull($em->find(PickList::class, $listId), 'a released pick list was deleted');
    }

    // ---------------------------------------------------------------- vendors

    /**
     * @return array{vendor: Vendor, order: PurchaseOrder, line: PurchaseOrderLine}
     */
    private function vendorWithAnIssuedOrder(FunctionalTester $I, array $seed): array
    {
        $em = $this->em($I);

        $vendor = (new Vendor())->setName('Row Actions Supply ' . uniqid())->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-ROW-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($seed['warehouse'])
            ->setExpectedDate('2026-12-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($seed['product'])
            ->setName($seed['product']->getName())
            ->setSku($seed['product']->getSku())
            ->setQuantityOrdered('20.00')
            ->setUnitCost('3.0000')
            ->setSubtotal('60.00');
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        $order->setStatus('Issued', DocumentActor::system());
        $order->recalculateTotals();
        $em->flush();

        return ['vendor' => $vendor, 'order' => $order, 'line' => $line];
    }

    /**
     * The owner's own example: each row had no way to reach /vendors/{id}. Now it has a row menu.
     *
     * The three flat buttons became one `.row-action-dropdown` when the vendor list was conformed
     * to `/admin/company` (#660) — same control, same classes, same place. Delete is gone from it
     * now: the customer record's own row menu (admin/company/_list_rows.html.twig) has never
     * offered one — a customer is only ever deactivated — and the vendor record was rebuilt to
     * that same shape. The absence is asserted with the presence of the row menu itself as its
     * positive control, so a selector typo cannot pass this by matching nothing twice.
     */
    public function theVendorsListOffersViewAndEditButNoHardDelete(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);
        $vendor = (new Vendor())->setName('Row Actions Untouched ' . uniqid());
        $em->persist($vendor);
        $em->flush();
        $vendorId = (int) $vendor->getId();
        $detail = '/admin/bundles/procurement/vendors/' . $vendorId;

        $I->amOnPage('/admin/bundles/procurement/vendors');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('td[data-label="Actions"] .row-action-menu button.table-action.row-action-toggle');
        $I->seeElement('.row-action-dropdown a[href="' . $detail . '"]');
        $I->seeElement('.row-action-dropdown a[href="/admin/bundles/procurement/vendors/' . $vendorId . '/edit"]');
        $I->dontSeeElement('.row-action-dropdown button.danger');
        $I->dontSeeElement('form#delete-vendor-' . $vendorId);
    }

    // --------------------------------------------------------------- receipts

    /**
     * Books a real delivery in through ReceivingService — the same path the receiving bay uses, so
     * the receipt, its movement group and `purchase_order_line.quantity_received` are all written
     * the way the application writes them.
     */
    private function bookIn(FunctionalTester $I, array $seed, array $fixture, string $quantity): GoodsReceipt
    {
        $request = ReceivingRequest::againstPurchaseOrder(
            $fixture['order'],
            'SLIP-' . uniqid(),
            'row-actions@example.test',
            null,
            null,
            'row-actions-receive-' . uniqid(),
        );
        $request->add($seed['product'], $quantity, $fixture['line'], null, null, null, $seed['bin'], '3.0000');

        return $I->grabService(ReceivingService::class)->receive($request, 'row-actions@example.test');
    }

    /**
     * The one with lasting consequences. A receipt booked to the wrong PO leaves
     * `purchase_order_line.quantity_received` and every three-way match permanently wrong, and an
     * adjustment fixes the stock and reaches none of that.
     *
     * Voiding is a SECOND FACT: the receipt keeps its number, its lines and its original movement
     * group, gains a void stamp and a second movement group, and the PO line's running total comes
     * back down.
     */
    public function voidingAReceiptTakesTheStockBackOutAndUncreditsThePurchaseOrderLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);

        $receipt = $this->bookIn($I, $seed, $fixture, '6.00');
        $receiptId = (int) $receipt->getId();
        $lineId = (int) $fixture['line']->getId();

        $em = $this->em($I);
        $em->clear();
        // Through QuantityScale::canonical(), the accessor every reader of this column uses: SQLite
        // stores NUMERIC and hands '6.00' back as '6', so comparing the raw string would be a test
        // about SQLite.
        $I->assertSame('6.0000', QuantityScale::canonical($em->find(PurchaseOrderLine::class, $lineId)->getQuantityReceived()));

        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form[action="/admin/bundles/procurement/receiving/' . $receiptId . '/void"][method="post"]');

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/' . $receiptId . '/void', [
            '_token' => $I->csrfToken(),
            'reason' => 'Booked against the wrong purchase order.',
        ]);

        $em->clear();
        $voided = $em->find(GoodsReceipt::class, $receiptId);
        $I->assertNotNull($voided, 'the receipt was deleted rather than voided');
        $I->assertTrue($voided->isVoided());
        $I->assertSame('Booked against the wrong purchase order.', $voided->getVoidReason());
        // Both halves in the ledger: what it did, and what undid it.
        $I->assertNotNull($voided->getMovementGroup(), 'voiding unwrote the original movement group');
        $I->assertNotNull($voided->getVoidMovementGroup());
        $I->assertNotSame($voided->getMovementGroup()->getId(), $voided->getVoidMovementGroup()->getId());
        // The lines still say what was on the dock.
        $I->assertCount(1, $voided->getLines());

        // The paperwork half, which is the whole reason this exists.
        $I->assertSame('0.0000', QuantityScale::canonical($em->find(PurchaseOrderLine::class, $lineId)->getQuantityReceived()));
    }

    /** A second void would take units off the shelf this receipt never put there. */
    public function voidingAReceiptTwiceIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);

        $receipt = $this->bookIn($I, $seed, $fixture, '4.00');
        $receiptId = (int) $receipt->getId();
        $lineId = (int) $fixture['line']->getId();

        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/' . $receiptId . '/void', [
            '_token' => $I->csrfToken(),
            'reason' => 'Wrong warehouse.',
        ]);

        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/' . $receiptId . '/void', [
            '_token' => $I->csrfToken(),
            'reason' => 'Wrong warehouse, again.',
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->see('already voided');
        // The un-crediting happened exactly once: a second one would have driven the line negative,
        // and the clamp would have hidden it at 0.00 either way — so the reason it reads 0.00 is
        // that the first void ran and the second did not.
        $I->assertSame('0.0000', QuantityScale::canonical($em->find(PurchaseOrderLine::class, $lineId)->getQuantityReceived()));
        $I->assertSame('Wrong warehouse.', $em->find(GoodsReceipt::class, $receiptId)->getVoidReason());
    }

    /** Voiding with no reason is refused: a decision with nothing on it is unauditable later. */
    public function voidingAReceiptWithNoReasonIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);

        $receipt = $this->bookIn($I, $seed, $fixture, '4.00');
        $receiptId = (int) $receipt->getId();

        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/' . $receiptId . '/void', [
            '_token' => $I->csrfToken(),
            'reason' => '   ',
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertFalse($em->find(GoodsReceipt::class, $receiptId)->isVoided());
    }

    /**
     * The stock has moved on since. StockMovementService::assertSourcesCanCover() refuses, the whole
     * void rolls back, and the receipt stays exactly as it was — the honest outcome, because
     * unwinding it would leave the ledger short with nothing saying why.
     */
    public function voidingAReceiptWhoseStockHasGoneIsRefusedAndChangesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);

        $receipt = $this->bookIn($I, $seed, $fixture, '6.00');
        $receiptId = (int) $receipt->getId();
        $lineId = (int) $fixture['line']->getId();

        // Out of the bin the receipt put it in, into the other one — the source row the void would
        // draw on no longer holds it.
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'row-actions-move-' . uniqid(), 'Moved to another bin')
                ->move(
                    $seed['product'],
                    new DetailKey($seed['warehouse'], $seed['bin'], null, null, InventoryDetail::STATUS_AVAILABLE),
                    new DetailKey($seed['warehouse'], $seed['spare'], null, null, InventoryDetail::STATUS_AVAILABLE),
                    6,
                )
        );

        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/' . $receiptId . '/void', [
            '_token' => $I->csrfToken(),
            'reason' => 'Booked against the wrong purchase order.',
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertFalse($em->find(GoodsReceipt::class, $receiptId)->isVoided(), 'the receipt was voided while its stock was elsewhere');
        // The un-crediting is inside the same transaction as the movement, so it rolled back too.
        $I->assertSame('6.0000', QuantityScale::canonical($em->find(PurchaseOrderLine::class, $lineId)->getQuantityReceived()), 'the PO line was un-credited by a void that failed');
    }

    /** The receipts list reaches the detail and the void, and offers no Edit — deliberately. */
    public function theReceiptsListReachesDetailAndVoid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);
        $receipt = $this->bookIn($I, $seed, $fixture, '2.00');
        $receiptId = (int) $receipt->getId();

        $I->amOnPage('/admin/bundles/procurement/receiving');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a.table-action[href="/admin/bundles/procurement/receiving/' . $receiptId . '"]');
        $I->seeElement('a.table-action.danger[href="/admin/bundles/procurement/receiving/' . $receiptId . '#void"]');
    }

    // ------------------------------------------------- purchase orders, bills

    /**
     * Cancel and Edit reachable from the row; still no Delete, because a PO is a document.
     *
     * The three flat buttons became one `.row-action-dropdown` when the purchase order list was
     * conformed to /admin/invoice — the same move the vendor list made above and the vendor bill
     * list made in #658 — so the selectors moved with them. What is asserted has not changed: View,
     * Edit and Cancel are each reachable from the row, and Cancel is a real submit against a real
     * `<form>` emitted OUTSIDE the filter form, because a <form> inside a <form> is dropped by every
     * parser and the button would then submit the filters instead.
     */
    public function thePurchaseOrdersListReachesDetailEditAndCancel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);
        $orderId = (int) $fixture['order']->getId();
        $detail = '/admin/bundles/procurement/purchase-orders/' . $orderId;

        $I->amOnPage('/admin/bundles/procurement/purchase-orders');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('td[data-label="Actions"] .row-action-menu button.table-action.row-action-toggle');
        $I->seeElement('.row-action-dropdown a[href="' . $detail . '"]');
        $I->seeElement('.row-action-dropdown a[href="' . $detail . '/edit"]');
        $I->seeElement('.row-action-dropdown button.table-action.danger[type="submit"][form="cancel-po-' . $orderId . '"]');
        $I->seeElement('form#cancel-po-' . $orderId . '[method="post"]');

        // The cancel form is a sibling of the filter form, never a child of it.
        $I->dontSeeElement('form#po-filters form#cancel-po-' . $orderId);
    }

    /** Cancelling from the list, with JavaScript off, really moves purchase_order.status. */
    public function cancellingAPurchaseOrderFromTheListMovesItsStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $fixture = $this->vendorWithAnIssuedOrder($I, $seed);
        $orderId = (int) $fixture['order']->getId();

        $I->amOnPage('/admin/bundles/procurement/purchase-orders');
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $orderId . '/cancel', [
            '_token' => $I->csrfToken(),
        ]);

        $em = $this->em($I);
        $em->clear();
        $I->assertSame('Cancelled', $em->find(PurchaseOrder::class, $orderId)->getStatus());
    }
}
