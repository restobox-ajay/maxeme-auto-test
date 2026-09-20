<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\AuditLog;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #417: optimistic concurrency on the order edit page, on top of #396's request-scoped write
 * lock.
 *
 * #396 already stops two concurrent SAVES from interleaving their writes at the SQL level — but
 * it does nothing about a page that was simply left open, unedited, since before someone else's
 * save already landed: admin B's request acquires that lock cleanly and, without a version check,
 * would silently overwrite admin A's already-committed edit with the stale field values B's
 * browser has been holding onto. These tests pin the version check that closes that gap:
 *
 * - a submit whose hidden `version` field no longer matches the order's current version is
 *   refused outright, with nothing from the post applied and a distinct log entry recording the
 *   refusal;
 * - a submit whose version DOES match still saves normally and the version column moves by
 *   exactly one, ending up recorded in the order's own log;
 * - the lightweight polling endpoint the edit page's JS pings (see app.js) returns just that
 *   version number, cheaply.
 */
final class AdminOrderEditVersionLockCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('order-version-lock@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Order Version Lock Co')
            ->setCode('OVL-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('OVL-SKU-' . uniqid())
            ->setName('Order Version Lock Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product);

        return $product;
    }

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OVL-' . uniqid())
            ->setPoNumber('ORIGINAL-PO')
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
        // A live order, which since #539 stage 2 is approve() on a Draft rather than a status
        // string. Called BEFORE the insert deliberately: this whole file asserts that the fixture
        // starts at version 1, and approving after the insert would be a second write that moved it
        // to 2. A brand-new order is Draft, so the action is valid here, and the timeline entry it
        // adds rides along on the $logs cascade.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return $order;
    }

    private function reload(FunctionalTester $I, int $orderId): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $orderId);
    }

    /** @return list<AuditLog> newest first */
    private function orderLogs(FunctionalTester $I, int $orderId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'SalesOrder', 'entityId' => $orderId, 'actorType' => 'document'],
            ['id' => 'DESC'],
        );
    }

    // ----------------------------------------------------------------------- mapping / round-trip

    /** The edit page's hidden field is what round-trips the version — no field, no protection. */
    public function theEditPageRendersTheOrdersCurrentVersionAsAHiddenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input.js-order-version', [
            'name' => 'version',
            'value' => '1',
        ]);
        // The polling hook app.js reads to ping the version-check endpoint from.
        $I->seeElement('input.js-order-version[data-version-check-url]');
    }

    /** The lightweight endpoint app.js polls: just the version, nothing else. */
    public function theVersionCheckEndpointReturnsJustTheCurrentVersion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->sendAjaxGetRequest('/admin/order/' . $order->getId() . '/version');
        $I->seeResponseCodeIsSuccessful();
        $payload = json_decode($I->grabPageSource(), true);
        $I->assertSame(['found' => true, 'version' => 1], $payload);

        // Bump it (a real save would) and confirm the endpoint reflects it without needing the
        // rest of the order.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $live = $entityManager->find(SalesOrder::class, $order->getId());
        $live->setPoNumber('Bumped');
        $entityManager->flush();

        $I->sendAjaxGetRequest('/admin/order/' . $order->getId() . '/version');
        $payload = json_decode($I->grabPageSource(), true);
        $I->assertSame(['found' => true, 'version' => 2], $payload);
    }

    public function theVersionCheckEndpointReports404ForAMissingOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->sendAjaxGetRequest('/admin/order/999999999/version');
        $I->seeResponseCodeIs(404);
        $payload = json_decode($I->grabPageSource(), true);
        $I->assertSame(['found' => false], $payload);
    }

    // ------------------------------------------------------------------------------ the rejection

    /**
     * The core #417 scenario: admin B's form was rendered at version 1. Before B submits, admin
     * A's save lands and moves the order to version 2. B's submit — still carrying version 1 —
     * must be refused outright: nothing B typed reaches the database, the order keeps A's values,
     * and B sees a clear message rather than a raw exception or a silent overwrite.
     */
    public function aStaleSubmitIsRejectedAndDoesNotOverwriteTheNewerSave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        // Admin B loads the edit page — this is what puts version=1 in B's hidden field.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeElement('input.js-order-version', ['value' => '1']);
        $token = $I->csrfToken();

        // Admin A's save lands first, in between B's page load and B's submit.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $winner = $entityManager->find(SalesOrder::class, $orderId);
        $winner->setPoNumber('SET-BY-ADMIN-A');
        $entityManager->flush();
        $I->assertSame(2, $winner->getVersion(), 'admin A\'s save should have moved the version to 2');

        // Admin B submits, unaware — still version 1, still holding the pre-A field values.
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $token,
            'company_id' => (string) $company->getId(),
            'version' => '1',
            'po_number' => 'SET-BY-ADMIN-B-TOO-LATE',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        // Sent back to a fresh copy of the edit page, not silently through to detail.
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $orderId);
        $I->see('changed by someone else');

        $saved = $this->reload($I, $orderId);
        $I->assertSame('SET-BY-ADMIN-A', $saved->getPoNumber(), 'B\'s stale edit must not have overwritten A\'s already-committed save');
        $I->assertSame(2, $saved->getVersion(), 'a rejected submit must not itself move the version');

        $logs = $this->orderLogs($I, $orderId);
        $rejection = current(array_filter($logs, static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'Save rejected')));
        $I->assertNotFalse($rejection, 'a rejected stale submit should leave its own log entry, not silence');
        $I->assertStringContainsString('1', $rejection->getSummary(), 'the log should say which submitted version was stale');
    }

    /**
     * The companion case: a submit whose version DOES match the order's current one is not
     * caught by anything #417 added — it saves exactly as it always did, and the version column
     * moves by one to reflect that a write happened.
     */
    public function aNonStaleSubmitStillSucceedsAndTheVersionIncrements(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeElement('input.js-order-version', ['value' => '1']);
        $token = $I->csrfToken();

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $token,
            'company_id' => (string) $company->getId(),
            'version' => '1',
            'po_number' => 'SET-BY-A-NON-STALE-SAVE',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/detail/' . $orderId);

        $saved = $this->reload($I, $orderId);
        $I->assertSame('SET-BY-A-NON-STALE-SAVE', $saved->getPoNumber(), 'a non-stale submit should still apply normally');
        $I->assertSame(2, $saved->getVersion(), 'a successful save should move the version forward by exactly one');

        $logs = $this->orderLogs($I, $orderId);
        $updated = current(array_filter($logs, static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'updated by')));
        $I->assertNotFalse($updated, 'a successful save should still leave the usual "updated by" log entry');
        $I->assertStringContainsString('version 2', $updated->getSummary(), 'the log should record the version the save resulted in');
    }

    /**
     * A post with no `version` field at all (any submit predating this feature) is not treated
     * as automatically stale — there is nothing to compare it against, so it is left to the #396
     * lock alone, exactly as before this issue.
     */
    public function aSubmitWithNoVersionFieldIsNotRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $orderId = (int) $order->getId();

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $token = $I->csrfToken();

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $token,
            'company_id' => (string) $company->getId(),
            'po_number' => 'SET-WITH-NO-VERSION-FIELD',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/detail/' . $orderId);
        $saved = $this->reload($I, $orderId);
        $I->assertSame('SET-WITH-NO-VERSION-FIELD', $saved->getPoNumber());
    }
}
