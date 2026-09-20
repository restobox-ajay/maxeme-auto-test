<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorReturn;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Vendor return -> stock leaving -> debit memo (#638), driven through the real admin screens per
 * #624: raise a return against a receipt with TWO lines, ship only one of them, and assert both the
 * row that moved AND the row that must not have.
 */
final class VendorReturnAndDebitMemoCest
{
    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here survives the rollback and is read by whatever runs next. The return and
     * the debit memo each allocate a number through PurchaseDocumentNumberGenerator, which reads its
     * prefix through that cache — so this clears it for the same reason ProcurementScreensCest does.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-return-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Two dimensional products, ten units of each already on the shelf (booked the way stock
     * actually arrives, exactly as SalesReturnReceiptTest does for the sell-side mirror), plus a
     * GoodsReceipt naming both — the receipt the vendor return is raised against.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} warehouseId, vendorId, productAId, productBId, receiptId
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Vendor Return Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Returnable Supply ' . uniqid());
        $em->persist($vendor);

        $productA = (new ProductCore())
            ->setSku('VR-A-' . random_int(1000, 9999))
            ->setName('Vendor Return Widget A')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $productB = (new ProductCore())
            ->setSku('VR-B-' . random_int(1000, 9999))
            ->setName('Vendor Return Widget B — must not move')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        // Ten of each, already on the shelf.
        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'vr-seed-a-' . uniqid())
                ->receive($productA, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
        );
        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'vr-seed-b-' . uniqid())
                ->receive($productB, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
        );
        $em->flush();

        $receipt = (new GoodsReceipt())
            ->setReceiptNumber('RC-VR-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->setWarehouse($warehouse);
        $em->persist($receipt);

        $lineA = (new GoodsReceiptLine())->setProduct($productA)->setName($productA->getName())->setSku($productA->getSku())->setQuantity('10.00');
        $lineB = (new GoodsReceiptLine())->setProduct($productB)->setName($productB->getName())->setSku($productB->getSku())->setQuantity('10.00');
        $receipt->addLine($lineA);
        $receipt->addLine($lineB);
        $em->persist($lineA);
        $em->persist($lineB);
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
     * Return 4 units of product A against the receipt. Product B's receipt line and stock must be
     * completely untouched — the negative #624 exists to force, and the one #638 names by name.
     */
    public function shippingAVendorReturnMovesOnlyTheNamedProductAndDebitsTheBill(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [$warehouseId, $vendorId, $productAId, $productBId, $receiptId] = $this->seed($I);

        // --- Raise the return against the receipt, for product A only ---------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        // The receipt's own lines are transcribed into the form at zero, so a return for part of a
        // delivery is the default and sending the whole thing back is a deliberate act — the same
        // default the sell-side RMA editor takes, for the same reason.
        $I->seeInField('lines[0][name]', 'Vendor Return Widget A');
        $I->seeInField('lines[0][quantity]', '0.00');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $vendorId,
            'goods_receipt_id' => (string) $receiptId,
            'reason' => 'Overstock.',
            'lines' => [
                0 => ['product_id' => (string) $productAId, 'name' => 'Vendor Return Widget A', 'sku' => 'VR-A', 'quantity' => '4.00', 'reason' => 'Overstock'],
                1 => ['product_id' => (string) $productBId, 'name' => 'Vendor Return Widget B', 'sku' => 'VR-B', 'quantity' => '0.00'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        /** @var VendorReturn $vendorReturn */
        $vendorReturn = $em->getRepository(VendorReturn::class)->findOneBy(['goodsReceipt' => $receiptId]);
        $I->assertNotNull($vendorReturn, 'the save form created the return');
        $returnId = (int) $vendorReturn->getId();
        $I->assertCount(1, $vendorReturn->getLines(), 'the zero-quantity line for product B was dropped, exactly like every other line editor in this app');

        // --- Authorise ---------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId);
        $authToken = $I->grabAttributeFrom('form[action$="/action/authorise"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $returnId . '/action/authorise', ['_token' => $authToken]);
        $I->seeResponseCodeIsSuccessful();

        // --- Guard: before shipping, nothing has moved yet ----------------------------------------
        $before = $this->inventoryFor($em, $productAId, $warehouseId);
        $I->assertSame('10.0000', $before->getAvailableQuantity(), 'guard: authorising is not shipping — stock is untouched');

        // --- Ship ----------------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId);
        $shipToken = $I->grabAttributeFrom('form[action$="/ship"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $returnId . '/ship', [
            '_token' => $shipToken,
            'warehouse_id' => (string) $warehouseId,
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- Assert product A: 4 units left, at the right status and location ----------------------
        $em->clear();
        $afterA = $this->inventoryFor($em, $productAId, $warehouseId);
        $I->assertSame('6.0000', $afterA->getAvailableQuantity(), 'four units left the shelf');
        $I->assertSame('10.0000', $afterA->getReceivedQuantity(), 'received is a lifetime count of what arrived and does not move on the way back out');
        $I->assertSame('4.0000', $afterA->getWriteOffQuantity(), 'folded into the existing write_off bucket — see InventoryDetail::STATUS_RETURNED_TO_VENDOR');

        $returnedRow = $em->getConnection()->fetchAssociative(
            "SELECT quantity, warehouse_id, location_id FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$productAId],
        );
        $I->assertNotFalse($returnedRow, 'a real inventory_detail row was written at the terminal status');
        $I->assertSame(4, (int) $returnedRow['quantity']);
        $I->assertSame($warehouseId, (int) $returnedRow['warehouse_id'], 'it names the warehouse the goods actually shipped from');
        $I->assertNull($returnedRow['location_id'], 'terminal statuses drop their bin — the goods left the building');

        // --- The negative: product B is completely untouched ----------------------------------------
        $afterB = $this->inventoryFor($em, $productBId, $warehouseId);
        $I->assertSame('10.0000', $afterB->getAvailableQuantity(), 'product B was never named on the shipped return and must still be whole');
        $I->assertSame('0.0000', $afterB->getWriteOffQuantity(), 'nothing wrote it off');

        $stillReturnedB = (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status = 'returned_to_vendor'",
            [$productBId],
        );
        $I->assertSame(0, $stillReturnedB, 'no row was ever written for the product that did not ship');

        $receiptLineB = $em->getConnection()->fetchAssociative('SELECT quantity FROM goods_receipt_line WHERE goods_receipt_id = ? AND product_id = ?', [$receiptId, $productBId]);
        // Formatted rather than compared raw: SQLite's NUMERIC affinity hands a scalar fetch back as
        // an int/float, not the decimal string Doctrine's own type conversion produces.
        $I->assertSame('10.00', number_format((float) $receiptLineB['quantity'], 2, '.', ''), 'the receipt line for the untouched product is exactly as it was booked in');

        $vendorReturn = $em->getRepository(VendorReturn::class)->find($returnId);
        $I->assertSame('Shipped', $vendorReturn->getStatus()->value);

        // --- Now the money: a debit memo against this return, applied to a real bill ------------------
        $vendor = $em->getRepository(Vendor::class)->find($vendorId);
        $bill = (new VendorBill())->setBillNumber('BILL-VR-' . random_int(1000, 9999))->setVendor($vendor)->setVendorName($vendor->getName())->setTotal('500.00');
        $em->persist($bill);
        $em->flush();
        $billId = (int) $bill->getId();

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?vendor_return=' . $returnId);
        $I->seeResponseCodeIsSuccessful();
        $memoToken = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $memoToken,
            'id' => '0',
            'vendor_id' => (string) $vendorId,
            'vendor_return_id' => (string) $returnId,
            'reason' => 'Overstock return.',
            'lines' => [
                0 => ['name' => 'Vendor Return Widget A', 'sku' => 'VR-A', 'quantity' => '4.00', 'unit_cost' => '5.0000'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $memo = $em->getRepository(DebitMemo::class)->findOneBy(['vendorReturn' => $returnId]);
        $I->assertNotNull($memo, 'the save form created the debit memo');
        $memoId = (int) $memo->getId();
        // Formatted: SQLite's NUMERIC affinity stores '20.00' as the number 20, so a decimal column
        // read back through Doctrine is '20' rather than the string that was written.
        $I->assertSame('20.00', number_format((float) $memo->getTotal(), 2, '.', ''), '4 units at $5.00');

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', ['_token' => $issueToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $applyToken = $I->grabAttributeFrom('form[action$="/apply"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/apply', [
            '_token' => $applyToken,
            'vendor_bill_id' => (string) $billId,
            'amount' => '20.00',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $memo = $em->getRepository(DebitMemo::class)->find($memoId);
        $I->assertSame('Closed', $memo->getStatus()->value, 'the whole balance was applied');
        $I->assertSame('0.00', $memo->getBalance(), 'balance = total - applied - refunded, derived, never stored');

        $application = $em->getConnection()->fetchAssociative('SELECT amount, vendor_bill_id FROM debit_memo_application WHERE debit_memo_id = ?', [$memoId]);
        $I->assertNotFalse($application, 'a real application row landed in the database');
        $I->assertSame('20.00', number_format((float) $application['amount'], 2, '.', ''));
        $I->assertSame($billId, (int) $application['vendor_bill_id'], 'applied to the specific bill named on the form, not merely any bill for the vendor');
    }

    private function inventoryFor(\Doctrine\ORM\EntityManagerInterface $em, int $productId, int $warehouseId): ProductInventory
    {
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy(['product' => $productId, 'warehouse' => $warehouseId]);
        if (!$inventory instanceof ProductInventory) {
            throw new \RuntimeException('No product_inventory row for that product/warehouse pair.');
        }

        return $inventory;
    }
}
