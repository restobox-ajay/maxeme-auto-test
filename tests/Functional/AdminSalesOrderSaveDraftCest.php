<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin order form's single "Save Draft" button was split into "Save Draft & Recalc"
 * (save_mode=draft_recalc, stays on the edit page so the admin sees freshly recalculated
 * totals) and "Save Draft & Exit" (save_mode=draft_exit, today's original "Save Draft"
 * redirect behavior, just renamed). Covered over real HTTP because the redirect target is
 * decided inline in OrderController::edit()/create().
 */
final class AdminSalesOrderSaveDraftCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-save-draft-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Save Draft Test Co')
            ->setCode('SAVEDRAFT-' . uniqid())
            ->setPrimaryEmail('buyer@save-draft.example');
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /**
     * Draft, not Pending as this fixture once was: the two draft buttons only render on — and are
     * only obeyed for — an order that is still a Draft (#264). Since #539 stage 2 that needs no
     * status call at all — a new order IS a Draft, and the only way out of it is approve(). This file is about where each of
     * them LANDS, which is unchanged; the demotion they used to perform on a live order is
     * AdminOrderSavePathCest's subject.
     */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SAVEDRAFT-' . uniqid())
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Save Draft Test Product')
                ->setSku($product->getSku())
                ->setQuantity('2')
                ->setPrice('20.00')
                ->setSubtotal('40.00')
        );
        $I->haveInRepository($order);

        return $order;
    }

    private function editLinesPayload(ProductCore $product, string $qty, string $price): array
    {
        return [
            'product_id' => (string) $product->getId(),
            'qty' => $qty,
            'price' => $price,
        ];
    }

    public function savingAnExistingOrderWithDraftExitRedirectsToTheOrderDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = (new ProductCore())->setSku('SAVEDRAFT-SKU-1')->setName('Save Draft Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [$this->editLinesPayload($product, '2', '20.00')],
            'save_mode' => 'draft_exit',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/detail/' . $order->getId());
        $I->seeInRepository(SalesOrder::class, [
            'id' => $order->getId(),
            'status' => 'Draft',
        ]);
    }

    public function savingAnExistingOrderWithDraftRecalcRedirectsBackToTheEditPageWithUpdatedTotals(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = (new ProductCore())->setSku('SAVEDRAFT-SKU-2')->setName('Save Draft Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [$this->editLinesPayload($product, '5', '20.00')],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->seeInRepository(SalesOrder::class, [
            'id' => $order->getId(),
            'status' => 'Draft',
            'subtotal' => '100.00',
        ]);
        // And the recalculated figure is on the page the redirect landed on, read out of the
        // totals box (#627): see('100.00') is a substring match over the whole document, so it was
        // satisfied by '1100.00', by '2100.00', and by the line price beside it.
        $I->assertSame(
            '$100.00',
            trim($I->grabTextFrom('.order-total-box strong.js-order-total-before-tax')),
            'sales_order.subtotal — 5 units at 20.00',
        );
    }

    public function createPageDraftRecalcRedirectsToTheEditPageForTheNewlyCreatedOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = (new ProductCore())->setSku('SAVEDRAFT-SKU-3')->setName('Save Draft Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendAjaxPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [$this->editLinesPayload($product, '3', '10.00')],
            'save_mode' => 'draft_recalc',
        ]);

        $newOrder = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $newOrder->getId());
        $I->seeInRepository(SalesOrder::class, [
            'id' => $newOrder->getId(),
            'status' => 'Draft',
        ]);
    }
}
