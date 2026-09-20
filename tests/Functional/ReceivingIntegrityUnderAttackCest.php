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
 * Receiving, attacked rather than demonstrated (#624).
 *
 * The suite that shipped with this code is green, so nothing here repeats what it already pins.
 * Every case below is a gap that suite leaves — checked against it file by file before it was
 * written — and every one of them moves stock, which is why receiving was the priority.
 *
 * ## What each case is FOR
 *
 * | case                                           | what it attacks                                   |
 * |------------------------------------------------|---------------------------------------------------|
 * | `twoLinesOfOneDelivery...`                     | `assertSerialsAreDistinct()` ACROSS lines, not in one block |
 * | `aSerialAlreadyInStockInADifferentCase...`     | `serialIsAlreadyOnTheShelf()` vs its own stated case rule |
 * | `blankAndWhitespaceOnly...`                    | `SerialList::parse()` — never referenced by any existing test |
 * | `aSerialBlockPastTheLimit...`                  | `SerialList::LIMIT` — likewise untested            |
 * | `aSecondOrderLineForTheSameProduct...`         | `ReceivingController::orderLineFor()` — the #660 shape, inbound |
 * | `twoRowsOfOneSubmissionSettleTwoOrderLines...` | the same binding WITHIN one submission, which no state on disk can distinguish |
 * | `receivingAgainstACancelledPurchaseOrder...`   | `refusesReceipts()` on Cancelled — only Draft/Closed were tested |
 * | `aNegativeQuantity...`                         | the controller's blank-row `continue`              |
 * | `theMinimumShelfLifeBoundary...`               | `remaining < minimum` at exactly the minimum       |
 * | `aDoubleSubmittedReceivingForm...`             | outer idempotency, through the screen              |
 *
 * ## House rules this follows
 *
 *  - plain form POSTs, no `X-Requested-With`, so the no-JS guarantee is exercised for free;
 *  - every assertion is a `table.column` read back from the database AFTER the operation, never an
 *    entity fetched before it;
 *  - a BYSTANDER product is asserted unchanged after every single case, refusals included. That is
 *    the cheap half and it is the half that catches integrity bugs;
 *  - the #550 invariant and `PRAGMA foreign_key_check` are asserted after every test in `_after`,
 *    so a case that books stock correctly but corrupts a bucket still fails.
 *
 * The cases marked **DEFECT** below were written as the correct expectation rather than as the
 * behaviour of the day, and they failed when they were written: a test that asserts a bug is a test
 * that has to be rewritten when the bug is fixed. The production defects they name have since been
 * corrected, so they pass — and each is now the regression guard for the fix that made it pass.
 */
final class ReceivingIntegrityUnderAttackCest
{
    private const FORM = '/admin/bundles/procurement/receiving/new';
    private const SETTINGS = '/admin/bundles/procurement/settings';

    private int $warehouseId = 0;
    private int $otherWarehouseId = 0;
    private int $binId = 0;
    private int $vendorId = 0;
    private int $bystanderId = 0;

    public function _before(FunctionalTester $I): void
    {
        $this->warehouseId = 0;
        $this->otherWarehouseId = 0;
        $this->binId = 0;
        $this->vendorId = 0;
        $this->bystanderId = 0;

        $em = $I->grabService(EntityManagerInterface::class);
        $bundles = $I->grabService(BundleStatusRepository::class);
        foreach (['ProcurementBundle', 'InventoryDepthBundle'] as $source) {
            $em->persist($bundles->ensureBySource($source)->setStatus(BundleStatus::STATUS_ACTIVE));
        }
        $em->flush();

        // The global minimum shelf life is process-global configuration read through a cache. A
        // previous test in this class sets it; without this the next one inherits it.
        $I->grabService(AppSettings::class)->clearCache();

        $this->loginAsAdmin($I);
        $this->site($I);
    }

    /**
     * The invariant and the foreign keys, after EVERY case including the ones that refuse.
     *
     * In `_after` rather than at the end of each test on purpose: a refusal is exactly where a
     * half-applied movement would hide, and a per-test call would be the one thing an author forgets
     * on the case that needed it.
     */
    public function _after(FunctionalTester $I): void
    {
        $this->invariantHolds($I);
        $this->foreignKeysAreClean($I);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Serials
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The same serial on two DIFFERENT lines of one delivery.
     *
     * The shipped suite covers a serial repeated inside ONE pasted block — that refusal is the
     * controller's `serialBlockRefusal()`, which only ever sees one row. Two rows each carrying a
     * clean block, sharing a value between them, reaches a different guard entirely
     * (`ReceivingService::assertSerialsAreDistinct()`) and nothing exercised it.
     *
     * A receiver produces this by pressing *Add more lines* and pasting the second half of a
     * manifest that overlapped the first.
     */
    public function twoLinesOfOneDeliveryCannotClaimTheSameSerial(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-TWIN-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);

        // TWO order lines, so both posted rows are rows the form renders for a line of the order.
        // A spare row would reach the same guard, but it would also trip the separate re-render
        // defect that `aFilledSpareRowSurvivesBeingReRendered()` below is about, and a test should
        // fail for the reason it names.
        $orderId = $this->purchaseOrder($I, [[$productId, '2.00'], [$productId, '2.00']]);

        // Each block is internally clean, so the per-row check passes both. SN-TWIN-B is the
        // overlap, and only the across-lines guard can see it.
        $this->postRows($I, $orderId, [
            0 => ['quantity' => '', 'serials' => "SN-TWIN-A\nSN-TWIN-B", 'location_id' => (string) $this->binId],
            1 => ['quantity' => '', 'serials' => "SN-TWIN-B\nSN-TWIN-C", 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('again on line', $I->grabTextFrom('#flash-error-1'));
        $I->assertStringContainsString('SN-TWIN-B', $I->grabTextFrom('#flash-error-1'));

        $I->assertSame(0, $this->receiptCount($I, $orderId), 'nothing was booked in');
        $I->assertSame(0, $this->available($I, $productId), 'and no unit reached the shelf');
        foreach ($this->orderLineIds($I, $orderId) as $lineId) {
            $I->assertSame('0.00', $this->receivedOn($I, $lineId), 'purchase_order_line.quantity_received stayed 0.00');
        }

        // The positive control: the SAME two rows with the overlap corrected go in cleanly, so the
        // refusal above is about the duplicate and not about the two-row shape.
        $this->postRows($I, $orderId, [
            0 => ['quantity' => '', 'serials' => "SN-TWIN-A\nSN-TWIN-B", 'location_id' => (string) $this->binId],
            1 => ['quantity' => '', 'serials' => "SN-TWIN-C\nSN-TWIN-D", 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $productId), 'four units, one per serial');
        $I->assertSame(
            ['SN-TWIN-A', 'SN-TWIN-B', 'SN-TWIN-C', 'SN-TWIN-D'],
            $this->liveSerials($I, $productId),
            'inventory_detail.serial holds each one exactly once',
        );

        $this->bystanderIsUntouched($I, 'after the across-line duplicate was refused and the corrected delivery booked in');
    }

    /**
     * **DEFECT.** A serial already on the shelf, arriving again in a different case.
     *
     * `ReceivingService::assertSerialsAreDistinct()` states its rule once, for both halves of the
     * job it does: *"Compared case-insensitively. `abc-1` and `ABC-1` on two cartons are one serial
     * typed twice far more often than two units, and two rows a later scan cannot tell apart is
     * worse than a correction made in front of the goods."*
     *
     * The in-delivery half honours that — it keys `$seen` on `mb_strtolower($serial)`. The
     * on-the-shelf half does not: `serialIsAlreadyOnTheShelf()` asks
     * `d.serial = :serial`, which SQLite compares with its default BINARY collation, and
     * `uniq_live_serial` is a plain index over `(product_id, serial)` so the database does not
     * catch it either. The stated rule therefore holds within one POST and is silently dropped
     * across two.
     *
     * The consequence is the exact harm the docblock names: two live `inventory_detail` rows for
     * one physical unit, which a later scan cannot tell apart.
     */
    public function aSerialAlreadyInStockInADifferentCaseIsStillTheSameUnit(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-CASE-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);

        // First, the control that proves the rule IS case-insensitive where it was implemented:
        // both spellings inside one delivery are refused.
        $withinOne = $this->purchaseOrder($I, [[$productId, '2.00']]);
        $this->postRows($I, $withinOne, [
            0 => ['quantity' => '', 'serials' => "SN-CASE-99\nsn-case-99", 'location_id' => (string) $this->binId],
        ]);
        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('more than once', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->available($I, $productId), 'the same-posting pair booked nothing');

        // Now the same pair split across two deliveries. The unit is on the shelf after the first.
        $first = $this->purchaseOrder($I, [[$productId, '1.00']]);
        $this->postRows($I, $first, [
            0 => ['quantity' => '1', 'serials' => 'SN-CASE-88', 'location_id' => (string) $this->binId],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(['SN-CASE-88'], $this->liveSerials($I, $productId), 'the first unit is live');

        // The second delivery names the same serial in lower case. One serial is one unit, and that
        // unit has not left the building.
        $second = $this->purchaseOrder($I, [[$productId, '1.00']]);
        $this->postRows($I, $second, [
            0 => ['quantity' => '1', 'serials' => 'sn-case-88', 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(
            1,
            $this->liveSerialRowCount($I, $productId, 'sn-case-88'),
            'DEFECT: inventory_detail holds two live rows for one serial — SN-CASE-88 and sn-case-88 — '
            . 'because serialIsAlreadyOnTheShelf() compares case-SENSITIVELY while the rule it enforces, '
            . 'and the in-delivery half of the same method, are case-insensitive',
        );

        $this->bystanderIsUntouched($I, 'after the case-differing serial was offered twice');
    }

    /**
     * Blank lines, whitespace-only lines and a trailing newline in a pasted serial block.
     *
     * `SerialList::parse()` is not referenced by one test in the suite that shipped with it, and
     * this is the shape a real paste has: a column copied out of a spreadsheet brings empty cells
     * and a trailing newline with it. A blank that survived parsing would become a receipt line for
     * one unit with an empty serial, which is a unit nobody can ever find again.
     */
    public function blankAndWhitespaceOnlyLinesInASerialBlockAreNotSerials(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-BLANK-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, [[$productId, '2.00']]);

        // A blank line, a spaces-only line, a tab-only line, padding around a real value, and a
        // trailing newline. Two real serials in all. The quantity box is left empty so the serials
        // count themselves, which is the documented way to avoid arguing with the box.
        $this->postRows($I, $orderId, [
            0 => [
                'quantity' => '',
                'serials' => "SN-BLANK-1\n\n   \n\t\n  SN-BLANK-2  \n",
                'location_id' => (string) $this->binId,
            ],
        ]);

        $I->dontSeeElement('#flash-error-1');

        $I->assertSame(
            ['SN-BLANK-1', 'SN-BLANK-2'],
            $this->liveSerials($I, $productId),
            'inventory_detail.serial holds the two real values, trimmed',
        );
        $I->assertSame(2, $this->available($I, $productId), 'two units, not five');
        $I->assertSame(
            2,
            (int) $this->column($I, 'SELECT COUNT(*) FROM goods_receipt_line WHERE product_id = ?', [$productId]),
            'and two goods_receipt_line rows, one per real serial',
        );
        $I->assertSame(
            0,
            (int) $this->column(
                $I,
                "SELECT COUNT(*) FROM goods_receipt_line WHERE product_id = ? AND (serial IS NULL OR TRIM(serial) = '')",
                [$productId],
            ),
            'no receipt line was created for a blank',
        );

        $this->bystanderIsUntouched($I, 'after a padded serial block was booked in');
    }

    /**
     * A pasted block past `SerialList::LIMIT`, which no test references.
     *
     * The ceiling exists so one POST cannot become a hundred thousand receipt lines and movement
     * rows. What matters is that crossing it refuses the delivery WHOLE rather than truncating it:
     * a truncated serial list is a delivery that is quietly short, and the short half is on the
     * shelf under nobody's name.
     */
    public function aSerialBlockPastTheLimitIsRefusedAndBooksNothing(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-LIMIT-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, [[$productId, '2.00']]);

        $serials = [];
        for ($i = 1; $i <= 501; $i++) {
            $serials[] = sprintf('SN-LIMIT-%04d', $i);
        }

        $this->postRows($I, $orderId, [
            0 => ['quantity' => '', 'serials' => implode("\n", $serials), 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('at most', $I->grabTextFrom('#flash-error-1'));

        $I->assertSame(0, $this->receiptCount($I, $orderId), 'the delivery was refused whole');
        $I->assertSame(0, $this->available($I, $productId), 'not one of the 501 was booked in');
        $I->assertSame(
            0,
            (int) $this->column($I, 'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ?', [$productId]),
            'and no detail row was left behind by a partial apply',
        );

        // The control: the same screen, the same product, a block it will take.
        $this->postRows($I, $orderId, [
            0 => ['quantity' => '', 'serials' => "SN-LIMIT-0001\nSN-LIMIT-0002", 'location_id' => (string) $this->binId],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(2, $this->available($I, $productId), 'the block under the ceiling books in');

        $this->bystanderIsUntouched($I, 'after an oversized block was refused');
    }

    /**
     * **DEFECT.** A spare row with a product chosen crashes the screen the moment it is re-rendered.
     *
     * `_receive_row.html.twig:48` hands the REDISPLAY value straight to the shared product field:
     *
     *     selected: row.product_id|default(null),
     *
     * `$draft` is documented as "everything is kept as the strings it was posted as … This is a
     * redisplay, not a parse", so `row.product_id` is the string `"2"`. The partial it is given to
     * expects an entity — `templates/admin/_partials/product_field.html.twig:50` evaluates
     * `selected.id == product.id` — and Twig cannot take `.id` off a string, so the render dies with
     *
     *     Impossible to access an attribute ("id") on a string variable ("2")
     *
     * and the response is a 500.
     *
     * Every path that re-renders this form is affected, and they are the ordinary ones: pressing
     * *Add more lines* after picking a product on a spare row, and ANY refusal on a delivery that
     * has a spare row in it. Both are reached with JavaScript off, which is this app's baseline.
     *
     * It also defeats the thing the re-render exists for. `ReceivingController::submit()` says so
     * itself: *"Re-rendered, not redirected. A redirect discards the form, and this screen now
     * carries blocks of seventy pasted serials — throwing those away to report a missing expiry
     * date would make the refusal more expensive than the mistake. Everything typed comes back."*
     * A 500 throws away strictly more than the redirect it was chosen over.
     *
     * The shipped `addMoreLinesKeepsWhatWasAlreadyTyped` misses it because it types a packing slip
     * and serials on the ORDER's own row, which renders through the `orderLine` branch and never
     * reaches the product field.
     */
    public function aFilledSpareRowSurvivesBeingReRendered(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-SPARE-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, [[$productId, '4.00']]);

        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $lineIds = $this->orderLineIds($I, $orderId);
        $token = $I->csrfToken();

        // ── the control: *Add more lines* with the spare row left EMPTY. This is the shape the
        //    shipped suite covers, and it works.
        $I->sendFormPostRequest(self::FORM, [
            '_token' => $token,
            'purchase_order_id' => (string) $orderId,
            'action' => 'add_lines',
            'packing_slip' => 'PS-ADV',
            'lines' => [
                0 => ['purchase_order_line_id' => (string) $lineIds[0], 'quantity' => '2', 'location_id' => (string) $this->binId],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="lines[0][quantity]"]');

        // ── the same gesture, with a product actually chosen on the spare row. A receiver does this
        //    to add a second product to the delivery and then presses *Add more lines* again for a
        //    third. Nothing is being booked in; this is just the form coming back.
        $I->sendFormPostRequest(self::FORM, [
            '_token' => $I->csrfToken(),
            'purchase_order_id' => (string) $orderId,
            'action' => 'add_lines',
            'packing_slip' => 'PS-ADV',
            'lines' => [
                0 => ['purchase_order_line_id' => (string) $lineIds[0], 'quantity' => '2', 'location_id' => (string) $this->binId],
                1 => ['product_id' => (string) $productId, 'quantity' => '2', 'location_id' => (string) $this->binId],
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->assertStringNotContainsString(
            'Impossible to access an attribute',
            $I->grabPageSource(),
            'DEFECT: _receive_row.html.twig:48 passes the posted product_id STRING to '
            . 'product_field.html.twig:50, which reads `selected.id` off it, so re-rendering a spare '
            . 'row with a product chosen returns a 500 instead of the form',
        );

        // What the receiver typed must come back, which is the whole reason this path re-renders.
        $I->seeElement('input[name="lines[0][quantity]"]');
        $I->assertSame(
            0,
            $this->receiptCount($I, $orderId),
            '*Add more lines* books nothing in, crash or no crash',
        );
        $I->assertSame(0, $this->available($I, $productId), 'and no stock moved');

        $this->bystanderIsUntouched($I, 'after a spare row was re-rendered');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Purchase order lines
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * **DEFECT.** A purchase order with two lines for the SAME product, received in two deliveries.
     *
     * This is GitHub #660's shape, on the way IN. `AutomaticSourcePicker::plan()` reads current
     * stock per call and so lets two lines for one product both draw from the same row;
     * `ReceivingController::orderLineFor()` has the same defect in the other direction —
     *
     *     foreach ($order->getLines() as $line) {
     *         if ($line->getProduct() === $product) {
     *             return $line;          // the FIRST match, every time
     *         }
     *     }
     *
     * — so a spare row naming a product is credited to the first order line carrying it, whether or
     * not that line has already been received in full. It cannot see what an earlier delivery, or an
     * earlier row of the same submission, already settled.
     *
     * Two lines for one product is ordinary: two price breaks, two delivery dates, two cost centres.
     * A receiver reaching this presses *Add more lines* for the second carton, which is exactly what
     * that button is for.
     *
     * The correct outcome is that the second delivery settles the line that is still outstanding and
     * the order reads Received. What happens instead is that the first line is credited twice, the
     * second line never at all, and the order stays Partially Received forever with goods nobody can
     * account for.
     */
    public function aSecondOrderLineForTheSameProductGetsItsOwnCredit(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-TWOLINE-' . uniqid(), null);

        // One product, two lines — 10 then 5.
        $orderId = $this->purchaseOrder($I, [[$productId, '10.00'], [$productId, '5.00']]);
        $lineIds = $this->orderLineIds($I, $orderId);
        $I->assertCount(2, $lineIds, 'the order really does carry two lines for one product');

        // ── delivery one settles the first line exactly, through the row the form renders for it.
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '10', 'location_id' => (string) $this->binId],
        ]);
        $I->dontSeeElement('#flash-error-1');

        $I->assertSame('10.00', $this->receivedOn($I, $lineIds[0]), 'line one is settled in full');
        $I->assertSame('0.00', $this->receivedOn($I, $lineIds[1]), 'and line two has had nothing yet');
        $I->assertSame(10, $this->available($I, $productId));

        // ── delivery two: the remaining five arrive. A spare row names the PRODUCT, because a spare
        //    row is what *Add more lines* gives and it has no order line to point at.
        $this->postRows($I, $orderId, [
            0 => ['quantity' => '5', 'product_id' => (string) $productId, 'location_id' => (string) $this->binId],
        ]);
        $I->dontSeeElement('#flash-error-1');

        // The stock is not in doubt — fifteen units really did arrive and really are on the shelf.
        $I->assertSame(15, $this->available($I, $productId), 'all fifteen units are on the shelf');

        // All three figures are read BEFORE the first assertion, so whichever one fails reports the
        // whole observed state rather than just its own half of it.
        $lineOne = $this->receivedOn($I, $lineIds[0]);
        $lineTwo = $this->receivedOn($I, $lineIds[1]);
        $status = (string) $this->column($I, 'SELECT status FROM purchase_order WHERE id = ?', [$orderId]);

        $observed = sprintf(
            ' [observed: purchase_order_line.quantity_received line one = %s, line two = %s;'
            . ' purchase_order.status = %s]',
            $lineOne,
            $lineTwo,
            $status,
        );

        $I->assertSame(
            '5.00',
            $lineTwo,
            'DEFECT: the second delivery was credited to the FIRST order line — orderLineFor() returns '
            . 'the first line carrying the product and cannot see that it is already complete, so the '
            . 'still-outstanding line two is never settled' . $observed,
        );
        $I->assertSame(
            '10.00',
            $lineOne,
            'DEFECT: the already-complete first line was credited a second time' . $observed,
        );
        $I->assertSame(
            'Received',
            $status,
            'DEFECT: every ordered unit arrived, so the order is Received — it reads Partially Received '
            . 'because line two still shows nothing against it' . $observed,
        );

        $this->bystanderIsUntouched($I, 'after two deliveries against a two-line order');
    }

    /**
     * **DEFECT.** TWO rows of ONE submission, one product, two order lines.
     *
     * The case above covers two SEPARATE deliveries, and a per-line outstanding check is enough to
     * pass it: by the time the second delivery is posted, the first has been credited to the
     * database and the first line reads as complete.
     *
     * This is the half that check cannot see. `purchase_order_line.quantity_received` is credited by
     * ReceivingService AFTER the controller's row loop has finished, so every row in one submission
     * reads the same pre-submission state — both rows find line one outstanding, and both are
     * credited to it. That is exactly what #660 means by planning against current state while
     * ignoring what this operation has already consumed, and no state on disk can distinguish the
     * two rows while the loop is still running.
     *
     * A receiver produces it with *Add more lines*: two cartons of one product off one truck, on one
     * packing slip, against an order that bought them on two lines.
     */
    public function twoRowsOfOneSubmissionSettleTwoOrderLinesForOneProduct(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-ONEPOST-' . uniqid(), null);

        $orderId = $this->purchaseOrder($I, [[$productId, '10.00'], [$productId, '5.00']]);
        $lineIds = $this->orderLineIds($I, $orderId);
        $I->assertCount(2, $lineIds, 'the order really does carry two lines for one product');

        // BOTH rows name the PRODUCT and neither names an order line — which is what two spare rows
        // from *Add more lines* post, and the only shape that reaches the binding under test. One
        // POST, one packing slip, one receipt.
        $this->postRows($I, $orderId, [
            0 => ['quantity' => '10', 'product_id' => (string) $productId, 'location_id' => (string) $this->binId],
            1 => ['quantity' => '5', 'product_id' => (string) $productId, 'location_id' => (string) $this->binId],
        ]);

        $I->dontSeeElement('#flash-error-1');

        // The stock is not in doubt — fifteen units arrived and fifteen are on the shelf. Where the
        // credit landed is the whole question.
        $I->assertSame(15, $this->available($I, $productId), 'all fifteen units are on the shelf');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'and one submission wrote one goods_receipt');

        // Read before the first assertion so whichever one fails reports the whole observed state.
        $lineOne = $this->receivedOn($I, $lineIds[0]);
        $lineTwo = $this->receivedOn($I, $lineIds[1]);
        $status = (string) $this->column($I, 'SELECT status FROM purchase_order WHERE id = ?', [$orderId]);

        $observed = sprintf(
            ' [observed: purchase_order_line.quantity_received line one = %s, line two = %s;'
            . ' purchase_order.status = %s]',
            $lineOne,
            $lineTwo,
            $status,
        );

        $I->assertSame(
            '10.00',
            $lineOne,
            'DEFECT: the second row of the SAME submission was credited to line one as well — the '
            . 'credit happens after the row loop, so both rows read a line one that still looked '
            . 'outstanding' . $observed,
        );
        $I->assertSame(
            '5.00',
            $lineTwo,
            'DEFECT: line two was never settled, though the row that should have settled it was in '
            . 'the same submission' . $observed,
        );
        $I->assertSame(
            'Received',
            $status,
            'DEFECT: every ordered unit arrived in one delivery, so the order is Received' . $observed,
        );

        $this->bystanderIsUntouched($I, 'after two rows of one submission settled two order lines');
    }

    /**
     * A Cancelled purchase order refuses receipts.
     *
     * `PurchaseOrderStatus::refusesReceipts()` names Draft and Cancelled. The shipped suite covers
     * Draft (refused) and Closed (deliberately still accepted) and never covers Cancelled, so half
     * of that method has never been executed by a test.
     */
    public function receivingAgainstACancelledPurchaseOrderIsRefused(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-CANX-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, [[$productId, '6.00']]);

        $em = $I->grabService(EntityManagerInterface::class);
        $order = $em->find(PurchaseOrder::class, $orderId);
        $order->setStatus('Cancelled', DocumentActor::system(), 'Purchase order cancelled: Vendor could not supply');
        $em->flush();
        $em->clear();

        $I->assertSame(
            'Cancelled',
            (string) $this->column($I, 'SELECT status FROM purchase_order WHERE id = ?', [$orderId]),
            'the order is Cancelled before the goods are offered',
        );

        $this->postRows($I, $orderId, [
            0 => ['quantity' => '6', 'product_id' => (string) $productId, 'location_id' => (string) $this->binId],
        ]);

        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('Cancelled', $I->grabTextFrom('#flash-error-1'));

        $I->assertSame(0, $this->receiptCount($I, $orderId), 'no receipt was written against it');
        $I->assertSame(0, $this->available($I, $productId), 'and no stock moved');
        $I->assertSame(
            'Cancelled',
            (string) $this->column($I, 'SELECT status FROM purchase_order WHERE id = ?', [$orderId]),
            'and the refusal did not re-derive the status of a cancelled order',
        );

        // The control: the same goods, the same screen, against an order that is live.
        $liveOrder = $this->purchaseOrder($I, [[$productId, '6.00']]);
        $this->receiveThrough($I, $liveOrder, [
            0 => ['quantity' => '6', 'location_id' => (string) $this->binId],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(6, $this->available($I, $productId), 'so it was the cancellation that refused them');

        $this->bystanderIsUntouched($I, 'after a delivery against a cancelled order');
    }

    /**
     * A negative quantity typed into a row beside a good one.
     *
     * `ReceivingService::assertLineIsBookable()` refuses a non-positive quantity by name — but the
     * controller never lets a negative row reach it. Its blank-row guard is
     *
     *     if ($serials === [] && ($quantity === '' || QuantityScale::compare($quantity, 0) <= 0)) {
     *         continue;
     *     }
     *
     * so `-5` is treated exactly like an empty box and DROPPED, silently, while the rest of the
     * delivery books in and reports success. A blank row genuinely is a row nobody filled in; `-5`
     * is a row somebody filled in wrongly, and the two should not be the same submission.
     *
     * This pins what actually happens, so that the difference is visible and deliberate rather than
     * discovered on a dock. The stock assertions are the point: whatever the screen says, the
     * negative row must never move stock in either direction.
     */
    public function aNegativeQuantityNeverMovesStockInEitherDirection(FunctionalTester $I): void
    {
        $negativeId = $this->product($I, 'ADV-NEG-' . uniqid(), null);
        $goodId = $this->product($I, 'ADV-POS-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, [[$negativeId, '5.00'], [$goodId, '3.00']]);
        $lineIds = $this->orderLineIds($I, $orderId);

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '-5', 'location_id' => (string) $this->binId],
            1 => ['quantity' => '3', 'location_id' => (string) $this->binId],
        ]);

        // The good line went in. That is the positive control that makes the rest meaningful: the
        // submission was processed rather than rejected wholesale.
        $I->assertSame(3, $this->available($I, $goodId), 'the good row booked in');
        $I->assertSame('3.00', $this->receivedOn($I, $lineIds[1]));

        // The negative row moved nothing, in either direction. This is the assertion that matters:
        // a negative that reached the ledger would REMOVE stock through a receiving screen.
        $I->assertSame(0, $this->available($I, $negativeId), 'the negative row took nothing off the shelf');
        $I->assertSame(
            '0.00',
            $this->receivedOn($I, $lineIds[0]),
            'and credited nothing to its purchase order line',
        );
        $I->assertSame(
            0,
            (int) $this->column($I, 'SELECT COUNT(*) FROM goods_receipt_line WHERE product_id = ?', [$negativeId]),
            'the negative row produced no goods_receipt_line at all — it was dropped as though blank, '
            . 'not refused by name',
        );
        $I->assertSame(
            0,
            (int) $this->column(
                $I,
                'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND quantity < 0',
                [$negativeId],
            ),
            'and left no negative detail row behind',
        );

        $this->bystanderIsUntouched($I, 'after a negative quantity was typed beside a good row');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Shelf life
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The minimum shelf life boundary, at exactly the minimum and one day either side.
     *
     * `MinimumShelfLife::findingFor()` computes `$short = $minimum['days'] > 0 && $remaining <
     * $minimum['days']` — a STRICT comparison, so a delivery with exactly the minimum number of days
     * left clears it. Both shipped shelf-life Cests test values far from the boundary (+3, +20, +30,
     * +120, +400 against minimums of 90 and 365) and neither lands on it, so the one line that
     * decides "is 90 days enough when the minimum is 90" has never been executed either way.
     *
     * An off-by-one here is a buyer's contract: "we need 90 days on it" means 90 is acceptable.
     */
    public function theMinimumShelfLifeBoundaryAcceptsExactlyTheMinimum(FunctionalTester $I): void
    {
        $this->setGlobalMinimum($I, 90);

        $atTheLine = $this->product($I, 'ADV-SL-AT-' . uniqid(), ['mode' => TrackingPolicy::MODE_LOT, 'expiry' => true]);
        $oneInside = $this->product($I, 'ADV-SL-IN-' . uniqid(), ['mode' => TrackingPolicy::MODE_LOT, 'expiry' => true]);
        $oneOutside = $this->product($I, 'ADV-SL-OUT-' . uniqid(), ['mode' => TrackingPolicy::MODE_LOT, 'expiry' => true]);

        // ── exactly 90 days left against a 90-day minimum: accepted, and NOT recorded as an override.
        $order = $this->purchaseOrder($I, [[$atTheLine, '2.00']]);
        $this->receiveThrough($I, $order, [
            0 => [
                'quantity' => '2',
                'lot_code' => 'LOT-AT-LINE',
                'expiry' => $this->inDays(90),
                'location_id' => (string) $this->binId,
            ],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(2, $this->available($I, $atTheLine), 'exactly the minimum is enough');
        $I->assertSame(
            0,
            $this->overrideCount($I, $atTheLine),
            'and nothing was recorded as a short-dated exception, because nothing was short',
        );

        // ── one day inside the minimum: refused until a reason is given.
        $order = $this->purchaseOrder($I, [[$oneInside, '2.00']]);
        $this->receiveThrough($I, $order, [
            0 => [
                'quantity' => '2',
                'lot_code' => 'LOT-ONE-INSIDE',
                'expiry' => $this->inDays(89),
                'location_id' => (string) $this->binId,
            ],
        ]);
        $I->seeElement('#flash-error-1');
        $I->assertStringContainsString('short-dated', $I->grabTextFrom('#flash-error-1'));
        $I->assertSame(0, $this->available($I, $oneInside), 'one day inside the minimum is refused');

        // With the reason, the same line books in and the figures are recorded against it.
        $this->receiveThrough($I, $order, [
            0 => [
                'quantity' => '2',
                'lot_code' => 'LOT-ONE-INSIDE',
                'expiry' => $this->inDays(89),
                'location_id' => (string) $this->binId,
                'short_dated_reason' => 'Customer accepted the shorter date in writing',
            ],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(2, $this->available($I, $oneInside));

        $override = $this->overrideRow($I, $oneInside);
        $I->assertNotNull($override, 'the decision was recorded');
        $I->assertSame(90, (int) $override['minimum_days'], 'procurement_short_dated_receipt.minimum_days');
        $I->assertSame(89, (int) $override['remaining_days'], 'and remaining_days is one short of it');
        $I->assertSame('global', (string) $override['minimum_source']);

        // ── one day outside: accepted with nothing recorded, the mirror of the case above.
        $order = $this->purchaseOrder($I, [[$oneOutside, '2.00']]);
        $this->receiveThrough($I, $order, [
            0 => [
                'quantity' => '2',
                'lot_code' => 'LOT-ONE-OUTSIDE',
                'expiry' => $this->inDays(91),
                'location_id' => (string) $this->binId,
            ],
        ]);
        $I->dontSeeElement('#flash-error-1');
        $I->assertSame(2, $this->available($I, $oneOutside));
        $I->assertSame(0, $this->overrideCount($I, $oneOutside));

        $this->bystanderIsUntouched($I, 'after three deliveries either side of the minimum');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Idempotency
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The receiving form, double-submitted — the same POST body twice.
     *
     * Idempotency is unit-tested by handing `ReceivingService` the same `clientOperationId` twice.
     * Nothing tests it through the SCREEN, and the screen is where it happens: the key is
     * `hash('xxh128', $_token)`, so a double-submitted form is two identical bodies carrying one
     * token — a receiver double-clicking *Book it in*, or pressing it again when the page is slow.
     * With no JavaScript to disable the button, that is the ordinary case rather than the exotic one.
     *
     * Posting the identical body twice is the only way to reach it: the token is re-randomised on
     * every render, so re-submitting a freshly scraped token would be a DIFFERENT operation and
     * would correctly book the goods in twice.
     */
    public function aDoubleSubmittedReceivingFormBooksTheGoodsInOnce(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'ADV-DOUBLE-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, [[$productId, '5.00']]);

        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        $lineIds = $this->orderLineIds($I, $orderId);

        // One body, captured once, posted twice — byte for byte, token included.
        $body = [
            '_token' => $I->csrfToken(),
            'purchase_order_id' => (string) $orderId,
            'action' => 'receive',
            'packing_slip' => 'PS-DOUBLE',
            'lines' => [
                0 => [
                    'purchase_order_line_id' => (string) $lineIds[0],
                    'quantity' => '5',
                    'location_id' => (string) $this->binId,
                ],
            ],
        ];

        $I->sendFormPostRequest(self::FORM, $body);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#flash-error-1');

        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the first submission wrote one receipt');
        $I->assertSame(5, $this->available($I, $productId));

        // The second press of the same button.
        $I->sendFormPostRequest(self::FORM, $body);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            1,
            $this->receiptCount($I, $orderId),
            'goods_receipt still holds ONE row for this order — the resubmission was recognised',
        );
        $I->assertSame(
            5,
            $this->available($I, $productId),
            'and the five units were booked in once, not ten',
        );
        $I->assertSame(
            '5.00',
            $this->receivedOn($I, $lineIds[0]),
            'purchase_order_line.quantity_received was credited once',
        );
        $I->assertSame(
            'Received',
            (string) $this->column($I, 'SELECT status FROM purchase_order WHERE id = ?', [$orderId]),
            'and the order derived Received from five of five, not from ten',
        );

        $this->bystanderIsUntouched($I, 'after the form was double-submitted');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Fixtures
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);

        // The QA admin identity, created fresh in the throwaway test database. No real account is
        // read or written: the Functional schema is rebuilt from entity metadata every run.
        $admin = (new AdminUser())->setEmail('qa-admin+' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'qa-local-only-2026'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function site(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $regions = $I->grabService(WarehouseFulfillmentRegionService::class);

        $region = (new FulfillmentRegion())->setName('Adversary ' . uniqid());
        $em->persist($region);
        $warehouse = $regions->createWarehouseForRegion($region, 'BC', 'CA');

        // A second site, so a bin at another warehouse is a real row rather than a hypothetical.
        $otherRegion = (new FulfillmentRegion())->setName('Adversary Far ' . uniqid());
        $em->persist($otherRegion);
        $otherWarehouse = $regions->createWarehouseForRegion($otherRegion, 'AB', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('ADV-01')->setSortKey(10);
        $em->persist($bin);

        $vendor = (new Vendor())->setName('Adversary Supply ' . uniqid())->setCurrency('CAD')->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        $this->warehouseId = (int) $warehouse->getId();
        $this->otherWarehouseId = (int) $otherWarehouse->getId();
        $this->binId = (int) $bin->getId();
        $this->vendorId = (int) $vendor->getId();

        // The row that must NOT change. Stocked through a real receipt, so its buckets have a
        // genuine history rather than a number written into a column by this test.
        $this->bystanderId = $this->product($I, 'ADV-BYSTANDER-' . uniqid(), null);
        $this->receiveThrough($I, $this->purchaseOrder($I, [[$this->bystanderId, '7.00']]), [
            0 => ['quantity' => '7', 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(7, $this->available($I, $this->bystanderId), 'the bystander opens at seven');
    }

    /** @param array{mode?: string, expiry?: bool, in?: bool, out?: bool, sentinel?: ?string}|null $policy */
    private function product(FunctionalTester $I, string $sku, ?array $policy): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Adversary ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);

        if ($policy !== null) {
            $row = (new TrackingPolicy())
                ->setName('Adversary policy ' . $sku)
                ->setMode($policy['mode'] ?? TrackingPolicy::MODE_NONE)
                ->setRequiresExpiry($policy['expiry'] ?? false)
                ->setTrackIn($policy['in'] ?? true)
                ->setTrackOut($policy['out'] ?? false)
                ->setSentinelIn($policy['sentinel'] ?? TrackingPolicy::DEFAULT_SENTINEL);
            $em->persist($row);
            $product->setTrackingPolicy($row);
        }

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

    /**
     * An issued purchase order, one line per entry.
     *
     * Takes a LIST of lines rather than one product and one quantity, because two lines for the
     * same product is a case this file exists to attack and a single-line helper cannot express it.
     *
     * @param list<array{0: int, 1: string}> $lines product id, quantity ordered
     */
    private function purchaseOrder(FunctionalTester $I, array $lines): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-ADV-' . strtoupper(bin2hex(random_bytes(5))))
            ->setVendor($em->find(Vendor::class, $this->vendorId))
            ->setWarehouse($em->find(Warehouse::class, $this->warehouseId))
            ->setExpectedDate('2026-10-01');
        $em->persist($order);

        foreach ($lines as $index => [$productId, $quantity]) {
            $product = $em->find(ProductCore::class, $productId);
            $line = (new PurchaseOrderLine())
                ->setProduct($product)
                ->setName((string) $product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantityOrdered($quantity)
                ->setUnitCost('1.0000')
                ->setSubtotal($quantity)
                ->setSortOrder($index);
            $order->addLine($line);
            $em->persist($line);
        }

        $em->flush();

        $order->setStatus('Issued', DocumentActor::system());
        $em->flush();
        $em->clear();

        return (int) $order->getId();
    }

    /**
     * Posts the receiving form, taking each row's order line off the RENDERED page.
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
            'packing_slip' => 'PS-ADV',
            'lines' => $rows,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * Posts rows EXACTLY as given, binding nothing to the rendered order lines.
     *
     * This is what a spare row from *Add more lines* posts: a `product_id` and no
     * `purchase_order_line_id`. `receiveThrough()` cannot express it, because it fills the hidden
     * field in from the page.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function postRows(FunctionalTester $I, int $orderId, array $rows): void
    {
        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // Row 0 still settles the order's own first line when the caller did not name a product,
        // which is what the rendered form does for it.
        $rendered = $I->grabMultiple('input[name$="[purchase_order_line_id]"]', 'value');
        foreach ($rows as $index => $row) {
            if (!isset($row['product_id']) && isset($rendered[$index])) {
                $rows[$index]['purchase_order_line_id'] = (string) $rendered[$index];
            }
        }

        $I->sendFormPostRequest(self::FORM, [
            '_token' => $I->csrfToken(),
            'purchase_order_id' => (string) $orderId,
            'action' => 'receive',
            'packing_slip' => 'PS-ADV',
            'lines' => $rows,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

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
            'app_setting.setting_value holds the minimum this test is about',
        );
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Reads — every one of them goes back to the database
    // ─────────────────────────────────────────────────────────────────────────────────────────

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

    private function available(FunctionalTester $I, int $productId): int
    {
        return (int) (float) (string) $this->column(
            $I,
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = 'available'",
            [$productId, $this->warehouseId],
        );
    }

    /** The serials this product currently has live rows for, in value order. @return list<string> */
    private function liveSerials(FunctionalTester $I, int $productId): array
    {
        $rows = $this->rows(
            $I,
            "SELECT serial FROM inventory_detail WHERE product_id = ? AND serial IS NOT NULL AND quantity > 0 ORDER BY serial",
            [$productId],
        );

        return array_map(static fn (array $row): string => (string) $row['serial'], $rows);
    }

    /** Live rows for one serial, compared the way the rule says it is compared: without case. */
    private function liveSerialRowCount(FunctionalTester $I, int $productId, string $serial): int
    {
        return (int) $this->column(
            $I,
            'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND LOWER(serial) = LOWER(?) AND quantity > 0',
            [$productId, $serial],
        );
    }

    private function receiptCount(FunctionalTester $I, int $orderId): int
    {
        return (int) $this->column($I, 'SELECT COUNT(*) FROM goods_receipt WHERE purchase_order_id = ?', [$orderId]);
    }

    /** @return list<int> */
    private function orderLineIds(FunctionalTester $I, int $orderId): array
    {
        $rows = $this->rows(
            $I,
            'SELECT id FROM purchase_order_line WHERE purchase_order_id = ? ORDER BY sort_order, id',
            [$orderId],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * `purchase_order_line.quantity_received`, normalised to two decimal places.
     *
     * SQLite hands a NUMERIC column back in whatever form it stored — `'10'` for a value the
     * application wrote as `'10.00'` — so comparing the raw string would fail on formatting rather
     * than on quantity, and would keep failing after a real defect was fixed. The NUMBER is the
     * fact being asserted.
     */
    private function receivedOn(FunctionalTester $I, int $lineId): string
    {
        $raw = (string) $this->column($I, 'SELECT quantity_received FROM purchase_order_line WHERE id = ?', [$lineId]);

        return number_format((float) $raw, 2, '.', '');
    }

    private function overrideCount(FunctionalTester $I, int $productId): int
    {
        return (int) $this->column(
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
            . ' JOIN goods_receipt_line l ON l.id = sd.receipt_line_id WHERE l.product_id = ?'
            . ' ORDER BY sd.id DESC',
            [$productId],
        );

        return $rows[0] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  Standing checks
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The bystander, which no case in this file touches and every case must leave alone.
     *
     * Both its physical total and its `received` bucket, because a bug that credited the wrong row
     * would move exactly one of them.
     */
    private function bystanderIsUntouched(FunctionalTester $I, string $after): void
    {
        $I->assertSame(7, $this->available($I, $this->bystanderId), 'the bystander still holds seven ' . $after);
        $I->assertSame(
            '7',
            (string) $this->column(
                $I,
                'SELECT received_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
                [$this->bystanderId, $this->warehouseId],
            ),
            'and product_inventory.received_quantity is unchanged ' . $after,
        );
    }

    /**
     * #550's invariant, for every dimensional product in this test's warehouse:
     *
     *     quantity + received + transfer_in − transfer_out − write_off − quarantine − SUM(sold)
     *       == SUM(available)
     *
     * Taken verbatim from `InventoryDetailRecalcCommand`, which is the command that checks it in
     * production. Asserted from OUTSIDE the code that maintains it, after every case — a receipt
     * that lands the right units in the right bin and drifts a bucket by one is a defect this is
     * the only assertion here that would catch.
     */
    private function invariantHolds(FunctionalTester $I): void
    {
        $rows = $this->rows(
            $I,
            'SELECT pi.product_id, pi.quantity, pi.received_quantity, pi.transfer_in_quantity,'
            . ' pi.transfer_out_quantity, pi.write_off_quantity, pi.quarantine_quantity'
            . ' FROM product_inventory pi'
            . ' JOIN product_core p ON p.id = pi.product_id'
            . " WHERE pi.warehouse_id = ? AND p.inventory_mode = 'dimensional'",
            [$this->warehouseId],
        );

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];

            $available = (int) (float) (string) $this->column(
                $I,
                "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail"
                . " WHERE product_id = ? AND warehouse_id = ? AND status = 'available'",
                [$productId, $this->warehouseId],
            );
            $sold = (int) (float) (string) $this->column(
                $I,
                "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail"
                . " WHERE product_id = ? AND warehouse_id = ? AND status = 'sold'",
                [$productId, $this->warehouseId],
            );

            $claimed = (int) $row['quantity']
                + (int) $row['received_quantity']
                + (int) $row['transfer_in_quantity']
                - (int) $row['transfer_out_quantity']
                - (int) $row['write_off_quantity']
                - (int) $row['quarantine_quantity']
                - $sold;

            $I->assertSame(
                $available,
                $claimed,
                sprintf(
                    'the #550 invariant drifted for product_core.id=%d: product_inventory claims %d, '
                    . 'inventory_detail holds %d available',
                    $productId,
                    $claimed,
                    $available,
                ),
            );
        }
    }

    /**
     * `PRAGMA foreign_key_check`, which returns one row per violation and nothing at all when the
     * database is sound. Receiving writes across seven tables in one transaction, and a row left
     * pointing at a parent that was rolled back is exactly what a refusal could leave behind.
     */
    private function foreignKeysAreClean(FunctionalTester $I): void
    {
        $violations = $this->rows($I, 'PRAGMA foreign_key_check');

        $I->assertSame([], $violations, 'PRAGMA foreign_key_check reported orphaned rows');
    }
}
