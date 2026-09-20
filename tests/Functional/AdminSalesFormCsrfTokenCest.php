<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The global CsrfProtectionSubscriber (see App\EventSubscriber\CsrfProtectionSubscriber) rejects
 * every state-changing request that carries no valid token, and app.js's ajaxSend/fetch hook only
 * attaches one to AJAX calls — a plain <form method="post"> has to carry {{ csrf_field() }} itself
 * or a real (no-JS, and even JS: order/estimate save is a native submit, not fetch/$.ajax) save
 * is silently bounced with "Your session expired or the form was stale." The order and estimate
 * save forms were missed when the rest of the app migrated to csrf_field() — this locks in the fix.
 */
final class AdminSalesFormCsrfTokenCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-sales-csrf-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())->setName('CSRF Form Test Co')->setCode('CSRFFORM-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeOrder(FunctionalTester $I, Company $company): SalesOrder
    {
        $product = (new ProductCore())->setSku('CSRFFORM-SKU-' . uniqid())->setName('CSRF Form Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('CSRFFORM-' . uniqid())
            ->setSubtotal('10.00')
            ->setTax('0.00')
            ->setTotal('10.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('CSRF Form Test Product')
                ->setSku($product->getSku())
                ->setQuantity('1')
                ->setPrice('10.00')
                ->setSubtotal('10.00')
        );
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. With no invoices
        // it settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function makeEstimate(FunctionalTester $I, Company $company): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('CSRFFORM-EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Draft', DocumentActor::system());
        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function orderCreatePageRendersATokenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch%5Bcompany_id%5D=' . $company->getId());
        $I->seeElement('#order-form input[type="hidden"][name="_token"]');
    }

    public function orderEditPageRendersATokenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I, $this->makeCompany($I));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('#order-form input[type="hidden"][name="_token"]');
    }

    public function estimateCreatePageRendersATokenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeElement('#estimate-form input[type="hidden"][name="_token"]');
    }

    public function estimateEditPageRendersATokenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I, $this->makeCompany($I));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('#estimate-form input[type="hidden"][name="_token"]');
    }

    /** @return array<string, mixed> the minimal valid post body edit() needs to actually save */
    private function editPostBody(SalesOrder $order, string $poNumber): array
    {
        $line = $order->getLines()->first();

        return [
            'company_id' => (string) $order->getCompany()->getId(),
            'save_mode' => 'save',
            'po_number' => $poNumber,
            'lines' => [
                0 => [
                    'product_id' => (string) $line->getProduct()->getId(),
                    'name' => $line->getName(),
                    'sku' => $line->getSku(),
                    'quantity' => $line->getQuantity(),
                    'price' => $line->getPrice(),
                ],
            ],
        ];
    }

    /** A real (no-JS) browser POST with no token is rejected, not silently saved. */
    public function savingAnOrderWithoutATokenIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I, $this->makeCompany($I));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->editPostBody($order, 'REJECTED-PO'));
        $I->seeInRepository(SalesOrder::class, ['id' => $order->getId(), 'poNumber' => null]);
    }

    /** The token this same page renders is the one CsrfProtectionSubscriber accepts. */
    public function savingAnOrderWithTheRenderedTokenSucceeds(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I, $this->makeCompany($I));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $token = $I->grabAttributeFrom('#order-form input[type="hidden"][name="_token"]', 'value');

        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            ['_token' => $token] + $this->editPostBody($order, 'ACCEPTED-PO'),
        );
        $I->seeInRepository(SalesOrder::class, ['id' => $order->getId(), 'poNumber' => 'ACCEPTED-PO']);
    }
}
