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
 * Goods that arrive ALREADY EXPIRED are warned about on their own — conducted per #624 (item 69).
 *
 * ## The defect this closes
 *
 * `AbstractProcurementController::calendarDate()` validates the FORMAT of a posted date and nothing
 * else, and `InventoryLot::isExpired()` is consulted only by `ExpireLotsCommand`, which sweeps stock
 * that is already on the shelf. Item 68 added a minimum shelf life — but it starts at 0, which is
 * the shipped default, and at 0 it measured nothing. So a pallet whose last usable day had already
 * passed booked in clean, counted as `available`, and stayed invisible as a problem until that
 * night's sweep moved it out again. The warehouse read as holding sellable stock it did not hold.
 *
 * ## A separate trigger, not a stricter minimum
 *
 * `expiredStillWarnsWhenTheProductIsExemptFromTheMinimum()` is the case the whole change exists
 * for: global minimum 0 AND a per-product override of 0, which is the strongest possible statement
 * that nobody wants a threshold on this product — and the delivery is STILL warned about. An
 * exemption says how much life this buyer insists on; it cannot mean "accepts goods that are
 * already dead", because dead stock is not a short threshold, it is unsellable on arrival by the
 * application's own convention.
 *
 * ## One message, even when both findings fire
 *
 * `expiredAndInsideTheMinimumIsOneMessageLedByTheExpiry()` pins what a receiver actually sees: ONE
 * error, led by the expiry, with the missed minimum named in a trailing clause — and it asserts
 * `#flash-error-2` is absent against `#flash-error-1` being present, so "one message" is checked
 * rather than assumed. One pallet, one decision, one reason box, one recorded row.
 *
 * ## Boundary
 *
 * `expiry` is the LAST USABLE DAY throughout #550 — `InventoryLot::isExpired()` and
 * `ExpireLotsCommand` both compare strictly. `aDateOfTodayIsStillUsableAndIsNotWarnedAbout()` pins
 * that receiving agrees: yesterday warns, today does not. An off-by-one here would either wave
 * through a dead pallet or refuse a live one, and neither is visible from a green suite.
 *
 * ## Every absence has a positive control on the same element (#627)
 *
 * And nothing here does `see()` on a number — this subject is dates and day counts, where `see('1')`
 * matches '21' and a date is nothing but digits. Every numeric claim is an `assertSame` against
 * `table.column`; every textual one is `grabTextFrom()` on a specific element id.
 *
 * ## Per-run state
 *
 * Codeception reuses ONE Cest instance across methods. Every property is reset in `_before`, every
 * subject gets a unique SKU, and the global minimum is set explicitly through the real settings
 * screen in each test rather than inherited.
 */
final class ExpiredOnArrivalAtReceivingCest
{
    private const FORM = '/admin/bundles/procurement/receiving/new';
    private const SCAN = '/admin/bundles/procurement/receiving/scan';
    private const SETTINGS = '/admin/bundles/procurement/settings';
    private const RULE_SAVE = '/admin/bundles/procurement/settings/receiving-rule';

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

        // AppSettings caches the whole table under one key and the pool outlives a transaction
        // rollback, so a minimum left by the previous test would be read by this one's first receipt.
        $I->grabService(AppSettings::class)->clearCache();

        $this->loginAsAdmin($I);
        $this->site($I);
    }

    // ══ 1. THE WHOLE PATH: a dead pallet warns, and a reason books it in ═════════════════════

    /**
     * A delivery that expired yesterday is refused until a reason is given, then booked in.
     *
     * The global minimum is set to 0 first — the shipped default, and the state in which this used
     * to pass in total silence. So nothing but the expiry date can be producing the warning.
     */
    public function anAlreadyExpiredDeliveryWarnsAndBooksInWithAReason(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $productId = $this->product($I, 'EXP-WARN-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '6.00');
        $expiry = $this->inDays(-1);

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '6', 'lot_code' => 'EXP-B1', 'expiry' => $expiry, 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('ARRIVED EXPIRED', $I->grabTextFrom('#flash-error-1'));
        $I->assertStringContainsString('saying WHY', $I->grabTextFrom('#flash-error-1'));
        $I->assertStringNotContainsString(
            'It also misses',
            $I->grabTextFrom('#flash-error-1'),
            'with no minimum in force there is no minimum clause to add',
        );
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'nothing was booked in while nobody had said why');
        $I->assertSame(0, $this->available($I, $productId), 'and no stock moved');
        $I->assertSame(0, $this->overrideCount($I, $productId));
        $this->bystanderIsUntouched($I, 'the warned expired receipt');

        // ── The same delivery, with a reason on the line.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '6',
                'lot_code' => 'EXP-B1',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Vendor credit agreed, returning on the next collection',
            ],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the delivery is booked in once a reason is given');
        $I->assertSame(6, $this->available($I, $productId), 'the goods reached stock — dead goods on the dock are still goods on the dock');

        $override = $this->overrideRow($I, $productId);
        $I->assertNotNull($override, 'the override was recorded, not merely allowed');
        $I->assertSame('Vendor credit agreed, returning on the next collection', (string) $override['reason']);
        $I->assertSame(-1, (int) $override['remaining_days'], 'negative is what makes the row say EXPIRED rather than short');
        $I->assertSame(0, (int) $override['minimum_days'], 'and it records that no minimum was in force when this was accepted');
        $I->assertSame($expiry, substr((string) $override['expiry'], 0, 10));
        $I->assertNotSame('', (string) $override['overridden_by'], 'and who decided it');

        // Surfaced on the document, and marked apart from a short date on the grid.
        $receiptId = (int) (string) $this->column($I, 'SELECT id FROM goods_receipt WHERE purchase_order_id = ?', [$orderId]);
        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#short-dated-table');
        $I->assertStringContainsString(
            'expired',
            $I->grabTextFrom('#short-dated-shortfall-' . (int) $override['id']),
            'the Finding column names which of the two it was',
        );
        $I->assertStringContainsString(
            'none in force',
            $I->grabTextFrom('#short-dated-minimum-' . (int) $override['id']),
            'and does not render an absent threshold as a met one',
        );

        $I->amOnPage('/admin/bundles/procurement/receiving');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#receipt-expired-' . $receiptId);
        $I->dontSeeElement('#receipt-short-dated-' . $receiptId);

        $this->bystanderIsUntouched($I, 'the accepted expired receipt');
    }

    /**
     * The same delivery with the box blank is REFUSED — the override is not a tick.
     *
     * Its positive control is the case above, which books the identical delivery in on a reason.
     */
    public function anAlreadyExpiredDeliveryWithNoReasonIsRefused(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $productId = $this->product($I, 'EXP-NOREASON-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '4.00');
        $expiry = $this->inDays(-45);

        // Whitespace is what a browser posts when nobody typed in the box.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '4',
                'lot_code' => 'EXP-B2',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => '   ',
            ],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('ARRIVED EXPIRED', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'whitespace is not a reason');
        $I->assertSame(0, $this->available($I, $productId));
        $I->assertSame(0, $this->overrideCount($I, $productId));

        // The positive control: same product, same order, same dead date, a real reason.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '4',
                'lot_code' => 'EXP-B2',
                'expiry' => $expiry,
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Quarantined for destruction, counted in so it can be written off',
            ],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $productId));
        $I->assertSame(1, $this->overrideCount($I, $productId));
        $I->assertSame(-45, (int) $this->overrideRow($I, $productId)['remaining_days']);

        $this->bystanderIsUntouched($I, 'the blank-reason expired cases');
    }

    /**
     * A comfortable future date books in first time, with no warning and no override row.
     *
     * The positive control for both cases above (#627). Without it they pass just as well on a
     * screen that warns about every delivery.
     */
    public function aFutureDateIsNotWarnedAbout(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $productId = $this->product($I, 'EXP-FUTURE-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '5.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'lot_code' => 'EXP-B3', 'expiry' => $this->inDays(400), 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'it booked in first time, with no reason typed anywhere');
        $I->assertSame(5, $this->available($I, $productId));
        $I->assertSame(0, $this->overrideCount($I, $productId), 'and nothing was logged as an exception');

        $this->bystanderIsUntouched($I, 'the future-dated receipt');
    }

    /**
     * TODAY is still a usable day. Yesterday is not.
     *
     * The boundary, pinned because getting it wrong is invisible: one day out in one direction
     * waves a dead pallet through, one day out in the other refuses a live one. The convention is
     * `InventoryLot::isExpired()`'s and `ExpireLotsCommand`'s — strictly before today — and
     * receiving has to agree with the sweep or stock changes status the moment it lands.
     *
     * Both halves assert on the same element, one request apart.
     */
    public function aDateOfTodayIsStillUsableAndIsNotWarnedAbout(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $todayId = $this->product($I, 'EXP-TODAY-' . uniqid());
        $todayOrder = $this->purchaseOrder($I, $todayId, '2.00');

        $this->receiveThrough($I, $todayOrder, [
            0 => ['quantity' => '2', 'lot_code' => 'EXP-TODAY', 'expiry' => $this->inDays(0), 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $todayOrder), 'the last usable day is still a usable day');
        $I->assertSame(2, $this->available($I, $todayId));
        $I->assertSame(0, $this->overrideCount($I, $todayId));

        // One day earlier, on the same element: the warning is there.
        $deadId = $this->product($I, 'EXP-YESTERDAY-' . uniqid());
        $deadOrder = $this->purchaseOrder($I, $deadId, '2.00');

        $this->receiveThrough($I, $deadOrder, [
            0 => ['quantity' => '2', 'lot_code' => 'EXP-YDAY', 'expiry' => $this->inDays(-1), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertSame(0, $this->receiptCount($I, $deadOrder));
        $I->assertSame(0, $this->available($I, $deadId));

        $this->bystanderIsUntouched($I, 'the today-versus-yesterday boundary');
    }

    // ══ 2. THE CASE THE CHANGE EXISTS FOR ═══════════════════════════════════════════════════

    /**
     * Global minimum 0 AND a per-product override of 0 — and an expired delivery still warns.
     *
     * The strongest possible statement that nobody wants a threshold on this product, and it is
     * still not permission to book in goods that are already dead. The override is read back off
     * `procurement_product_rule.minimum_shelf_life_days` by column BEFORE the delivery, so the test
     * cannot pass because the exemption silently failed to save.
     *
     * The positive control is on the same element and the same exempt product: a live date goes
     * straight through, so the exemption demonstrably still does its own job.
     */
    public function expiredStillWarnsWhenTheProductIsExemptFromTheMinimum(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $productId = $this->product($I, 'EXP-EXEMPT-' . uniqid());
        $this->setProductMinimum($I, $productId, '0');

        $I->assertSame(
            '0',
            (string) $this->column($I, 'SELECT minimum_shelf_life_days FROM procurement_product_rule WHERE product_id = ?', [$productId]),
            'the product really is exempt from the minimum, stored as 0',
        );
        $I->assertSame(
            '0',
            (string) $this->column($I, 'SELECT setting_value FROM app_setting WHERE setting_key = ?', ['procurement_minimum_shelf_life_days']),
            'and there is no global minimum either',
        );

        // ── A live date on the exempt product: nothing at all, which is what exempt means.
        $liveOrder = $this->purchaseOrder($I, $productId, '3.00');
        $this->receiveThrough($I, $liveOrder, [
            0 => ['quantity' => '3', 'lot_code' => 'EXP-EX-LIVE', 'expiry' => $this->inDays(2), 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $liveOrder), 'two days of life is fine for a product exempt from any minimum');
        $I->assertSame(3, $this->available($I, $productId));
        $I->assertSame(0, $this->overrideCount($I, $productId));

        // ── A dead date on the SAME exempt product: warned, on the same element.
        $deadOrder = $this->purchaseOrder($I, $productId, '3.00');
        $this->receiveThrough($I, $deadOrder, [
            0 => ['quantity' => '3', 'lot_code' => 'EXP-EX-DEAD', 'expiry' => $this->inDays(-7), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('ARRIVED EXPIRED', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->receiptCount($I, $deadOrder), 'exempt from a minimum is not permission to book in dead stock');
        $I->assertSame(3, $this->available($I, $productId), 'and the live receipt above is untouched');

        // And it books in on a reason, recording that no minimum was in force.
        $this->receiveThrough($I, $deadOrder, [
            0 => [
                'quantity' => '3',
                'lot_code' => 'EXP-EX-DEAD',
                'expiry' => $this->inDays(-7),
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Taken in to return; the vendor shipped the wrong pallet',
            ],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $deadOrder));
        $I->assertSame(6, $this->available($I, $productId));

        $override = $this->overrideRow($I, $productId);
        $I->assertNotNull($override);
        $I->assertSame(-7, (int) $override['remaining_days']);
        $I->assertSame(0, (int) $override['minimum_days']);
        $I->assertSame('product', (string) $override['minimum_source'], 'the exemption is what was in force, and the row says so');

        $this->bystanderIsUntouched($I, 'the exempt-product expired cases');
    }

    // ══ 3. BOTH AT ONCE ═════════════════════════════════════════════════════════════════════

    /**
     * Expired AND inside the minimum: ONE message, led by the expiry, naming the minimum after it.
     *
     * What a receiver sees is the thing being asserted, so it is asserted on the element rather
     * than inferred. `#flash-error-2` absent against `#flash-error-1` present is the "one message,
     * not two" claim — the same element family, one asserted present and the next asserted absent.
     *
     * And ONE row, carrying both facts: `remaining_days` below zero says expired, `minimum_days`
     * above it says short. Two rows would say somebody made two decisions about one pallet.
     */
    public function expiredAndInsideTheMinimumIsOneMessageLedByTheExpiry(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 90);

        $productId = $this->product($I, 'EXP-BOTH-' . uniqid());
        $orderId = $this->purchaseOrder($I, $productId, '4.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '4', 'lot_code' => 'EXP-BOTH', 'expiry' => $this->inDays(-5), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->dontSeeElement('#flash-error-2');

        $message = $I->grabTextFrom('#flash-error-1');
        $I->assertStringContainsString('ARRIVED EXPIRED', $message, 'the worse finding leads');
        $I->assertStringContainsString('It also misses', $message, 'and the minimum it also missed is named after it');
        $I->assertStringNotContainsString(
            'is short-dated',
            $message,
            'it is not ALSO reported as an ordinary short date — that headline is for a live date inside the minimum',
        );
        $I->assertSame(0, $this->receiptCount($I, $orderId));

        // One reason, one row, both facts on it.
        $this->receiveThrough($I, $orderId, [
            0 => [
                'quantity' => '4',
                'lot_code' => 'EXP-BOTH',
                'expiry' => $this->inDays(-5),
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Accepted for return; both problems noted with the driver',
            ],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $productId));
        $I->assertSame(1, $this->overrideCount($I, $productId), 'one decision is one row, not two');

        $override = $this->overrideRow($I, $productId);
        $I->assertSame(-5, (int) $override['remaining_days'], 'the row says expired');
        $I->assertSame(90, (int) $override['minimum_days'], 'and the same row says which minimum it also missed');

        // The document names both, in one cell, on one row.
        $receiptId = (int) (string) $this->column($I, 'SELECT id FROM goods_receipt WHERE purchase_order_id = ?', [$orderId]);
        $I->amOnPage('/admin/bundles/procurement/receiving/' . $receiptId);
        $I->seeResponseCodeIsSuccessful();
        $finding = $I->grabTextFrom('#short-dated-shortfall-' . (int) $override['id']);
        $I->assertStringContainsString('expired', $finding);
        $I->assertStringContainsString('short of the minimum', $finding);

        $this->bystanderIsUntouched($I, 'the expired-and-short case');
    }

    // ══ 4. A PRODUCT THAT CAPTURES NO EXPIRY IS UNAFFECTED ══════════════════════════════════

    /**
     * A product whose policy captures no expiry gets no date box, no reason box, and no warning.
     *
     * The absence and its positive control are on the SAME request and the same element names
     * (#627): the no-expiry line is `lines[0]` and carries neither `[expiry]` nor
     * `[short_dated_reason]`; the expiry-capturing line is `lines[1]` and carries both. Asserting
     * the absence alone would pass on a page that failed to render a line table at all.
     *
     * Lot mode WITHOUT `requires_expiry`, deliberately, rather than an untracked product: the row
     * still captures a batch, so what is shown to gate the check is the expiry capture and not
     * tracking in general.
     */
    public function aProductThatCapturesNoExpiryIsUnaffected(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 90);

        $noExpiryId = $this->product($I, 'EXP-NODATE-' . uniqid(), false);
        $withExpiryId = $this->product($I, 'EXP-HASDATE-' . uniqid());

        $em = $I->grabService(EntityManagerInterface::class);
        $order = $em->find(PurchaseOrder::class, $this->purchaseOrder($I, $noExpiryId, '4.00'));
        $second = (new PurchaseOrderLine())
            ->setProduct($em->find(ProductCore::class, $withExpiryId))
            ->setName('Expiry second')
            ->setSku('EXP-HASDATE')
            ->setQuantityOrdered('1.00')
            ->setUnitCost('1.0000')
            ->setSubtotal('1.00');
        $order->addLine($second);
        $em->persist($second);
        $em->flush();
        $orderId = (int) $order->getId();

        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // The no-expiry line: a batch box, and neither of the date controls.
        $I->seeElement('input[name="lines[0][lot_code]"]');
        $I->dontSeeElement('input[name="lines[0][expiry]"]');
        $I->dontSeeElement('input[name="lines[0][short_dated_reason]"]');

        // The positive control, same page, same element names one row down.
        $I->seeElement('input[name="lines[1][lot_code]"]');
        $I->seeElement('input[name="lines[1][expiry]"]');
        $I->seeElement('input[name="lines[1][short_dated_reason]"]');

        // And it books in with no date and no reason, under a 90-day minimum.
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '4', 'lot_code' => 'EXP-NODATE-B', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $noExpiryId));
        $I->assertSame(0, $this->overrideCount($I, $noExpiryId), 'no date, nothing to be wrong with');
        $I->assertNull(
            $this->column($I, 'SELECT expiry FROM inventory_lot WHERE product_id = ?', [$noExpiryId]),
            'and the batch it created carries no expiry at all',
        );

        $this->bystanderIsUntouched($I, 'the no-expiry product');
    }

    // ══ 5. THE SCAN CONSOLE WARNS AT THE SAME MOMENT ════════════════════════════════════════

    /**
     * The console says it while the pallet is still in front of the receiver.
     *
     * The same `MinimumShelfLife` object the service asks, so neither screen can let through what
     * the other would stop. Asserted on `#flash-info-1`, with the positive control being the same
     * scan one day later — the last usable day — which produces no such flash.
     */
    public function theScanConsoleWarnsOnADeadDateAsItIsScanned(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 0);

        $productId = $this->product($I, 'EXP-SCAN-' . uniqid());
        $sku = (string) $I->grabService(EntityManagerInterface::class)->find(ProductCore::class, $productId)->getSku();
        $orderId = $this->purchaseOrder($I, $productId, '4.00');

        // Scan the carton; the console asks for the batch and the date it must carry.
        $this->scanStep($I, self::SCAN . '?po=' . $orderId, ['code' => $sku, 'quantity' => '2']);
        $this->scanStep($I, null, ['code' => 'SCAN-DEAD', 'expiry' => $this->inDays(-3), 'action' => 'capture']);

        $I->seeElement('#flash-info-1');
        $I->assertStringContainsString('EXPIRED ON ARRIVAL', $I->grabTextFrom('#flash-info-1'));
        $I->assertStringContainsString('still in front of you', $I->grabTextFrom('#flash-info-1'));
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'a scan writes nothing — it changes the URL and only the URL');

        // The positive control: the same scan with the last usable day, on the same element.
        $liveOrder = $this->purchaseOrder($I, $productId, '4.00');
        $this->scanStep($I, self::SCAN . '?po=' . $liveOrder, ['code' => $sku, 'quantity' => '2']);
        $this->scanStep($I, null, ['code' => 'SCAN-LIVE', 'expiry' => $this->inDays(0), 'action' => 'capture']);

        $I->dontSeeElement('#flash-info-1');
        $I->assertSame(0, $this->receiptCount($I, $liveOrder));

        $this->bystanderIsUntouched($I, 'the scan console warnings');
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('expired-arrival-' . uniqid() . '@example.test');
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

        $region = (new FulfillmentRegion())->setName('Expired Arrival ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('EXP-01')->setSortKey(10);
        $em->persist($bin);

        $vendor = (new Vendor())->setName('Expired Arrival Supply ' . uniqid())->setCurrency('CAD')->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        $this->warehouseId = (int) $warehouse->getId();
        $this->binId = (int) $bin->getId();
        $this->vendorId = (int) $vendor->getId();

        // The row that must not change, booked in through a real receipt with a long date.
        $this->bystanderId = $this->product($I, 'EXP-BYSTANDER-' . uniqid());
        $this->receiveThrough($I, $this->purchaseOrder($I, $this->bystanderId, '5.00'), [
            0 => ['quantity' => '5', 'lot_code' => 'EXP-BY', 'expiry' => $this->inDays(900), 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(5, $this->available($I, $this->bystanderId), 'the bystander opens at five');
    }

    /**
     * One dimensional product on a lot tracking policy, with or without the expiry requirement.
     *
     * `$capturesExpiry` false gives lot mode WITHOUT `requires_expiry`, which is the product that
     * never gets a date box — used by `aProductThatCapturesNoExpiryIsUnaffected()` as the subject
     * whose only difference from the control is the expiry capture itself.
     */
    private function product(FunctionalTester $I, string $sku, bool $capturesExpiry = true): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $policy = (new TrackingPolicy())
            ->setName('Expired Arrival Policy ' . $sku)
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry($capturesExpiry)
            ->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Expired arrival ' . $sku)
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
            ->setPoNumber('PO-EXP-' . strtoupper(bin2hex(random_bytes(4))))
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

    /** A calendar date $days from today — negative for the past — as the date input posts it. */
    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    // ── driving the real screens ────────────────────────────────────────────────────────────

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

        $I->assertSame(
            (string) $days,
            (string) $this->column($I, 'SELECT setting_value FROM app_setting WHERE setting_key = ?', ['procurement_minimum_shelf_life_days']),
        );
    }

    /** Sets one product's own minimum through the real receiving-rule form. */
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
            'packing_slip' => 'PS-EXP',
            'lines' => $rows,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * One scan on the console, posted as a wedge scanner's Enter key posts it.
     *
     * Every hidden field is taken off the RENDERED form rather than reconstructed — the slip lives
     * in those fields, so a test that rebuilt them would be checking its own idea of the console's
     * state instead of the console's. Pass `$url` to start a slip, or null to continue the one the
     * previous step left on screen.
     *
     * @param array<string, string> $extra
     */
    private function scanStep(FunctionalTester $I, ?string $url, array $extra): void
    {
        if ($url !== null) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
        }

        $names = $I->grabMultiple('#scan-in-form input', 'name');
        $values = $I->grabMultiple('#scan-in-form input', 'value');

        $pairs = [];
        foreach ($names as $index => $name) {
            if ((string) $name !== '') {
                $pairs[] = urlencode((string) $name) . '=' . urlencode((string) ($values[$index] ?? ''));
            }
        }

        parse_str(implode('&', $pairs), $params);

        $I->sendFormPostRequest(self::SCAN, array_replace_recursive($params, $extra));
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
            'the bystander gained a shelf-life override during: ' . $after,
        );
    }
}
