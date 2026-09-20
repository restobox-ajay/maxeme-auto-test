<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\TrackingPolicy;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\ProductReceivingRule;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Receiving asks for the identity it enforces, on BOTH screens — conducted per #624 (item 67).
 *
 * ## What was wrong, and what the test that matters has to prove
 *
 * Serial-tracked goods had no way onto the shelf. The typed receipt form demanded one line per
 * serial and rendered exactly one row per purchase order line with no control to add another; the
 * scan console refused serials outright and said a product needing one "has to be booked in on the
 * typed form, where the field is there and is enforced" — which was false, because the typed form
 * enforced one-unit-per-serial only IF you typed a serial and never asked for one. So the only way
 * to book serialised stock in was to leave the serial blank, which is the exact outcome tracking
 * exists to prevent.
 *
 * A form that renders more rows proves nothing on its own. What is proved here is the whole path:
 * a serial-tracked product is received WITH serials through the real screens, the serials land in
 * `inventory_detail` attached to the right units, and a receipt with nothing captured is REFUSED.
 *
 * ## Every declared requirement gets a case, and every case a positive control (#627)
 *
 * Four requirements are declared, and after item 67 three of them come from ONE place. Core's
 * `tracking_policy` says what a unit carries — lot, serial, and the expiry that rides on a batch —
 * and `ProductReceivingRule` derives its answers from it. The fourth, the destination bin, is still
 * a column on `procurement_product_rule`, because a bin is where goods were PUT rather than what a
 * unit carries.
 *
 * That is the fix, and the reason the guards were dead before it: they asked the bundle's table,
 * `ruleFor()` returns an empty rule for a product with no row, and nothing in the application ever
 * created a row. So these cases set a TRACKING POLICY — which is what a real user sets, and what
 * the walkthrough set — and assert the guard fires.
 *
 * The one difference between the four is pinned rather than assumed: a blank IDENTITY may be
 * excused by the receiver declaring the units unidentified (#573's warehouse-mid-transition case,
 * which keeps its sentinel row and its worklist entry), and a blank BIN may not.
 *
 * Every refusal case is paired with the same delivery going THROUGH — otherwise "it was refused"
 * is indistinguishable from "this screen refuses everything", which is exactly the failure #627
 * exists to stop. And no assertion here is `see()` on a number: this feature is quantities, serials
 * and ids, so every numeric claim is an `assertSame` against `table.column` read back from the
 * connection.
 *
 * ## The row that must not change
 *
 * Every scenario stocks a SECOND product in the same warehouse and the same bin that no receipt
 * names. Its `inventory_detail` balance and its `product_inventory` bucket are asserted untouched
 * after every operation, refusals included — which is the half of #624 that would alone have caught
 * three of the four integrity bugs that were green in a 1200-test suite.
 *
 * ## Per-run state
 *
 * Codeception reuses ONE Cest instance across methods, so anything held on `$this` leaks from test
 * to test — it produced fifteen spurious failures for the walkthrough that filed this item. Every
 * property is reset in `_before` and every subject is created per test with a unique SKU.
 */
final class ReceivingCapturesTheIdentityCest
{
    private const FORM = '/admin/bundles/procurement/receiving/new';
    private const SCAN = '/admin/bundles/procurement/receiving/scan';

    private int $warehouseId = 0;
    private int $binId = 0;
    private int $vendorId = 0;
    private int $bystanderId = 0;

    public function _before(FunctionalTester $I): void
    {
        // Per-run state, cleared because the instance is not (see the class docblock).
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

        $this->loginAsAdmin($I);
        $this->site($I);
    }

    // ══ 1. THE WHOLE PATH: serials typed on the real form land in the database ══════════════

    /**
     * Seventy units, one serial each, pasted as a column into the form's serial block.
     *
     * This is the case the item was filed for. It proves the path rather than the form: three
     * serials go in through the real screen, three `inventory_detail` rows come back out, each
     * carrying one unit and its own serial, each attached to the right product in the right
     * warehouse — and the receipt lines carry them too.
     *
     * Seventy would prove nothing three does not; the count is asserted as a count so the block
     * expanding into N lines is what is being checked, not the number seventy.
     */
    public function aColumnOfSerialsBecomesOneStockedUnitEach(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-SER-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '3.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['serials' => "SN-AAA\nSN-BBB\nSN-CCC", 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $orderId), 'one receipt was written');
        $I->assertSame(3, $this->available($I, $productId), 'three units reached stock');

        $rows = $this->rows(
            $I,
            'SELECT serial, quantity FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? ORDER BY serial',
            [$productId, $this->warehouseId],
        );
        $I->assertCount(3, $rows, 'one detail row per serial, not one row of three');
        $I->assertSame(['SN-AAA', 'SN-BBB', 'SN-CCC'], array_column($rows, 'serial'));
        foreach ($rows as $row) {
            $I->assertSame(1, (int) (float) (string) $row['quantity'], 'a serial names one unit, so its row holds one');
        }

        $I->assertSame(
            ['SN-AAA', 'SN-BBB', 'SN-CCC'],
            array_column($this->rows(
                $I,
                'SELECT serial FROM goods_receipt_line WHERE product_id = ? ORDER BY serial',
                [$productId],
            ), 'serial'),
            'and the receipt itself records which serials arrived',
        );

        $this->bystanderIsUntouched($I, 'a column of serials booked in');
    }

    // ══ 2. REFUSAL AND ITS POSITIVE CONTROL, per requirement ════════════════════════════════

    /**
     * A serial-tracked delivery with nothing captured is REFUSED — and the same one WITH serials
     * goes through.
     *
     * The positive control is the whole test (#627). "It was refused" on its own is
     * indistinguishable from "this screen refuses everything", and the walkthrough's finding was
     * that the screen refused nothing at all.
     */
    public function aSerialTrackedReceiptWithNoSerialIsRefusedAndWithSerialsIsNot(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-SERGATE-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '2.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '2', 'location_id' => (string) $this->binId],
        ]);

        $I->see('is serialised and no serial was entered');
        $I->assertSame(0, $this->receiptCount($I, $orderId), 'no receipt was written');
        $I->assertSame(0, $this->available($I, $productId), 'and nothing reached stock');
        $this->bystanderIsUntouched($I, 'the refused serial receipt');

        // The positive control, on the same product and the same order.
        $this->receiveThrough($I, $orderId, [
            0 => ['serials' => "SN-CTRL-1\nSN-CTRL-2", 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('is serialised and no serial was entered');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the same delivery with serials IS booked in');
        $I->assertSame(2, $this->available($I, $productId));
        $this->bystanderIsUntouched($I, 'the accepted serial receipt');
    }

    /** The same rule in lot mode: the two dimensions are one gate wearing two nouns. */
    public function aLotTrackedReceiptWithNoBatchIsRefusedAndWithABatchIsNot(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-LOTGATE-' . uniqid(), ['mode' => TrackingPolicy::MODE_LOT]);
        $orderId = $this->purchaseOrder($I, $productId, '4.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '4', 'location_id' => (string) $this->binId],
        ]);

        $I->see('is lot-tracked and no batch code was entered');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(0, $this->available($I, $productId));
        $this->bystanderIsUntouched($I, 'the refused lot receipt');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '4', 'lot_code' => 'BATCH-CTRL', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('is lot-tracked and no batch code was entered');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(4, $this->available($I, $productId));
        $I->assertSame(
            'BATCH-CTRL',
            (string) $this->column($I, 'SELECT code FROM inventory_lot WHERE product_id = ?', [$productId]),
            'the batch code reached inventory_lot',
        );
        $this->bystanderIsUntouched($I, 'the accepted lot receipt');
    }

    /**
     * Expiry is its own Yes/No, independent of lot, serial and direction (#795): a `none`-mode
     * policy — no batch, no serial, nothing else captured — can still require a date, and the same
     * typed screen renders the same Expiry box for it (`needs.capturesExpiry` does not depend on
     * `needs.capturesLot`). Before #795 that date had nowhere to land: `ReceivingService::resolveLot()`
     * returns null for a line naming no batch, and nothing else on the line carried it forward, so
     * whatever was typed there was silently dropped rather than reaching `inventory_detail`.
     */
    public function anExpiryRequiredProductWithNoLotStoresItOnTheDetailRow(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-EXP-NOLOT-' . uniqid(), ['expiry' => true]);
        $orderId = $this->purchaseOrder($I, $productId, '5.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'location_id' => (string) $this->binId],
        ]);

        $I->see('needs an expiry date');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $this->bystanderIsUntouched($I, 'the refused lot-less expiry');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'expiry' => '2027-03-01', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('needs an expiry date');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertNull(
            $this->column($I, 'SELECT id FROM inventory_lot WHERE product_id = ?', [$productId]),
            'no batch was captured, so no lot row exists for the date to hide inside',
        );
        $I->assertSame(
            '2027-03-01',
            substr((string) $this->column($I, 'SELECT expiry FROM inventory_detail WHERE product_id = ?', [$productId]), 0, 10),
            'the last usable day is on the detail row itself',
        );
        $this->bystanderIsUntouched($I, 'the accepted lot-less expiry');
    }

    /**
     * A policy that says the batch must carry an expiry refuses a batch that arrived without one.
     *
     * The walkthrough's finding (5): a policy flagged "the batch must carry an expiry date" produced
     * a batch with NO expiry, and the Lots screen then printed "Does not expire" about it. The
     * positive control books the same batch WITH a date and reads the date back out of
     * `inventory_lot.expiry` by column, so the test says the date was stored rather than that the
     * page mentioned one.
     */
    public function aBatchWithoutItsRequiredExpiryIsRefusedAndWithOneIsNot(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-EXP-' . uniqid(), ['mode' => TrackingPolicy::MODE_LOT, 'expiry' => true]);
        $orderId = $this->purchaseOrder($I, $productId, '5.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'lot_code' => 'BATCH-NO-DATE', 'location_id' => (string) $this->binId],
        ]);

        $I->see('needs an expiry date');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertNull(
            $this->column($I, 'SELECT id FROM inventory_lot WHERE product_id = ?', [$productId]),
            'and no undated batch was created for it to print "does not expire" about',
        );
        $this->bystanderIsUntouched($I, 'the refused undated batch');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '5', 'lot_code' => 'BATCH-DATED', 'expiry' => '2027-03-01', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('needs an expiry date');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(
            '2027-03-01',
            substr((string) $this->column($I, 'SELECT expiry FROM inventory_lot WHERE product_id = ?', [$productId]), 0, 10),
            'the last usable day is on the batch',
        );
        $this->bystanderIsUntouched($I, 'the accepted dated batch');
    }

    /**
     * The fourth requirement, and the one that is still a column somebody set: the destination bin.
     *
     * Nothing had ever tested it. It is the only one a declaration does not excuse, which the next
     * case asserts from the other side.
     */
    public function aBinRequiredProductPutAwayNowhereIsRefusedAndWithABinIsNot(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-BIN-' . uniqid(), null, ['location' => true]);
        $orderId = $this->purchaseOrder($I, $productId, '7.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '7'],
        ]);

        $I->see('needs a destination bin');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(0, $this->available($I, $productId));
        $this->bystanderIsUntouched($I, 'the refused binless receipt');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '7', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('needs a destination bin');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(7, $this->available($I, $productId));
        $I->assertSame(
            $this->binId,
            (int) (string) $this->column($I, 'SELECT location_id FROM inventory_detail WHERE product_id = ?', [$productId]),
            'and the units are in the bin that was named',
        );
        $this->bystanderIsUntouched($I, 'the accepted binned receipt');
    }

    // ══ 3. THE DECLARATION: what it excuses and what it does not ════════════════════════════

    /**
     * Declaring the units unidentified books them in on the sentinel and puts them on the worklist.
     *
     * #573's warehouse-mid-transition case, unchanged in every respect except that somebody says it
     * rather than leaving a box blank. N unidentified units are ONE row of N wearing the label, not
     * N rows and not a suffixed value — a sentinel is a label, and it identifies nothing.
     *
     * The negative control is the same delivery WITHOUT the declaration, above.
     */
    public function decliningToGiveAnIdentityBooksTheUnitsInOnTheSentinel(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-DECL-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL, 'sentinel' => '[PENDING]']);
        $orderId = $this->purchaseOrder($I, $productId, '4.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '4', 'unidentified' => '1', 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the delivery is booked in');
        $I->assertSame(4, $this->available($I, $productId));

        $rows = $this->rows(
            $I,
            'SELECT serial, quantity, expect_resolution FROM inventory_detail WHERE product_id = ?',
            [$productId],
        );
        $I->assertCount(1, $rows, 'one row wearing the label, not four');
        $I->assertSame('[PENDING]', (string) $rows[0]['serial']);
        $I->assertSame(4, (int) (float) (string) $rows[0]['quantity']);
        $I->assertSame(1, (int) $rows[0]['expect_resolution'], 'and it is on the tracking worklist');

        $this->bystanderIsUntouched($I, 'the declared-unidentified receipt');
    }

    /**
     * The declaration excuses an identity and NOT a bin, which is the whole difference between the
     * two kinds of requirement.
     *
     * The positive control is the same POST with the bin supplied: identity still declared away,
     * bin now named, and it goes through — so the refusal is about the bin and nothing else.
     */
    public function decliningToGiveAnIdentityDoesNotExcuseARequiredBin(FunctionalTester $I): void
    {
        $productId = $this->product(
            $I,
            'RI-DECLBIN-' . uniqid(),
            ['mode' => TrackingPolicy::MODE_SERIAL],
            ['location' => true],
        );
        $orderId = $this->purchaseOrder($I, $productId, '2.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '2', 'unidentified' => '1'],
        ]);

        $I->see('needs a destination bin');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $this->bystanderIsUntouched($I, 'the refused binless declaration');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '2', 'unidentified' => '1', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('needs a destination bin');
        $I->assertSame(1, $this->receiptCount($I, $orderId), 'the declaration excused the serial, never the bin');
        $I->assertSame(2, $this->available($I, $productId));
        $this->bystanderIsUntouched($I, 'the accepted binned declaration');
    }

    // ══ 4. THE ROW OFFERS WHAT THE PRODUCT NEEDS, AND NOTHING ELSE ══════════════════════════

    /**
     * A product that tracks nothing gets no batch box and no serial box; a serial-tracked one does.
     *
     * The absence assertion and its positive control are on the SAME element name in the SAME
     * request (#627): the untracked line's `lines[0]` carries neither `[lot_code]` nor `[serials]`,
     * and the serial-tracked line's `lines[1]` carries `[serials]`. Asserting the absence alone
     * would pass just as well on a page that failed to render a line table at all.
     */
    public function aRowOffersOnlyTheIdentityItsProductCarries(FunctionalTester $I): void
    {
        $untrackedId = $this->product($I, 'RI-NOTRACK-' . uniqid(), ['mode' => TrackingPolicy::MODE_NONE]);
        $serialId = $this->product($I, 'RI-ROWSER-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);

        $em = $I->grabService(EntityManagerInterface::class);
        $order = $em->find(PurchaseOrder::class, $this->purchaseOrder($I, $untrackedId, '1.00'));
        $second = (new PurchaseOrderLine())
            ->setProduct($em->find(ProductCore::class, $serialId))
            ->setName('Identity second')
            ->setSku('RI-ROWSER')
            ->setQuantityOrdered('1.00')
            ->setUnitCost('1.0000')
            ->setSubtotal('1.00');
        $order->addLine($second);
        $em->persist($second);
        $em->flush();

        $I->amOnPage(self::FORM . '?po=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        // The product that tracks nothing: no boxes, and it SAYS so rather than being silent.
        $I->dontSeeElement('textarea[name="lines[0][serials]"]');
        $I->dontSeeElement('input[name="lines[0][lot_code]"]');
        $I->see('Tracks nothing');

        // The positive control, on the same page: the serial-tracked line has the block.
        $I->seeElement('textarea[name="lines[1][serials]"]');
        $I->dontSeeElement('input[name="lines[1][lot_code]"]');
        $I->see('a serial per unit, so one line per unit');

        $this->bystanderIsUntouched($I, 'rendering the receiving form');
    }

    /**
     * *Add more lines* grows the table and writes nothing.
     *
     * Both halves matter. The form used to render exactly one row per purchase order line with no
     * control to add another, on a screen that demanded ten — and the gesture this borrows from the
     * RFQ and vendor bill forms is `Save & add more lines`, which SAVES. A goods receipt has no
     * draft: saving one books stock. So the row count goes up and the receipt count stays at zero.
     */
    public function addMoreLinesGrowsTheTableAndBooksNothingIn(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-ADD-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '1.00');

        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $before = \count($I->grabMultiple('input[name$="[quantity]"]', 'name'));
        $I->assertSame(1, $before, 'the order has one line, so the form opens with one row');

        // A REAL quantity, deliberately. An empty row would make this pass without the add-lines
        // branch at all: the submit path would refuse the empty receipt and re-render the form with
        // spare rows, so "the table grew" would be true for the wrong reason. With a quantity on it,
        // anything other than the add-lines branch BOOKS THE DELIVERY IN — which is what the
        // receipt-count assertion below catches.
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '1', 'location_id' => (string) $this->binId],
        ], 'add_lines');

        $I->assertSame(0, $this->receiptCount($I, $orderId), 'nothing was booked in — a receipt has no draft to save');
        $I->assertSame(0, $this->available($I, $productId), 'and no stock moved');
        $I->assertSame(
            $before + 3,
            \count($I->grabMultiple('input[name$="[quantity]"]', 'name')),
            'the table grew by exactly the spare-row batch',
        );
        $I->seeInField('input[name="lines[0][quantity]"]', '1');
        $this->bystanderIsUntouched($I, 'adding more lines');
    }

    /**
     * What the receiver typed survives *Add more lines*.
     *
     * A form that loses half of itself when you ask it for another row is a form nobody presses the
     * button on twice, and the block this screen now carries is seventy pasted serials.
     */
    public function addMoreLinesKeepsWhatWasAlreadyTyped(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-KEEP-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '2.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['serials' => "SN-KEEP-1\nSN-KEEP-2", 'location_id' => (string) $this->binId],
        ], 'add_lines');

        $I->seeInField('textarea[name="lines[0][serials]"]', "SN-KEEP-1\nSN-KEEP-2");
        $I->seeInField('input[name="packing_slip"]', 'PS-RI');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $this->bystanderIsUntouched($I, 'adding lines with serials typed');
    }

    // ══ 5. SERIALS ARE UNIQUE, AND THE BLOCK AGREES WITH THE QUANTITY ═══════════════════════

    /**
     * One serial cannot be two units — in one block, or against stock already on the shelf.
     *
     * `uniq_live_serial` already refuses the second, as a driver-level unique constraint violation
     * naming an index, after the transaction has done work. A receiver holding seventy cartons and
     * a pasted manifest cannot act on that. Both refusals are named now, and both are paired with
     * the corrected delivery going through.
     */
    public function oneSerialCannotArriveTwice(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-DUP-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '4.00');

        // (a) twice in one pasted block.
        $this->receiveThrough($I, $orderId, [
            0 => ['serials' => "SN-DUP-1\nSN-DUP-2\nSN-DUP-1", 'location_id' => (string) $this->binId],
        ]);

        $I->see('appears more than once in the block of serials');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(0, $this->available($I, $productId));

        // The positive control: the same block with the repeat corrected.
        $this->receiveThrough($I, $orderId, [
            0 => ['serials' => "SN-DUP-1\nSN-DUP-2\nSN-DUP-3", 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('appears more than once in the block of serials');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(3, $this->available($I, $productId));

        // (b) again, against a unit already on the shelf.
        $secondOrderId = $this->purchaseOrder($I, $productId, '1.00');
        $this->receiveThrough($I, $secondOrderId, [
            0 => ['serials' => 'SN-DUP-2', 'location_id' => (string) $this->binId],
        ]);

        $I->see('is already in stock');
        $I->assertSame(0, $this->receiptCount($I, $secondOrderId));
        $I->assertSame(3, $this->available($I, $productId), 'and the shelf is unchanged');

        // The positive control: a serial that has not arrived before.
        $this->receiveThrough($I, $secondOrderId, [
            0 => ['serials' => 'SN-DUP-4', 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('is already in stock');
        $I->assertSame(1, $this->receiptCount($I, $secondOrderId));
        $I->assertSame(4, $this->available($I, $productId));

        $this->bystanderIsUntouched($I, 'the duplicate-serial cases');
    }

    /**
     * A quantity that disagrees with the serials beside it is refused, naming both numbers.
     *
     * One of the two is the delivery and the other is a miscount; silently letting either win is
     * how a delivery goes quietly short. The positive control is the same submission with the
     * quantity left blank, which the screen says will count the serials itself.
     */
    public function aQuantityThatDisagreesWithTheSerialsIsRefused(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-COUNT-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '3.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '3', 'serials' => "SN-C-1\nSN-C-2", 'location_id' => (string) $this->binId],
        ]);

        $I->see('serial(s) were entered');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(0, $this->available($I, $productId));

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '', 'serials' => "SN-C-1\nSN-C-2", 'location_id' => (string) $this->binId],
        ]);

        $I->dontSee('serial(s) were entered');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(2, $this->available($I, $productId), 'the serials counted themselves');

        $this->bystanderIsUntouched($I, 'the serial-count mismatch cases');
    }

    // ══ 5b. WHAT THE FORM REQUIRES WITH A PURCHASE ORDER LOADED ═════════════════════════════

    /**
     * The walkthrough's finding (3), answered: with a purchase order loaded the form has no required
     * field at all, and the same screen WITHOUT one marks vendor and warehouse required.
     *
     * Both halves are asserted here, as they were observed — and the conclusion drawn from them was
     * wrong, which is worth having in a test rather than only in a report. On the PO path those two
     * fields are not unrequired, they are **supplied**: they come from the order, are rendered
     * disabled, and submit nothing, which is a stronger guarantee than a `required` attribute. The
     * screen now SAYS so, which is what was actually missing.
     *
     * `required` cannot express "required only if this row is being received" without JavaScript,
     * and this app's baseline is that it works without any — a `required` serial on a purchase order
     * line that did not turn up makes the whole delivery unsubmittable. So the line-level
     * requirements are enforced server-side, and `aSerialTrackedReceiptWithNoSerialIsRefused...`
     * above is the proof that they now fire. What 550 units went in through was the guard, not the
     * attribute.
     */
    public function theFormStatesWhatThePurchaseOrderSuppliesAndRequiresTheRestWhenThereIsNone(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-REQ-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '1.00');

        // ── with a purchase order: the two fields are supplied, disabled, and said out loud.
        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('select[name="vendor_id"]');
        $I->dontSeeElement('select[name="warehouse_id"]');
        $I->see('cannot be changed here');

        // ── the positive control: the no-PO screen (#792: bare `self::FORM` is now the Choose PO
        //    screen, which has no vendor/warehouse fields at all — `?nopo=1` is the no-PO path)
        //    marks both required.
        $I->amOnPage(self::FORM . '?nopo=1');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="vendor_id"][required]');
        $I->seeElement('select[name="warehouse_id"][required]');

        $this->bystanderIsUntouched($I, 'rendering both header states');
    }

    /**
     * A receipt with nothing on it records nothing, and is refused rather than written empty.
     *
     * The other half of "no required field": a submission where every box is blank produces no
     * document. It was already true and nothing covered it, and it is the floor the line-level
     * guards stand on.
     */
    public function aReceiptWithEveryBoxBlankIsRefused(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-EMPTY-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '3.00');

        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => ''],
        ]);

        $I->see('A receipt with no lines records nothing');
        $I->assertSame(0, $this->receiptCount($I, $orderId));
        $I->assertSame(0, $this->available($I, $productId));

        // The positive control: the same form with the quantity filled in writes the receipt.
        $this->receiveThrough($I, $orderId, [
            0 => ['quantity' => '3'],
        ]);

        $I->dontSee('A receipt with no lines records nothing');
        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(3, $this->available($I, $productId));

        $this->bystanderIsUntouched($I, 'the empty receipt cases');
    }

    // ══ 6. THE SCAN CONSOLE ═════════════════════════════════════════════════════════════════

    /**
     * The console stops and asks for the serial, and the unit only joins the slip once it has one.
     *
     * This is the screen that used to refuse serials outright and say a product needing one "has to
     * be booked in on the typed form, where the field is there and is enforced". The barcode on a
     * serialised unit IS the serial, so a goods-in dock is its natural home.
     *
     * The intermediate state is asserted as well as the end state: after the carton scan the console
     * is WAITING and the slip still holds nothing, because a unit counted before its serial is a
     * unit that can be booked in without one.
     */
    public function theScanConsoleAsksForTheSerialAndBooksItIn(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-SCAN-' . uniqid(), ['mode' => TrackingPolicy::MODE_SERIAL]);
        $orderId = $this->purchaseOrder($I, $productId, '2.00');
        $sku = (string) $I->grabService(EntityManagerInterface::class)->find(ProductCore::class, $productId)->getSku();

        // ── scan the carton. The console asks rather than counting.
        $this->scanForm($I, self::SCAN . '?po=' . $orderId, ['code' => $sku, 'quantity' => '1']);
        $I->see('Scan the serial');
        $I->see('scan or type the serial on it next');
        $I->dontSeeElement('input[name="q[' . $productId . ']"]');

        // ── scan the serial. Now it is on the slip, as one unit.
        $this->scanForm($I, null, ['code' => 'SN-SCAN-1'], 'capture');
        $I->seeElement('input[name="s[' . $productId . '][]"]');
        $I->assertSame(
            ['SN-SCAN-1'],
            $I->grabMultiple('#scan-in-form input[name="s[' . $productId . '][]"]', 'value'),
            'the serial is carried in the slip, which is the URL',
        );

        // ── a second carton and its serial.
        $this->scanForm($I, null, ['code' => $sku, 'quantity' => '1']);
        $this->scanForm($I, null, ['code' => 'SN-SCAN-2'], 'capture');

        $I->assertSame(0, $this->receiptCount($I, $orderId), 'nothing is written until Book it in — every scan changes the URL and only the URL');

        // ── book the slip in, through the form the page rendered.
        $this->postForm($I, 'form[action$="/receiving/new"]', self::FORM);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $rows = $this->rows(
            $I,
            'SELECT serial, quantity FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? ORDER BY serial',
            [$productId, $this->warehouseId],
        );
        $I->assertSame(['SN-SCAN-1', 'SN-SCAN-2'], array_column($rows, 'serial'), 'the scanned serials are on the shelf');
        foreach ($rows as $row) {
            $I->assertSame(1, (int) (float) (string) $row['quantity']);
        }

        $this->bystanderIsUntouched($I, 'a scanned serial delivery');
    }

    /**
     * The console no longer tells a falsehood about the other screen (#627).
     *
     * The explanatory paragraphs this originally checked (including the corrected true one) were
     * cut entirely in a later pass — a screen a dock worker rereads every delivery does not need
     * prose re-explaining itself each time, and `theScanConsoleAsksForTheSerialAndBooksItIn` below
     * already proves identity capture happens here, behaviourally rather than by an assertion on a
     * sentence. What is still worth guarding is the negative: the old, false claim staying gone.
     */
    public function theScanConsoleNoLongerSendsReceiversToTheTypedForm(FunctionalTester $I): void
    {
        $I->amOnPage(self::SCAN);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSee('Batch codes, expiry dates and serials are not asked for here');
        $I->dontSee('has to be booked in on the typed form, where the field is there and is enforced');

        $this->bystanderIsUntouched($I, 'rendering the scan console');
    }

    /**
     * A product that tracks nothing is never asked for an identity — the console just counts it.
     *
     * The positive control for the case above: without it, "the console asks" is indistinguishable
     * from "the console asks about everything", which would make a scanner useless for the ordinary
     * untracked carton that is most of a goods-in dock.
     */
    public function theScanConsoleDoesNotAskAboutAProductThatTracksNothing(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-SCANNONE-' . uniqid(), ['mode' => TrackingPolicy::MODE_NONE]);
        $orderId = $this->purchaseOrder($I, $productId, '6.00');
        $sku = (string) $I->grabService(EntityManagerInterface::class)->find(ProductCore::class, $productId)->getSku();

        $this->scanForm($I, self::SCAN . '?po=' . $orderId, ['code' => $sku, 'quantity' => '6']);

        $I->dontSee('Scan the serial');
        $I->see('on the slip');
        $I->assertSame(
            ['6'],
            $I->grabMultiple('#scan-in-form input[name="q[' . $productId . ']"]', 'value'),
            'six went straight onto the slip',
        );

        $this->postForm($I, 'form[action$="/receiving/new"]', self::FORM);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(1, $this->receiptCount($I, $orderId));
        $I->assertSame(6, $this->available($I, $productId));
        $this->bystanderIsUntouched($I, 'a scanned untracked delivery');
    }

    /**
     * The console gates on resolving the delivery before it shows a scan loop at all (#707): the
     * bare screen with nothing named yet asks "which delivery is this", not "scan a carton", and
     * once a purchase order is on the URL the reverse is true.
     */
    public function theConsoleGatesOnWhichPoBeforeShowingTheScanLoop(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-GATE-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '1.00');

        $I->amOnPage(self::SCAN);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Which delivery is this?');
        $I->dontSee('Scan a carton');
        $I->dontSeeElement('#scan-in-form');

        $I->amOnPage(self::SCAN . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Scan a carton');
        $I->dontSee('Which delivery is this?');
        $I->seeElement('#scan-in-form');

        $this->bystanderIsUntouched($I, 'gating on which PO');
    }

    /**
     * Scanning the PO's own number — not its database id — resolves it and moves straight to the
     * scan loop, the same door NetSuite/D365's own receiving flows open with a printed PO's barcode.
     */
    public function scanningTheOwnPoNumberResolvesItAndMovesOn(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-PONUM-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '1.00');
        $poNumber = (string) $I->grabService(EntityManagerInterface::class)->find(PurchaseOrder::class, $orderId)->getPoNumber();

        $I->amOnPage(self::SCAN . '?po_number=' . urlencode($poNumber));
        $I->seeResponseCodeIsSuccessful();
        $I->see('Scan a carton');
        $I->see($poNumber);

        $this->bystanderIsUntouched($I, 'scanning a PO by its number');
    }

    /** A number that names no purchase order says so, and stays on "which delivery", not a 500 or a silent no-op. */
    public function scanningAnUnknownPoNumberSaysSoAndStaysOnStepOne(FunctionalTester $I): void
    {
        $I->amOnPage(self::SCAN . '?po_number=NO-SUCH-PO-NUMBER');
        $I->seeResponseCodeIsSuccessful();
        $I->see('No purchase order found');
        $I->see('Which delivery is this?');
        $I->dontSeeElement('#scan-in-form');

        $this->bystanderIsUntouched($I, 'an unknown PO number');
    }

    /**
     * Received by is captured from who is signed in, not typed — the form carries no such input any
     * more, and the receipt still records the session's own user (ReceivingController::submit()'s
     * existing fallback, unchanged).
     */
    public function receivedByComesFromTheSessionNotATypedField(FunctionalTester $I): void
    {
        $productId = $this->product($I, 'RI-RECBY-' . uniqid(), null);
        $orderId = $this->purchaseOrder($I, $productId, '4.00');
        $sku = (string) $I->grabService(EntityManagerInterface::class)->find(ProductCore::class, $productId)->getSku();

        $I->amOnPage(self::SCAN . '?po=' . $orderId);
        $I->dontSeeElement('input[name="received_by"]');
        $I->see('Received by');

        $this->scanForm($I, null, ['code' => $sku, 'quantity' => '4']);
        $this->postForm($I, 'form[action$="/receiving/new"]', self::FORM);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService(EntityManagerInterface::class);
        $receiptId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM goods_receipt WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
        $receivedBy = (string) $em->getConnection()->fetchOne('SELECT received_by FROM goods_receipt WHERE id = ?', [$receiptId]);
        $I->assertNotSame('', $receivedBy, 'received_by was captured from the session even though nothing was typed');

        $this->bystanderIsUntouched($I, 'received by falling back to the session user');
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    /**
     * One scan on the console, posted as a wedge scanner's Enter key posts it.
     *
     * Every hidden field is taken off the RENDERED form rather than reconstructed — the slip lives
     * in those fields, and a test that rebuilt them would be testing its own idea of the console's
     * state instead of the console's. Pass `$url` to start a slip, or null to continue the one the
     * previous step left on screen.
     *
     * @param array<string, string> $extra
     */
    private function scanForm(FunctionalTester $I, ?string $url, array $extra, string $action = ''): void
    {
        if ($url !== null) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
        }

        $this->postForm($I, '#scan-in-form', self::SCAN, $action === '' ? $extra : array_merge($extra, ['action' => $action]));
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * Posts every input the named form rendered, plus $extra, as a plain form POST.
     *
     * `parse_str` turns the `lines[0][serial]` bracket names back into the nested array Symfony's
     * request bag produces, so what reaches the controller is what a browser would have sent. The
     * CSRF token is one of those inputs, so this goes THROUGH the app's check rather than round it.
     * `submitForm()` cannot be used on an admin screen at all — with the `admin.localhost` Host
     * header the crawler resolves the action as an absolute URL and refuses it as external.
     *
     * @param array<string, mixed> $extra
     */
    private function postForm(FunctionalTester $I, string $selector, string $action, array $extra = []): void
    {
        $names = $I->grabMultiple($selector . ' input', 'name');
        $values = $I->grabMultiple($selector . ' input', 'value');

        $pairs = [];
        foreach ($names as $index => $name) {
            if ((string) $name !== '') {
                $pairs[] = urlencode((string) $name) . '=' . urlencode((string) ($values[$index] ?? ''));
            }
        }

        parse_str(implode('&', $pairs), $params);

        $I->sendFormPostRequest($action, array_replace_recursive($params, $extra));
    }


    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('receiving-identity-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A warehouse of this test's own, one bin in it, one vendor, and the bystander product.
     *
     * Ids rather than entities: a page request through the Symfony module can reset the container
     * and with it the EntityManager, so a fixture object held across one comes back detached.
     */
    private function site(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Receiving Identity ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('RI-01')->setSortKey(10);
        $em->persist($bin);

        $vendor = (new Vendor())->setName('Identity Supply ' . uniqid())->setCurrency('CAD')->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        $this->warehouseId = (int) $warehouse->getId();
        $this->binId = (int) $bin->getId();
        $this->vendorId = (int) $vendor->getId();

        // The row that must not change. Stocked through a real receipt so its figures have a known
        // history rather than being a number this test wrote into a column by hand.
        $this->bystanderId = $this->product($I, 'RI-BYSTANDER-' . uniqid(), null);
        $this->receiveThrough($I, $this->purchaseOrder($I, $this->bystanderId, '5.00'), [
            0 => ['quantity' => '5', 'location_id' => (string) $this->binId],
        ]);

        $I->assertSame(5, $this->available($I, $this->bystanderId), 'the bystander opens at five');
    }

    /**
     * One dimensional product, optionally on a tracking policy and optionally under a receiving rule.
     *
     * @param array{mode?: string, expiry?: bool, in?: bool, out?: bool, sentinel?: ?string}|null $policy
     * @param array{location?: bool}|null                                                         $rule
     */
    private function product(FunctionalTester $I, string $sku, ?array $policy, ?array $rule = null): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Identity ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);

        if ($policy !== null) {
            $row = (new TrackingPolicy())
                ->setName('Policy ' . $sku)
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

        // The bin is the one requirement still stored on a `procurement_product_rule` row. The other
        // three derive from the tracking policy above, which is why `$rule` takes only this key.
        if (($rule['location'] ?? false) === true) {
            $em->persist((new ProductReceivingRule())->setProduct($product)->setLocationRequired(true));
        }

        $em->persist(
            (new ProductInventory())
                ->setProduct($product)
                ->setWarehouse($em->find(\App\Entity\Warehouse::class, $this->warehouseId))
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
            ->setPoNumber('PO-RI-' . strtoupper(bin2hex(random_bytes(4))))
            ->setVendor($em->find(Vendor::class, $this->vendorId))
            ->setWarehouse($em->find(\App\Entity\Warehouse::class, $this->warehouseId))
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

    // ── driving the real screens ────────────────────────────────────────────────────────────

    /**
     * Posts the receiving form exactly as a browser with scripting off does.
     *
     * The CSRF token is scraped off the rendered form and posted back, so this goes THROUGH the
     * app's CSRF check rather than round it. `submitForm()` cannot be used on an admin screen at
     * all — with the `admin.localhost` Host header the crawler resolves the form action as an
     * absolute URL and the module refuses it as external.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function receiveThrough(FunctionalTester $I, int $orderId, array $rows, string $action = 'receive'): void
    {
        $I->amOnPage(self::FORM . '?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // The purchase order line each row settles, taken off the PAGE rather than looked up: what
        // this posts is then what the rendered form posts, hidden fields included, which is the
        // point of conducting the test through the screen at all. The order's own lines render
        // first, so document order is row order.
        foreach ($I->grabMultiple('input[name$="[purchase_order_line_id]"]', 'value') as $index => $lineId) {
            if (isset($rows[$index])) {
                $rows[$index]['purchase_order_line_id'] = (string) $lineId;
            }
        }

        $I->sendFormPostRequest(self::FORM, [
            '_token' => $I->csrfToken(),
            'purchase_order_id' => (string) $orderId,
            'action' => $action,
            'packing_slip' => 'PS-RI',
            'lines' => $rows,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** One scan on the console, posted as the scanner's Enter key posts it. */
    private function scanStep(FunctionalTester $I, string $url, array $params): void
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest(self::SCAN, array_merge(['_token' => $I->csrfToken()], $params));
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

    /** The bystander is exactly where site() left it — asserted after every operation, refusals too. */
    private function bystanderIsUntouched(FunctionalTester $I, string $after): void
    {
        $I->assertSame(5, $this->available($I, $this->bystanderId), "the bystander's detail balance moved during: " . $after);
        $I->assertSame(
            '5',
            (string) (int) (float) (string) $this->column(
                $I,
                'SELECT received_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
                [$this->bystanderId, $this->warehouseId],
            ),
            "the bystander's received bucket moved during: " . $after,
        );
    }
}
