<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin order save/status path, and the five ways it acted on decisions nobody made.
 *
 * - #264 the draft buttons demoted a LIVE order to Draft — releasing its stock reservations —
 *   with no confirmation and no log entry. A non-draft order now shows "Save & Recalc" instead,
 *   and edit() refuses the demotion however the mode arrives.
 * - #265 the no-JS row delete is a manipulation of the form, not a save that means anything about
 *   status or about being finished.
 * - #266 the status endpoint wrote any string it was handed into the order's status.
 * - #267 every save deleted and recreated every line, so the line-level audit trail was noise and
 *   the line primary keys churned.
 * - #282 order create discarded every typed address field and copied an address-book entry
 *   without checking whose it was.
 * - #539 stage 2 narrowed the endpoint further still: Approve and Void are the only two transitions
 *   an admin performs, and every other status an order can hold is derived from its invoices.
 */
final class AdminOrderSavePathCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('order-save-path@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** An order cannot be created for a company with no active fulfillment region since #237. */
    private function makeCompany(FunctionalTester $I, string $name = 'Order Save Path Co'): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('OSP-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku = 'OSP-SKU'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku . '-' . uniqid())
            ->setName('Order Save Path Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        return $product;
    }

    /**
     * $status is one of the only two an order can be PUT INTO (#539 stage 2): every order is born a
     * Draft and approve() is the one transition that moves it. Partially Invoiced, Invoiced and
     * Closed are derived from the invoice set and cannot be declared by a fixture at all — a match
     * with no arm for them is deliberate, so asking for one fails loudly here rather than producing
     * a Draft that quietly passes the wrong test.
     */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, string $status): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OSP-' . uniqid())
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

        match ($status) {
            'Draft' => null,
            'Approved' => $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
        };

        $I->haveInRepository($order);

        return $order;
    }

    /** @return list<string> every timeline comment on the order, newest first. */
    private function logComments(FunctionalTester $I, int $orderId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(
            static fn (AuditLog $log): string => (string) $log->getSummary(),
            $entityManager->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'SalesOrder', 'entityId' => $orderId, 'actorType' => 'document'],
                ['id' => 'DESC'],
            ),
        );
    }

    private function reload(FunctionalTester $I, int $orderId): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $orderId);
    }

    // ------------------------------------------------------------------ #264, the draft buttons

    /**
     * The two buttons that could demote a live order are simply not on the page any more, and the
     * one that replaces them is. Asserted as markup because that is the gate an admin actually
     * meets; the refusal behind it is the next test.
     */
    public function aLiveOrderShowsSaveAndRecalcInPlaceOfTheTwoDraftButtons(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeElement('button[name="save_mode"][value="draft_recalc"]');
        $I->dontSeeElement('button[name="save_mode"][value="draft_exit"]');
        $I->seeElement('.order-bottom-actions button[name="save_mode"][value="recalc"]');
        $I->seeElement('.order-bottom-actions button[name="save_mode"][value="order"]');
        $I->see('Save & Recalc', '.order-bottom-actions');
    }

    /** While the order IS a Draft nothing about the draft buttons changes. */
    public function aDraftOrderStillShowsBothDraftButtonsAndNoSaveAndRecalc(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-bottom-actions button[name="save_mode"][value="draft_recalc"]');
        $I->seeElement('.order-bottom-actions button[name="save_mode"][value="draft_exit"]');
        $I->seeElement('.order-bottom-actions button[name="save_mode"][value="order"]');
        $I->dontSeeElement('button[name="save_mode"][value="recalc"]');
    }

    /**
     * The half the template cannot be trusted with: a stale form or a scripted POST still carries
     * draft_exit/draft_recalc, and the order must not lose its status — and with it its inventory
     * reservations — for having received one. The save itself still happens.
     */
    public function aDraftSaveModeCannotDemoteALiveOrderHoweverItArrives(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Both fixtures before the first save: reading an order back clears the entity manager.
        $orders = [
            'draft_recalc' => $this->makeOrder($I, $company, $product, 'Approved'),
            'draft_exit' => $this->makeOrder($I, $company, $product, 'Approved'),
        ];

        foreach ($orders as $saveMode => $order) {
            $before = $order->getStatus();
            $I->amOnPage('/admin/order/edit/' . $order->getId());
            $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
                '_token' => $I->csrfToken(),
                'company_id' => (string) $company->getId(),
                'lines' => [
                    ['product_id' => (string) $product->getId(), 'qty' => '4', 'price' => '50.00', 'tax_code' => 'E'],
                ],
                'save_mode' => $saveMode,
            ]);

            $saved = $this->reload($I, (int) $order->getId());
            $I->assertSame($before, $saved->getStatus(), sprintf('save_mode=%s demoted a %s order', $saveMode, $before));
            $I->assertSame(200.0, (float) $saved->getSubtotal(), 'the refusal swallowed the save as well');
        }
    }

    /** Save & Recalc: saves, recalculates, leaves the status alone, comes back to the form. */
    public function saveAndRecalcSavesWithoutTouchingTheStatusAndStaysOnTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('Approved', $saved->getStatus());
        $I->assertSame(150.0, (float) $saved->getSubtotal(), 'Save & Recalc did not recalculate');
    }

    /** Save Order is untouched by any of this: on a live order it saves and leaves for detail. */
    public function saveOrderOnALiveOrderStillSavesAndLeavesForTheDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/detail/' . $order->getId());
        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('Approved', $saved->getStatus());
        $I->assertSame(50.0, (float) $saved->getSubtotal());
    }

    // ------------------------------------------------------------------- #265, no-JS row delete

    /**
     * The ✕ posts the whole form plus `remove_line` and nothing else. It is a row manipulation,
     * exactly like add_charge_line: the row goes, the status does not move and the admin is left
     * on the form they were working in.
     */
    public function theNoJsRowDeleteIsARowManipulationAndNotASave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $keep = $this->makeProduct($I, 'OSP-KEEP');
        $drop = $this->makeProduct($I, 'OSP-DROP');

        $order = $this->makeOrder($I, $company, $keep, 'Draft');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($drop)
                ->setName($drop->getName())
                ->setSku((string) $drop->getSku())
                ->setQuantity('1.00')
                ->setPrice('50.00')
                ->setSubtotal('50.00')
        );
        $I->haveInRepository($order);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $keep->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
                ['product_id' => (string) $drop->getId(), 'qty' => '1', 'price' => '50.00'],
            ],
            'remove_line' => '1',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('Draft', $saved->getStatus(), 'removing a line promoted the Draft');
        $I->assertCount(1, $saved->getLines());
        $I->assertSame((string) $keep->getSku(), (string) $saved->getLines()->first()->getSku());
    }

    // ------------------------------------------------------------------- #266, the status endpoint

    /** A status the enum does not know is refused, and the order keeps the one it had. */
    public function theStatusEndpointRefusesAStatusThatIsNotASalesOrderStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // 'Proccessing' is the issue's own example: it used to persist, and the order then reserved
        // no inventory at all because bucketForStatus() had nothing to match it to.
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Proccessing',
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertSame('Approved', $this->reload($I, (int) $order->getId())->getStatus());
    }

    /** A missing status is the same refusal, not the TypeError/500 it used to be. */
    public function theStatusEndpointRefusesAMissingStatusRatherThanErroring(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertSame('Approved', $this->reload($I, (int) $order->getId())->getStatus());
    }

    /**
     * The two transitions an admin owns, from the states each is legal in (#539 stage 2). Approve
     * accepts a Draft; Void takes any live order out of reporting totals. These two are now the
     * whole of what this endpoint may write, and each writes its own timeline entry — the endpoint
     * no longer restates the transition, so "Order status changed from X to Y." must not appear.
     */
    public function theStatusEndpointPerformsTheTwoTransitionsAnAdminOwns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Both fixtures before the first post: reading an order back clears the entity manager.
        $draft = $this->makeOrder($I, $company, $product, 'Draft');
        $live = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $draft->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $draft->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Approved', $this->reload($I, (int) $draft->getId())->getStatus());
        $draftLogs = $this->logComments($I, (int) $draft->getId());
        $I->assertContains('Order approved.', $draftLogs, 'approve() should have written its own timeline entry');
        foreach ($draftLogs as $comment) {
            $I->assertStringNotContainsString('Order status changed from', $comment, 'the endpoint restated a transition the action already logged');
        }

        $I->amOnPage('/admin/order/edit/' . $live->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $live->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Void', $this->reload($I, (int) $live->getId())->getStatus());
        $I->assertContains(
            'Order voided (was Approved).',
            $this->logComments($I, (int) $live->getId()),
            'void() should have written its own timeline entry, naming what the order was'
        );
    }

    /**
     * The four statuses the endpoint used to write and must not: Partially Invoiced, Invoiced and
     * Closed are computed from the order's invoices, and Draft is where an unapproved order derives
     * back to. Writing any of them by hand would be overruled by the next recalculation, so each is
     * refused outright and the order is left exactly as it was.
     */
    public function theStatusEndpointRefusesEveryDerivedStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // A fixture each, all made up front: reading an order back clears the entity manager.
        $orders = [];
        foreach (['Draft', 'Partially Invoiced', 'Invoiced', 'Closed'] as $status) {
            $orders[$status] = $this->makeOrder($I, $company, $product, 'Approved');
        }

        foreach ($orders as $status => $order) {
            $I->amOnPage('/admin/order/edit/' . $order->getId());
            $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
                '_token' => $I->csrfToken(),
                'status' => $status,
            ]);

            $I->seeResponseCodeIs(400);
            $I->assertStringContainsString(
                'cannot be set by hand',
                $I->grabPageSource(),
                sprintf('%s should be refused as derived, and the refusal should say so', $status)
            );
            $I->assertSame(
                'Approved',
                $this->reload($I, (int) $order->getId())->getStatus(),
                sprintf('%s was written by hand', $status)
            );
        }
    }

    /**
     * Approve is not idempotent and the endpoint does not pretend otherwise: approving an order
     * that is already live is the action's own refusal, carried back as a 400 with its message.
     */
    public function theStatusEndpointRefusesApprovingAnOrderThatIsNotADraft(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertStringContainsString('Only a Draft order can be approved', $I->grabPageSource());
        $I->assertSame('Approved', $this->reload($I, (int) $order->getId())->getStatus());
        // A refused transition leaves no trace: the second "Order approved." would be a history of
        // something that did not happen.
        $I->assertCount(
            1,
            array_filter($this->logComments($I, (int) $order->getId()), static fn (string $c): bool => $c === 'Order approved.'),
        );
    }

    /**
     * Void is terminal. isOrderLockedForEditing() covers it, so a second attempt never reaches the
     * action at all — the endpoint turns it away as locked, which is also what stops a voided order
     * being edited back into the reporting totals.
     */
    public function aVoidedOrderIsLockedAgainstAnyFurtherStatusChange(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Void', $this->reload($I, (int) $order->getId())->getStatus());

        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertSame('Void', $this->reload($I, (int) $order->getId())->getStatus());
    }

    /**
     * The one entry updateStatus() still writes itself. The transition is the action's to log; who
     * was told about it is a property of this request, so a ticked notify_client adds a second,
     * separate entry rather than a duplicate of the action's.
     */
    public function tickingNotifyClientAddsItsOwnEntryBesideTheActionsOwn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
            'notify_client' => '1',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $comments = $this->logComments($I, (int) $order->getId());
        $I->assertContains('Order approved.', $comments);
        $I->assertContains('Customer notified of the change from Draft to Approved.', $comments);
    }

    // ------------------------------------------------------------------------ #267, line identity

    /**
     * The point of the change: a save that edits a quantity edits the LINE, so its primary key
     * survives and the audit log can show 5 → 7 instead of a line vanishing and another appearing.
     */
    public function editingALineKeepsItsIdentityInsteadOfReplacingTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');
        $lineIdBefore = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // The hidden field is what carries the identity across the round trip.
        $I->seeElement('input[type="hidden"][name="lines[0][id]"][value="' . $lineIdBefore . '"]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                [
                    'id' => (string) $lineIdBefore,
                    'product_id' => (string) $product->getId(),
                    'qty' => '7',
                    'price' => '50.00',
                    'tax_code' => 'E',
                ],
            ],
            'save_mode' => 'recalc',
        ]);

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines());
        $line = $saved->getLines()->first();
        $I->assertSame($lineIdBefore, (int) $line->getId(), 'the line was replaced rather than updated');
        $I->assertSame(7.0, (float) $line->getQuantity());
        $I->assertSame(350.0, (float) $saved->getSubtotal());
    }

    /**
     * The other half of a diff: the rows a save did not carry are still deleted, and only those.
     * A new row with no id still becomes a new line.
     */
    public function aSaveDeletesOnlyTheLinesItOmittedAndAddsTheOnesWithNoId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $keep = $this->makeProduct($I, 'OSP-DKEEP');
        $drop = $this->makeProduct($I, 'OSP-DDROP');
        $add = $this->makeProduct($I, 'OSP-DADD');

        $order = $this->makeOrder($I, $company, $keep, 'Approved');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($drop)
                ->setName($drop->getName())
                ->setSku((string) $drop->getSku())
                ->setQuantity('1.00')
                ->setPrice('50.00')
                ->setSubtotal('50.00')
        );
        $I->haveInRepository($order);

        $keepLineId = null;
        $dropLineId = null;
        foreach ($order->getLines() as $line) {
            if ($line->getProduct()->getId() === $keep->getId()) {
                $keepLineId = (int) $line->getId();
            } else {
                $dropLineId = (int) $line->getId();
            }
        }

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $keepLineId, 'product_id' => (string) $keep->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
                ['product_id' => (string) $add->getId(), 'qty' => '1', 'price' => '10.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'recalc',
        ]);

        $saved = $this->reload($I, (int) $order->getId());
        $ids = [];
        $skus = [];
        foreach ($saved->getLines() as $line) {
            $ids[] = (int) $line->getId();
            $skus[] = (string) $line->getSku();
        }

        $I->assertCount(2, $ids);
        $I->assertContains($keepLineId, $ids, 'the kept line was replaced');
        $I->assertNotContains($dropLineId, $ids, 'the omitted line was not deleted');
        $I->assertContains((string) $add->getSku(), $skus, 'the new row did not become a line');
        $I->assertSame(110.0, (float) $saved->getSubtotal());
    }

    /**
     * A line id belonging to somebody else's order is not a way to reach that line: it matches
     * nothing on this order, so it becomes a new line here and the other order is untouched.
     */
    public function aLineIdFromAnotherOrderIsNotHonoured(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $mine = $this->makeOrder($I, $company, $product, 'Approved');
        $theirs = $this->makeOrder($I, $this->makeCompany($I, 'Other Save Path Co'), $product, 'Approved');
        $theirLineId = (int) $theirs->getLines()->first()->getId();

        $I->amOnPage('/admin/order/edit/' . $mine->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $mine->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $theirLineId, 'product_id' => (string) $product->getId(), 'qty' => '9', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'recalc',
        ]);

        $theirsAfter = $this->reload($I, (int) $theirs->getId());
        $I->assertSame(2.0, (float) $theirsAfter->getLines()->first()->getQuantity(), 'another order\'s line was rewritten');
        $I->assertSame($theirLineId, (int) $theirsAfter->getLines()->first()->getId());
    }

    // ------------------------------------------------------------------------- #282, create addresses

    /**
     * The create screen renders the same editable address card the edit screen does, and create()
     * threw every field of it away in favour of the raw address-book copy.
     */
    public function orderCreateKeepsTheAddressFieldsTheAdminTyped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $bookEntry = (new CompanyAddress())
            ->setCompany($company)
            ->setIsDefaultBilling(true)
            ->setAddressLine1('1 Book Street')
            ->setCity('Bookville')
            ->setPhone('555-0000');
        $I->haveInRepository($bookEntry);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'billing_address_id' => (string) $bookEntry->getId(),
            'billing_address_1' => '1 Book Street, Suite 400',
            'billing_phone' => '555-1234',
            'save_mode' => 'draft_exit',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($created, 'no order was created');

        $billing = $created->getEffectiveBillingAddress();
        $I->assertNotNull($billing);
        $I->assertSame('1 Book Street, Suite 400', $billing->getAddressLine1(), 'the typed suite number was discarded');
        $I->assertSame('555-1234', $billing->getPhone(), 'the typed phone was discarded');
        // The book entry itself is only ever read — correcting an order must not rewrite it.
        $entityManager->clear();
        $I->assertSame('1 Book Street', $entityManager->find(CompanyAddress::class, $bookEntry->getId())->getAddressLine1());
    }

    /**
     * The data-exposure half: create() checked only that the posted id resolved to SOME
     * CompanyAddress, so a posted number was enough to copy another customer's address — name,
     * street, phone and both emails — onto a new order.
     */
    public function orderCreateRefusesAnAddressBelongingToAnotherCompany(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I, 'Someone Else Ltd');
        $product = $this->makeProduct($I);

        $foreignAddress = (new CompanyAddress())
            ->setCompany($otherCompany)
            ->setIsDefaultBilling(true)
            ->setAddressLine1('99 Private Lane')
            ->setCity('Confidential')
            ->setPhone('555-9999')
            ->setEmailPrimary('someone-else@private.example');
        $I->haveInRepository($foreignAddress);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'billing_address_id' => (string) $foreignAddress->getId(),
            'save_mode' => 'draft_exit',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($created, 'no order was created');

        $billing = $created->getEffectiveBillingAddress();
        $leaked = $billing === null ? '' : implode('|', [
            (string) $billing->getAddressLine1(),
            (string) $billing->getCity(),
            (string) $billing->getPhone(),
            (string) $billing->getEmailPrimary(),
        ]);
        $I->assertStringNotContainsString('99 Private Lane', $leaked);
        $I->assertStringNotContainsString('Confidential', $leaked);
        $I->assertStringNotContainsString('555-9999', $leaked);
        $I->assertStringNotContainsString('someone-else@private.example', $leaked);
    }

    // ------------------------------------------------------------------ #303, control-byte stripping

    /**
     * A raw NUL (or other C0 control byte) submitted in a text field used to be stored verbatim and
     * later served into HTML — invalid HTML, and a plausible route to silent truncation anywhere
     * downstream that treats a NUL as end-of-string (PDF generation, some mail transports). The rest
     * of the value must survive intact — this is stripping, not truncating.
     */
    public function aNulByteInPoNumberIsStrippedRatherThanStoredOrTruncating(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'po_number' => "PO\0-AFTER-NUL",
            'save_mode' => 'save',
        ]);

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('PO-AFTER-NUL', $saved->getPoNumber(), 'the NUL byte should be stripped, not truncate the rest of the value');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('PO-AFTER-NUL');
    }

    /**
     * #302: po_number had no server-side length cap, so one oversized POST permanently bloated the
     * shared admin order pages via the audit trail's before/after snapshots (which are never edited
     * after the fact). The cap has to hold on the real save path, not just in a unit test of the
     * helper, and the resulting audit row must not carry the oversized value either.
     */
    public function aPoNumberOverTheColumnLengthIsTruncatedAndDoesNotBloatTheAuditTrail(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $huge = str_repeat('X', 100_000);
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'po_number' => $huge,
            'save_mode' => 'save',
        ]);

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame(80, strlen((string) $saved->getPoNumber()), 'po_number should be capped at the column length (80)');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $log = $entityManager->getRepository(AuditLog::class)->findOneBy(['entityType' => 'SalesOrder', 'entityId' => $order->getId()], ['id' => 'DESC']);
        $I->assertNotNull($log, 'the save should have produced an audit row');
        $I->assertLessThan(1000, strlen((string) $log->getDataAfter()), 'the audit row must not carry the oversized value — it should already be bounded before it ever reaches the audit trail');
    }

    /**
     * po_number is a single-line reference, unlike special_instructions/delivery_instructions —
     * TextInput::nullableStringMax() deliberately keeps tab/CR/LF in the middle of a value because
     * those fields are legitimately multi-line. An embedded newline in a PO number is never that;
     * it is a paste artifact (a copied value that carried CRLF from another single-line field), and
     * trim() only ever strips one from the very edges, so it survived to storage and every later
     * render of the field.
     */
    public function anEmbeddedLineBreakInPoNumberIsStrippedRatherThanKept(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'po_number' => "PO\r\n123",
            'save_mode' => 'save',
        ]);

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('PO123', $saved->getPoNumber(), 'the embedded CRLF should be stripped, not kept in the middle of the value');
    }
}
