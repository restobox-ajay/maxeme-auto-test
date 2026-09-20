<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\InvoiceLineStockOverride;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesOrderLineStockOverride;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Selling and billing beyond the shelf: warned about, allowed, and recorded (#326) — conducted (#624).
 *
 * ## What changed, and why it is not a guard being weakened
 *
 * #326 put a ceiling on the admin order form because nothing consulted `ProductInventory` at all: an
 * admin could save 500 units against 2 in stock, reconciliation wrote the 500 into a hold bucket
 * afterwards — it records what a document says, it does not judge it — and availability went
 * negative, which made the SKU unbuyable for everybody else. The ceiling REFUSED such a save
 * outright. The standalone invoice gained the same refusal when that screen was revived.
 *
 * The refusal was the wrong half of the answer for an ADMIN. **Overselling is the operator's
 * privilege.** They know about the delivery landing Friday, the drop-ship, the substitution agreed
 * on the phone, the customer content to wait — none of which this database has any way of knowing.
 * What was actually missing was not a ban but a RECORD. So the save now states the shortfall plainly
 * and goes through the moment somebody says why, writing who decided, why, and the figures they were
 * looking at, against the line it happened on.
 *
 * The one thing still withheld is a save with NO reason, and not because the decision is doubted:
 * the record is the entire point of the mechanism, and a row with an empty `reason` explains nothing
 * while looking like a record. `anOrderBeyondStockWithNoReasonIsRefusedAndWritesNothing()` posts a
 * MISSING box and a box holding three spaces, because a blank nullified in one layer and passed
 * through by another is exactly how this kind of hole is left open.
 *
 * ## The customer path is NOT this, and case 5 pins it
 *
 * `aCustomerAcceptingAnOverQuantityQuoteStillGetsAHeldDraft()` is the boundary. A customer accepting
 * a quote for more than exists still produces a HELD DRAFT — `EstimateConversionService` never
 * refuses the acceptance and never prompts anybody for a reason — because an admin deliberately
 * overriding with their name on it is a different act from a customer doing it silently. The two
 * paths ask different methods of `AdminOrderStockValidator`: the admin screens ask
 * `shortfallsFor()`/`shortfallsForInvoice()` about a form POST, the customer path asks
 * `shortfallsForOrder()` about an order's own lines, and only the first two changed.
 *
 * ## OverInvoicingGuard is a different guard and still refuses
 *
 * `theSameDeliveryStillCannotBeBilledTwice()` is the regression control. That guard refuses billing
 * one order line twice — a claim on goods, not a claim on stock — and nothing here goes near it. It
 * has no reason box and no override, and a build that softened it would fail that case.
 *
 * ## What availability does afterwards, asserted rather than assumed
 *
 * An override does not protect anything: the order holds what it says it holds, the reconciler
 * writes it into `sales_hold_quantity` exactly as for any other approved order, and availability
 * goes negative by the shortfall. That is the accepted consequence of the decision and the tests
 * below assert it as a FACT, by column — `quantity`, `sales_hold_quantity`, `backordered_quantity` —
 * rather than leaving it to be discovered later.
 *
 * ## Assertions (#627)
 *
 * Nothing here is asserted with `see()` on a number: `see('12')` matches '120', and this subject is
 * nothing but numbers. Every figure is an `assertSame` against a named column; every sentence is a
 * `grabTextFrom()` on a specific element id or on `.form-error-banner`, and every absence is paired
 * with a positive control on that SAME element.
 *
 * The figures are chosen so that no one of them is a substring of another and none is derivable from
 * another by accident: 12 on the shelf, 50 asked for, 38 short, 7 for the within-stock control.
 *
 * ## Per-run state
 *
 * Codeception reuses ONE Cest instance across methods, so every property is reassigned in `_before()`
 * and every subject gets a unique SKU and order number.
 *
 * @group bundle-agnostic
 */
final class AdminStockOverrideCest
{
    /** The region every document below actually uses. */
    private const REGION = 'Override Region';

    /**
     * Two hostile decoys, not decorative ones — the arrangement AdminOrderStockCheckCest uses.
     *
     * RICH is stocked far higher than REGION, so code reading the wrong region would wrongly let an
     * over-quantity save through with no warning at all. BARE is stocked at zero, so code reading
     * THAT would wrongly warn about a save that fits. One catches a false pass, the other a false
     * failure, and both are on the same customer and alphabetically either side of REGION.
     */
    private const REGION_RICH = 'AAA Rich Override Decoy';
    private const REGION_BARE = 'ZZZ Bare Override Decoy';

    /** On the shelf for every case that is about scarcity. */
    private const ON_HAND = 12;

    /** What the over-quantity documents ask for, and what nothing can cover of it. */
    private const ASKED_FOR = 50;
    private const SHORT_BY = 38;

    /** The within-stock control: comfortably under ON_HAND, and not a substring of anything above. */
    private const WITHIN_STOCK = 7;

    /**
     * The same demand with half a unit on the end, and what nothing covers of it (#601).
     *
     * Half a unit rather than a quarter because half is the figure `(int) round()` gets WRONG in the
     * loudest direction: 50.5 rounds UP to 51, so a build that still rounds on the way in records
     * more than was asked for, not less, and cannot be mistaken for a truncation of the line
     * quantity somewhere else. 50.5 − 12 on the shelf is 38.5, and neither figure is a substring of
     * the other or of ON_HAND.
     */
    private const FRACTIONAL_ASKED = '50.5';
    private const FRACTIONAL_SHORT = '38.5';

    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('stock-override@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // Reassigned every test rather than initialised once: one Cest instance serves them all, and
        // driving a screen boots a request that may leave the entity manager cleared, after which
        // the previous method's objects are detached.
        $this->company = (new Company())
            ->setName('Override Wholesale')
            ->setCode('OVR-' . uniqid());
        $I->haveInRepository($this->company);

        foreach ([self::REGION_RICH, self::REGION, self::REGION_BARE] as $name) {
            $this->assignRegion($I, $name);
        }

        $this->product = (new ProductCore())
            ->setSku('OVR-SKU-' . uniqid())
            ->setName('Override Widget')
            ->setUnit('EA')
            // Exempt, so no case below needs a tax province and no figure here is a tax figure.
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $I->haveStockFor($this->product, self::ON_HAND, self::REGION);
        $I->haveStockFor($this->product, self::ON_HAND + 1000, self::REGION_RICH);
        $I->haveStockFor($this->product, 0, self::REGION_BARE);
    }

    // ══ 1. ORDER: the notice states the shortfall, and a reason saves it ═════════════════════

    /**
     * The headline case. 50 units against 12 on the shelf: the operator is told by how much, and the
     * save goes through the moment they say why — recorded, and visible on the order afterwards.
     *
     * Both halves post the SAME form to the SAME screen for the same customer, product, region and
     * quantity. The only difference between the refused post and the accepted one is the reason box,
     * which is what makes this a test of the reason rather than of two arrangements.
     */
    public function anOrderLineBeyondStockStatesTheShortfallAndSavesWithAReason(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        // ── With no reason: told the figures, and nothing is written.
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', null);

        $I->seeElement('.form-error-banner');
        $notice = $I->grabTextFrom('.form-error-banner');
        $I->assertStringContainsString(self::ASKED_FOR . ' requested in ' . self::REGION, $notice, 'the notice names what was asked for, in the region it was asked in');
        $I->assertStringContainsString('only ' . self::ON_HAND . ' available', $notice, 'and the ceiling, before the operator has to decide anything');
        $I->assertStringContainsString('— ' . self::SHORT_BY . ' short', $notice, 'and by how much: a concrete figure, not "insufficient stock"');
        $I->assertStringContainsString('say why in the reason box on the line', $notice, 'and that going ahead is available, not that they did something wrong');
        $I->assertStringNotContainsString('insufficient', strtolower($notice), 'the notice informs; it does not accuse');

        $I->assertSame($before, $this->highestOrderId($I), 'no order row was written while nobody had said why');
        $I->assertSame(0, $this->overrideCount($I), 'and no override record either');
        $this->assertInventory($I, self::ON_HAND, 0, 0, 'nothing was held against the shelf');

        // ── The same post, with a reason.
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', 'Restock lands Friday');

        $I->dontSeeElement('.form-error-banner');
        $orderRow = $this->newestOrderRow($I, $before);
        $I->assertSame('Approved', (string) $orderRow['status'], 'the order really is live, not quietly demoted to a draft');

        $lineRows = $this->orderLineRows($I, (int) $orderRow['id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame(
            self::ASKED_FOR . '.0000',
            $this->quantity($lineRows[0]['quantity']),
            'the line was saved for what was asked for, not silently capped at the shelf',
        );

        // ── The record, by column.
        $override = $this->overrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame(self::REGION, (string) $override['region_name']);
        $I->assertSame(self::ASKED_FOR, (int) $override['requested_quantity']);
        $I->assertSame(self::ON_HAND, (int) $override['available_quantity'], 'the figure the operator was actually shown, snapshotted');
        $I->assertSame(0, (int) $override['backorder_capacity'], 'nothing but stock stood behind this SKU — nobody opted it in');
        $I->assertSame('Restock lands Friday', (string) $override['reason']);
        $I->assertStringContainsString(
            'stock-override@example.test',
            (string) $override['overridden_by'],
            'the person who decided is on the row, not just the fact that somebody did',
        );

        // ── And it is visible on the document, not only in a table nobody opens.
        $I->amOnPage('/admin/order/detail/' . (int) $orderRow['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#stock-override-table');
        $overrideId = (int) $override['id'];
        $I->assertSame((string) self::ASKED_FOR, trim($I->grabTextFrom('#stock-override-requested-' . $overrideId)));
        $I->assertSame((string) self::ON_HAND, trim($I->grabTextFrom('#stock-override-available-' . $overrideId)));
        $I->assertSame((string) self::SHORT_BY, trim($I->grabTextFrom('#stock-override-shortfall-' . $overrideId)));
        $I->assertSame('Restock lands Friday', trim($I->grabTextFrom('#stock-override-reason-' . $overrideId)));

        // ── What it costs, stated as fact rather than left to be found out later. The order holds
        //    all 50; the shelf has 12; availability is 12 − 50 = −38. Nothing protects against that,
        //    deliberately: the figure is true, and a true negative is what tells everybody else the
        //    shelf is spoken for.
        $this->assertInventory($I, self::ON_HAND, self::ASKED_FOR, 0, 'the whole line is held, exactly as for any other approved order');
        $I->assertSame($this->quantity(-self::SHORT_BY), $this->availability($I), 'availability really does go negative, and is not clamped anywhere');
    }

    // ══ 2. ORDER: no reason, no save ═════════════════════════════════════════════════════════

    /**
     * A blank reason is not a reason — in the box that was never filled in, and in the box holding
     * three spaces.
     *
     * The whitespace half is the one worth having. A blank caught by the parser but passed through by
     * the writer (or the reverse) saves a row with `reason = ''`: an override record that explains
     * nothing while looking like one, which is the same hole as no record at all.
     */
    public function anOrderBeyondStockWithNoReasonIsRefusedAndWritesNothing(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        // No reason box at all.
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', null);
        $I->seeElement('.form-error-banner');
        $I->assertSame($before, $this->highestOrderId($I));
        $I->assertSame(0, $this->overrideCount($I));

        // A box with nothing but whitespace in it.
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', '   ');
        $I->seeElement('.form-error-banner');
        $I->assertStringContainsString(
            'say why in the reason box on the line',
            $I->grabTextFrom('.form-error-banner'),
            'and it asks again rather than accepting the spaces',
        );
        $I->assertSame($before, $this->highestOrderId($I), 'three spaces is not a reason and wrote no order');
        $I->assertSame(0, $this->overrideCount($I), 'and no override row with an empty reason on it');
        $this->assertInventory($I, self::ON_HAND, 0, 0, 'and nothing was reserved');

        // POSITIVE CONTROL on the same element, same screen, same quantity: only the box changes.
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', 'Drop-shipping this one');
        $I->dontSeeElement('.form-error-banner');
        $I->assertGreaterThan($before, $this->highestOrderId($I), 'a real reason saves the same post');
        $I->assertSame(1, $this->overrideCount($I));
    }

    // ══ 3. ORDER: the positive control — within stock, no warning, no record ═════════════════

    /**
     * An order the shelf covers saves silently and writes no override row.
     *
     * Without this, case 1 passes just as well on a build that warned about every line and demanded
     * a reason for buying one unit of something with a thousand on the shelf.
     */
    public function anOrderWithinStockSavesWithNoWarningAndNoOverrideRow(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        $this->postOrder($I, (string) self::WITHIN_STOCK, 'order', null);

        $I->dontSeeElement('.form-error-banner');
        $orderRow = $this->newestOrderRow($I, $before);
        $I->assertSame('Approved', (string) $orderRow['status']);

        $lineRows = $this->orderLineRows($I, (int) $orderRow['id']);
        $I->assertSame(self::WITHIN_STOCK . '.0000', $this->quantity($lineRows[0]['quantity']));
        $I->assertSame(0, $this->overrideCount($I), 'a line the shelf covers is not a decision anybody took');
        $this->assertInventory($I, self::ON_HAND, self::WITHIN_STOCK, 0);
        $I->assertSame($this->quantity(self::ON_HAND - self::WITHIN_STOCK), $this->availability($I), 'and availability stays positive');

        // The panel is absent from the document too, paired with case 1's positive assertion on the
        // same element id.
        $I->amOnPage('/admin/order/detail/' . (int) $orderRow['id']);
        $I->dontSeeElement('#stock-override-table');
    }

    // ══ 4. INVOICE: the same three, on the standalone invoice ════════════════════════════════

    /**
     * A standalone invoice for 50 against 12 states the shortfall, and bills it once somebody says
     * why.
     *
     * An ISSUED invoice HOLDS what it bills — InvoiceReservationSubject writes it into
     * `pending_quantity` — so this is #326 verbatim on the other document, and the override is the
     * same override.
     */
    public function aStandaloneInvoiceBeyondStockStatesTheShortfallAndSavesWithAReason(FunctionalTester $I): void
    {
        $before = $this->highestInvoiceId($I);

        $this->postInvoice($I, (string) self::ASKED_FOR, 'issue', null);

        $I->seeElement('.form-error-banner');
        $notice = $I->grabTextFrom('.form-error-banner');
        $I->assertStringContainsString(self::ASKED_FOR . ' requested in ' . self::REGION, $notice);
        $I->assertStringContainsString('only ' . self::ON_HAND . ' available', $notice);
        $I->assertStringContainsString('— ' . self::SHORT_BY . ' short', $notice);
        $I->assertStringContainsString('recorded on the invoice', $notice, 'and it names the document the record lands on');
        $I->assertSame($before, $this->highestInvoiceId($I), 'the refusal wrote no invoice row and drew no invoice number');
        $I->assertSame(0, $this->invoiceOverrideCount($I));

        $this->postInvoice($I, (string) self::ASKED_FOR, 'issue', 'Customer collecting from the vendor');

        $I->dontSeeElement('.form-error-banner');
        $invoiceRow = $this->newestInvoiceRow($I, $before);
        $I->assertNull($invoiceRow['sales_order_id'], 'still a standalone invoice');

        $lineRows = $this->invoiceLineRows($I, (int) $invoiceRow['id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame(self::ASKED_FOR . '.0000', $this->quantity($lineRows[0]['quantity']));

        $override = $this->invoiceOverrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame(self::REGION, (string) $override['region_name']);
        $I->assertSame(self::ASKED_FOR, (int) $override['requested_quantity']);
        $I->assertSame(self::ON_HAND, (int) $override['available_quantity']);
        $I->assertSame(0, (int) $override['backorder_capacity'], 'capacity is never cover on an invoice');
        $I->assertSame('Customer collecting from the vendor', (string) $override['reason']);
        $I->assertStringContainsString('stock-override@example.test', (string) $override['overridden_by']);

        $I->amOnPage('/admin/invoice/detail/' . (int) $invoiceRow['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#stock-override-table');
        $overrideId = (int) $override['id'];
        $I->assertSame((string) self::ASKED_FOR, trim($I->grabTextFrom('#stock-override-requested-' . $overrideId)));
        $I->assertSame((string) self::SHORT_BY, trim($I->grabTextFrom('#stock-override-shortfall-' . $overrideId)));
        $I->assertSame('Customer collecting from the vendor', trim($I->grabTextFrom('#stock-override-reason-' . $overrideId)));

        // An issued invoice holds what it bills, in its own bucket and not the order's.
        $this->assertInventory($I, self::ON_HAND, 0, 0, 'an invoice holds nothing in the ORDER bucket');
        $I->assertSame(self::ASKED_FOR, $this->pendingHeld($I), 'it holds the whole billed quantity in pending');
        $I->assertSame($this->quantity(-self::SHORT_BY), $this->availability($I), 'and availability goes negative by the shortfall');
    }

    /** No reason, no invoice — including the whitespace box. See the order case for why. */
    public function aStandaloneInvoiceBeyondStockWithNoReasonIsRefusedAndWritesNothing(FunctionalTester $I): void
    {
        $before = $this->highestInvoiceId($I);

        $this->postInvoice($I, (string) self::ASKED_FOR, 'issue', null);
        $I->seeElement('.form-error-banner');
        $I->assertSame($before, $this->highestInvoiceId($I));

        $this->postInvoice($I, (string) self::ASKED_FOR, 'issue', "  \t ");
        $I->seeElement('.form-error-banner');
        $I->assertSame($before, $this->highestInvoiceId($I), 'whitespace is not a reason on this screen either');
        $I->assertSame(0, $this->invoiceOverrideCount($I));

        // POSITIVE CONTROL: same element, same screen, same quantity, only the box changes.
        $this->postInvoice($I, (string) self::ASKED_FOR, 'issue', 'Vendor drop-ship agreed');
        $I->dontSeeElement('.form-error-banner');
        $I->assertGreaterThan($before, $this->highestInvoiceId($I));
        $I->assertSame(1, $this->invoiceOverrideCount($I));
    }

    /** The invoice positive control: within stock, no warning, no record. */
    public function aStandaloneInvoiceWithinStockSavesWithNoWarningAndNoOverrideRow(FunctionalTester $I): void
    {
        $before = $this->highestInvoiceId($I);

        $this->postInvoice($I, (string) self::WITHIN_STOCK, 'issue', null);

        $I->dontSeeElement('.form-error-banner');
        $invoiceRow = $this->newestInvoiceRow($I, $before);
        $lineRows = $this->invoiceLineRows($I, (int) $invoiceRow['id']);
        $I->assertSame(self::WITHIN_STOCK . '.0000', $this->quantity($lineRows[0]['quantity']));
        $I->assertSame(0, $this->invoiceOverrideCount($I));
        $I->assertSame($this->quantity(self::ON_HAND - self::WITHIN_STOCK), $this->availability($I));

        $I->amOnPage('/admin/invoice/detail/' . (int) $invoiceRow['id']);
        $I->dontSeeElement('#stock-override-table');
    }

    // ══ 5. THE BOUNDARY: the customer path is untouched ══════════════════════════════════════

    /**
     * A customer accepting a quote for more than exists still gets a HELD DRAFT — no reason box, no
     * override, no refusal.
     *
     * This is the line the whole change is drawn against. #326 exists because a customer accepting
     * 500 units against 5 drove availability to −495 silently, and `EstimateConversionService` holds
     * such an order as a Draft rather than refusing the acceptance, deliberately: the customer has
     * agreed to a price we quoted them and telling them they cannot accept it — with no way to
     * resolve it themselves — is a worse failure than a slow order.
     *
     * An ADMIN putting their name on an oversell is a different act. So the admin screens gained an
     * override and this path gained nothing, and the assertions below are the ones that would fail if
     * the two ever shared the code that decides.
     */
    public function aCustomerAcceptingAnOverQuantityQuoteStillGetsAHeldDraft(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuoteFor($I, (string) self::ASKED_FOR);
        $customer = $this->customerUser($I);
        $I->amLoggedInAs($customer, 'main');
        $I->haveHttpHeader('Host', 'localhost');

        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $I->csrfToken()]);

        // The acceptance succeeded: there IS an order, and the quote is Accepted.
        $orderRow = $this->connection($I)->fetchAssociative(
            'SELECT id, status FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$this->company->getId()],
        );
        $I->assertIsArray($orderRow, 'the customer was not refused — the acceptance produced an order');
        $I->assertSame('Draft', (string) $orderRow['status'], 'and it is HELD as a Draft, which is the #326 answer on this path');

        // Nothing about an override anywhere: not on the order, and not asked of the customer.
        $I->assertSame(0, $this->overrideCount($I), 'no override record — nobody was prompted and nobody decided');
        $this->assertInventory($I, self::ON_HAND, 0, 0, 'a Draft reserves nothing, so the shelf is untouched');
        $I->assertSame($this->quantity(self::ON_HAND), $this->availability($I), 'availability is exactly what it was — this is what the hold-as-Draft protects');

        // And the customer's own screen says nothing about reasons or overrides.
        $I->amOnPage('/orders/' . (int) $orderRow['id']);
        $I->dontSeeElement('#stock-override-table');
    }

    // ══ 6. REGRESSION CONTROL: a different guard, still refusing ═════════════════════════════

    /**
     * `OverInvoicingGuard` still refuses billing the same delivery twice.
     *
     * It is NOT the guard being softened here and it must not be. It refuses a CLAIM on an order
     * line — goods somebody says were sent — rather than a claim on stock, it has no reason box and
     * no override, and the remedies it names are unlink or raise the order line. A build that
     * confused the two would let the second invoice issue, and this is the case that would say so.
     */
    public function theSameDeliveryStillCannotBeBilledTwice(FunctionalTester $I): void
    {
        $context = $this->approvedOrderWithLine($I, '10');

        // BOTH drafts first, then the issues. That order is the guard's whole reason for existing:
        // `SalesOrder::uninvoicedQuantityFor()` counts only invoices that count toward invoiced
        // quantity, and a draft counts for nothing — so two drafts each for the whole line both pass
        // the check made when an invoice is RAISED, and nothing looked at the pair again until this
        // guard did, at ISSUE.
        $firstId = $this->raiseDraft($I, $context, '10');
        $secondId = $this->raiseDraft($I, $context, '10');
        $I->assertNotSame($firstId, $secondId, 'two drafts for one order line both exist, which is allowed');

        $this->issue($I, $firstId);
        $I->assertSame('Pending', (string) $this->invoiceRow($I, $firstId)['status'], 'the first invoice issued');

        $I->amOnPage('/admin/invoice/detail/' . $secondId);
        $action = '/admin/invoice/' . $secondId . '/action/issue';
        $I->sendFormPostRequest($action, [
            '_token' => (string) $I->grabAttributeFrom('form[action="' . $action . '"] input[name="_token"]', 'value'),
        ]);

        $I->see('cannot be issued');
        $I->assertSame('Draft', (string) $this->invoiceRow($I, $secondId)['status'], 'the second invoice is still a draft');
        $I->assertSame(
            0,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice_line_stock_override'),
            'and it grew no reason box on the way — this guard has no override and gained none',
        );
    }

    // ══ 7. THE FIGURES ARE RECORDED AS TYPED, FRACTIONS INCLUDED (#601) ══════════════════════

    /**
     * The headline case for #601 on this feature: an override of 50.5 units is RECORDED as 50.5.
     *
     * ## What was wrong
     *
     * `sales_order_line_stock_override.requested_quantity` was `INTEGER`, and so was the
     * `(int) round()` in `AdminOrderStockValidator::requestedByProductAndWarehouse()` three layers
     * above it. Neither refused a fractional quantity: 50.5 became 51 on the way in, the notice
     * quoted 51, and the row recorded 51 as the figure somebody had decided on — with no error
     * anywhere, and with the order line itself still correctly holding 50.5000. The record of the
     * decision disagreed with the document it was about, in the direction of MORE than was asked
     * for.
     *
     * Fractional quantities are supported and that is settled (#601): every other mapped quantity in
     * this application is `NUMERIC(14, 4)`, and these four columns now are too.
     *
     * ## Asserted by column, then by re-read, then on the screen
     *
     * The figure is read straight out of `sales_order_line_stock_override` — a number computed and
     * never written looks identical from an entity — then again through the mapping with the
     * identity map cleared, because a column that holds 50.5 and a getter that hands back 51 would
     * be the same defect one layer up. Note what a re-read gives: SQLite's NUMERIC affinity stores
     * the narrowest lossless form, so the value comes back `50.5` and not the text `50.5000`. Both
     * sides are therefore compared at the column's four places rather than as whatever string the
     * file happened to use.
     *
     * ## The row that must NOT move
     *
     * The fractional override is written FIRST and its whole row snapshotted; a second, unrelated
     * over-quantity save on the same product then writes its own record. The snapshot is re-read
     * afterwards and must be identical, column for column. These rows are a SNAPSHOT of what one
     * person was looking at when they decided, and a later save re-writing them would make every
     * earlier record a description of today.
     */
    public function anOrderBeyondStockRecordsAFractionalQuantityAsTypedAndLeavesEarlierRecordsAlone(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        // ── The notice states the fraction, before anybody has decided anything.
        $this->postOrder($I, self::FRACTIONAL_ASKED, 'order', null);

        $I->seeElement('.form-error-banner');
        $notice = $I->grabTextFrom('.form-error-banner');
        $I->assertStringContainsString(
            self::FRACTIONAL_ASKED . ' requested in ' . self::REGION,
            $notice,
            'the operator is told what they actually asked for, not what it rounds to',
        );
        $I->assertStringContainsString('only ' . self::ON_HAND . ' available', $notice);
        $I->assertStringContainsString(
            '— ' . self::FRACTIONAL_SHORT . ' short',
            $notice,
            'and the three figures in the sentence subtract: 50.5 − 12 is 38.5, never 39',
        );

        // ── The same post with a reason: saved, and recorded.
        $this->postOrder($I, self::FRACTIONAL_ASKED, 'order', 'Half case agreed on the phone');
        $I->dontSeeElement('.form-error-banner');

        $orderRow = $this->newestOrderRow($I, $before);
        $lineRows = $this->orderLineRows($I, (int) $orderRow['id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame(
            '50.5000',
            $this->quantity($lineRows[0]['quantity']),
            'the line itself holds the half unit, which it always did — it is the RECORD that used to lose it',
        );

        $override = $this->overrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame(
            '50.5000',
            $this->quantity($override['requested_quantity']),
            'the record says what was asked for: 50.5, not the 51 an INTEGER column and an (int) cast made of it',
        );
        $I->assertSame(
            self::ON_HAND . '.0000',
            $this->quantity($override['available_quantity']),
            'availability is a whole figure today and lands in the widened column losing nothing',
        );
        $I->assertSame(0, (int) $override['backorder_capacity'], 'nobody opted this SKU in, so nothing but stock stood behind it');
        $I->assertSame('Half case agreed on the phone', (string) $override['reason']);
        $I->assertSame(1, $this->overrideCount($I), 'one line, one decision, one row');

        // ── Re-read through the mapping, identity map cleared: the getter agrees with the column.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $reread = $entityManager->find(SalesOrderLineStockOverride::class, (int) $override['id']);
        $I->assertInstanceOf(SalesOrderLineStockOverride::class, $reread);
        $I->assertSame('50.5000', $this->quantity($reread->getRequestedQuantity()), 'and it is still fractional after a round trip through Doctrine');
        $I->assertSame(
            $this->quantity(self::FRACTIONAL_SHORT),
            $this->quantity($reread->getShortfallQuantity()),
            'the derived shortfall is fractional too: 50.5 − 12 − 0',
        );

        // ── On the document, where somebody reads it. Through |qty, so 50.5 reads "50.5".
        $I->amOnPage('/admin/order/detail/' . (int) $orderRow['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#stock-override-table');
        $overrideId = (int) $override['id'];
        $I->assertSame(self::FRACTIONAL_ASKED, trim($I->grabTextFrom('#stock-override-requested-' . $overrideId)));
        $I->assertSame((string) self::ON_HAND, trim($I->grabTextFrom('#stock-override-available-' . $overrideId)), 'a whole figure still prints whole — no "12.0000"');
        $I->assertSame(self::FRACTIONAL_SHORT, trim($I->grabTextFrom('#stock-override-shortfall-' . $overrideId)));

        // ── THE ROW THAT MUST NOT MOVE. A second over-quantity save, on the same product, writes
        //    its own record; this one is a snapshot of a decision already taken.
        $snapshot = $this->overrideRow($I, (int) $lineRows[0]['id']);
        $this->postOrder($I, (string) self::ASKED_FOR, 'order', 'A different call, later');
        $I->dontSeeElement('.form-error-banner');
        $I->assertSame(2, $this->overrideCount($I), 'the later save wrote its own row — the control that this is not a no-op');
        $I->assertSame(
            $snapshot,
            $this->overrideRow($I, (int) $lineRows[0]['id']),
            'and the earlier record is untouched, column for column, including its 50.5',
        );
    }

    /** The same, on the standalone invoice — the other two of the four columns. */
    public function aStandaloneInvoiceBeyondStockRecordsAFractionalQuantityAsTyped(FunctionalTester $I): void
    {
        $before = $this->highestInvoiceId($I);

        $this->postInvoice($I, self::FRACTIONAL_ASKED, 'issue', null);
        $I->seeElement('.form-error-banner');
        $notice = $I->grabTextFrom('.form-error-banner');
        $I->assertStringContainsString(self::FRACTIONAL_ASKED . ' requested in ' . self::REGION, $notice);
        $I->assertStringContainsString('— ' . self::FRACTIONAL_SHORT . ' short', $notice);

        $this->postInvoice($I, self::FRACTIONAL_ASKED, 'issue', 'Half case collected from the vendor');
        $I->dontSeeElement('.form-error-banner');

        $invoiceRow = $this->newestInvoiceRow($I, $before);
        $lineRows = $this->invoiceLineRows($I, (int) $invoiceRow['id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame('50.5000', $this->quantity($lineRows[0]['quantity']));

        $override = $this->invoiceOverrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame('50.5000', $this->quantity($override['requested_quantity']), 'the billed figure is recorded as billed');
        $I->assertSame(self::ON_HAND . '.0000', $this->quantity($override['available_quantity']));
        $I->assertSame(0, (int) $override['backorder_capacity'], 'capacity is never cover on an invoice');
        $I->assertSame(1, $this->invoiceOverrideCount($I));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $reread = $entityManager->find(InvoiceLineStockOverride::class, (int) $override['id']);
        $I->assertInstanceOf(InvoiceLineStockOverride::class, $reread);
        $I->assertSame('50.5000', $this->quantity($reread->getRequestedQuantity()));
        $I->assertSame($this->quantity(self::FRACTIONAL_SHORT), $this->quantity($reread->getShortfallQuantity()));

        $I->amOnPage('/admin/invoice/detail/' . (int) $invoiceRow['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#stock-override-table');
        $overrideId = (int) $override['id'];
        $I->assertSame(self::FRACTIONAL_ASKED, trim($I->grabTextFrom('#stock-override-requested-' . $overrideId)));
        $I->assertSame(self::FRACTIONAL_SHORT, trim($I->grabTextFrom('#stock-override-shortfall-' . $overrideId)));
    }

    // ══ 8. THE BOX IS ON THE SCREEN, AND AN ADMIN CAN REACH IT ══════════════════════════════

    /**
     * The defect this section exists for: every case above posts `lines[N][stock_override_reason]`
     * by BUILDING the request, and a built request posts fields no screen renders just as happily
     * as fields it does. The whole mechanism — the measurement, the refusal, the record, the panel
     * on the document — was complete and correct, and `templates/admin/_stock_override_reason.html.twig`
     * was included from nothing. In a browser an over-stock admin save could therefore only ever be
     * REFUSED: the one field that unlocks it was typeable nowhere.
     *
     * So these five cases never name the field. They open the screen, read off the page everything
     * a browser would submit, type into the box the PAGE rendered, and post that. A build where the
     * box is missing fails on the read, before any of it.
     *
     * ## What "typed" means here
     *
     * The payload is the rendered form, untouched, except for the cells an operator fills in: the
     * product, the quantity, the region and the price — and the reason. Nothing is renamed and
     * nothing is reformatted, which is the point: `LineDenomination::boxUntouched()` compares a box
     * against its hidden twin byte for byte, so a hand-assembled payload would prove nothing about
     * the round trip a person makes.
     */
    public function theOrderFormRendersTheReasonBoxSoAnOverStockSaveIsFinallyPossibleInABrowser(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        // ── The box is in the QUANTITY CELL, beside the figure it explains. Asserted through one
        //    XPath anchored on the cell that holds `lines[0][qty]`, so the claim is "in the same
        //    cell as the quantity" and not "somewhere on this page", and so a selector that had
        //    stopped matching anything could not pass as the finding (#627).
        $qtyCell = '//table[contains(@class, "order-lines-table")]//td[input[@name="lines[0][qty]"]]';
        $I->seeElement($qtyCell);
        $I->seeElement($qtyCell . '/input[@name="lines[0][stock_override_reason]"]');

        $posted = $I->grabFormPayload('order-form');
        $I->assertArrayHasKey(
            'stock_override_reason',
            $posted['lines'][0],
            'the order form renders no box for the field its own save reads, so an over-stock save'
            . ' can only ever be refused in a browser — which is the whole of #326 still open',
        );
        $I->assertSame('', $posted['lines'][0]['stock_override_reason'], 'and it comes up empty, as a box nobody has typed in should');

        // ── What an operator fills in, and nothing else. Every other control is exactly as rendered.
        $posted['fulfillment_region'] = self::REGION;
        $posted['lines'][0]['product_id'] = (string) $this->product->getId();
        $posted['lines'][0]['location'] = self::REGION;
        $posted['lines'][0]['qty'] = (string) self::ASKED_FOR;
        $posted['lines'][0]['price'] = '10.00';
        $posted['lines'][0]['stock_override_reason'] = 'Container lands Friday';
        $posted['save_mode'] = 'order';

        $I->sendFormPostRequest('/admin/order/create', $posted);

        // ── It went through. Read back out of the columns, never off an entity the request left in
        //    memory (#624).
        $I->dontSeeElement('.form-error-banner');

        $orderRow = $this->newestOrderRow($I, $before);
        $lineRows = $this->orderLineRows($I, (int) $orderRow['id']);
        $I->assertCount(1, $lineRows, 'one product line, and the spare blank row posted nothing');
        $I->assertSame(
            $this->quantity(self::ASKED_FOR),
            $this->quantity($lineRows[0]['quantity']),
            'the line holds the quantity that was typed into the rendered box',
        );

        $override = $this->overrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame(
            'Container lands Friday',
            (string) $override['reason'],
            'sales_order_line_stock_override.reason holds what was typed into the box the PAGE'
            . ' rendered — the save no browser could reach before this',
        );
        $I->assertSame($this->quantity(self::ASKED_FOR), $this->quantity($override['requested_quantity']));
        $I->assertSame($this->quantity(self::ON_HAND), $this->quantity($override['available_quantity']));
        $I->assertSame(1, $this->overrideCount($I), 'one line, one decision, one row');

        // ── THE ROW THAT MUST NOT MOVE. A second over-stock save through the same screen writes its
        //    own record; this one is a snapshot of a decision already taken, and a later save
        //    rewriting it would make every earlier record a description of today.
        $snapshot = $this->overrideRow($I, (int) $lineRows[0]['id']);

        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $second = $I->grabFormPayload('order-form');
        $second['fulfillment_region'] = self::REGION;
        $second['lines'][0]['product_id'] = (string) $this->product->getId();
        $second['lines'][0]['location'] = self::REGION;
        $second['lines'][0]['qty'] = (string) self::ASKED_FOR;
        $second['lines'][0]['price'] = '10.00';
        $second['lines'][0]['stock_override_reason'] = 'A different call, later';
        $second['save_mode'] = 'order';
        $I->sendFormPostRequest('/admin/order/create', $second);

        $I->dontSeeElement('.form-error-banner');
        $I->assertSame(2, $this->overrideCount($I), 'the later save wrote its own row — the control that this is not a no-op');
        $I->assertSame(
            $snapshot,
            $this->overrideRow($I, (int) $lineRows[0]['id']),
            'and the earlier record is untouched, column for column',
        );
    }

    /**
     * The refusal, driven the same way: the RENDERED form, posted with the box left exactly as it
     * came off the page.
     *
     * The positive control is the same payload with text typed in, posted to the same URL a moment
     * later. Nothing else differs — same customer, product, region, quantity and every other
     * control — so what is being tested is the reason and not two arrangements (#627).
     */
    public function theRenderedOrderFormWithTheBoxLeftEmptyIsStillRefused(FunctionalTester $I): void
    {
        $before = $this->highestOrderId($I);

        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $posted = $I->grabFormPayload('order-form');
        $posted['fulfillment_region'] = self::REGION;
        $posted['lines'][0]['product_id'] = (string) $this->product->getId();
        $posted['lines'][0]['location'] = self::REGION;
        $posted['lines'][0]['qty'] = (string) self::ASKED_FOR;
        $posted['lines'][0]['price'] = '10.00';
        $posted['save_mode'] = 'order';

        // ── As rendered: the box is posted, and posted EMPTY. Blank is absent, in every layer.
        $I->assertArrayHasKey(
            'stock_override_reason',
            $posted['lines'][0],
            'the rendered form posts no reason box, so what follows would be a refusal nothing on'
            . ' this screen could ever have answered',
        );
        $I->assertSame('', $posted['lines'][0]['stock_override_reason']);
        $I->sendFormPostRequest('/admin/order/create', $posted);

        $I->seeElement('.form-error-banner');
        $notice = $I->grabTextFrom('.form-error-banner');
        $I->assertStringContainsString('— ' . self::SHORT_BY . ' short', $notice, 'the figures, not a bare refusal');
        $I->assertSame($before, $this->highestOrderId($I), 'nothing was written while nobody had said why');
        $I->assertSame(0, $this->overrideCount($I), 'and no record either');
        $this->assertInventory($I, self::ON_HAND, 0, 0, 'nothing was held against the shelf');

        // ── Whitespace is blank too. A person tabbing past the box is not a reason.
        $posted['lines'][0]['stock_override_reason'] = '   ';
        $I->sendFormPostRequest('/admin/order/create', $posted);
        $I->seeElement('.form-error-banner');
        $I->assertSame($before, $this->highestOrderId($I), 'three spaces are not a reason in any layer');
        $I->assertSame(0, $this->overrideCount($I));

        // ── THE POSITIVE CONTROL: the same post, with something in the box.
        $posted['lines'][0]['stock_override_reason'] = 'Drop-shipping this one';
        $I->sendFormPostRequest('/admin/order/create', $posted);

        $I->dontSeeElement('.form-error-banner');
        $orderRow = $this->newestOrderRow($I, $before);
        $lineRows = $this->orderLineRows($I, (int) $orderRow['id']);
        $I->assertSame('Drop-shipping this one', (string) $this->overrideRow($I, (int) $lineRows[0]['id'])['reason']);
        $I->assertSame(1, $this->overrideCount($I), 'the box was the only thing standing between the two posts');
    }

    /** The same, on the standalone invoice create screen — the other document that measures. */
    public function theInvoiceCreateScreenRendersTheReasonBoxSoAnOverStockBillIsFinallyPossible(FunctionalTester $I): void
    {
        $before = $this->highestInvoiceId($I);

        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $qtyCell = '//table[contains(@class, "invoice-line-table")]//td[input[@name="lines[0][qty]"]]';
        $I->seeElement($qtyCell);
        $I->seeElement($qtyCell . '/input[@name="lines[0][stock_override_reason]"]');

        $posted = $I->grabFormPayload('invoice-create-form');
        $I->assertArrayHasKey(
            'stock_override_reason',
            $posted['lines'][0],
            'the invoice create screen renders no box for the field its own save reads',
        );

        $posted['invoice_date'] = '2026-09-12';
        $posted['fulfillment_region'] = self::REGION;
        $posted['lines'][0]['product_id'] = (string) $this->product->getId();
        $posted['lines'][0]['location'] = self::REGION;
        $posted['lines'][0]['qty'] = (string) self::ASKED_FOR;
        $posted['lines'][0]['price'] = '10.00';
        $posted['save_mode'] = 'issue';

        // ── As rendered, the box is empty, and an issued invoice beyond the shelf is refused.
        $I->assertSame('', $posted['lines'][0]['stock_override_reason']);
        $I->sendFormPostRequest('/admin/invoice/create', $posted);
        $I->seeElement('.form-error-banner');
        $I->assertSame($before, $this->highestInvoiceId($I), 'no invoice was written while nobody had said why');
        $I->assertSame(0, $this->invoiceOverrideCount($I));

        // ── A refused save comes back with the typed reason still in the box on THIS screen, which
        //    is what its row reader carries the reason for. Read off the re-rendered page, not
        //    assumed.
        $posted['lines'][0]['stock_override_reason'] = 'Customer collecting from the vendor';
        $I->sendFormPostRequest('/admin/invoice/create', $posted);
        $I->dontSeeElement('.form-error-banner');

        $invoiceRow = $this->newestInvoiceRow($I, $before);
        $lineRows = $this->invoiceLineRows($I, (int) $invoiceRow['id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame($this->quantity(self::ASKED_FOR), $this->quantity($lineRows[0]['quantity']));

        $override = $this->invoiceOverrideRow($I, (int) $lineRows[0]['id']);
        $I->assertSame(
            'Customer collecting from the vendor',
            (string) $override['reason'],
            'invoice_line_stock_override.reason holds what was typed into the rendered box',
        );
        $I->assertSame($this->quantity(self::ASKED_FOR), $this->quantity($override['requested_quantity']));
        $I->assertSame(1, $this->invoiceOverrideCount($I));
    }

    /**
     * A refused invoice save hands the reason BACK in the box, read off the re-rendered page.
     *
     * Being told the figure and then handed an empty form is a worse answer than the figure. This
     * screen re-renders from the POST and its row reader carries `stockOverrideReason` for exactly
     * this; the assertion is on the box's own value attribute, not on the page text, so a reason
     * printed anywhere else would not satisfy it.
     */
    public function aRefusedInvoiceSaveHandsTheTypedReasonBackInTheBox(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $posted = $I->grabFormPayload('invoice-create-form');
        $posted['invoice_date'] = '2026-09-12';
        $posted['fulfillment_region'] = self::REGION;
        $posted['lines'][0]['product_id'] = (string) $this->product->getId();
        $posted['lines'][0]['location'] = self::REGION;
        $posted['lines'][0]['qty'] = (string) self::ASKED_FOR;
        $posted['lines'][0]['price'] = '10.00';
        $posted['save_mode'] = 'issue';
        // A SECOND line with no reason is what makes this a refusal even though line 1 has one: the
        // refusal and the re-render then happen with text in a box, which is the state under test.
        $posted['lines'][0]['stock_override_reason'] = 'Restock lands Friday';
        $posted['lines'][1]['product_id'] = (string) $this->product->getId();
        $posted['lines'][1]['location'] = self::REGION_BARE;
        $posted['lines'][1]['qty'] = (string) self::WITHIN_STOCK;
        $posted['lines'][1]['price'] = '10.00';

        $I->sendFormPostRequest('/admin/invoice/create', $posted);
        $I->seeElement('.form-error-banner');

        $box = '//table[contains(@class, "invoice-line-table")]//input[@name="lines[0][stock_override_reason]"]';
        $I->seeElement($box);
        $I->assertSame(
            'Restock lands Friday',
            (string) $I->grabAttributeFrom($box, 'value'),
            'the refused save came back with the reason still in the box it was typed into',
        );
        // The positive control on the SAME element, one row down: the row nobody typed in comes back
        // empty, so the assertion above is about the value and not about a box that always holds it.
        $I->assertSame(
            '',
            (string) $I->grabAttributeFrom(
                '//table[contains(@class, "invoice-line-table")]//input[@name="lines[1][stock_override_reason]"]',
                'value',
            ),
        );
    }

    /**
     * The one screen that must NOT grow a box, asserted against a screen that has one.
     *
     * A box is a promise that a reason will be recorded, and the QUOTE form measures no stock at
     * all and is refused for none. A quote's shortfall is answered much later and elsewhere —
     * `EstimateConversionService` holds the converted ORDER as a Draft and tells an admin — and
     * there is deliberately no override on that path, because a customer accepting silently is the
     * #326 defect itself. Case 5 above pins that behaviour.
     *
     * The invoice-from-order screen used to be this test's second case too, back when
     * `createFromOrder()` was a separate save path that measured no shortfall of its own. It no
     * longer is: `create()`/`applyLineRows()` are now the ONE save path for every invoice, order
     * behind it or not (#full-parity, 2026-09-13), and the stock check runs unconditionally in it —
     * see `theInvoiceFromOrderScreenAlsoGrowsTheReasonBoxNow()` for the positive proof.
     *
     * The absence here is paired with the presence of the quantity box in the same row, and with
     * the order form's box on the same selector shape — so a selector that had stopped matching
     * anything could not pass as the finding (#627).
     */
    public function theQuoteFormRendersNoReasonBoxBecauseItsSaveDoesNotReadOne(FunctionalTester $I): void
    {
        // ── The control: the order form's quantity cell has one.
        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $orderQtyCell = '//table[contains(@class, "order-lines-table")]//td[input[@name="lines[0][qty]"]]';
        $I->seeElement($orderQtyCell);
        $I->seeElement($orderQtyCell . '/input[@name="lines[0][stock_override_reason]"]');

        // ── The quote form: the same cell, the same quantity box in it, and no reason box.
        $I->amOnPage('/admin/estimate/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        $quoteQtyCell = '//table[contains(@class, "estimate-line-table")]//td[input[@name="lines[0][qty]"]]';
        $I->seeElement($quoteQtyCell);
        $I->dontSeeElement($quoteQtyCell . '/input[@name="lines[0][stock_override_reason]"]');
        $I->assertArrayNotHasKey(
            'stock_override_reason',
            $I->grabFormPayload('estimate-form')['lines'][0],
            'the quote form posts a field promising an override its save neither reads nor honours',
        );
    }

    /**
     * The invoice-from-order screen now grows the same reason box the standalone screen always
     * has — a side effect of the two invoice save paths becoming one (#full-parity, 2026-09-13):
     * `stockShortfallCheck()` runs unconditionally, order behind the invoice or not, so an
     * order-linked line billed beyond the shelf is refused exactly like a standalone one until a
     * reason is typed, and saves once one is.
     */
    public function theInvoiceFromOrderScreenAlsoGrowsTheReasonBoxNow(FunctionalTester $I): void
    {
        // Ordered for the full ASKED_FOR amount (unlike approvedOrderWithLine's fixture, whose 1000
        // units in REGION would leave nothing short to override), so billing all of it at issue
        // never runs into OverInvoicingGuard's own, separate, issue-time refusal. A Draft invoice
        // holds no stock at all (InvoiceInventoryBucketResolver::bucketForStatus() has no bucket for
        // it) so this has to issue to exercise the shortfall check.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $entityManager->find(Company::class, (int) $this->company->getId());
        $product = $entityManager->find(ProductCore::class, (int) $this->product->getId());
        $total = number_format((float) self::ASKED_FOR * 5.0, 2, '.', '');
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OVR-SO-' . strtoupper(substr(uniqid(), -8)))
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku((string) $product->getSku())
            ->setLocation(self::REGION)
            ->setTaxCode('E')
            ->setQuantity((string) self::ASKED_FOR)
            ->setPrice('5.00')
            ->setSubtotal($total);
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);
        $orderId = (int) $order->getId();
        $lineId = (int) $line->getId();
        $productId = (int) $product->getId();

        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $drawnQtyCell = '//table[contains(@class, "invoice-line-table")]//td[input[@name="lines[0][qty]"]]';
        $I->seeElement($drawnQtyCell);
        $I->seeElement($drawnQtyCell . '/input[@name="lines[0][stock_override_reason]"]');

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => (string) $I->grabAttributeFrom('form input[name="_token"]', 'value'),
            'fulfillment_region' => self::REGION,
            'lines' => [['product_id' => (string) $productId, 'sales_order_line_id' => (string) $lineId, 'qty' => (string) self::ASKED_FOR, 'price' => '5.00', 'location' => self::REGION]],
            'save_mode' => 'issue',
        ]);
        $I->seeElement('.form-error-banner');

        $box = '//table[contains(@class, "invoice-line-table")]//input[@name="lines[0][stock_override_reason]"]';
        $I->assertSame('', (string) $I->grabAttributeFrom($box, 'value'), 'no reason yet, so the save was refused');

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => (string) $I->grabAttributeFrom('form input[name="_token"]', 'value'),
            'fulfillment_region' => self::REGION,
            'lines' => [['product_id' => (string) $productId, 'sales_order_line_id' => (string) $lineId, 'qty' => (string) self::ASKED_FOR, 'price' => '5.00', 'location' => self::REGION, 'stock_override_reason' => 'Restock lands Friday']],
            'save_mode' => 'issue',
        ]);
        $I->dontSeeElement('.form-error-banner');
    }

    // ───────────────────────────────────────────────────────────── fixtures and screen drivers

    private function assignRegion(FunctionalTester $I, string $name): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
            $I->haveInRepository($region);
            // A region has to have somewhere to draw stock from (#546).
            $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
            $entityManager->flush();
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($I->grabEntityFromRepository(Company::class, ['id' => $this->company->getId()]))
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
        );
    }

    /**
     * Posts the real admin order create form, with or without a reason on the line.
     *
     * A plain form POST carrying a token scraped off the page that was just loaded — what a browser
     * with JavaScript off sends. `$reason === null` omits the box entirely, which is what a form
     * whose operator never typed in it actually posts.
     */
    private function postOrder(FunctionalTester $I, string $qty, string $saveMode, ?string $reason): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $line = [
            'product_id' => (string) $this->product->getId(),
            'qty' => $qty,
            'price' => '10.00',
            'tax_code' => 'E',
            'location' => self::REGION,
        ];
        if ($reason !== null) {
            $line['stock_override_reason'] = $reason;
        }

        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [$line],
            'save_mode' => $saveMode,
        ]);
    }

    /** Posts the real standalone invoice create form. See postOrder() for the null-reason rule. */
    private function postInvoice(FunctionalTester $I, string $qty, string $saveMode, ?string $reason): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $params = [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'save_mode' => $saveMode,
            'invoice_date' => '2026-09-12',
            'fulfillment_region' => self::REGION,
            // Indexed, not the parallel line_* arrays this screen used to post: those appended,
            // so fourteen of them had to be kept in step by hand and a blank row slipped every
            // later row against the others.
            'lines' => [[
                'product_id' => (string) $this->product->getId(),
                'name' => '',
                'sku' => '',
                'location' => self::REGION,
                'qty' => $qty,
                'price' => '10.00',
                'unit' => '',
                'unit_id' => '',
                'tax_code' => 'E',
                'weight' => '',
                'cost' => '',
            ]],
        ];
        if ($reason !== null) {
            $params['lines'][0]['stock_override_reason'] = $reason;
        }

        $I->sendFormPostRequest('/admin/invoice/create', $params);
    }

    /** A Priced quote for this company and product, which is the only state acceptance allows. */
    private function pricedQuoteFor(FunctionalTester $I, string $qty): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $entityManager->find(Company::class, (int) $this->company->getId());
        $product = $entityManager->find(ProductCore::class, (int) $this->product->getId());
        $lineTotal = number_format((float) $qty * 10.0, 2, '.', '');

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('OVR-Q-' . strtoupper(substr(uniqid(), -8)))
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            // A priced quote carries its shipping row, the same as the one the customer was shown.
            // Conversion copies the fee lines onto the order verbatim, and an order with none is not
            // a document this path ever produces.
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal($lineTotal)
            ->setTax('0.00')
            ->setTotal($lineTotal);
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION)
                ->setQuantity($qty)
                ->setPrice('10.00')
                ->setSubtotal($lineTotal)
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function customerUser(FunctionalTester $I): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('ovr-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Ada')
            ->setLastName('Buyer')
            ->setCompany($I->grabEntityFromRepository(Company::class, ['id' => $this->company->getId()]));
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        return $customer;
    }

    /**
     * An approved order with one line, stocked so the stock ceiling is never what refuses anything
     * in case 6 — that case is about OverInvoicingGuard and nothing else.
     *
     * @return array{orderId: int, lineId: int}
     */
    private function approvedOrderWithLine(FunctionalTester $I, string $quantity): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $entityManager->find(Company::class, (int) $this->company->getId());
        $product = $entityManager->find(ProductCore::class, (int) $this->product->getId());
        $I->haveStockFor($product, 1000, self::REGION);

        $total = number_format((float) $quantity * 5.0, 2, '.', '');
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OVR-SO-' . strtoupper(substr(uniqid(), -8)))
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku((string) $product->getSku())
            ->setLocation(self::REGION)
            ->setTaxCode('E')
            ->setQuantity($quantity)
            ->setPrice('5.00')
            ->setSubtotal($total);
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return ['orderId' => (int) $order->getId(), 'lineId' => (int) $line->getId()];
    }

    /** @param array{orderId: int, lineId: int} $context */
    private function raiseDraft(FunctionalTester $I, array $context, string $quantity): int
    {
        return $this->raiseInvoiceForOrder($I, $context, $quantity, 'draft');
    }

    /**
     * Presses Issue on the invoice's own screen.
     *
     * The token is scraped from THAT form rather than from anywhere on the page, so a test whose
     * button has quietly stopped being rendered fails here instead of asserting nothing.
     */
    private function issue(FunctionalTester $I, int $invoiceId): void
    {
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $action = '/admin/invoice/' . $invoiceId . '/action/issue';
        $I->sendFormPostRequest($action, [
            '_token' => (string) $I->grabAttributeFrom('form[action="' . $action . '"] input[name="_token"]', 'value'),
        ]);
    }

    /** @param array{orderId: int, lineId: int} $context */
    private function raiseInvoiceForOrder(FunctionalTester $I, array $context, string $quantity, string $saveMode): int
    {
        $productId = (int) $this->connection($I)->fetchOne('SELECT product_id FROM sales_order_line WHERE id = ?', [$context['lineId']]);

        $I->amOnPage('/admin/invoice/create?order_id=' . $context['orderId']);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $context['orderId'], [
            '_token' => (string) $I->grabAttributeFrom('form input[name="_token"]', 'value'),
            'lines' => [['product_id' => (string) $productId, 'sales_order_line_id' => (string) $context['lineId'], 'qty' => $quantity, 'price' => '5.00']],
            'save_mode' => $saveMode,
        ]);

        return (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$context['orderId']],
        );
    }

    // ─────────────────────────────────────────────────────────────────── reading columns back

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function highestOrderId(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sales_order');
    }

    private function highestInvoiceId(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');
    }

    /** @return array<string, mixed> */
    private function newestOrderRow(FunctionalTester $I, int $above): array
    {
        $row = $this->connection($I)->fetchAssociative('SELECT * FROM sales_order WHERE id > ? ORDER BY id DESC LIMIT 1', [$above]);
        $I->assertIsArray($row, 'the save wrote a sales_order row');

        return $row;
    }

    /** @return array<string, mixed> */
    private function newestInvoiceRow(FunctionalTester $I, int $above): array
    {
        $row = $this->connection($I)->fetchAssociative('SELECT * FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1', [$above]);
        $I->assertIsArray($row, 'the save wrote an invoice row');

        return $row;
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
        $I->assertIsArray($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function orderLineRows(FunctionalTester $I, int $orderId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT * FROM sales_order_line WHERE order_id = ? ORDER BY sort_order ASC, id ASC',
            [$orderId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function invoiceLineRows(FunctionalTester $I, int $invoiceId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
            [$invoiceId],
        );
    }

    /** Override rows for THIS test's product only, so a leftover from another Cest cannot show up. */
    private function overrideCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM sales_order_line_stock_override o
             JOIN sales_order_line l ON l.id = o.order_line_id
             WHERE l.product_id = ?',
            [$this->product->getId()],
        );
    }

    private function invoiceOverrideCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM invoice_line_stock_override o
             JOIN invoice_line l ON l.id = o.invoice_line_id
             WHERE l.product_id = ?',
            [$this->product->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function overrideRow(FunctionalTester $I, int $orderLineId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM sales_order_line_stock_override WHERE order_line_id = ?',
            [$orderLineId],
        );
        $I->assertIsArray($row, 'the save wrote an override row against the line it was decided on');

        return $row;
    }

    /** @return array<string, mixed> */
    private function invoiceOverrideRow(FunctionalTester $I, int $invoiceLineId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM invoice_line_stock_override WHERE invoice_line_id = ?',
            [$invoiceLineId],
        );
        $I->assertIsArray($row, 'the save wrote an override row against the invoice line');

        return $row;
    }

    /**
     * The inventory row for this product in THIS region, by column name.
     *
     * Read straight out of `product_inventory` rather than off an entity the request left in memory:
     * a figure computed and never written looks identical from an entity and is the whole class of
     * defect #624 exists to catch.
     *
     * @return array<string, mixed>
     */
    private function inventoryRow(FunctionalTester $I): array
    {
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
            [$this->product->getId(), $warehouse?->getId()],
        );
        $I->assertIsArray($row, 'the product has an inventory row in the region under test');

        return $row;
    }

    private function assertInventory(FunctionalTester $I, int $quantity, int $salesHold, int $backordered, string $because = ''): void
    {
        $row = $this->inventoryRow($I);
        $I->assertSame($quantity, (int) $row['quantity'], 'quantity: ' . $because);
        $I->assertSame($salesHold, (int) $row['sales_hold_quantity'], 'sales_hold_quantity: ' . $because);
        $I->assertSame($backordered, (int) $row['backordered_quantity'], 'backordered_quantity: ' . $because);
    }

    private function pendingHeld(FunctionalTester $I): int
    {
        return (int) $this->inventoryRow($I)['pending_quantity'];
    }

    /** Availability as the entity computes it — the figure every screen and every check reads. */
    private function availability(FunctionalTester $I): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $inventory = $entityManager->getRepository(\App\Entity\ProductInventory::class)->findOneBy([
            'product' => $entityManager->find(ProductCore::class, (int) $this->product->getId()),
            'warehouse' => $warehouse,
        ]);

        return $inventory?->getAvailableQuantity() ?? '0.0000';
    }

    /** A quantity at the column's four places (#645). */
    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
