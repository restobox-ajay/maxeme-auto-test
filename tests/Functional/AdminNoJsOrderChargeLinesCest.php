<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin order form's charge rows — shipping, manual tax adjustments and ad-hoc fee lines —
 * without JavaScript. Quotes have had all of this for a while; orders had none of it.
 *
 * Three defects, all on the same money path:
 *
 *  1. OrderController had no `charge_lines_present` guard, so a post that never carried charge rows
 *     was read as "delete every charge" rather than "this post was never shown them". Opening a
 *     saved order, changing a quantity and pressing Save Draft & Recalc destroyed every shipping
 *     row, tax adjustment and manual fee line on it and silently changed the total.
 *     EstimateController::applyChargeLinesFromRequest() has had the guard all along.
 *  2. templates/admin/order/form.html.twig rendered zero charge_lines[...] inputs — the rows were
 *     built entirely by app.js from the form's data-order-charges attribute — so without JS an
 *     admin could not see or edit a single charge on an order.
 *  3. The Add Line control in .order-add-line-bar is a type="button": visible, and dead without JS.
 *
 * Every POST here goes through sendFormPostRequest(), i.e. a plain form POST with no
 * X-Requested-With header — exactly what a browser with JavaScript off sends, which is the whole
 * point of this Cest. sendAjaxPostRequest() would send the same fields but claim to be an XHR.
 */
final class AdminNoJsOrderChargeLinesCest
{
    /** A shipping row, a manual tax adjustment and an ad-hoc fee line, as an order stores them. */
    private const STORED_FEE_LINES = <<<'JSON'
        [
          {"feeId":null,"slug":"shipping-legacy-courier","label":"Shipping (Legacy Courier)","taxClass":"E","amount":25.0,"placement":"main_line","type":"shipping","source":"manual"},
          {"feeId":null,"slug":"crating","label":"Crating","taxClass":"E","amount":40.0,"placement":"main_line","type":"fee","source":"manual"}
        ]
        JSON;

    private const STORED_TAX_LINES = <<<'JSON'
        {"lines":[{"label":"Tax Adjustment","rate":null,"amount":7.0,"slug":"tax-adjustment","source":"manual"}],"total":7.0,"perLineTax":[],"perLineTaxLabel":[]}
        JSON;

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-nojs-order-charges-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('No Js Charge Test Co')
            ->setCode('NOJSCHG-' . uniqid());
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('NOJSCHG-SKU-1')
            ->setName('No Js Charge Test Product')
            // Exempt so no calculator adds tax of its own: every figure in these tests then comes
            // from the charge rows, which is what is under test.
            ->setSalesTaxCode('E')
            ->setDefaultPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /** An order of 2 x $50 with no charges of any kind on it yet. */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('NOJSCHG-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('No Js Charge Test Product')
                ->setQuantity('2.00')
                ->setTaxCode('E')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );
        $I->haveInRepository($order);

        return $order;
    }

    /** The same order carrying a shipping row, a manual tax line and a manual fee line. */
    private function makeOrderWithEveryChargeKind(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = $this->makeOrder($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(SalesOrder::class, $order->getId());
        $stored
            ->setFeeLines(self::STORED_FEE_LINES)
            ->setTaxLines(self::STORED_TAX_LINES)
            ->setShippingMethod('Legacy Courier')
            ->setTax('7.00')
            // 100 lines + 25 shipping + 40 crating + 7 tax adjustment.
            ->setTotal('172.00');
        $entityManager->flush();

        return $order;
    }

    /** The lines an ordinary edit save posts back — one product row, nothing about charges. */
    private function linePost(FunctionalTester $I, Company $company, ProductCore $product, string $qty = '2'): array
    {
        return [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft_recalc',
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => $qty, 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ];
    }

    /** @return array{shipping: float, fee: float, tax: float} */
    private function storedChargeTotals(FunctionalTester $I, int $orderId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(SalesOrder::class, $orderId);

        $totals = ['shipping' => 0.0, 'fee' => 0.0, 'tax' => 0.0];
        foreach (json_decode((string) $order->getFeeLines(), true) ?: [] as $line) {
            if (($line['type'] ?? '') === 'shipping') {
                $totals['shipping'] += (float) $line['amount'];
            } elseif (($line['source'] ?? '') === 'manual') {
                $totals['fee'] += (float) $line['amount'];
            }
        }
        foreach ((json_decode((string) $order->getTaxLines(), true) ?: [])['lines'] ?? [] as $line) {
            if (($line['source'] ?? '') === 'manual') {
                $totals['tax'] += (float) $line['amount'];
            }
        }

        return $totals;
    }

    // ── Defect 3: the missing charge_lines_present guard ─────────────────────────────────────

    /**
     * The data-loss case, exactly as an admin hits it: open a saved order, change one quantity,
     * press Save Draft & Recalc. Before the guard this post wiped the shipping row, the tax
     * adjustment and the manual fee line, and moved the total, while reporting a clean save.
     *
     * The quantity really does change, so this is a save that did something — it is only the
     * charges it must leave alone.
     */
    public function aSaveCarryingNoChargeRowsLeavesEveryStoredChargeAndTheTotalsAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithEveryChargeKind($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            $this->linePost($I, $company, $product, '2')
        );

        $totals = $this->storedChargeTotals($I, $order->getId());
        $I->assertEqualsWithDelta(25.0, $totals['shipping'], 0.001, 'the shipping row was destroyed');
        $I->assertEqualsWithDelta(40.0, $totals['fee'], 0.001, 'the manual fee line was destroyed');
        $I->assertEqualsWithDelta(7.0, $totals['tax'], 0.001, 'the manual tax line was destroyed');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertEqualsWithDelta(172.0, (float) $saved->getTotal(), 0.011, 'the total moved');
        $I->assertEqualsWithDelta(7.0, (float) $saved->getTax(), 0.011);
        // The charge rows survived a save that genuinely changed the document, not a no-op.
        $I->assertEqualsWithDelta(100.0, (float) $saved->getSubtotal(), 0.011);
    }

    /**
     * The other half of the same rule, and the reason the guard has to key off the marker rather
     * than off "no rows arrived": a post that DID render the charge UI and carries no rows is an
     * admin who removed them all, and that must really clear them.
     */
    public function aSaveCarryingTheChargeUiMarkerAndNoRowsDoesClearThem(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithEveryChargeKind($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            $this->linePost($I, $company, $product) + ['charge_lines_present' => '1']
        );

        $totals = $this->storedChargeTotals($I, $order->getId());
        $I->assertEqualsWithDelta(0.0, $totals['shipping'], 0.001, 'the shipping row should have been cleared');
        $I->assertEqualsWithDelta(0.0, $totals['fee'], 0.001, 'the manual fee line should have been cleared');
        $I->assertEqualsWithDelta(0.0, $totals['tax'], 0.001, 'the manual tax line should have been cleared');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertEqualsWithDelta(100.0, (float) $saved->getTotal(), 0.011);
    }

    /**
     * The guard restores the charges rather than merely refusing to write: posting the rows the
     * form hands back must produce byte-identical snapshots, or a "protected" save would still be
     * quietly rewriting the money it claims to preserve.
     */
    public function theChargesTheGuardRestoresAreTheOnesThatWereAlreadyStored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithEveryChargeKind($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->linePost($I, $company, $product));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $first = $entityManager->find(SalesOrder::class, $order->getId());
        $firstFees = $first->getFeeLines();
        $firstTaxes = $first->getTaxLines();
        $I->assertStringContainsString('Shipping (Legacy Courier)', (string) $firstFees);
        $I->assertStringContainsString('Crating', (string) $firstFees);
        $I->assertStringContainsString('Tax Adjustment', (string) $firstTaxes);
        // The shipping method the row derives from is preserved too, not blanked.
        $I->assertSame('Legacy Courier', $first->getShippingMethod());

        // A second identical unguarded post must be a no-op on top of the first.
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->linePost($I, $company, $product));

        $entityManager->clear();
        $second = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame($firstFees, $second->getFeeLines());
        $I->assertSame($firstTaxes, $second->getTaxLines());
        $I->assertSame($first->getTotal(), $second->getTotal());
    }

    // ── Defects 1 and 2: the rows and the button on the page ─────────────────────────────────

    /**
     * Every stored charge comes back as a real, editable, posting input — the read half of the
     * round trip, without which the rows the guard preserves are invisible and the Add Line submit
     * below creates a row nobody can fill in.
     */
    public function everyStoredChargeRendersAsAnEditableInputWithoutJs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithEveryChargeKind($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        // Shipping: a named row, so its label is fixed and travels as a hidden field.
        $I->seeElement('input[type="hidden"][name="charge_lines[0][type]"][value="shipping"]');
        $I->seeElement('input[name="charge_lines[0][amount]"][value="25.00"]');
        $I->seeElement('input[type="hidden"][name="charge_lines[0][label]"][value="Shipping (Legacy Courier)"]');

        // Manual tax adjustment: free-text label, so an editable input, plus its reporting slug.
        $I->seeElement('input[type="hidden"][name="charge_lines[1][type]"][value="tax"]');
        $I->seeElement('input[name="charge_lines[1][amount]"][value="7.00"]');
        $I->seeElement('input[type="text"][name="charge_lines[1][label]"][value="Tax Adjustment"]');
        $I->seeElement('input[name="charge_lines[1][slug]"][value="tax-adjustment"]');

        // Manual fee line: everything a fee needs, since it has no Fee definition to inherit from.
        $I->seeElement('input[type="hidden"][name="charge_lines[2][type]"][value="fee"]');
        $I->seeElement('input[name="charge_lines[2][amount]"][value="40.00"]');
        $I->seeElement('input[type="text"][name="charge_lines[2][label]"][value="Crating"]');
        $I->seeElement('input[name="charge_lines[2][slug]"][value="crating"]');
        $I->seeElement('select[name="charge_lines[2][taxClass]"] option[value="E"][selected]');
        // #669, order-only: placement is no longer an editable select on the row — it travels as a
        // hidden field carrying whatever was chosen when the row was added (or, for a row the
        // server is redrawing, whatever is already stored).
        $I->seeElement('input[type="hidden"][name="charge_lines[2][placement]"][value="main_line"]');

        // And the marker that lets a later save clear them all deliberately.
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');
    }

    /**
     * A manual fee's placement is stated in plain text on the row, not just carried as a hidden
     * field — because the row's own position no longer says which it is: Main Line and Before Tax
     * both anchor beside Subtotal (chargeRowAnchor() in app.js), so a row with nothing visible
     * stating its placement is indistinguishable from the other kind. Two fees, one of each
     * placement, is the positive control for both labels at once.
     */
    public function aFeeRowsPlacementIsStatedInPlainTextOnTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(SalesOrder::class, $order->getId());
        $stored->setFeeLines(<<<'JSON'
            [
              {"feeId":null,"slug":"handling","label":"Handling","taxClass":"E","amount":10.0,"placement":"main_line","type":"fee","source":"manual"},
              {"feeId":null,"slug":"admin-charge","label":"Admin Charge","taxClass":"E","amount":15.0,"placement":"before_tax_line","type":"fee","source":"manual"}
            ]
            JSON);
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('input[type="hidden"][name="charge_lines[0][placement]"][value="main_line"]');
        $I->seeElement('input[type="hidden"][name="charge_lines[1][placement]"][value="before_tax_line"]');

        $handlingLabel = $I->grabTextFrom('//input[@name="charge_lines[0][label]"]/parent::span/span[@class="order-charge-placement-label"]');
        $adminChargeLabel = $I->grabTextFrom('//input[@name="charge_lines[1][label]"]/parent::span/span[@class="order-charge-placement-label"]');

        $I->assertSame('Main Line', $handlingLabel, 'a main_line fee must say so on its own row');
        $I->assertSame('Before Tax', $adminChargeLabel, 'a before_tax_line fee must say so, and not the other row\'s text');
        $I->assertNotSame($handlingLabel, $adminChargeLabel, 'the two placements must read differently — that is the whole point');
    }

    /**
     * The control itself: the type select has to post, and the no-JS button has to be a real submit
     * rather than the dead type="button" beside it.
     */
    public function theAddLineBarOffersAPostingSelectAndARealSubmitButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-add-line-bar select.js-order-charge-type[name="charge_line_type"]');
        $I->seeElement('.order-add-line-bar button[type="submit"][name="add_charge_line"].no-js-inline');
        // The JS control is untouched and still exactly what it was.
        $I->seeElement('.order-add-line-bar button[type="button"].js-order-bottom-add');
    }

    /**
     * The write half, for each of the three kinds of row the select can produce: one POST appends
     * the row, saves it and recalculates, and the edit page it lands back on renders that row as an
     * editable input.
     */
    public function theNoJsAddLineButtonAddsAShippingRowSavesItAndRecalculates(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'shipping:Custom Shipping',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $totals = $this->storedChargeTotals($I, $order->getId());
        $I->assertEqualsWithDelta(0.0, $totals['shipping'], 0.001, 'the shipping row did not persist at $0');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertStringContainsString('Custom Shipping', (string) $saved->getFeeLines());

        // Landed back on the edit page, with the new row rendered as an editable charge row.
        $I->seeElement('input[type="hidden"][name="charge_lines[0][type]"][value="shipping"]');
        $I->seeElement('input[name="charge_lines[0][amount]"]');

        // Now the amount the admin types into it is saved and folded into the totals.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft_recalc',
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '18.00', 'type' => 'shipping']],
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->assertEqualsWithDelta(18.0, $this->storedChargeTotals($I, $order->getId())['shipping'], 0.001);
        $entityManager->clear();
        $I->assertEqualsWithDelta(
            118.0,
            (float) $entityManager->find(SalesOrder::class, $order->getId())->getTotal(),
            0.011,
            'the total did not recalculate off the new shipping row'
        );
    }

    public function theNoJsAddLineButtonAddsATaxRowSavesItAndRecalculates(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'tax:Custom GST',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertStringContainsString('Custom GST', (string) $saved->getTaxLines());

        $I->seeElement('input[type="hidden"][name="charge_lines[0][type]"][value="tax"]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft_recalc',
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom GST', 'amount' => '5.00', 'type' => 'tax', 'slug' => '']],
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->assertEqualsWithDelta(5.0, $this->storedChargeTotals($I, $order->getId())['tax'], 0.001);
        $entityManager->clear();
        $reloaded = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertEqualsWithDelta(5.0, (float) $reloaded->getTax(), 0.011);
        $I->assertEqualsWithDelta(105.0, (float) $reloaded->getTotal(), 0.011);
    }

    /**
     * The fee case is the one the select can only reach through "Empty Fee Line", which carries no
     * label of its own. The row still has to survive the save that creates it — normalize() drops a
     * row with neither label nor amount — so it lands under the type's default label, which the
     * editable input it comes back as is there to replace.
     */
    public function theNoJsAddLineButtonAddsAFeeRowSavesItAndRecalculates(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'empty-fee:',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $feeLines = json_decode((string) $saved->getFeeLines(), true) ?: [];
        $manual = array_values(array_filter($feeLines, static fn (array $l) => ($l['source'] ?? '') === 'manual'));
        $I->assertCount(1, $manual, 'the fee row did not persist');
        $I->assertSame('fee', $manual[0]['type']);

        // It comes back as a free-text label the admin can rename, with its fee-only controls.
        $I->seeElement('input[type="hidden"][name="charge_lines[0][type]"][value="fee"]');
        $I->seeElement('input[type="text"][name="charge_lines[0][label]"]');
        $I->seeElement('select[name="charge_lines[0][taxClass]"]');
        $I->seeElement('input[type="hidden"][name="charge_lines[0][placement]"]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft_recalc',
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [[
                'label' => 'Crating', 'amount' => '12.00', 'type' => 'fee',
                'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line',
            ]],
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->assertEqualsWithDelta(12.0, $this->storedChargeTotals($I, $order->getId())['fee'], 0.001);
        $entityManager->clear();
        $I->assertEqualsWithDelta(
            112.0,
            (float) $entityManager->find(SalesOrder::class, $order->getId())->getTotal(),
            0.011
        );
        $I->seeElement('input[type="text"][name="charge_lines[0][label]"][value="Crating"]');
    }

    /**
     * Pressing Add Line twice for a named shipping method must not charge shipping twice — the
     * server-side twin of what .js-order-bottom-add does when it removes the previous named row.
     */
    public function addingASecondNamedShippingRowReplacesTheFirstRatherThanStacking(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $add = fn (): array => [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'shipping:Custom Shipping',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ];

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $add());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $add());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $feeLines = json_decode((string) $entityManager->find(SalesOrder::class, $order->getId())->getFeeLines(), true) ?: [];
        $shipping = array_values(array_filter($feeLines, static fn (array $l) => ($l['type'] ?? '') === 'shipping'));
        $I->assertCount(1, $shipping, 'a second Add Line stacked another shipping row');
    }

    /**
     * The button saves and comes back to the edit page — the admin is still working, and the row it
     * just added is only usable on a page carrying its inputs.
     *
     * What it must NOT do is touch the status. Adding a shipping row says nothing about whether the
     * document is a draft. An earlier cut of this feature answered the submit as `draft_recalc`,
     * which took edit()'s str_starts_with(..., 'draft') branch and dragged a live order back to
     * Draft; on a part-invoiced one it would have been worse. Approved in, Approved out.
     *
     * The order is put into that live status by approve(), the named action that is now the only
     * way in (#539 stage 2) — an order can no longer be assigned a status at all.
     */
    public function theNoJsAddLineButtonLeavesAnExistingOrdersStatusAloneAndStaysOnTheEditPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(SalesOrder::class, $order->getId())
            ->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'tax:Custom PST',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $entityManager->clear();
        $I->assertSame('Approved', $entityManager->find(SalesOrder::class, $order->getId())->getStatus());
    }

    /** And a Draft stays a Draft — the submit is not a promotion either. */
    public function theNoJsAddLineButtonDoesNotPromoteADraft(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        // No status to assign: makeOrder() leaves the order where every new one starts, which is
        // Draft, and #539 stage 2 removed the setter that used to restate it here.
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'shipping:Custom Shipping',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $entityManager->clear();
        $I->assertSame('Draft', $entityManager->find(SalesOrder::class, $order->getId())->getStatus());
    }

    /**
     * On create there is no status to leave alone, and a document nobody has finished writing is a
     * Draft. Only an explicit "Save Order" mints a live one.
     */
    public function theNoJsAddLineButtonMintsANewOrderAsADraft(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_line_type' => 'shipping:Custom Shipping',
            'add_charge_line' => '1',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company], ['id' => 'DESC']);
        $I->assertNotNull($created, 'the Add Line submit did not create an order');
        $I->assertSame('Draft', $created->getStatus());
    }

    /**
     * The rows are inside <noscript> because a CSS class cannot stop an input from posting: with JS
     * on, app.js builds this same list from data-order-charges, and a second server-rendered copy in
     * the DOM would post alongside it and double every charge. Both readings come off the same JSON,
     * so they can never show different charges either.
     */
    public function theServerRenderedRowsSitInsideNoscriptSoTheJsBuiltOnesCannotCollide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithEveryChargeKind($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $source = $I->grabPageSource();

        // Every charge_lines[...] input the page renders is inside a <noscript>.
        $outsideNoscript = preg_replace('#<noscript\b.*?</noscript>#s', '', $source);
        $I->assertStringNotContainsString('charge_lines[0]', (string) $outsideNoscript);
        $I->assertStringNotContainsString('charge_lines[1]', (string) $outsideNoscript);
        $I->assertStringNotContainsString('charge_lines[2]', (string) $outsideNoscript);

        // The presence marker is deliberately NOT inside one — the JS path posts it too.
        $I->assertStringContainsString('name="charge_lines_present"', (string) $outsideNoscript);

        // app.js's own source of rows is untouched and still carries all three.
        $charges = json_decode((string) $I->grabAttributeFrom('form#order-form', 'data-order-charges'), true);
        $I->assertCount(3, $charges);
    }
}
