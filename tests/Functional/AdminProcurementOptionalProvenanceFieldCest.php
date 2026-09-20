<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorReturn;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A debit memo, a vendor return, or a bill raised standalone (#773) — no `?receipt=`, no `?order=`
 * query, nothing to link it to. `vendor_return_id`/`vendor_bill_id`/`goods_receipt_id`/
 * `purchase_order_id` always render, whether or not there is a document to name — an empty hidden
 * input or a select's own blank first option, never the field left off the form.
 *
 * The value posted here is READ OFF THE REAL RENDERED PAGE, not typed in by the test, which is the
 * whole point: a hand-crafted `'purchase_order_id' => '0'` (the shape the OLD, still-passing smoke
 * test for this same screen used) proves nothing about what a browser actually submits for a form
 * it never touched.
 */
final class AdminProcurementOptionalProvenanceFieldCest
{
    public function savingAStandaloneDebitMemoAsRenderedSucceeds(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new');
        $I->seeResponseCodeIsSuccessful();
        $vendorReturnId = $I->grabAttributeFrom('input[name="vendor_return_id"]', 'value');
        $vendorBillId = $I->grabAttributeFrom('input[name="vendor_bill_id"]', 'value');
        $I->assertSame('', $vendorReturnId, 'guard: the case under test is the blank hidden input');
        $I->assertSame('', $vendorBillId, 'guard: the case under test is the blank hidden input');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'vendor_return_id' => $vendorReturnId,
            'vendor_bill_id' => $vendorBillId,
            'reason' => 'Standalone restock.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $memo = $I->grabService(EntityManagerInterface::class)->getRepository(DebitMemo::class)->findOneBy(['vendor' => $vendor]);
        $I->assertInstanceOf(DebitMemo::class, $memo, 'the save must have gone through, not 400ed');
        $I->assertNull($memo->getVendorReturn());
        $I->assertNull($memo->getVendorBill());
    }

    public function savingAStandaloneVendorReturnAsRenderedSucceeds(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new');
        $I->seeResponseCodeIsSuccessful();
        $receiptId = $I->grabAttributeFrom('input[name="goods_receipt_id"]', 'value');
        $I->assertSame('', $receiptId, 'guard: the case under test is the blank hidden input');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'goods_receipt_id' => $receiptId,
            'reason' => 'Standalone return.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $return = $I->grabService(EntityManagerInterface::class)->getRepository(VendorReturn::class)->findOneBy(['vendor' => $vendor]);
        $I->assertInstanceOf(VendorReturn::class, $return, 'the save must have gone through, not 400ed');
        $I->assertNull($return->getGoodsReceipt());
    }

    /**
     * The trickiest of the three: purchase_order_id AND warehouse_id are both SELECTs here, not
     * plain hidden inputs — and both default to a blank `value=""` first option with no PO chosen.
     *
     * Reopened (#773, 2026-09-20): the first version of this test never posted `warehouse_id` at
     * all, so it never actually reproduced the bug report's own words — "the same POST with those
     * fields omitted saves fine." `VendorBillController::receivingWarehouse()` read
     * `$request->request->getInt('warehouse_id', 0)` directly, missing the same `optionalId()`
     * guard the other three fields already had.
     */
    public function savingAStandaloneBillAsRenderedSucceeds(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);

        $I->amOnPage('/admin/bundles/procurement/bills/new');
        $I->seeResponseCodeIsSuccessful();
        // No <option> here carries `selected` — a plain <select>'s own default, per spec, is
        // whichever option comes first, exactly as an unmodified <select> submits from a real
        // browser. grabValueFrom() reads the tag's own `value` attribute, which a <select> (unlike
        // an <input>) does not have; the option that would actually be submitted is this one.
        $orderId = $I->grabAttributeFrom('select[name="purchase_order_id"] option:first-child', 'value');
        $I->assertSame('', $orderId, 'guard: the case under test is the select\'s own blank default option');
        $warehouseId = $I->grabAttributeFrom('select[name="warehouse_id"] option:first-child', 'value');
        $I->assertSame('', $warehouseId, 'guard: the case under test is the select\'s own blank default option');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'vendor_id' => (string) $vendor->getId(),
            'vendor_invoice_no' => 'PROV-FIELD-1',
            'document_date' => '2026-09-01',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $bill = $I->grabService(EntityManagerInterface::class)->getRepository(VendorBill::class)->findOneBy(['vendorInvoiceNo' => 'PROV-FIELD-1']);
        $I->assertInstanceOf(VendorBill::class, $bill, 'the save must have gone through, not 400ed');
        $I->assertNull($bill->getPurchaseOrder());
    }

    private function seedVendor(FunctionalTester $I): Vendor
    {
        $vendor = (new Vendor())->setName('Provenance Field Vendor ' . uniqid());
        $I->haveInRepository($vendor);

        return $vendor;
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('procurement-provenance-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }
}
