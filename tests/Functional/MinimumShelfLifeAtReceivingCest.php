<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Minimum shelf life at receiving, and the tracking policy that can no longer contradict itself —
 * conducted per #624 (item 68).
 *
 * ## What is being proved, and why each half needs the other
 *
 * **It warns, it does not block.** A date closer than the minimum refuses the receipt until somebody
 * says WHY, and books the goods in the moment they do. That is not a softened gate, it is the only
 * honest one: the pallet is physically on the dock by the time anybody reads the date, so refusing
 * the RECORD does not refuse the PALLET — it makes real stock invisible, and invisible stock is
 * picked anyway. So every refusal case here is paired with the same delivery going THROUGH on a
 * reason, and the stock is read back out of `inventory_detail` by column afterwards.
 *
 * **The override is not a tick.** A bare checkbox would answer who and when and leave the only
 * question anybody asks a fortnight later — on what grounds — unanswered. `aShortDatedReceiptWith
 * NoReasonIsRefused()` is what stops that being quietly reintroduced.
 *
 * **The absence of a rule row is not the absence of a rule.** That sentence is what item 67 was:
 * `procurement_product_rule` was the only place a requirement lived and nothing ever created a row,
 * so every guard reading it passed silently for every product in the database. The minimum shelf
 * life deliberately does NOT work that way — a product with no row follows the GLOBAL, which applies
 * to every product whether or not a row exists — and `aProductWithNoRuleRowFollowsTheGlobal()`
 * asserts the row's absence by column before proving the warning still fires.
 *
 * ## Every absence has a positive control on the same element (#627)
 *
 * `aReceiptComfortablyOutsideTheMinimumIsNotWarnedAbout()` exists so that
 * `aShortDatedReceiptWarnsAndBooksInWithAReason()` cannot pass on a screen that warns about
 * everything, and both check `#flash-error-1` — the same element, one asserting it is there with the
 * short-dated wording in it, the other that it is not there at all.
 *
 * Nothing here does `see()` on a number. This feature is day counts, and `see('30')` matches '130',
 * which on this subject is not a hypothetical: the figures are 30, 90 and 120. Every numeric claim
 * is an `assertSame` against `table.column` read back off the connection, and every textual claim is
 * `grabTextFrom()` on a specific element id.
 *
 * ## The row that must not change
 *
 * A second product is stocked in the same warehouse and the same bin and named by no receipt here.
 * Its `inventory_detail` balance is asserted untouched after every operation, refusals included.
 *
 * ## Per-run state
 *
 * Codeception reuses ONE Cest instance across methods, so anything held on `$this` leaks from test
 * to test. Every property is reset in `_before`, every subject is created per test with a unique
 * SKU, and the global minimum is re-set through the real settings screen at the start of each test
 * rather than assumed to be whatever the previous one left it at.
 */
final class MinimumShelfLifeAtReceivingCest
{
    private const FORM = '/admin/bundles/procurement/receiving/new';
    private const SETTINGS = '/admin/bundles/procurement/settings';
    private const RULE_SAVE = '/admin/bundles/procurement/settings/receiving-rule';
    private const POLICY_SAVE = '/admin/product/tracking-policies/save';

    /** The global minimum every test sets, unless it is testing the global itself. */
    private const GLOBAL_DAYS = 90;

    private int $warehouseId = 0;
    private int $binId = 0;
    private int $vendorId = 0;
    private int $bystanderId = 0;

    public function _before(FunctionalTester $I): void
    {
        $this->warehouseId = 0;
        $this->binId = 0;
        $this->vendorId = 0;
        $this->bystanderId = 0;

        $em = $I->grabService(EntityManagerInterface::class);
        $bundles = $I->grabService(BundleStatusRepository::class);
        foreach (['ProcurementBundle', 'InventoryDepthBundle'] as $source) {
            $em->persist($bundles->ensureBySource($source)->setStatus(BundleStatus::STATUS_ACTIVE));
        }
        $em->flush();

        // AppSettings caches the whole table under one key, and the cache pool outlives a
        // transaction rollback. A minimum left in it by the previous test would be read by this
        // one's first receipt, so it goes before anything is set.
        $I->grabService(AppSettings::class)->clearCache();

        $this->loginAsAdmin($I);
        $this->site($I);
    }

    // ══ 1. THE WHOLE PATH: a short date warns, and a reason books it in ══════════════════════

    /**
     * A delivery inside the global minimum is refused until a reason is given, then booked in.
     *
     * Three separate claims, and the third is the one the item was filed for: the stock MOVED
     * (`inventory_detail`, by column), and the decision was RECORDED (`procurement_short_dated_
     * receipt`, by column) with the reason, the person, the date and the shortfall on it.
     */
    public function aShortDatedReceiptWarnsAndBooksInWithAReason(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $productId = $this->product($I, 'MSL-WARN-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '6.00');

        // 30 days of shelf life against a 90-day minimum: 60 short.
        $expiry = $this->inDays(30);

        // The control is on the row before anything is posted: a receiver with scripting off has a
        // box to put the reason in, on the line that carries the date.
        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="lines[0][expiry]"]');
        $I->seeElement('input[name="lines[0][short_dated_reason]"]');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '6', 'lot_code' => 'MSL-B1', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('is short-dated', $I->grabTextFrom('#flash-error-1'));
        $I->assertStringContainsString('saying WHY', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'nothing was booked in while nobody had said why');
        $I->assertSame(0, $this->available($I, $productId), 'and no stock moved');
        $I->assertSame(0, $this->overrideCount($I, $productId), 'and no override was recorded for a receipt that did not happen');
        $this->bystanderIsUntouched($I, 'the warned short-dated receipt');

        // ── The same delivery, with a reason on the line.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '6',
                'lot_code' => 'MSL-B1',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Promo run ships next week, agreed with the buyer',
            ],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the delivery is booked in once a reason is given');
        $I->assertSame(6, $this->available($I, $productId), 'the goods reached stock — a short date is not a refusal');

        $override = $this->overrideRow($I, $productId);
        $I->assertNotNull($override, 'the override was recorded, not merely allowed');
        $I->assertSame('Promo run ships next week, agreed with the buyer', (string) $override['reason']);
        $I->assertSame(self::GLOBAL_DAYS, (int) $override['minimum_days']);
        $I->assertSame(30, (int) $override['remaining_days'], 'what the goods actually had left');
        $I->assertSame('global', (string) $override['minimum_source']);
        $I->assertSame($expiry, substr((string) $override['expiry'], 0, 10));
        $I->assertNotSame('', (string) $override['overridden_by'], 'and who decided it');

        // ── It is SURFACED, which is the difference between an exception and a silent flag.
        //
        //    On the receipt, where the decision belongs, and on the receipts grid, where the
        //    question "what did we let through" is asked. Not on the three-way match Exceptions
        //    worklist: that screen is a money worklist, every row on it carries what it puts at
        //    risk in the document's currency and the whole screen is sorted by that figure, and a
        //    short date has no bill and no amount — what it is worth is a number of days.
        $receiptId = (int) (string) $this->column($I, 'SELECT id FROM goods_receipt WHERE purchase_order_id = ?', [$orderId]);
        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#short-dated-table');
        $I->assertSame(
            (string) $override['reason'],
            trim($I->grabTextFrom('#short-dated-reason-' . (int) $override['id'])),
            'the reason is on the document, not only in a column',
        );
        $I->assertStringContainsString(
            'day(s)',
            $I->grabTextFrom('#short-dated-shortfall-' . (int) $override['id']),
            'and so is what it was short by',
        );

        // The bystander's receipt went through the same screen with a comfortable date, so it is
        // the control for the filter: BOTH rows unfiltered, only the short-dated one filtered. The
        // assertion is on the ROW, not on the marker — a marker only appears on matching rows, so
        // asserting its absence would pass whether the filter worked or not.
        $bystanderReceiptId = (int) (string) $this->column(
            $I,
            'SELECT r.id FROM goods_receipt r JOIN goods_receipt_line l ON l.goods_receipt_id = r.id WHERE l.product_id = ?',
            [$this->bystanderId],
        );

        $I->amOnPage('/admin/bundles/procurement/receiving');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#receipt-row-' . $receiptId);
        $I->seeElement('#receipt-row-' . $bystanderReceiptId);
        $I->seeElement('#receipt-short-dated-' . $receiptId);
        $I->dontSeeElement('#receipt-short-dated-' . $bystanderReceiptId);

        $I->amOnPage('/admin/bundles/procurement/receiving?filters[short_dated]=1');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#receipt-row-' . $receiptId);
        $I->dontSeeElement('#receipt-row-' . $bystanderReceiptId);

        $this->bystanderIsUntouched($I, 'the accepted short-dated receipt');
    }

    /**
     * The same receipt with the box blank is REFUSED — the override is not a tick.
     *
     * Its positive control is the case above, which books the identical delivery in on a reason.
     * Without this, "an override exists" would be satisfied by a checkbox that books stock in
     * silently, which is the outcome the whole record exists to prevent.
     */
    public function aShortDatedReceiptWithNoReasonIsRefused(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $productId = $this->product($I, 'MSL-NOREASON-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '4.00');
        $expiry = $this->inDays(10);

        // A blank reason box is exactly what a browser posts when nobody typed in it.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '4',
                'lot_code' => 'MSL-B2',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => '   ',
            ],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('is short-dated', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'whitespace is not a reason');
        $I->assertSame(0, $this->available($I, $productId));
        $I->assertSame(0, $this->overrideCount($I, $productId));

        // The positive control, same product, same order, same date: a real reason goes through.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '4',
                'lot_code' => 'MSL-B2',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Customer accepted the date in writing',
            ],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $productId));
        $I->assertSame(1, $this->overrideCount($I, $productId));

        $this->bystanderIsUntouched($I, 'the blank-reason cases');
    }

    /**
     * A comfortable date books in first time, with no warning and no override row.
     *
     * The positive control for the two cases above (#627). Without it they pass just as well on a
     * screen that warns about every delivery, which would make the feature useless and invisible in
     * exactly the same way.
     */
    public function aReceiptComfortablyOutsideTheMinimumIsNotWarnedAbout(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $productId = $this->product($I, 'MSL-LONG-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '5.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'lot_code' => 'MSL-B3', 'expiry' => $this->inDays(400), 'location_id' => (string) $this->binId],
        ]);

        // The same element the short-dated cases assert IS present.
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'it booked in first time, with no reason typed anywhere');
        $I->assertSame(5, $this->available($I, $productId));
        $I->assertSame(0, $this->overrideCount($I, $productId), 'and nothing was logged as an exception');

        $this->bystanderIsUntouched($I, 'the comfortable receipt');
    }

    // ══ 2. THE PER-PRODUCT OVERRIDE BEATS THE GLOBAL, IN BOTH DIRECTIONS ═════════════════════

    /**
     * A stricter product override warns on a date the global would have allowed.
     *
     * Both halves are on the same DATE — 120 days out, comfortably past a global of 90 — so the only
     * thing separating the two products is the override, and the control product going through
     * proves the date itself is not the cause.
     */
    public function aStricterProductOverrideWarnsOnADateTheGlobalWouldAllow(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $strictId = $this->product($I, 'MSL-STRICT-' . uniqid());
        $this->setProductMinimum($I, $strictId, '365');

        $expiry = $this->inDays(120);

        $strictOrder = $this->purchaseOrder($I, $strictId, '3.00');
        $this->receiveThrough($I, $strictOrder, [
            0 => ['quantity' => '3', 'lot_code' => 'MSL-S1', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('set on this product', $I->grabTextFrom('#flash-error-1'), 'and it says WHOSE minimum refused it');
        $I->assertSame(0, $this->receiptCount($I, $strictOrder));

        // The control: a product with NO override, the same date, the same warehouse, the same day.
        $plainId = $this->product($I, 'MSL-PLAIN-' . uniqid());
        $plainOrder = $this->purchaseOrder($I, $plainId, '3.00');
        $this->receiveThrough($I, $plainOrder, [
            0 => ['quantity' => '3', 'lot_code' => 'MSL-S2', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $plainOrder), '120 days clears the global 90 for a product with no override');
        $I->assertSame(3, $this->available($I, $plainId));

        // And the strict one goes through on a reason, recording that the minimum was the PRODUCT's.
        $this->receiveThrough($I, $strictOrder, [
            0 => [
                'quantity' => '3',
                'lot_code' => 'MSL-S1',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Line trial only, not for the export channel',
            ],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $strictOrder));
        $I->assertSame(3, $this->available($I, $strictId));

        $override = $this->overrideRow($I, $strictId);
        $I->assertNotNull($override);
        $I->assertSame(365, (int) $override['minimum_days'], 'the figure recorded is the override, not the global');
        $I->assertSame('product', (string) $override['minimum_source']);

        $this->bystanderIsUntouched($I, 'the stricter override cases');
    }

    /**
     * A looser product override does NOT warn on a date the global would have refused.
     *
     * The other direction, and the one that proves the override really replaces the global rather
     * than tightening it. Its control is the identical delivery against a product with no override,
     * which IS refused on the same date.
     */
    public function aLooserProductOverrideDoesNotWarnOnADateTheGlobalWouldRefuse(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $looseId = $this->product($I, 'MSL-LOOSE-' . uniqid());
        $this->setProductMinimum($I, $looseId, '14');

        $expiry = $this->inDays(30);

        $looseOrder = $this->purchaseOrder($I, $looseId, '2.00');
        $this->receiveThrough($I, $looseOrder, [
            0 => ['quantity' => '2', 'lot_code' => 'MSL-L1', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $looseOrder), '30 days clears this product\'s own 14-day minimum');
        $I->assertSame(2, $this->available($I, $looseId));
        $I->assertSame(0, $this->overrideCount($I, $looseId), 'nothing was short, so nothing was logged');

        // The control, on the same date: no override means the global 90, and 30 is short of it.
        $plainId = $this->product($I, 'MSL-LOOSECTL-' . uniqid());
        $plainOrder = $this->purchaseOrder($I, $plainId, '2.00');
        $this->receiveThrough($I, $plainOrder, [
            0 => ['quantity' => '2', 'lot_code' => 'MSL-L2', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertSame(0, $this->receiptCount($I, $plainOrder), 'the same date against the global is short');

        $this->bystanderIsUntouched($I, 'the looser override cases');
    }

    /**
     * A product with NO `procurement_product_rule` row at all follows the global.
     *
     * The case item 67 makes mandatory. Its hole was that an absent row answered "nothing is
     * required" for every product in the database; here an absent row answers "no OVERRIDE", and
     * the global still applies. The absence is asserted by column BEFORE the delivery, so this
     * cannot pass on a row that some earlier step quietly created.
     */
    public function aProductWithNoRuleRowFollowsTheGlobal(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $productId = $this->product($I, 'MSL-NOROW-' . uniqid());

        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT COUNT(*) FROM procurement_product_rule WHERE product_id = ?', [$productId]),
            'this product has no receiving rule row — the absence is the point of the test',
        );

        $orderId = $this->purchaseOrder($I, $productId, '8.00');
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '8', 'lot_code' => 'MSL-N1', 'expiry' => $this->inDays(20), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('set for every product', $I->grabTextFrom('#flash-error-1'), 'the global is what refused it');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT COUNT(*) FROM procurement_product_rule WHERE product_id = ?', [$productId]),
            'and measuring did not create a rule row as a side effect',
        );

        // It books in on a reason, still with no rule row anywhere.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '8',
                'lot_code' => 'MSL-N1',
                'expiry' => $this->inDays(20),
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Short shelf life accepted, going straight out on a standing order',
            ],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(8, $this->available($I, $productId));
        $I->assertSame('global', (string) $this->overrideRow($I, $productId)['minimum_source']);

        $this->bystanderIsUntouched($I, 'the no-rule-row fallback');
    }

    /**
     * Zero on a product is "exempt", and it is a different answer from "not set".
     *
     * The three-state distinction the whole column exists for, proved where it can be seen: the
     * exempt product is not warned about on a date the global refuses, and — the half that would
     * otherwise rot — its rule ROW survives, because `isEmpty()` deleting it would silently turn the
     * exemption back into "follow the global".
     */
    public function zeroOnAProductMeansExemptAndIsNotTheSameAsNotSet(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, self::GLOBAL_DAYS);

        $exemptId = $this->product($I, 'MSL-EXEMPT-' . uniqid());
        $this->setProductMinimum($I, $exemptId, '0');

        $I->assertSame(
            '0',
            (string) $this->column($I, 'SELECT minimum_shelf_life_days FROM procurement_product_rule WHERE product_id = ?', [$exemptId]),
            'the exemption is stored as 0 and the row was NOT deleted as empty',
        );

        $orderId = $this->purchaseOrder($I, $exemptId, '9.00');
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '9', 'lot_code' => 'MSL-E1', 'expiry' => $this->inDays(3), 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'an exempt product is never called short');
        $I->assertSame(9, $this->available($I, $exemptId));
        $I->assertSame(0, $this->overrideCount($I, $exemptId));

        // Clearing the box is "not set", which is NOT the same answer: the row goes and the global
        // applies again, on the same date that was just accepted.
        $this->setProductMinimum($I, $exemptId, '');

        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT COUNT(*) FROM procurement_product_rule WHERE product_id = ?', [$exemptId]),
            'a rule row with nothing on it is deleted rather than stored',
        );

        $secondOrder = $this->purchaseOrder($I, $exemptId, '9.00');
        $this->receiveThrough($I, $secondOrder, [
            0 => ['quantity' => '9', 'lot_code' => 'MSL-E2', 'expiry' => $this->inDays(3), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertSame(0, $this->receiptCount($I, $secondOrder), 'the same date is short again once the exemption is cleared');
        $I->assertSame(9, $this->available($I, $exemptId), 'and the stock from the accepted receipt did not move');

        $this->bystanderIsUntouched($I, 'the exempt-versus-unset cases');
    }

    // ══ 3. PART ONE: THE POLICY FORM REFUSES A CONTRADICTION ════════════════════════════════

    /**
     * A `lot` policy requiring an expiry with nothing captured inbound cannot be saved.
     *
     * Asserted by reading `tracking_policy`'s columns back after the POST, not by what the page
     * said. Two refusals and one positive control, all through the real save action:
     *
     *  - requires_expiry with track_in off — the contradiction: an expiry demanded at the dock with
     *    no batch to hang it on, and no box on the receiving screen that satisfies it;
     *  - a tracked mode with BOTH directions off — `isInert()` under a name that says otherwise;
     *  - the corrected policy, which saves, so the refusals are not this screen refusing everything.
     */
    public function aContradictoryTrackingPolicyCannotBeSaved(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $name = 'MSL Contradiction ' . uniqid();

        // (a) requires_expiry with nothing captured on the way in.
        $this->savePolicy($I, [
            'id' => '0',
            'name' => $name,
            'mode' => TrackingPolicy::MODE_LOT,
            'requires_expiry' => '1',
            'track_out' => '1',
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('no batch to hang it on', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT COUNT(*) FROM tracking_policy WHERE name = ?', [$name]),
            'no row was written for the refused policy',
        );

        // (b) a tracked mode capturing in neither direction — "None wearing another name".
        $inertName = $name . ' inert';
        $this->savePolicy($I, [
            'id' => '0',
            'name' => $inertName,
            'mode' => TrackingPolicy::MODE_LOT,
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('wearing another name', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT COUNT(*) FROM tracking_policy WHERE name = ?', [$inertName]),
        );

        // (c) the positive control: the same policy with capture-inbound ticked saves.
        $this->savePolicy($I, [
            'id' => '0',
            'name' => $name,
            'mode' => TrackingPolicy::MODE_LOT,
            'requires_expiry' => '1',
            'track_in' => '1',
            'track_out' => '1',
        ]);

        $I->dontSeeElement('#flash-error-1');

        $row = $this->rows($I, 'SELECT mode, requires_expiry, track_in, track_out FROM tracking_policy WHERE name = ?', [$name]);
        $I->assertCount(1, $row, 'the corrected policy is one row');
        $I->assertSame(TrackingPolicy::MODE_LOT, (string) $row[0]['mode']);
        $I->assertSame(1, (int) $row[0]['requires_expiry']);
        $I->assertSame(1, (int) $row[0]['track_in'], 'requires_expiry implies capture-inbound, and the stored row says so');
        $I->assertSame(1, (int) $row[0]['track_out']);

        // (d) an EXISTING policy already in the refused state is not rewritten, and refuses on its
        //     next save. Nothing in this feature writes to data that is already there.
        $legacy = (new TrackingPolicy())
            ->setName($name . ' legacy')
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry(true)
            ->setTrackIn(false)
            ->setTrackOut(false);
        $em->persist($legacy);
        $em->flush();
        $legacyId = (int) $legacy->getId();

        $I->amOnPage('/admin/product/tracking-policies/' . $legacyId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#policy-contradiction');
        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT track_in FROM tracking_policy WHERE id = ?', [$legacyId]),
            'looking at it changed nothing',
        );

        $this->savePolicy($I, [
            'id' => (string) $legacyId,
            'name' => $name . ' legacy',
            'mode' => TrackingPolicy::MODE_LOT,
            'requires_expiry' => '1',
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertSame(
            0,
            (int) (string) $this->column($I, 'SELECT track_in FROM tracking_policy WHERE id = ?', [$legacyId]),
            'the refused save left the stored row exactly as it was',
        );
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('shelf-life-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** A warehouse of this test's own, one bin in it, one vendor, and the bystander product. */
    private function site(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Shelf Life ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('MSL-01')->setSortKey(10);
        $em->persist($bin);

        $vendor = (new Vendor())->setName('Shelf Life Supply ' . uniqid())->setCurrency('CAD')->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        $this->warehouseId = (int) $warehouse->getId();
        $this->binId = (int) $bin->getId();
        $this->vendorId = (int) $vendor->getId();

        // The row that must not change. Booked in through a real receipt with a long date, so it
        // has a known history rather than being a number written into a column by hand — and so the
        // fixture itself demonstrates the no-warning path before any assertion depends on it.
        $this->bystanderId = $this->product($I, 'MSL-BYSTANDER-' . uniqid());
        $this->receiveThrough($I, $this->purchaseOrder($I, $this->bystanderId, '5.00'), [
            0 => ['quantity' => '5', 'lot_code' => 'MSL-BY', 'expiry' => $this->inDays(900), 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(5, $this->available($I, $this->bystanderId), 'the bystander opens at five');
    }

    /**
     * One dimensional product on a lot + expiry tracking policy.
     *
     * Lot mode with `requires_expiry` and capture-inbound, because that is the only configuration in
     * which receiving captures an expiry date at all — `CaptureRequirement::capturesExpiry()` decides
     * whether the box is rendered, and ReceivingService's own guard refuses the batch without a date
     * before the shelf-life check is ever reached. So a shelf-life test on any other policy would be
     * testing a box that is not on the screen.
     */
    private function product(FunctionalTester $I, string $sku): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $policy = (new TrackingPolicy())
            ->setName('Shelf Life Policy ' . $sku)
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry(true)
            ->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Shelf life ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        $em->persist(
            (new ProductInventory())
                ->setProduct($product)
                ->setWarehouse($em->find(Warehouse::class, $this->warehouseId))
                ->setQuantity(0),
        );
        $em->flush();

        return (int) $product->getId();
    }

    /** An issued purchase order for $quantity of one product, ready to receive against. */
    private function purchaseOrder(FunctionalTester $I, int $productId, string $quantity): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = $em->find(ProductCore::class, $productId);

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-MSL-' . strtoupper(bin2hex(random_bytes(4))))
            ->setVendor($em->find(Vendor::class, $this->vendorId))
            ->setWarehouse($em->find(Warehouse::class, $this->warehouseId))
            ->setExpectedDate('2026-10-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName((string) $product->getName())
            ->setSku((string) $product->getSku())
            ->setQuantityOrdered($quantity)
            ->setUnitCost('1.0000')
            ->setSubtotal($quantity);
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        $order->setStatus('Issued', DocumentActor::system());
        $em->flush();

        return (int) $order->getId();
    }

    /** A calendar date $days from today, as the date input on the form posts it. */
    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days))->format('Y-m-d');
    }

    // ── driving the real screens ────────────────────────────────────────────────────────────

    /** Sets the global minimum through the real Procurement Settings screen. */
    private function setGlobalMinimum(FunctionalTester $I, int $days): void
    {
        $I->amOnPage(self::SETTINGS);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::SETTINGS . '/save', [
            '_token' => $I->csrfToken(),
            'procurement_quantity_tolerance_percent' => '0',
            'procurement_price_tolerance_percent' => '0',
            'procurement_minimum_shelf_life_days' => (string) $days,
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Read it back off the column, through the screen that shows it: the assertion is that the
        // setting was STORED, not that a form was posted.
        $I->assertSame(
            (string) $days,
            (string) $this->column($I, 'SELECT setting_value FROM app_setting WHERE setting_key = ?', ['procurement_minimum_shelf_life_days']),
        );
    }

    /**
     * Sets (or clears) one product's own minimum through the real receiving-rule form.
     *
     * `$days` is posted exactly as the box submits it: '' for "not set", '0' for exempt, a number
     * otherwise.
     */
    private function setProductMinimum(FunctionalTester $I, int $productId, string $days): void
    {
        $I->amOnPage(self::SETTINGS);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::RULE_SAVE, [
            '_token' => $I->csrfToken(),
            'product_id' => (string) $productId,
            'minimum_shelf_life_days' => $days,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** One save on the tracking policy form, as the form posts it. */
    private function savePolicy(FunctionalTester $I, array $fields): void
    {
        $I->amOnPage('/admin/product/tracking-policies/new');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::POLICY_SAVE, array_merge(['_token' => $I->csrfToken()], $fields));
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * Posts the receiving form exactly as a browser with scripting off does.
     *
     * The CSRF token is scraped off the rendered form and posted back, so this goes THROUGH the
     * app's check rather than round it. `submitForm()` cannot be used on an admin screen: with the
     * `admin.localhost` Host header the crawler resolves the action as an absolute URL and refuses
     * it as external.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function receiveThrough(FunctionalTester $I, int $orderId, array $rows): void
    {
        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        foreach ($I->grabMultiple('input[name$="[purchase_order_line_id]"]', 'value') as $index => $lineId) {
            if (isset($rows[$index])) {
                $rows[$index]['purchase_order_line_id'] = (string) $lineId;
            }
        }

        $I->sendFormPostRequest(self::FORM, [
            '_token' => $I->csrfToken(),
            'purchase_order_id' => (string) $orderId,
            'action' => 'receive',
            'packing_slip' => 'PS-MSL',
            'lines' => $rows,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    // ── reading the database back, by column ────────────────────────────────────────────────

    private function column(FunctionalTester $I, string $sql, array $params = []): ?string
    {
        $value = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $params);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<array<string, mixed>> */
    private function rows(FunctionalTester $I, string $sql, array $params = []): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative($sql, $params);
    }

    /** `SUM(inventory_detail.quantity)` available for one product in this test's warehouse. */
    private function available(FunctionalTester $I, int $productId): int
    {
        return (int) (float) (string) $this->column(
            $I,
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = 'available'",
            [$productId, $this->warehouseId],
        );
    }

    private function receiptCount(FunctionalTester $I, int $orderId): int
    {
        return (int) (string) $this->column($I, 'SELECT COUNT(*) FROM goods_receipt WHERE purchase_order_id = ?', [$orderId]);
    }

    /** How many short-dated overrides exist against one product, by column. */
    private function overrideCount(FunctionalTester $I, int $productId): int
    {
        return (int) (string) $this->column(
            $I,
            'SELECT COUNT(*) FROM procurement_short_dated_receipt sd'
            . ' JOIN goods_receipt_line l ON l.id = sd.receipt_line_id WHERE l.product_id = ?',
            [$productId],
        );
    }

    /** @return array<string, mixed>|null */
    private function overrideRow(FunctionalTester $I, int $productId): ?array
    {
        $rows = $this->rows(
            $I,
            'SELECT sd.* FROM procurement_short_dated_receipt sd'
            . ' JOIN goods_receipt_line l ON l.id = sd.receipt_line_id WHERE l.product_id = ? ORDER BY sd.id DESC',
            [$productId],
        );

        return $rows[0] ?? null;
    }

    /** The bystander is exactly where site() left it — asserted after every operation, refusals too. */
    private function bystanderIsUntouched(FunctionalTester $I, string $after): void
    {
        $I->assertSame(5, $this->available($I, $this->bystanderId), "the bystander's detail balance moved during: " . $after);
        $I->assertSame(
            0,
            $this->overrideCount($I, $this->bystanderId),
            "the bystander gained a short-dated override during: " . $after,
        );
    }
}
