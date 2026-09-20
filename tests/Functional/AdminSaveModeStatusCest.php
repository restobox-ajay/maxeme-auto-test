<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * What a save that states no `save_mode` is allowed to do to a document's status (#236).
 *
 * The order form has three save buttons and each one is a statement of intent: draft_recalc and
 * draft_exit say Draft, order accepts the order. Those are unchanged and the first test here pins
 * them, because they are the whole regression risk of this change.
 *
 * The status names moved in #539 stage 2 — the order says how much of it has been invoiced now, and
 * Pending/Processing/Completed are the INVOICE's — but not one rule below moved with them.
 *
 * Everything else in this file is about the case where nothing was stated at all. The no-JS row
 * delete posts the entire form plus `remove_line` and no mode — and edit() used to default an
 * absent mode to 'order', which is exactly the string the Save Order button posts. So deleting a
 * line from a Draft promoted it to a live order, and create() minted one for a save
 * nobody had described. An absent mode is not a decision about status; it now keeps the status the
 * order already had, and mints Draft when there is no order yet.
 *
 * Quotes are here too, unchanged and asserted: EstimateController already defaults create to Draft
 * and promotes only on an explicit save_mode=submit. It is the model this fix copied, so the
 * behaviour is pinned rather than left to be rediscovered.
 */
final class AdminSaveModeStatusCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('save-mode-status@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Every company here gets an active fulfillment region: since #237 an order cannot be created
     * for a company without one, and a single active region resolves itself server-side so no
     * fulfillment_region field has to be posted.
     */
    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Save Mode Status Co')
            ->setCode('SMS-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku = 'SMS-SKU'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku . '-' . uniqid())
            ->setName('Save Mode Status Widget')
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
     * $status is one of the two an order can be PUT INTO (#539 stage 2): every order is born a Draft
     * and approve() is the only transition that moves it. Everything past Approved is derived from
     * the invoice set, so a fixture cannot declare it — the match below has no arm for those, on
     * purpose, so asking for one fails here instead of silently producing a Draft.
     */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, string $status): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SMS-' . uniqid())
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

    /** The fields a real save of this order posts, minus whatever the caller wants to add. */
    private function savePost(FunctionalTester $I, Company $company, ProductCore $product, array $extra = []): array
    {
        return array_merge([
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ], $extra);
    }

    private function statusOf(FunctionalTester $I, int $orderId): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return (string) $entityManager->find(SalesOrder::class, $orderId)->getStatus();
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

    // ------------------------------------------------------- the explicit buttons, unchanged

    /**
     * The regression this change must not cause. All three buttons still mean what their labels
     * say, including `order` accepting a Draft — the behaviour the absent case used to borrow, and
     * the reason it was a bug. Save Order APPROVES the order now rather than assigning it a status
     * (#539 stage 2); it is the same button making the same decision, through the named action.
     *
     * The two draft modes are asserted on a Draft, not on a live order as they once were: a
     * draft save can only ever confirm a draft now, because demoting a live order released its
     * stock reservations silently (#264). What a draft mode does to a NON-draft order is
     * AdminOrderSavePathCest's subject.
     */
    public function theThreeSaveButtonsStillDecideTheStatusTheyAlwaysDid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Every fixture up front: reading a status back clears the entity manager, and a fixture
        // written after that would be persisting against detached Company/Product objects.
        $cases = [
            ['draft_recalc', 'Draft', 'Draft'],
            ['draft_exit', 'Draft', 'Draft'],
            // Approved and not Invoiced: unlike create(), an edit save raises no invoice, so the
            // deriver finds an approved order with nothing invoiced against it and leaves it there.
            ['order', 'Draft', 'Approved'],
        ];
        foreach ($cases as $i => [, $before]) {
            $cases[$i][3] = $this->makeOrder($I, $company, $product, $before);
        }

        foreach ($cases as [$saveMode, $before, $after, $order]) {
            $I->amOnPage('/admin/order/edit/' . $order->getId());
            $I->sendFormPostRequest(
                '/admin/order/edit/' . $order->getId(),
                $this->savePost($I, $company, $product, ['save_mode' => $saveMode])
            );

            $I->assertSame(
                $after,
                $this->statusOf($I, (int) $order->getId()),
                sprintf('save_mode=%s on a %s order should give %s', $saveMode, $before, $after)
            );
        }
    }

    /**
     * Same on create: only Save Order mints a live order, the two draft modes mint a Draft.
     *
     * A live one lands in Invoiced rather than Approved, and that is the whole of #539 arriving at
     * once: create() approves the order and raises no invoice for it (#539), because an
     * admin-raised order is invoiced deliberately through Convert to Invoice rather than arriving
     * pre-billed. An approved order with nothing invoiced is exactly what SalesOrderStatusDeriver
     * calls Approved. The button's decision is unchanged — draft or live — and this is what "live"
     * now looks like on an admin-raised order.
     */
    public function theThreeSaveButtonsStillMintTheStatusTheyAlwaysDidOnCreate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->makeProduct($I);

        // A company each, all made before the first save: reading the created order back clears
        // the entity manager, which would detach anything made later in the loop.
        $cases = [
            ['draft_recalc', 'Draft'],
            ['draft_exit', 'Draft'],
            ['order', 'Approved'],
        ];
        foreach ($cases as $i => $case) {
            $cases[$i][2] = $this->makeCompany($I);
        }

        foreach ($cases as [$saveMode, $expected, $company]) {
            $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
            $I->sendFormPostRequest(
                '/admin/order/create',
                $this->savePost($I, $company, $product, ['save_mode' => $saveMode])
            );

            $I->assertSame(
                $expected,
                (string) $this->newestOrderFor($I, $company)->getStatus(),
                sprintf(
                    'create with save_mode=%s should mint %s (an admin-raised order is approved but'
                    . ' not billed — it carries no invoice until one is raised)',
                    $saveMode,
                    $expected
                )
            );
        }
    }

    /**
     * The top-of-page Save Order button posts through form="order-form" and used to carry no
     * name/value at all — it promoted only because the controller defaulted an unstated post to
     * 'order'. With that default gone the button has to state its mode, or the one control on the
     * page labelled "Save Order" would quietly stop making orders.
     */
    public function theHeaderSaveOrderButtonStatesItsModeExplicitly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('.panel-actions button[type="submit"][form="order-form"][name="save_mode"][value="order"]');
        $I->dontSeeElement('.panel-actions button[type="submit"][form="order-form"]:not([name])');
    }

    // ------------------------------------------------------- absent save_mode, edit

    /**
     * The core of #236, across the statuses an order actually sits in. A Draft is the one that used
     * to break — it was promoted to a live order — but the rule is the same for both: a save that
     * said nothing about status changes nothing about status.
     *
     * Draft and Approved are the two an order can be placed in and left in without inventing an
     * invoice for it (#539 stage 2). The derived three are not omitted for being uninteresting:
     * Partially Invoiced and Invoiced are functions of an invoice set, and Closed cannot be edited
     * at all. What a save with no mode does to a status the enum does not know is the next test.
     */
    public function aSaveStatingNoModeLeavesEveryStatusExactlyAsItFoundIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Both up front — see the note in the three-buttons test above.
        $orders = [];
        foreach (['Draft', 'Approved'] as $status) {
            $orders[$status] = $this->makeOrder($I, $company, $product, $status);
        }

        foreach ($orders as $status => $order) {
            $I->amOnPage('/admin/order/edit/' . $order->getId());
            $I->sendFormPostRequest(
                '/admin/order/edit/' . $order->getId(),
                $this->savePost($I, $company, $product, ['po_number' => 'PO-NO-MODE-' . $status])
            );

            $entityManager = $I->grabService(EntityManagerInterface::class);
            $entityManager->clear();
            $saved = $entityManager->find(SalesOrder::class, (int) $order->getId());

            // The positive control this assertion was missing (#594). A status that held still
            // because NOTHING was written proves nothing about save_mode — a 500 on an unguarded
            // enum, a CSRF refusal or a validation bounce all leave both statuses exactly where the
            // fixture put them. The neighbouring legacy-status test already carries this guard and
            // says why; this one did not.
            $I->assertSame(
                'PO-NO-MODE-' . $status,
                $saved->getPoNumber(),
                sprintf('the %s order was never saved at all, so its unchanged status is about nothing', $status)
            );

            $I->assertSame(
                $status,
                (string) $saved->getStatus(),
                sprintf('a save with no save_mode changed a %s order', $status)
            );
        }
    }

    /**
     * A row still carrying a pre-#539 status: the one case where a no-mode save does move the
     * status, and pointedly not because the save said anything about it.
     *
     * SalesOrderStatusDeriver reads a status the enum does not know as "never approved" and
     * normalises it DOWN to Draft. That is the assertion worth having: the absent mode still decides
     * nothing — if it were being defaulted to 'order' again, this legacy row would come back live
     * rather than as a Draft, which is precisely the #236 defect.
     *
     * The status has to be written past the entity because there is no way to hold one otherwise:
     * SalesOrder has no setStatus(), and the subscriber would recompute the value away on the very
     * flush that wrote it. A real legacy row got here by predating all of that.
     */
    public function aSaveStatingNoModeLeavesALegacyStatusToTheDeriverRatherThanPromotingIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE sales_order SET status = :status WHERE id = :id',
            ['status' => 'Processing', 'id' => (int) $order->getId()]
        );
        $I->assertSame('Processing', $this->statusOf($I, (int) $order->getId()), 'guard: the legacy row was not seeded');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            $this->savePost($I, $company, $product, ['po_number' => 'PO-LEGACY'])
        );

        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        // The save really happened: a status that held still because nothing was written would
        // prove nothing about what the save decided.
        $I->assertSame('PO-LEGACY', $saved->getPoNumber(), 'the save was refused, so nothing here is about save_mode');
        $I->assertSame(
            'Draft',
            $saved->getStatus(),
            'a legacy status must be normalised down by the deriver, never promoted by a save that stated no mode'
        );
    }

    /**
     * The post that found the bug: the no-JS ✕ on a line row. It sends the whole form plus
     * `remove_line`, states no save_mode, and used to promote the Draft it was deleting a row from.
     * The removal itself still has to happen — a status that stayed put because nothing was saved
     * would prove nothing.
     */
    public function theNoJsRowDeleteDropsTheRowWithoutPromotingTheDraft(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $keep = $this->makeProduct($I, 'SMS-KEEP');
        $drop = $this->makeProduct($I, 'SMS-DROP');

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
        $I->seeElement('#order-form button.no-js-inline[name="remove_line"][value="1"]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $keep->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
                ['product_id' => (string) $drop->getId(), 'qty' => '1', 'price' => '50.00'],
            ],
            'remove_line' => '1',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        $I->assertSame('Draft', (string) $saved->getStatus(), 'the no-JS row delete promoted a Draft');
        $I->assertCount(1, $saved->getLines(), 'remove_line should have dropped exactly one line');
        $I->assertSame((string) $keep->getSku(), (string) $saved->getLines()->first()->getSku());
    }

    /** A save nobody described is not a save that is finished: it lands back on the form. */
    public function aSaveStatingNoModeComesBackToTheEditForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            $this->savePost($I, $company, $product)
        );

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
    }

    // ------------------------------------------------------- absent save_mode, create

    /** No order yet, so there is no status to keep: the one a document nobody finished should have. */
    public function aCreateStatingNoModeMintsADraftAndNotALiveOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product));

        $created = $this->newestOrderFor($I, $company);
        $I->assertSame('Draft', (string) $created->getStatus());
        $I->assertCount(1, $created->getLines(), 'the order was minted without the line it was posted with');
    }

    /**
     * create() read `lines` raw and never looked at `remove_line`, so the no-JS ✕ on a not-yet-saved
     * order saved the very row it was pressed to delete — and, before this change, saved it onto a
     * live order.
     */
    public function theNoJsRowDeleteOnCreateMintsADraftWithoutTheRemovedRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $keep = $this->makeProduct($I, 'SMS-CKEEP');
        $drop = $this->makeProduct($I, 'SMS-CDROP');

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $keep->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
                ['product_id' => (string) $drop->getId(), 'qty' => '1', 'price' => '50.00'],
            ],
            'remove_line' => '1',
        ]);

        $created = $this->newestOrderFor($I, $company);
        $I->assertSame('Draft', (string) $created->getStatus());
        $I->assertCount(1, $created->getLines(), 'the removed row was saved anyway');
        $I->assertSame((string) $keep->getSku(), (string) $created->getLines()->first()->getSku());
    }

    // ------------------------------------------------------- the status endpoint still works

    /**
     * Status still changes — it just changes where it is meant to. The modal posts to its own
     * endpoint, which says a status and nothing else, and none of this touches it.
     *
     * 'Approved' is one of exactly two statuses the endpoint still writes since #539 stage 2 — the
     * other is Void, and everything else an order can say about itself is derived from its invoices.
     * What the endpoint does with the derived ones is AdminOrderSavePathCest's subject.
     */
    public function theStatusEndpointStillChangesTheStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);

        $I->assertSame('Approved', $this->statusOf($I, (int) $order->getId()));
    }

    // ------------------------------------------------------- quotes, already correct

    private function makeQuote(FunctionalTester $I, Company $company, ProductCore $product, string $status): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('SMSQ-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('100.00')
            ->setTotal('100.00');
        $estimate->setStatus($status, DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantity('2.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /**
     * EstimateController needed no change for #236 and this says why in the only way that survives
     * someone deciding to "make the two controllers consistent": a quote edit that states no
     * save_mode leaves the quote alone, and only an explicit save_mode=submit promotes a Draft.
     */
    public function aQuoteSaveStatingNoModeLeavesTheQuotesStatusAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $quotes = [];
        foreach (['Draft', 'Submitted'] as $status) {
            $quotes[$status] = [$status, $this->makeQuote($I, $company, $product, $status)];
        }

        foreach ($quotes as [$status, $estimate]) {
            $line = $estimate->getLines()->first();

            $renamed = 'Renamed by a no-mode save (' . $status . ')';

            $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
            $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
                '_token' => $I->csrfToken(),
                'lines' => [
                    0 => ['id' => (string) $line->getId(), 'name' => $renamed, 'qty' => '2', 'price' => '50.00'],
                ],
            ]);

            $entityManager = $I->grabService(EntityManagerInterface::class);
            $entityManager->clear();
            $saved = $entityManager->find(Estimate::class, $estimate->getId());

            // The positive control (#594). Without it a status that held still because the POST was
            // refused outright — a validation bounce, a 400, an exception — reads exactly like a
            // save that correctly declined to touch the status.
            $I->assertSame(
                $renamed,
                $saved->getLines()->first()->getName(),
                sprintf('the %s quote was never saved at all, so its unchanged status is about nothing', $status)
            );

            $I->assertSame(
                $status,
                $saved->getStatus(),
                sprintf('a quote save with no save_mode changed a %s quote', $status)
            );
        }
    }

    /** And the explicit promotion still works, so the assertion above is not passing by accident. */
    public function anExplicitSubmitStillPromotesADraftQuote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeQuote($I, $company, $product, 'Draft');
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'submit',
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => $product->getName(), 'qty' => '2', 'price' => '50.00'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertSame(
            'Submitted',
            $entityManager->find(Estimate::class, $estimate->getId())->getStatus()
        );
    }
}
