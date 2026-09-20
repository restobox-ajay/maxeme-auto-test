<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * What the admin order and quote forms do with the two numbers on a line, end to end over HTTP.
 *
 * The rules, which are the business's and not this code's:
 *
 *   quantity 0        legal — placeholder and soft-note rows are written that way, as in Zoho
 *   quantity blank    0 on BOTH documents: nothing is substituted for a figure nobody typed (#259)
 *   quantity negative not a quantity: saved as 0, and SAID SO on the row it happened to
 *   price negative    legal, deliberate, stored exactly as typed
 *   price 0 or more   legal
 *   price unreadable  not a price: saved as 0, and SAID SO on the row it happened to (#253)
 *   price blank       quote only: "No pricing". An order backfills the catalog default.
 *
 * And one rule about the arithmetic between those figures and the document's Grand Total: it is
 * rounded once, at the total, with everything before it carried at SalesDocumentMoney::SCALE — the
 * same way on both documents, which is what #257 was about.
 *
 * Nothing here is ever refused. A refusal costs the admin the whole form (issue #227) over a
 * figure the business considers legal, and the one figure that is not gets rewritten rather than
 * bounced — which is only safe while the rewrite is impossible to miss. So the assertions below
 * come in pairs: what was stored, AND what the page says about it. The message and the red cell
 * are server-rendered, because the admin these forms are built for has JavaScript off and the
 * save has already redirected by the time the page paints.
 *
 * sendFormPostRequest() rather than sendAjaxPostRequest() throughout: these are no-JS saves, and
 * the request should not announce itself as an XHR. (Both update Codeception's crawler since #228;
 * before that only sendFormPostRequest() did, which is the other reason it was chosen here.)
 */
final class AdminSalesLineQuantityAndPriceRulesCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-line-number-rules-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Line Rules Co')
            ->setCode('LINERULES-' . uniqid())
            ->setPrimaryEmail('buyer@line-rules.example');
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /**
     * A quote cannot be created for a company with no active fulfillment region (#238) — there is
     * no price list, so no correct price. Nothing to do with the line-number rules under test here,
     * but every quote below is created through the form, so each one needs a region to exist at all.
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

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('LINERULES-SKU-' . uniqid())
            ->setName('Line Rules Widget')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSalesTaxCode('G')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('LINERULES-' . uniqid())
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Line Rules Widget')
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setPrice('10.00')
                ->setSubtotal('20.00')
        );
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. It carries no
        // invoices, so the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, ProductCore $product): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('LINERULES-Q-' . uniqid())
            ->setSource('Admin');
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName('Line Rules Widget')
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setPrice('10.00')
                ->setSubtotal('20.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
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

    /* ── Orders ─────────────────────────────────────────────────────────────────────────── */

    public function anOrderLineQuantityOfZeroIsSavedAsZeroAndIsNotAnError(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '0', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('created successfully');

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getSubtotal());

        // 0 is a quantity, so nothing was coerced and nothing is flagged.
        $I->dontSeeElement('.line-warning-banner');
        $I->dontSeeElement('#order-form input.line-input-error');
        $I->dontSee('negative quantity was saved as 0');
    }

    public function aNegativeOrderLineQuantityIsSavedAsZeroWithTheCellRedAndTheLineNamed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        // Two lines, only the second bad: the message and the red cell both have to name row 2.
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '10.00'],
                ['product_id' => (string) $product->getId(), 'qty' => '-5', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        // The save SUCCEEDED — that is the whole point of the change.
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('created successfully');

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $lines = $saved->getLines()->toArray();
        $I->assertCount(2, $lines);
        $I->assertSame(3.0, (float) $lines[0]->getQuantity());
        $I->assertSame(0.0, (float) $lines[1]->getQuantity());
        $I->assertSame(0.0, (float) $lines[1]->getSubtotal());

        // …and it said so, in words, naming the line.
        $I->see('Line 2: negative quantity was saved as 0.', '.line-warning-banner');
        // …and painted that row's quantity cell red, server-side: no script ran here.
        $I->seeElement('#order-form td.line-cell-error input.line-input-error[name="lines[1][qty]"]');
        $I->dontSeeElement('#order-form input.line-input-error[name="lines[0][qty]"]');
        $I->seeNumberOfElements('#order-form .js-order-lines-body input.line-input-error', 1);
    }

    public function editingAnOrderKeepsAZeroQuantityAndFlagsANegativeOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '0', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->dontSeeElement('.line-warning-banner');

        // A blank row ahead of the bad one: it is skipped on save, so the row the message names is
        // the row the form re-renders, not the row that was posted.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['name' => '', 'qty' => '1', 'price' => ''],
                ['product_id' => (string) $product->getId(), 'qty' => '-5', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('updated successfully');
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertCount(1, $saved->getLines());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());

        $I->see('Line 1: negative quantity was saved as 0.', '.line-warning-banner');
        $I->seeElement('#order-form td.line-cell-error input.line-input-error[name="lines[0][qty]"]');
    }

    /**
     * A Qty the admin left empty is 0, not a figure the save picked for them (#259). Asserted on
     * the order side too, though it was already right here, because the rule is that the two
     * documents answer the same keystrokes the same way — and the quote's half of it is a change.
     */
    public function aBlankOrderLineQuantityIsSavedAsZeroRatherThanSubstituted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getSubtotal());
        // A blank quantity is not a coercion of anything — there was nothing to coerce.
        $I->dontSeeElement('.line-warning-banner');
    }

    public function aNegativeOrderLinePriceIsStoredExactlyAsTypedAndIsNotAWarning(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '-25.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        // Stated before anything is read back: a refusal over the price would leave nothing to read.
        $I->dontSee('price cannot be negative');
        $I->seeInRepository(SalesOrder::class, ['company' => $company->getId()]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->see('created successfully');

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $line = $saved->getLines()->first();
        $I->assertSame(-25.0, (float) $line->getPrice());
        // The subtotal follows the price down; it was clamped at 0 before, which quietly turned a
        // credit line into a free one.
        $I->assertSame(-50.0, (float) $line->getSubtotal());
        $I->assertSame(-50.0, (float) $saved->getSubtotal());

        // Nothing was coerced, so nothing is flagged — a legal price must not look like a mistake.
        $I->dontSeeElement('.line-warning-banner');
        $I->dontSeeElement('#order-form input.line-input-error');
        // And the form hands the negative price straight back rather than a clamped 0.00.
        $I->assertSame(-25.0, (float) $I->grabAttributeFrom('#order-form input[name="lines[0][price]"]', 'value'));

        // The same on the edit path, which is its own copy of the save loop.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '4', 'price' => '-12.50'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $line = $saved->getLines()->first();
        $I->assertSame(-12.5, (float) $line->getPrice());
        $I->assertSame(-50.0, (float) $line->getSubtotal());
        $I->dontSeeElement('.line-warning-banner');
    }

    /**
     * The spreadsheet paste from #253. `1,234.56` is not is_numeric(), and the order's bare (float)
     * cast read it as 1.00 — a $1,234.56 line stored at a dollar, with "created successfully"
     * printed over the top. It is saved as 0 now, and the row says so.
     */
    public function anUnreadableOrderLinePriceIsSavedAsZeroWithTheCellRedAndTheLineNamed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        // Two lines, only the second bad: the message and the red cell both have to name row 2.
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '1,234.56'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        // The save SUCCEEDED, the same way a coerced quantity's does.
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $I->see('created successfully');

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $lines = $saved->getLines()->toArray();
        $I->assertSame(10.0, (float) $lines[0]->getPrice());
        // 0, not the 1.00 the (float) cast used to invent out of the thousands separator.
        $I->assertSame(0.0, (float) $lines[1]->getPrice());
        $I->assertSame(0.0, (float) $lines[1]->getSubtotal());
        $I->assertSame(20.0, (float) $saved->getSubtotal());

        // …and it said so, in words, naming the line.
        $I->see('Line 2: the price was not a number and was saved as 0.', '.line-warning-banner');
        // …and painted that row's PRICE cell red — not its quantity, which was fine.
        $I->seeElement('#order-form td.line-cell-error input.line-input-error[name="lines[1][price]"]');
        $I->dontSeeElement('#order-form input.line-input-error[name="lines[1][qty]"]');
        $I->seeNumberOfElements('#order-form .js-order-lines-body input.line-input-error', 1);

        // The edit path is its own copy of the save loop and applies the same rule.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '$99'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $saved = $this->reloadOrder($I, (int) $order->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getPrice());
        $I->see('Line 1: the price was not a number and was saved as 0.', '.line-warning-banner');
    }

    /**
     * The tax-breakdown preview builds a transient order from the form's rows. It used to floor
     * each row's subtotal at 0, so a credit line previewed as if it were free — a figure the save
     * would never produce.
     */
    public function theTaxBreakdownPreviewFollowsANegativeLineSubtotalDown(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$I->grabService(BundleStatusRepository::class)->isActive('TaxBCBundle')) {
            return;
        }

        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel('Main')
            ->setAddressLine1('1 Dock Road')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setIsDefaultShipping(true)
            ->setIsDefaultBilling(true);
        $I->haveInRepository($address);

        $preview = function (float $subtotal) use ($I, $company, $address, $product): float {
            $I->amOnPage('/admin/order/tax-breakdown?' . http_build_query([
                'company_id' => (string) $company->getId(),
                'address_id' => (string) $address->getId(),
                'province' => 'BC',
                'shipping' => '0',
                'lines' => json_encode([
                    ['product_id' => $product->getId(), 'qty' => 2, 'subtotal' => $subtotal, 'tax_code' => 'G'],
                ]),
            ]));
            $I->seeResponseCodeIsSuccessful();

            return (float) json_decode($I->grabPageSource(), true)['perLineTax'][0];
        };

        $positive = $preview(100.0);
        $I->assertGreaterThan(0.0, $positive, 'a taxable line should preview some tax, or this test proves nothing');

        // A credit line is taxed as a credit: same magnitude, opposite sign.
        $negative = $preview(-100.0);
        $I->assertLessThan(0.0, $negative);
        $I->assertEqualsWithDelta(-$positive, $negative, 0.001);
    }

    /* ── Quotes ─────────────────────────────────────────────────────────────────────────── */

    public function aQuoteLineQuantityOfZeroIsStoredAsZeroRatherThanQuietlyBecomingOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Zero Qty Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Zero Qty Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '0', 'price' => '25.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        // This is the silent rewrite the quote form used to do: a typed 0 became 1, on a document
        // that converts into an order.
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getSubtotal());

        $I->dontSeeElement('.line-warning-banner');
        $I->dontSeeElement('#estimate-line-rows input.line-input-error');

        // …and the same on the edit path, which applies the lines through the same method.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $saved->getLines()->first()->getId(), 'product_id' => (string) $product->getId(), 'qty' => '0', 'price' => '25.00'],
            ],
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->dontSeeElement('.line-warning-banner');
    }

    public function aNegativeQuoteLineQuantityIsSavedAsZeroWithTheCellRedAndTheLineNamed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Negative Qty Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        // Two lines, only the second bad — the message and the red cell must name row 2.
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Negative Qty Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '25.00'],
                1 => ['product_id' => (string) $product->getId(), 'qty' => '-5', 'price' => '25.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        // The quote SAVED.
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $lines = $saved->getLines()->toArray();
        $I->assertCount(2, $lines);
        $I->assertSame(3.0, (float) $lines[0]->getQuantity());
        $I->assertSame(0.0, (float) $lines[1]->getQuantity());

        $I->see('Line 2: negative quantity was saved as 0.', '.line-warning-banner');
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:nth-child(2) td.line-cell-error input.line-input-error');
        $I->dontSeeElement('#estimate-line-rows tr.estimate-line-row:first-child input.line-input-error');
        $I->seeNumberOfElements('#estimate-line-rows input.line-input-error', 1);

        // Editing an existing quote coerces and reports it the same way.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $lines[0]->getId(), 'product_id' => (string) $product->getId(), 'qty' => '-2', 'price' => '25.00'],
                1 => ['id' => (string) $lines[1]->getId(), 'product_id' => (string) $product->getId(), 'qty' => '4', 'price' => '25.00'],
            ],
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $lines = $saved->getLines()->toArray();
        $I->assertSame(0.0, (float) $lines[0]->getQuantity());
        $I->assertSame(4.0, (float) $lines[1]->getQuantity());
        $I->see('Line 1: negative quantity was saved as 0.', '.line-warning-banner');
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:first-child td.line-cell-error input.line-input-error');
    }

    /**
     * The quote half of #259, and the one that was actually wrong: a blank Qty used to fall back to
     * the line's STORED quantity, which on a brand-new line is EstimateLine's own '1.00' default.
     * So an empty field silently put a 1 on a document that converts into a real order, while the
     * same empty field on an order produced 0.
     */
    public function aBlankQuoteLineQuantityIsSavedAsZeroRatherThanTheEntityDefaultOfOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Blank Qty Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Blank Qty Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '', 'price' => '25.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getSubtotal());
        $I->dontSeeElement('.line-warning-banner');

        // The edit path runs the same method, and here the stored quantity is a real 3 rather than
        // the entity default — a blank field must not resurrect it either.
        $lineId = (int) $saved->getLines()->first()->getId();
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '25.00'],
            ],
            'action' => 'save',
        ]);
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame(3.0, (float) $saved->getLines()->first()->getQuantity());

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'qty' => '', 'price' => '25.00'],
            ],
            'action' => 'save',
        ]);
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());
    }

    /**
     * The quote's half of #253. Its Price field is type="text" — it has to be, because blank means
     * "No pricing" — so the browser hands a comma-formatted paste straight to the server, which
     * filed it as unpriced. A quote that reads "No pricing" on a line the admin priced is the
     * dangerous version of this bug: it goes to the customer for approval that way.
     */
    public function anUnreadableQuoteLinePriceIsSavedAsZeroAndNotFiledAsNoPricing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Bad Price Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Bad Price Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '25.00'],
                1 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '1,234.56'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $lines = $saved->getLines()->toArray();
        $I->assertSame(25.0, (float) $lines[0]->getPrice());
        // A stored 0, NOT a null — the line is priced at zero and flagged, not quietly unpriced.
        $I->assertNotNull($lines[1]->getPrice());
        $I->assertSame(0.0, (float) $lines[1]->getPrice());
        $I->assertSame(0.0, (float) $lines[1]->getSubtotal());

        $I->see('Line 2: the price was not a number and was saved as 0.', '.line-warning-banner');
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:nth-child(2) td.line-cell-error input.line-input-error');
        $I->seeNumberOfElements('#estimate-line-rows input.line-input-error', 1);
    }

    /**
     * The feature the fix above must not have eaten: a BLANK price is still "No pricing", which is
     * a quote-only state and deliberate. Only a value that was typed and could not be read becomes
     * 0 — the absence of one is not a bad price.
     */
    public function aBlankQuoteLinePriceStillMeansNoPricingAndIsNotAWarning(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules No Pricing Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules No Pricing Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'save_mode' => 'draft',
        ]);

        // That first save is the one moment a blank does NOT mean "No pricing": the product has
        // only just been attached, so the catalog figure is seeded into the row (#283) exactly as
        // the browser's product-select handler would have filled the box.
        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $seeded = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertNotNull($seeded->getLines()->first()->getPrice(), 'attaching a product seeds its price');

        // Clearing the box on a later save is the deliberate "No pricing" this test is about — the
        // product is already attached, so nothing is seeded and the blank stands.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $seeded->getLines()->first()->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $line = $saved->getLines()->first();
        $I->assertNull($line->getPrice(), 'a blank price is still the absence of one, not a 0');
        $I->assertNull($line->getSubtotal());
        // Not priced, so no Grand Total — the whole point of the state.
        $I->assertNull($saved->getTotal());

        $I->dontSeeElement('.line-warning-banner');
        $I->dontSeeElement('#estimate-line-rows input.line-input-error');
        // The field comes back empty showing its TBD placeholder, not a 0.00 nobody typed.
        $I->assertSame('', $I->grabAttributeFrom('#estimate-line-rows input[name$="[price]"]', 'value'));
    }

    /* ── Rounding ───────────────────────────────────────────────────────────────────────── */

    /**
     * The same basket totals the same on both documents (#257).
     *
     * Four lines of 3 × 0.917 = 2.751 each: every one falls a tenth of a cent past the cent, and
     * they sum to a raw 11.004 that no line and no stored column ever holds. A charge row of 0.003
     * then sits on top — small enough that only the un-rounded subtotal can carry it across the
     * half-cent.
     *
     * That is what used to separate them. The order built its Grand Total from `(float)
     * $order->getSubtotal()` — the decimal(12,2) column, so 11.00 — reaching 11.003 → $11.00, while
     * the quote added its raw running float and reached 11.007 → $11.01. Same lines, same charge,
     * a cent apart, on a quote whose whole purpose is to become that order.
     *
     * Both now carry every intermediate at SalesDocumentMoney::SCALE and apply the cent once, at
     * the total: $11.01 on each. The lines are E (exempt) so the figure under test is the rounding
     * and not whichever tax bundle happens to be enabled in the suite.
     */
    public function anOrderAndAQuoteWithSubCentLineFractionsReachTheSameGrandTotal(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Rounding Region');
        $product = $this->makeProduct($I);

        $chargeRows = [
            ['label' => 'Handling', 'amount' => '0.003', 'type' => 'shipping'],
        ];

        // ONE basket, posted to both forms. It used to be built twice — as indexed rows for the
        // order and as four parallel arrays for the quote — because the two forms named their line
        // fields differently. They do not any more, so the claim this test makes ("the same basket
        // reaches the same grand total on both documents") is now made with literally the same rows.
        $orderLines = [];
        for ($i = 0; $i < 4; $i++) {
            $orderLines[] = ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '0.917', 'tax_code' => 'E'];
        }

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            // Named on both documents so they are priced off the same list. makeCompany() already
            // gave this company one active region and makeActiveRegion() added a second, so neither
            // save gets to infer it (resolveFulfillmentRegion() only answers for itself when there
            // is exactly one).
            'fulfillment_region' => 'Line Rules Rounding Region',
            'lines' => $orderLines,
            'charge_lines' => $chargeRows,
            'save_mode' => 'draft_recalc',
        ]);
        $order = $this->reloadOrder(
            $I,
            (int) $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()])->getId(),
        );

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Rounding Region',
            'lines' => $orderLines,
            'charge_lines' => $chargeRows,
            'save_mode' => 'draft',
        ]);
        $estimate = $this->reloadEstimate(
            $I,
            (int) $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()])->getId(),
        );

        // Each line still PERSISTS at the cent — the columns are decimal(12,2) and that has not
        // changed. The precision is in the arithmetic between the rows and the total.
        $I->assertSame(2.75, (float) $order->getLines()->first()->getSubtotal());
        $I->assertSame(2.75, (float) $estimate->getLines()->first()->getSubtotal());

        // Both header subtotals show the same rounded 11.00 — and neither total is built from it.
        $I->assertSame(11.0, (float) $order->getSubtotal());
        $I->assertSame(11.0, (float) $estimate->getSubtotal());

        // The assertion this test exists for.
        $I->assertSame(
            (float) $order->getTotal(),
            (float) $estimate->getTotal(),
            'an order and a quote built from the same basket must reach the same Grand Total',
        );
        // …at the figure rounding-once produces: 11.004 + 0.003 = 11.007.
        $I->assertSame(11.01, (float) $order->getTotal());
        $I->assertSame(11.01, (float) $estimate->getTotal());
    }

    public function aNegativeQuoteLinePriceIsStoredExactlyAsTypedAndIsNotAWarning(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Line Rules Negative Price Region');
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Line Rules Negative Price Region',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '-25.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $line = $saved->getLines()->first();
        $I->assertSame(-25.0, (float) $line->getPrice());
        $I->assertSame(-50.0, (float) $line->getSubtotal());
        $I->assertSame(-50.0, (float) $saved->getSubtotal());

        $I->dontSeeElement('.line-warning-banner');
        $I->dontSeeElement('#estimate-line-rows input.line-input-error');

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '4', 'price' => '-12.50'],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame(-12.5, (float) $saved->getLines()->first()->getPrice());
        $I->assertSame(-50.0, (float) $saved->getLines()->first()->getSubtotal());
        $I->dontSeeElement('.line-warning-banner');
    }

    /**
     * The quote form's own quantity field used to say min="1" while the server said max(1.0, …):
     * agreeing with each other and with nothing the business asked for. It then said min="0" — which
     * still refused a negative outright, in a browser, on a form that has never carried novalidate.
     *
     * Neither field claims a floor now (#248). The quantity field must not, because a negative
     * quantity is not refused: it is stored as 0 and REPORTED, and a browser that refuses the submit
     * puts that report out of reach — which is exactly what happened on quotes while orders coerced
     * and explained. The price field must not, because a negative price is a credit.
     */
    public function neitherFormsQuantityOrPriceFieldClaimsAFloorTheServerDoesNotRefuse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('#order-form input[name="lines[0][qty]"]');
        $I->dontSeeElement('#order-form input[name="lines[0][qty]"][min]');
        $I->dontSeeElement('#order-form input[name="lines[0][price]"][min]');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('#estimate-line-rows input[name$="[qty]"]');
        $I->dontSeeElement('#estimate-line-rows input[name$="[qty]"][min]');
        $I->dontSeeElement('#estimate-line-rows input[name$="[price]"][min]');
    }

    /**
     * The browser must not refuse what the server accepts.
     *
     * app.js used to block the order form's submit on "quantity must be greater than 0" and "price
     * cannot be negative". Every other test in this file proves the server takes both — so those
     * checks made the same order savable with JavaScript off and refused with it on, and they put
     * the red-cell warning path (which only a *negative* quantity triggers) out of reach of anyone
     * using a browser normally.
     *
     * Asserted against the file because the functional suite runs no JavaScript: there is no other
     * way to notice the rule being quietly reintroduced, and a silent reintroduction is exactly how
     * it would come back.
     */
    public function theOrderFormsJavascriptDoesNotRefuseFiguresTheServerAccepts(FunctionalTester $I): void
    {
        $appJs = file_get_contents(codecept_root_dir('public/assets/js/app.js'));
        $I->assertNotFalse($appJs, 'public/assets/js/app.js could not be read');

        // The messages themselves, minus the one comment that explains why they are gone.
        $withoutComments = preg_replace('~^\s*//.*$~m', '', $appJs);

        $I->assertStringNotContainsString('quantity must be greater than 0', $withoutComments);
        $I->assertStringNotContainsString('price cannot be negative', $withoutComments);
    }
}
