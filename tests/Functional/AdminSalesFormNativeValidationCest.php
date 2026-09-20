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
 * What the admin order form and the admin quote form assert in the BROWSER, and whether the server
 * agrees with it (#248).
 *
 * The bug this file exists for was not a rule being wrong. It was the same rule producing two
 * different behaviours depending on which of the two forms an admin happened to be on:
 *
 *   - `novalidate` sat on exactly one form in the whole admin, the order's #order-form. Every
 *     native constraint below it was switched off; the IDENTICAL constraint on the quote form, which
 *     has never carried novalidate, fired.
 *   - So min="0" on the quantity input meant "the server will store 0 and tell you" on an order and
 *     "the browser refuses, nothing is posted" on a quote — for the same number, typed into the same
 *     column, on two documents that convert into one another.
 *
 * The fix is the one the issue argues for: drop the constraints that CONTRADICT the server, then
 * drop novalidate, so what is left states only what the server also enforces. A native constraint
 * that the server does not back is a rule that exists in one browser and nowhere else.
 *
 * The assertions therefore come in two kinds — what the markup claims, and what actually happens
 * when the figure it used to refuse is posted. Neither is worth much alone: the first without the
 * second is an attribute check, and the second without the first passes happily on a form whose
 * field a real browser would never have let the admin submit.
 *
 * sendFormPostRequest() rather than sendAjaxPostRequest() throughout: these saves are the no-JS
 * path and must not announce themselves as an XHR. (Both update Codeception's crawler since #228;
 * before that only sendFormPostRequest() did, which is the other reason it was chosen here.)
 */
final class AdminSalesFormNativeValidationCest
{
    /** The stored shipping + manual fee that make the totals box render real charge rows. */
    private const CHARGE_FEE_LINES = <<<'JSON'
        [
            {"slug":"shipping","label":"Shipping (Ground)","taxClass":"E","amount":15.0,"placement":"main_line","type":"shipping","source":"auto-calc"},
            {"slug":"crating","label":"Crating","taxClass":"E","amount":25.0,"placement":"main_line","type":"fee","source":"manual"}
        ]
        JSON;

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-native-validation-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Native Validation Co')
            ->setCode('NATVAL-' . uniqid())
            ->setPrimaryEmail('buyer@native-validation.example');
        $I->haveInRepository($company);
        // One active region, so the Fulfillment Region control renders as the `required` <select>
        // rather than the nameless disabled input — the required attribute is one of the things
        // under test, and without a region there is nothing carrying it.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('NATVAL-SKU-' . uniqid())
            ->setName('Native Validation Widget')
            ->setDefaultPrice('10.00')
            ->setSalesTaxCode('G')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('NATVAL-' . uniqid())
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setFeeLines(self::CHARGE_FEE_LINES)
            ->setTotal('20.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setPrice('10.00')
                ->setSubtotal('20.00')
        );
        $I->haveInRepository($order);
        // A live order, expressed the #539 stage 2 way: approve() on a persisted Draft. It has no
        // invoices, so the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, ProductCore $product): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('NATVAL-Q-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('20.00')
            ->setFeeLines(self::CHARGE_FEE_LINES)
            ->setTotal('20.00');
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setPrice('10.00')
                ->setSubtotal('20.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /**
     * Every `<input>` tag on the page whose name matches $namePattern, as raw markup.
     *
     * Read off the source rather than through the crawler because two of the three places the order
     * form renders a quantity field live inside a <template> — the rows app.js clones for "Add
     * Product" / "Add Blank Line". A CSS assertion against those is at the mercy of how the parser
     * treats template content, and they are exactly where a dropped attribute would come back: a
     * row added with JavaScript on would then be validated differently from the rows already on the
     * page, which is a smaller copy of the very split this issue is about.
     *
     * @return list<string>
     */
    private function inputTagsMatching(string $source, string $namePattern): array
    {
        preg_match_all('#<input\b[^>]*>#i', $source, $matches);

        return array_values(array_filter(
            $matches[0],
            static fn (string $tag): bool => (bool) preg_match($namePattern, $tag)
        ));
    }

    /* ── What the markup claims ─────────────────────────────────────────────────────────────── */

    /**
     * The order form no longer switches native validation off — the single attribute that made
     * every other assertion in this file mean something different on one form than the other.
     */
    public function theOrderFormNoLongerSwitchesNativeValidationOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        // Stated positively first: an assertion that the attribute is absent proves nothing if the
        // form itself failed to render.
        $I->seeElement('form#order-form');
        $I->dontSeeElement('form#order-form[novalidate]');
        // …and no other form on the page smuggled it back in.
        $I->dontSeeElement('form[novalidate]');

        // The quote's forms never had it. Asserted anyway, because "make the two agree" is
        // satisfiable in the wrong direction — by adding novalidate to the quote form, which would
        // have put the coercion warnings out of reach on BOTH documents instead of neither.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form#estimate-form');
        $I->dontSeeElement('form[novalidate]');

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form#estimate-form');
        $I->dontSeeElement('form[novalidate]');
    }

    /**
     * Neither form's quantity field claims a floor — in any of the places either form renders one.
     *
     * min="0" was a lie in both directions. The server does not refuse a negative quantity: it
     * stores 0 and reports it, naming the line and painting the cell red. A browser that refuses the
     * submit does not enforce that rule, it hides it.
     */
    public function neitherFormsQuantityFieldClaimsAFloorInAnyRowItRenders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $estimate = $this->makeEstimate($I, $company, $product);

        // Order: one saved line, two spare .no-js-row rows, and the two <template> rows app.js
        // clones. All five are the same field and must say the same thing.
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $orderQtyInputs = $this->inputTagsMatching($I->grabPageSource(), '#name="lines\[[^\]]*\]\[qty\]"#');
        $I->assertGreaterThanOrEqual(
            3,
            count($orderQtyInputs),
            'the order form should render a quantity input per saved line, per spare no-JS row and per JS row template'
        );
        foreach ($orderQtyInputs as $tag) {
            $I->assertStringNotContainsString('min=', $tag, 'order quantity input still claims a floor: ' . $tag);
        }

        // Quote: same shape, minus the separate blank/product template split — and now literally
        // the same pattern as the order's above, because both forms name the field lines[N][qty].
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $quoteQtyInputs = $this->inputTagsMatching($I->grabPageSource(), '#name="lines\[[^\]]*\]\[qty\]"#');
        $I->assertGreaterThanOrEqual(
            3,
            count($quoteQtyInputs),
            'the quote form should render a quantity input per saved line, per spare no-JS row and per JS row template'
        );
        foreach ($quoteQtyInputs as $tag) {
            $I->assertStringNotContainsString('min=', $tag, 'quote quantity input still claims a floor: ' . $tag);
        }

        // The create page renders its own copy of the table, which is how the quote form's Price
        // field once ended up with a constraint the edit page's did not have.
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        foreach ($this->inputTagsMatching($I->grabPageSource(), '#name="line_qty\[\]"#') as $tag) {
            $I->assertStringNotContainsString('min=', $tag, 'quote create quantity input still claims a floor: ' . $tag);
        }
    }

    /**
     * The order form's line hint no longer states a rule the product stopped having.
     *
     * It read "each line's Qty * must be greater than 0". #229 made a quantity of 0 legal
     * server-side and #242 removed the app.js check that refused it, leaving this sentence as the
     * last thing in the product still telling an admin that a placeholder line was a mistake — and
     * it is server-rendered, so it was the last thing a no-JS admin could read on the subject.
     */
    public function theOrderFormNoLongerTellsAdminsAQuantityMustExceedZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-lines-section .field-hint');
        $I->dontSee('must be greater than 0', '.order-lines-section');
        // The line requirement itself is real and still stated — the hint was not simply deleted.
        $I->see('At least one order line is required', '.order-lines-section');
        // And it now says what the rule actually is.
        $I->see('Qty may be 0', '.order-lines-section');
    }

    /**
     * Both forms state the same charge-amount constraint, and it is one the server backs.
     *
     * The opposite call from the quantity field, deliberately. SalesDocumentChargeLines::normalize()
     * floors a negative amount at 0.0, so min="0" here is true — and unlike a coerced quantity there
     * is no warning banner or red cell to explain a coerced charge, so the browser saying so first
     * is the better of two behaviours that agree on the stored figure. What matters for this issue
     * is that both forms do the SAME thing, which they now do by construction: the row is one shared
     * partial (admin/_partials/sales_charge_row.html.twig).
     */
    public function bothFormsStateTheSameChargeAmountConstraint(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('input[name="charge_lines[0][amount]"][min="0"][step="0.01"]');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('input[name="charge_lines[0][amount]"][min="0"][step="0.01"]');
    }

    /**
     * Neither form carries a `required` attribute the server does not check.
     *
     * Exactly one field on each is required, and it is the same field: fulfillment_region, refused
     * server-side by OrderController::resolveFulfillmentRegion() and
     * EstimateController::applyEstimateFulfillmentRegion() (#237/#251/#261). Counting rather than
     * spot-checking is the point — this is what stops a new `required` being added to a field
     * nothing enforces, which is how the region field itself spent its first weeks.
     *
     * It is also why removing novalidate is safe: a `required` control inside a display:none
     * .no-js-row or .js-only element cannot be focused, and a browser refuses to submit a form it
     * cannot point the admin at. The one required control on each form is always visible.
     */
    public function neitherFormCarriesARequiredAttributeTheServerDoesNotEnforce(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('#order-form select[name="fulfillment_region"][required]');
        $I->seeNumberOfElements('#order-form [required]', 1);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('#estimate-form select[name="fulfillment_region"][required]');
        $I->seeNumberOfElements('#estimate-form [required]', 1);
    }

    /* ── What happens when the refused figure is actually posted ────────────────────────────── */

    /**
     * The whole point of the change, end to end on the document that was worse off.
     *
     * A negative quantity typed into the quote form's own quantity field now reaches the server,
     * which stores 0 and SAYS SO — the banner naming the line, the cell painted red. Before #248
     * that field carried min="0" on a form with no novalidate, so a real browser refused the submit
     * and none of it happened: the SalesDocumentLineWarnings path was unreachable from the quote
     * form entirely, while the identical figure on an order coerced and explained.
     *
     * The field assertion and the POST belong in one test on purpose. The coercion already had
     * coverage (AdminSalesLineQuantityAndPriceRulesCest); what it could not see is that no browser
     * would ever have made the post it was asserting on.
     */
    public function aNegativeQuantityTypedIntoTheQuoteFormReachesTheServerThatExplainsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // The field the admin types into. If this carried min="0" the POST below could not be made
        // from a browser, and everything after it would be proving something about an HTTP client.
        $I->seeElement('#estimate-line-rows input[name$="[qty]"]');
        $I->dontSeeElement('#estimate-line-rows input[name$="[qty]"][min]');

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'qty' => '-5', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);

        // The quote SAVED. Nothing is refused here — a refusal costs the admin the whole form.
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());

        // …and it said so, in words, naming the line, server-rendered: no script ran here.
        $I->see('Line 1: negative quantity was saved as 0.', '.line-warning-banner');
        // …and painted that row's quantity cell red.
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:first-child td.line-cell-error input.line-input-error');
    }

    /**
     * The same figure on the same column of the other document behaves the same way.
     *
     * Orders were never the broken half — novalidate meant the negative always reached the server.
     * Asserted beside the quote case anyway, because the failure this issue describes is a
     * DIFFERENCE between two forms, and a difference cannot be tested from one side of it.
     */
    public function theSameNegativeQuantityOnAnOrderBehavesIdentically(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('#order-form input[name="lines[0][qty]"]');
        $I->dontSeeElement('#order-form input[name="lines[0][qty]"][min]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '-5', 'price' => '10.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame(0.0, (float) $saved->getLines()->first()->getQuantity());

        $I->see('Line 1: negative quantity was saved as 0.', '.line-warning-banner');
        $I->seeElement('#order-form td.line-cell-error input.line-input-error[name="lines[0][qty]"]');
    }
}
