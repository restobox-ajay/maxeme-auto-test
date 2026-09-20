<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\AuditLog;
use App\Entity\Estimate;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Who a sales document says initiated it, what its activity log says happened, and what a save is
 * allowed to do to the stored contact name — the three things orders and quotes disagreed about.
 *
 * `user_name` is the "User" column of both list screens. Orders filled it with the company's
 * contact person, quotes with the staff member who typed the quote in ("First Last, email (id)"),
 * and the conversion service carried the latter onto the order — so one column held three different
 * kinds of value. It now means one thing on both: the CUSTOMER USER who initiated the document, and
 * null when an admin created it (#269).
 *
 * The logs were each missing what the other had: a quote's log opened empty, and an order edit that
 * moved the status left no trace of the move (#271). Both gaps are asserted here in both
 * directions.
 *
 * The address assertions cover #278: `edit()` used to compose a full name out of first+last (or,
 * failing those, the COMPANY name) and hand it to a setter that split it on the first space again.
 * A save whose form never rendered the address cards therefore rewrote the stored contact name from
 * a value it had never been shown.
 */
final class SalesDocumentUserAndLogCest
{
    /** Set by makeCompany(); the quote form posts its region by name, unlike order's. */
    private string $regionName = 'Main';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('doc-identity@example.test')
            ->setFirstName('Ida')
            ->setLastName('Admin');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Contact Person Co')
            ->setCode('CPC-' . uniqid())
            // The pair create() used to copy into user_name. Nobody named here asked for the order.
            ->setFirstName('Colin')
            ->setLastName('Contact');
        $I->haveInRepository($company);
        $this->regionName = $I->haveActiveFulfillmentRegionFor($company, 'Doc Identity Region');

        return $company;
    }

    /** The fields a real save of a brand-new quote posts. */
    private function quoteCreatePost(FunctionalTester $I, Company $company, ProductCore $product): array
    {
        return [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => $this->regionName,
            'save_mode' => 'draft',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00'],
            ],
        ];
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('DOCID-' . uniqid())
            ->setName('Identity Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is covered IN THE REGION THE LINE RESOLVES TO — which here is the
        // company's own, not the default. This suite is about log entries and the user column, not
        // scarcity, so the product is simply stocked where the orders will draw on it.
        $I->haveStockFor($product, 1000, $this->regionName);

        return $product;
    }

    /** The fields a real save of an order posts, minus whatever the caller wants to add. */
    private function savePost(FunctionalTester $I, Company $company, ProductCore $product, array $extra = []): array
    {
        return array_merge([
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            // The address card always posts these two, as hidden inputs, even when no address-book
            // entry is selected — see the {{ typeKey }}_address_id field in admin/order/form.html.twig.
            // applyOrderAddressFromRequest() returns early only when the key is wholly ABSENT (it
            // tests has(), so an empty value still counts as posted), which the real form never does.
            // Omitting them here would exercise a payload no browser sends and would skip the
            // address write entirely.
            'billing_address_id' => '',
            'shipping_address_id' => '',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ], $extra);
    }

    /** A Draft order — which since #539 stage 2 is simply a new one, with no status call at all. */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('DOCID-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
                ->setTaxCode('E')
        );
        $I->haveInRepository($order);

        return $order;
    }

    /**
     * A live order. Approving is an action on a persisted Draft now, not a status assignment, and
     * with no invoices against it the derived status settles at Approved.
     *
     * It leaves an 'Order approved.' entry on the order's timeline, which every assertion below
     * that counts or filters log comments has to allow for.
     */
    private function makeApprovedOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = $this->makeOrder($I, $company, $product);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function newestOrderFor(FunctionalTester $I, Company $company): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $orders = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1);
        $I->assertNotEmpty($orders, 'no order was created for this company');

        return $orders[0];
    }

    private function newestEstimateFor(FunctionalTester $I, Company $company): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $estimates = $entityManager->getRepository(Estimate::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1);
        $I->assertNotEmpty($estimates, 'no estimate was created for this company');

        return $estimates[0];
    }

    /** @return list<string> the comments on a document's log, newest last */
    private function orderLogComments(FunctionalTester $I, int $orderId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $logs = $entityManager->getRepository(AuditLog::class)
            ->findBy(['entityType' => 'SalesOrder', 'entityId' => $orderId, 'actorType' => 'document'], ['id' => 'ASC']);

        return array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);
    }

    /** @return list<string> */
    private function estimateLogComments(FunctionalTester $I, int $estimateId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $logs = $entityManager->getRepository(AuditLog::class)
            ->findBy(['entityType' => 'Estimate', 'entityId' => $estimateId, 'actorType' => 'document'], ['id' => 'ASC']);

        return array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);
    }

    // ------------------------------------------------------------------ #269, the User column

    public function anAdminCreatedOrderLeavesTheUserColumnEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product, ['save_mode' => 'order']));

        $order = $this->newestOrderFor($I, $company);
        $I->assertNull(
            $order->getUserName(),
            'nobody initiated this order but the admin, and the admin is not a customer user',
        );
    }

    public function anAdminCreatedQuoteLeavesTheUserColumnEmptyRatherThanNamingTheStaffMember(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', $this->quoteCreatePost($I, $company, $product));

        $estimate = $this->newestEstimateFor($I, $company);
        $I->assertNull(
            $estimate->getUserName(),
            'the staff member who typed this quote in belongs in the log, not in the customer-facing User column',
        );
    }

    // ------------------------------------------------------------------ #271, log equivalence

    public function aQuotesActivityLogOpensWithACreationEntry(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', $this->quoteCreatePost($I, $company, $product));

        $estimate = $this->newestEstimateFor($I, $company);
        $comments = $this->estimateLogComments($I, (int) $estimate->getId());

        $I->assertCount(1, $comments, 'a brand-new quote should have exactly its creation entry');
        $I->assertStringContainsString($estimate->getDocumentNumber(), $comments[0]);
        $I->assertStringContainsString('created by doc-identity@example.test', $comments[0]);
    }

    public function anOrderEditRecordsTheStatusTransitionItMakesAsWellAsTheUpdate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        // save_mode=order is the Save Order button: it approves a Draft — the one status change an
        // order edit still makes (#539 stage 2) — and used to do so leaving only a generic "updated"
        // line behind. The entry is now written by SalesOrder::approve() itself.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $orderId,
            $this->savePost($I, $company, $product, ['save_mode' => 'order'])
        );

        $comments = $this->orderLogComments($I, $orderId);
        // Not an exact match: #417 appends the resulting version number to this line (and to the
        // "updated by" one below), so the transition itself is still asserted as a substring.
        $I->assertNotEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'Order approved.')),
            'the status transition should still be recorded',
        );
        $I->assertNotEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'updated by')),
            'the generic update entry is still written alongside the transition',
        );
    }

    public function anOrderEditThatChangesNoStatusWritesNoTransitionEntry(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeApprovedOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        $approvalsBefore = count(array_filter(
            $this->orderLogComments($I, $orderId),
            static fn (string $c): bool => str_contains($c, 'Order approved.'),
        ));

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $orderId,
            $this->savePost($I, $company, $product, ['save_mode' => 'order'])
        );

        $comments = $this->orderLogComments($I, $orderId);

        // The positive control: without it, a 403 on CSRF or a 404 on the route writes no log at
        // all and the assertEmpty() below passes for the wrong reason (#594).
        $I->assertNotEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'updated by')),
            'the save has to have landed, or "no transition was recorded" is about nothing',
        );

        // Counted on the string this path actually writes. The old filter looked for
        // 'status changed', which only SalesOrderStatusDeriver ever emits and which an
        // already-Approved order could never produce — so the concrete regression this test is
        // named for, an edit re-signing an Approved order and appending a SECOND `Order approved.`
        // to the timeline on every save, was invisible to it.
        $I->assertCount(
            $approvalsBefore,
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'Order approved.')),
            'Approved stayed Approved — an edit must not re-sign the transition it already recorded',
        );
        $I->assertEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'status changed')),
            'and no deriver transition either',
        );
    }

    // ------------------------------------------------------------------ #278, the contact name

    public function aSaveThatNeverRenderedTheAddressCardLeavesTheStoredContactNameAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeApprovedOrder($I, $company, $product);

        $order->addressForWriting('billing')
            ->setFirstName('Mary Jane')
            ->setLastName('Watson')
            ->setCompanyName('Acme Widgets Ltd');
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->flush();
        $orderId = (int) $order->getId();

        // A no-JS/API post that carries the company name but no billing_first_name/_last_name — the
        // shape that used to make fullName() fall back to the COMPANY name and the setter split it
        // into first "Acme" / last "Widgets Ltd".
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $orderId,
            $this->savePost($I, $company, $product, [
                'save_mode' => 'order',
                'billing_company_name' => 'Acme Widgets Ltd',
                'shipping_company_name' => 'Acme Widgets Ltd',
            ])
        );

        $entityManager->clear();
        $reloaded = $entityManager->find(SalesOrder::class, $orderId);
        $billing = $reloaded->getBillingAddress();

        $I->assertSame('Mary Jane', $billing?->getFirstName(), 'a multi-word given name is not a company name');
        $I->assertSame('Watson', $billing?->getLastName());
        $I->assertSame('Acme Widgets Ltd', $billing?->getCompanyName(), 'company name is written straight through and stays');
    }

    public function aPostedFirstAndLastNameStillReachTheSnapshotIntact(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeApprovedOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $orderId,
            $this->savePost($I, $company, $product, [
                'save_mode' => 'order',
                'billing_first_name' => 'Anna Maria',
                'billing_last_name' => 'Rossi',
                'shipping_first_name' => 'Jean',
                'shipping_last_name' => 'van der Berg',
                'shipping_company_name' => 'Receiving Dock 4',
            ])
        );

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(SalesOrder::class, $orderId);

        $I->assertSame('Anna Maria', $reloaded->getBillingAddress()?->getFirstName());
        $I->assertSame('Rossi', $reloaded->getBillingAddress()?->getLastName());
        $I->assertSame('Jean', $reloaded->getShippingAddress()?->getFirstName());
        $I->assertSame('van der Berg', $reloaded->getShippingAddress()?->getLastName());
        $I->assertSame('Receiving Dock 4', $reloaded->getShippingCompanyName());
    }
}
