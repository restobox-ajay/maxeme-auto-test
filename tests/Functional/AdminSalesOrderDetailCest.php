<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Products table's NAME column used to truncate long product names with an ellipsis
 * (global `.user-manager-table td { white-space: nowrap; ... text-overflow: ellipsis; }` rule,
 * shared by several unrelated tables). Scoped the fix to this one column via a new
 * `order-product-name-cell` class, following the same pattern already used for the Batch cell.
 */
final class AdminSalesOrderDetailCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-detail-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function productNameCellWrapsInsteadOfTruncating(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Test Co')
            ->setCode('ORDDET-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail.example');
        $I->haveInRepository($company);

        $longName = 'AQQISHI AQSONE A/T 35X12.50R20LT 121Q E BSW ALL-TERRAIN TIRE';
        $product = (new ProductCore())->setSku('ORDDET-SKU-1')->setName($longName)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDET-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($longName)
                ->setSku('ORDDET-SKU-1')
                ->setQuantity('2')
                ->setPrice('20.00')
                ->setSubtotal('40.00')
        );
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($longName);
        $I->seeElement('td.order-product-name-cell');
    }

    /**
     * The Special Instructions row used to print a hardcoded `-`, so an instruction an admin or a
     * customer put on the order was invisible to anyone looking at it in the console — including
     * delivery notes and the customer's stated payment preference, which checkout appends into
     * this same field. The invoice printed it all along as "Customer Note"; only this page dropped
     * it (#268). Quote detail has always rendered the real value.
     */
    public function specialInstructionsRendersTheSavedValueRatherThanAHardcodedDash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Instructions Co')
            ->setCode('ORDDETSI-' . uniqid());
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETSI-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00')
            ->setSpecialInstructions('Leave at the loading dock | Preferred payment method: Pay Upon Delivery');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Special Instructions:');
        $I->see('Leave at the loading dock | Preferred payment method: Pay Upon Delivery');
    }

    /** An order that genuinely carries no instruction still reads as a dash, not as an empty row. */
    public function specialInstructionsFallsBackToADashWhenGenuinelyEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail No Instructions Co')
            ->setCode('ORDDETNSI-' . uniqid());
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETNSI-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Special Instructions:');
        $I->assertMatchesRegularExpression(
            '/Special Instructions:<\/label>\s*<strong>-<\/strong>/',
            $I->grabPageSource(),
        );
    }

    public function feeLineNameCellWrapsInsteadOfTruncating(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Fee Test Co')
            ->setCode('ORDDETFEE-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-fee.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETFEE-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('45.00');
        $order->setFeeLines(json_encode([
            ['feeId' => 1, 'slug' => 'fuel-surcharge', 'label' => 'Fuel Surcharge (AB/SK/MB/ON/QC/NB/NS/PE/NL)', 'taxClass' => 'none', 'amount' => 5.0, 'placement' => 'main_line'],
        ]));
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Fuel Surcharge (AB/SK/MB/ON/QC/NB/NS/PE/NL)');
        $I->seeElement('tr.fee-line-row td.order-product-name-cell');
    }

    public function infoBillingShippingCardsUseTightenedRowSpacing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Spacing Test Co')
            ->setCode('ORDDETSP-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-spacing.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETSP-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeNumberOfElements('section.detail-panel.order-detail-panel', 3);
    }

    public function paymentMethodRowShowsOrdersActualPaymentMethod(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Payment Method Test Co')
            ->setCode('ORDDETPM-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-payment-method.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETPM-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Pay Upon Delivery');
        $I->dontSee('Admin', '.detail-row strong');
    }

    public function paymentTermsRowShowsOrdersActualPaymentTerm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Payment Term Test Co')
            ->setCode('ORDDETPT-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-payment-term.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETPT-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Net 15');
        $I->dontSee('No payment terms');
    }

    public function paymentTermsRowFallsBackWhenOrderHasNoPaymentTerm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail No Payment Term Test Co')
            ->setCode('ORDDETNPT-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-no-payment-term.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETNPT-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('No payment terms');
    }

    /**
     * The status modal writes the saved value straight back into the header badge (#224), so the
     * badge needs a hook that does not depend on its colour. It used to be located by
     * `.badge.success, .badge.warn` — classes an Approved order's badge does not carry, while the
     * "Paid" payment badge right below it does, so the update landed on the wrong element or
     * nowhere at all and the page kept showing the previous status until a manual refresh.
     */
    public function theStatusBadgeCarriesItsOwnHookEvenWithoutAColourModifier(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Order Detail Status Badge Test Co')
            ->setCode('ORDDETSB-' . uniqid())
            ->setPrimaryEmail('buyer@order-detail-status-badge.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDDETSB-' . uniqid())
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // against it the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.js-order-status-badge');
        $I->see('Approved', '.js-order-status-badge');
        // The badge the old selector actually reached is a different one — the payment badge, which
        // since #539 stage 4 is rolled up from the order's invoices. This order has none, so nothing
        // has been billed and nothing can have been paid.
        $I->see('Not Paid', '.badge');
    }
}
