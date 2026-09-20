<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * How an admin with JavaScript turned off adds lines to an order or a quote, now that the `add_line`
 * submit added by #225 has been removed again.
 *
 * The loop these tests drive is the one that was always there:
 *
 *   fill one of the two spare `.no-js-row` rows at the bottom of the line table
 *     → press a save button that is a plain `type="submit"` with no `js-only` class
 *       ("Save Draft & Recalc" on an order, "Save as Draft"/"Save & Recalculate" on a quote)
 *     → the save redirects to the document's edit page
 *     → that page renders two FRESH spare rows
 *     → repeat.
 *
 * `add_line` only ever bought *not persisting*, and since #229 removed the quantity/price refusals
 * a half-built document can no longer be turned away, so the loop cannot dead-end.
 *
 * The assertion everything else rests on is "two spare rows are waiting on the page the save landed
 * on" — without it the loop is a one-shot and the deletion would not be safe. So it is made after
 * every single hop, not just the first.
 *
 * These POSTs are plain form posts (sendFormPostRequest — no X-Requested-With header), i.e. exactly
 * what a browser with JS off sends, and they carry only field names the form actually renders.
 */
final class AdminNoJsLineLoopCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-nojs-line-loop@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('NOJSLOOP-' . uniqid())
            ->setPrimaryEmail('buyer@nojs-line-loop.example');
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /**
     * A quote cannot be created for a company with no active fulfillment region (#238), so the
     * quote loop below needs one before its first "Save as Draft" can mint anything.
     */
    private function makeActiveRegion(FunctionalTester $I, Company $company, string $regionName): void
    {
        $priceList = (new PriceList())->setName($regionName . ' List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $region = (new FulfillmentRegion())->setName($regionName)->setStatus('Active');
        $I->haveInRepository($region);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name): ProductCore
    {
        // No ProductPricing row on purpose — every price these tests care about is one the admin
        // types into the form, which is the only thing a no-JS browser can send.
        $product = (new ProductCore())->setSku($sku)->setName($name)->setCostPrice('5.00')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function reloadOrder(FunctionalTester $I, int $id): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $id);
    }

    private function reloadEstimate(FunctionalTester $I, int $id): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Estimate::class, $id);
    }

    // ── Order form ──────────────────────────────────────────────────────────────────────────

    /**
     * The whole no-JS order loop, run three times over on one document. Round 1 mints the Draft from
     * the create page; rounds 2 and 3 happen on the edit page, which is what proves the loop is
     * repeatable rather than a one-shot: every landing page has to hand back two more spare rows.
     */
    public function withoutJsAnAdminBuildsAnOrderOneSpareRowAtATime(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Order Loop Co');
        $first = $this->makeProduct($I, 'NOJS-LOOP-1', 'No JS Loop Product One');
        $second = $this->makeProduct($I, 'NOJS-LOOP-2', 'No JS Loop Product Two');
        $third = $this->makeProduct($I, 'NOJS-LOOP-3', 'No JS Loop Product Three');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        // The page a no-JS admin starts from: two spare rows and nothing else.
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row', 2);
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row.no-js-row', 2);
        // The <noscript> mechanism the spare rows and the no-JS delete both depend on.
        $I->seeInSource('.no-js-row { display: table-row !important; }');
        $I->seeInSource('.no-js-inline { display: inline-block !important; }');
        $I->seeInSource('.js-only { display: none !important; }');
        // "Save Draft & Recalc" is the button that drives the loop: a plain submit, no js-only.
        $I->seeElement('#order-form button[type="submit"][name="save_mode"][value="draft_recalc"]:not(.js-only)');
        // Nothing named add_line is on the page any more.
        $I->dontSeeElement('#order-form [name="add_line"]');

        // ── Round 1: fill the spare product row, save, land on the edit page ────────────────
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-LOOP-1',
            'lines' => [
                ['product_id' => (string) $first->getId(), 'sku' => 'NOJS-LOOP-1', 'qty' => '3', 'price' => '11.50'],
                ['name' => '', 'qty' => '1', 'price' => ''],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('created successfully');

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines(), 'The filled spare row should have persisted as a line.');
        $I->assertSame('PO-LOOP-1', $saved->getPoNumber());
        $I->assertSame(3.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame(11.50, (float) $saved->getLines()->first()->getPrice());

        // THE assertion the deletion rests on: the page the save landed on is ready for the next
        // line. One saved row, plus two fresh spares.
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row', 3);
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row.no-js-row', 2);
        // The spare rows are the pair the form is built around: one product picker, one free text.
        $I->seeElement('#order-form tr.no-js-row select[name="lines[1][product_id]"]');
        $I->seeElement('#order-form tr.no-js-row input[name="lines[2][name]"]');
        // The saved line is a real row, not a spare.
        $I->seeElement('#order-form tr.order-line-row:not(.no-js-row) input[name="lines[0][qty]"][value="3"]');

        // ── Round 2: fill the spare product row again, save, land back here ─────────────────
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-LOOP-1',
            'lines' => [
                ['product_id' => (string) $first->getId(), 'sku' => 'NOJS-LOOP-1', 'qty' => '3', 'price' => '11.50'],
                ['product_id' => (string) $second->getId(), 'sku' => 'NOJS-LOOP-2', 'qty' => '4', 'price' => '7.25'],
                ['name' => '', 'qty' => '1', 'price' => ''],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('updated successfully');
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(2, $saved->getLines());
        $I->assertSame(
            ['NOJS-LOOP-1', 'NOJS-LOOP-2'],
            array_map(static fn (SalesOrderLine $line): string => (string) $line->getSku(), $saved->getLines()->toArray()),
        );

        // Two more spares — this is the hop that proves the loop repeats.
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row', 4);
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row.no-js-row', 2);
        $I->seeElement('#order-form tr.no-js-row select[name="lines[2][product_id]"]');
        $I->seeElement('#order-form tr.no-js-row input[name="lines[3][name]"]');

        // ── Round 3: once more, this time through the spare BLANK row ──────────────────────
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $first->getId(), 'sku' => 'NOJS-LOOP-1', 'qty' => '3', 'price' => '11.50'],
                ['product_id' => (string) $second->getId(), 'sku' => 'NOJS-LOOP-2', 'qty' => '4', 'price' => '7.25'],
                ['product_id' => (string) $third->getId(), 'sku' => 'NOJS-LOOP-3', 'qty' => '1', 'price' => '2.00'],
                ['name' => 'Hand written charge', 'qty' => '1', 'price' => '5.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(4, $saved->getLines());
        $I->seeInRepository(SalesOrderLine::class, ['name' => 'Hand written charge', 'price' => '5.00']);

        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row', 6);
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row.no-js-row', 2);
        $I->seeElement('#order-form tr.no-js-row select[name="lines[4][product_id]"]');
        $I->seeElement('#order-form tr.no-js-row input[name="lines[5][name]"]');
    }

    /**
     * The no-JS delete, which has no replacement and is the reason the whole `.no-js-inline`
     * mechanism stays.
     */
    public function withoutJsRemoveLineStillDeletesAnOrderLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Order Remove Co');
        $keep = $this->makeProduct($I, 'NOJS-KEEP', 'No JS Keep Product');
        $drop = $this->makeProduct($I, 'NOJS-DROP', 'No JS Drop Product');

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('NOJSLOOP-' . uniqid())
            // No status assignment: every order is born a Draft and approve() is the only way out
            // of it (#539 stage 2). A Draft is what this test wants, and what it must still be
            // afterwards — the no-JS delete is a row manipulation, not a promotion.
            ->setSubtotal('30.00')
            ->setTax('0.00')
            ->setTotal('30.00');
        foreach ([[$keep, 'NOJS-KEEP'], [$drop, 'NOJS-DROP']] as [$product, $sku]) {
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($sku)
                    ->setQuantity('1.00')
                    ->setPrice('15.00')
                    ->setSubtotal('15.00')
            );
        }
        $I->haveInRepository($order);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('#order-form button.no-js-inline[name="remove_line"][value="1"]');

        // Exactly what pressing that button sends: the whole form, plus remove_line naming the row.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $keep->getId(), 'sku' => 'NOJS-KEEP', 'qty' => '1', 'price' => '15.00'],
                ['product_id' => (string) $drop->getId(), 'sku' => 'NOJS-DROP', 'qty' => '1', 'price' => '15.00'],
            ],
            'remove_line' => '1',
            'save_mode' => 'draft_recalc',
        ]);

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines(), 'remove_line should have dropped exactly one line.');
        $I->assertSame('NOJS-KEEP', (string) $saved->getLines()->first()->getSku());
        $I->assertSame('Draft', $saved->getStatus(), 'deleting a row promoted the Draft.');
    }

    /**
     * `add_line` is gone, so a stray one — a stale bookmark, a cached page, a script posting the old
     * field — is now just an unrecognised parameter. It must not 500, and above all it must not
     * short-circuit the save it is riding along with.
     */
    public function aStrayAddLineParameterIsIgnoredAndTheOrderStillSaves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Stray Add Line Co');
        $product = $this->makeProduct($I, 'NOJS-STRAY', 'No JS Stray Product');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());

        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sku' => 'NOJS-STRAY', 'qty' => '2', 'price' => '6.00'],
            ],
            'add_line' => 'product',
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines(), 'A stray add_line must not make the save a no-op.');

        // And again on the edit page, where add_line used to short-circuit ahead of everything.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sku' => 'NOJS-STRAY', 'qty' => '9', 'price' => '6.00'],
            ],
            'add_line' => 'blank',
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines());
        $I->assertSame(9.0, (float) $saved->getLines()->first()->getQuantity(), 'A stray add_line must not skip the save.');
        // No row was appended by it either.
        $I->seeNumberOfElements('#order-form .js-order-lines-body tr.order-line-row.no-js-row', 2);
    }

    // ── Quote form ──────────────────────────────────────────────────────────────────────────

    /**
     * The same loop on the quote form. "Save as Draft" mints the quote from the create page and
     * redirects to edit; "Save & Recalculate" (action=save) keeps the admin on edit after that.
     */
    public function withoutJsAnAdminBuildsAQuoteOneSpareRowAtATime(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Quote Loop Co');
        $this->makeActiveRegion($I, $company, 'No JS Quote Loop Region');
        $first = $this->makeProduct($I, 'NOJS-QLOOP-1', 'No JS Quote Loop One');
        $second = $this->makeProduct($I, 'NOJS-QLOOP-2', 'No JS Quote Loop Two');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        // A starter row plus the two spares.
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row', 3);
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row.no-js-row', 2);
        $I->seeInSource('.no-js-row { display: table-row !important; }');
        $I->dontSeeElement('#estimate-form [name="add_line"]');

        // ── Round 1: create page, one product typed into the starter row ───────────────────
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'No JS Quote Loop Region',
            'po_number' => 'QUOTE-LOOP-1',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $first->getId(), 'name' => '', 'sku' => 'NOJS-QLOOP-1', 'qty' => '5', 'price' => '12.00'],
                1 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
                2 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertCount(1, $saved->getLines());
        $I->assertSame('QUOTE-LOOP-1', $saved->getPoNumber());
        $I->assertSame(5.0, (float) $saved->getLines()->first()->getQuantity());

        // One saved row, two fresh spares waiting.
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row', 3);
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row.no-js-row', 2);
        $I->seeElement('#estimate-line-rows tr.no-js-row select[name$="[product_id]"]');
        $I->seeElement('#estimate-line-rows tr.no-js-row input[name$="[name]"][type="text"]');
        $I->seeElement('#estimate-form button[type="submit"][name="action"][value="save"]:not(.js-only)');

        $savedLineId = (string) $saved->getLines()->first()->getId();

        // ── Round 2: fill a spare row on the edit page and save back onto it ───────────────
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'po_number' => 'QUOTE-LOOP-1',
            'lines' => [
                0 => ['id' => $savedLineId, 'product_id' => (string) $first->getId(), 'name' => '', 'sku' => 'NOJS-QLOOP-1', 'qty' => '5', 'price' => '12.00'],
                1 => ['id' => '', 'product_id' => (string) $second->getId(), 'name' => '', 'sku' => 'NOJS-QLOOP-2', 'qty' => '2', 'price' => '30.00'],
                2 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
            ],
            'action' => 'save',
        ]);

        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $I->see('Estimate saved.');
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertCount(2, $saved->getLines());
        $I->assertSame(
            ['NOJS-QLOOP-1', 'NOJS-QLOOP-2'],
            array_map(static fn (EstimateLine $line): string => (string) $line->getSku(), $saved->getLines()->toArray()),
        );

        // Two more spares — the loop repeats.
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row', 4);
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row.no-js-row', 2);

        // ── Round 3: once more, through the spare blank row this time ──────────────────────
        $lineIds = array_map(static fn (EstimateLine $line): string => (string) $line->getId(), $saved->getLines()->toArray());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => $lineIds[0], 'product_id' => (string) $first->getId(), 'name' => '', 'sku' => 'NOJS-QLOOP-1', 'qty' => '5', 'price' => '12.00'],
                1 => ['id' => $lineIds[1], 'product_id' => (string) $second->getId(), 'name' => '', 'sku' => 'NOJS-QLOOP-2', 'qty' => '2', 'price' => '30.00'],
                2 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
                3 => ['id' => '', 'product_id' => '', 'name' => 'Quoted handling charge', 'sku' => '', 'qty' => '1', 'price' => '4.00'],
            ],
            'action' => 'save',
        ]);

        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertCount(3, $saved->getLines());
        $I->seeInRepository(EstimateLine::class, ['name' => 'Quoted handling charge', 'price' => '4.00']);

        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row', 5);
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row.no-js-row', 2);
    }

    public function aStrayAddLineParameterIsIgnoredAndTheQuoteStillSaves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Stray Quote Co');
        $product = $this->makeProduct($I, 'NOJS-QSTRAY', 'No JS Stray Quote Product');

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('NOJSQSTRAY-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Draft', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName('No JS Stray Quote Product')
                ->setSku('NOJS-QSTRAY')
                ->setQuantity('2.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );
        $I->haveInRepository($estimate);
        $lineId = (string) $estimate->getLines()->first()->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => $lineId, 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => 'NOJS-QSTRAY', 'qty' => '8', 'price' => '50.00'],
                1 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
                2 => ['id' => '', 'product_id' => '', 'name' => '', 'sku' => '', 'qty' => '1', 'price' => ''],
            ],
            'add_line' => 'product',
            'action' => 'save',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertCount(1, $saved->getLines());
        $I->assertSame(8.0, (float) $saved->getLines()->first()->getQuantity(), 'A stray add_line must not skip the quote save.');
        $I->seeNumberOfElements('#estimate-line-rows tr.estimate-line-row.no-js-row', 2);
    }

    // ── The JS path is untouched ────────────────────────────────────────────────────────────

    /**
     * An admin WITH JavaScript must see no difference at all: the four add controls and the row
     * templates they clone are exactly where they were.
     */
    public function theJsAddControlsAreUntouchedOnBothForms(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'No JS Loop JS Path Co');
        $this->makeProduct($I, 'NOJS-JSPATH', 'No JS Loop JS Path Product');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->seeElement('#order-form .order-line-actions.js-only button.js-order-add-product[type="button"]');
        $I->seeElement('#order-form .order-line-actions.js-only button.js-order-add-blank[type="button"]');
        $I->seeElement('#order-form button.js-order-add-after.js-only[type="button"]');
        $I->seeElement('#order-form button.js-order-bottom-add[type="button"]');
        $I->seeElement('template#order-product-line-template');
        $I->seeElement('template#order-blank-line-template');
        // The index the JS hands the next cloned row: the rendered rows plus the two spares.
        $I->seeElement('#order-form[data-next-line-index="2"]');

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeElement('#estimate-form .order-line-actions.js-only button.js-estimate-add-product[type="button"]');
        $I->seeElement('#estimate-form .order-line-actions.js-only button.js-estimate-add-blank[type="button"]');
        // The per-row "+" is gone (#245) — it cloned a whole product line while looking like
        // order's batch "+". The bottom Add Line controls above are how a quote gains a line.
        $I->dontSeeElement('#estimate-form button.js-estimate-add-after');
    }
}
