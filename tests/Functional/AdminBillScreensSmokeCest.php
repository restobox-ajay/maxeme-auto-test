<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * bill_edit.html.twig and bill_detail.html.twig were deleted by commit 3afde7e0 ("Rebuild
 * PurchaseOrder edit/detail with full sell-side charge/tax JS parity") — that commit rebuilt only
 * PurchaseOrder's own equivalent and left Bill's for a later pass that never happened, so every
 * Bill screen 500'd (axcelmediacorp/wholesale-b2b-core#698). This is a smoke test for the rebuild:
 * new -> save -> detail -> edit, all rendering, not the full parity/tax-province test suite that
 * commit also deleted alongside the templates (14 Cest files) — that gap is real and is flagged
 * separately, not solved here.
 */
final class AdminBillScreensSmokeCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('bill-screens-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function seedVendor(FunctionalTester $I): Vendor
    {
        $vendor = (new Vendor())->setName('Bill Screens Vendor ' . uniqid());
        $I->haveInRepository($vendor);

        return $vendor;
    }

    private function seedWarehouse(FunctionalTester $I): Warehouse
    {
        $warehouse = (new Warehouse())->setName('Bill Screens Dock ' . uniqid())->setStatus('Active')->setProvince('BC');
        $I->haveInRepository($warehouse);

        return $warehouse;
    }

    private function seedProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())->setSku('BILL-SCREEN-' . uniqid())->setName('Bill Screens Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    public function theNewBillFormRendersForAStandaloneBill(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedVendor($I);

        $I->amOnPage('/admin/bundles/procurement/bills/new');
        $I->seeResponseCodeIsSuccessful();
        $I->see('New Bill');
        $I->seeElement('form#bill-form');
    }

    public function savingANewStandaloneBillRendersItsDetailAndEditPages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $warehouse = $this->seedWarehouse($I);
        $product = $this->seedProduct($I);

        $I->sendMultipartPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $I->csrfToken(),
            'id' => '0',
            'purchase_order_id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'warehouse_id' => (string) $warehouse->getId(),
            'vendor_invoice_no' => 'SMOKE-INV-1',
            'document_date' => '2026-09-01',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'unit_cost' => '10.0000'],
            ],
        ], []);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService(EntityManagerInterface::class);
        $bill = $em->getRepository(\ProcurementBundle\Entity\VendorBill::class)->findOneBy(['vendorInvoiceNo' => 'SMOKE-INV-1']);
        $I->assertInstanceOf(\ProcurementBundle\Entity\VendorBill::class, $bill);
        $I->assertCount(1, $bill->getLines());

        $I->amOnPage('/admin/bundles/procurement/bills/' . $bill->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($bill->getBillNumber());
        $I->see('Balance');

        $I->amOnPage('/admin/bundles/procurement/bills/' . $bill->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form#bill-form');
        $I->seeInField('vendor_invoice_no', 'SMOKE-INV-1');
    }

    public function theNewBillFormRendersWhenOpenedAgainstAPurchaseOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $warehouse = $this->seedWarehouse($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $order = (new PurchaseOrder())
            ->setPoNumber('BILL-SCREENS-PO-' . uniqid())
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setDocumentDate('2026-09-01')
            ->setCurrency('CAD');
        $I->haveInRepository($order);

        $I->amOnPage('/admin/bundles/procurement/bills/new?po=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($vendor->getName());
        $I->seeElement('select[name="purchase_order_id"]');
        $I->seeElement('input[name="warehouse_id"][value="' . $warehouse->getId() . '"]');
    }
}
