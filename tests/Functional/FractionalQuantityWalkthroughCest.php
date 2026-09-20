<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The sibling of {@see WarehouseToInvoiceWalkthroughCest}: the same conducted buy-and-sell chain,
 * driven end to end with FRACTIONAL quantities, asserting the exact decimal answer at every step.
 *
 * ## What this test is for, and what would make it worthless
 *
 * Fractional quantities are settled policy here (#601, and the `ShipmentQuantity` docblock says so
 * in as many words). This test does not relitigate that. It exists to FIND the places where the
 * stack does not honour it — where a whole-number assumption has been sprinkled in and justified
 * locally.
 *
 * Every expectation below is the decimal answer the arithmetic gives, written as a decimal literal.
 * Not what the code returns today. Not a tolerance, not a rounded comparison, not a truncated value
 * dressed up as an expectation. **A failing assertion here is the deliverable**, and tuning any
 * number in this file so that a step goes green would destroy the only thing the file is for. If a
 * layer turns 0.4 into 0, this test must say `expected '0.4000', got '0'` and stop.
 *
 * The single exception is the serial-tracked case ({@see self::aSerialTrackedLineIsTheOneWholeUnitRule()}),
 * where a whole number is the CORRECT answer: a serial identifies one physical unit, so there is no
 * such thing as 0.4 of one. Lot and expiry are quantities like any other and get no such exemption —
 * a lot is a batch a quantity came from, not a thing you can only have whole.
 *
 * ## One chained story, not a bag of interesting numbers
 *
 * Every quantity is derived from the one before it, so the closing balance only reconciles if every
 * single step kept its fraction:
 *
 * ```
 *   PO orders                2.5
 *   receive                  0.4   ->  2.1 outstanding
 *   receive                  2.1   ->  0 outstanding, 2.5 on the shelf
 *   invoice                  1.5   ->  1.0 never leaves the shelf
 *   ship                     0.6   ->  Partially Shipped, 0.9 still owed
 *   ship                     0.9   ->  fully shipped, the invoice completes
 *   void the 0.9                   ->  back to Partially Shipped, 0.9 back on the shelf
 * ```
 *
 * `0.4 + 2.1 = 2.5` and `0.6 + 0.9 = 1.5` are both exact in `NUMERIC(14, 4)` and both are the
 * classic binary-float trap, so the "0.1 + 0.2 is not 0.3" failure mode is INSIDE the story rather
 * than bolted on beside it: if anything reconciles as a float, the second receipt does not close the
 * purchase order line and the second shipment does not close the invoice line. And because each
 * figure is the previous one less something, a truncation at the receipt shows up as a wrong balance
 * at the shipment — catching a rounding error far from where it happened, which no single assertion
 * placed at the ends can do.
 *
 * Full four-decimal precision is covered separately ({@see self::aFourDecimalQuantityKeepsAllFourDecimals()})
 * so that 12.3456 does not muddy a story a reader is meant to be able to check by eye.
 *
 * ## Why the story is told twice
 *
 * {@see self::theFractionalChainSurvivesEveryStep()} drives the whole thing through the real screens
 * from the purchase order forward. When an upstream layer refuses or truncates, that method stops
 * there — which is correct, but it then measures nothing downstream of the refusal, and "we do not
 * know" is a poor answer to "where else does this break".
 *
 * So {@see self::theSameChainResumedFromASeededShelf()} tells the SAME story from the invoice
 * onward, over a shelf whose fractional quantity was placed by direct SQL rather than by the
 * receiving screen. That is a fixture, said out loud, not a workaround: it exists only so the
 * shipment, completion and void layers are actually exercised with a fraction rather than merely
 * blocked by one, and everything it asserts is asserted against the real screens and the real
 * services exactly as the first method does.
 *
 * ## House rules this file follows
 *
 * Conducted throughout (#624): real screens, plain form POSTs with no JavaScript, the CSRF token
 * scraped from the rendered form, its own data, and every read taken back out of the database rather
 * than off an entity fetched beforehand. Every numeric assertion is against a DB column by raw SQL
 * (#627) — never a bare `see()`, because `see('0.4')` would match `10.4000` — and the untracked line
 * rides along the whole way as the row that must NOT have moved.
 */
final class FractionalQuantityWalkthroughCest
{
    private const REGION = 'Fractional Region';

    // ------------------------------------------------------------------ the chain, in decimal

    /** What the purchase order line orders. */
    private const ORDERED = '2.5';

    /** The first, partial receipt. */
    private const RECEIPT_ONE = '0.4';

    /** The second receipt: exactly what the first left outstanding, so the two close the line. */
    private const RECEIPT_TWO = '2.1';

    /** What the invoice bills — less than is on the shelf, so 1.0 must never move. */
    private const INVOICED = '1.5';

    /** The first, partial shipment. */
    private const SHIPMENT_ONE = '0.6';

    /** The second shipment: exactly what the first left owing, so the two close the invoice line. */
    private const SHIPMENT_TWO = '0.9';

    /** The four-decimal case, kept out of the chain above on purpose. */
    private const FOUR_DECIMALS = '12.3456';

    private ?Warehouse $warehouse = null;

    private ?WarehouseLocation $bin = null;

    private ?Vendor $vendor = null;

    private ?Company $company = null;

    /** @var array<string, ProductCore> shape ('lot'|'untracked'|'serial') => product */
    private array $products = [];

    /** The lot code the lot-tracked line's stock sits under. */
    private string $lotCode = '';

    public function _before(FunctionalTester $I): void
    {
        $this->warehouse = null;
        $this->bin = null;
        $this->vendor = null;
        $this->company = null;
        $this->products = [];
        $this->lotCode = '';
    }

    // ===================================================================== the chain, front door

    /**
     * The whole chain through the real screens, from the purchase order forward.
     *
     * Steps 1-4 of the brief: a fractional-friendly base unit, a fractional ordered quantity, a
     * fractional goods receipt, and a partial receipt followed by a second one summing exactly to
     * what was ordered. Every balance is read back out of the database as the column holds it.
     */
    public function theFractionalChainSurvivesEveryStep(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);
        $this->seedVendor($I);

        // ---- Step 1 + 2: a purchase order for 2.5 of a product based in a unit that has halves.
        $poId = $this->createAndIssuePurchaseOrder($I);

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '2.5000',
                $this->poLineColumn($I, $poId, $this->products[$shape], 'quantity_ordered'),
                sprintf('%s: purchase_order_line.quantity_ordered must hold the 2.5 that was ordered', $shape),
            );
            // The positive control for the absence assertions further down: nothing has been
            // received yet, so this column is genuinely zero rather than merely unwritten.
            $I->assertSame(
                '0.0000',
                $this->poLineColumn($I, $poId, $this->products[$shape], 'quantity_received'),
                sprintf('%s: nothing has arrived yet', $shape),
            );
            $I->assertSame(
                '0.0000',
                $this->detailTotal($I, $this->products[$shape], InventoryDetail::STATUS_AVAILABLE),
                sprintf('%s: a purchase order is a promise — no stock has moved', $shape),
            );
        }

        // ---- Step 3: a fractional goods receipt. 0.4 of 2.5.
        $this->receive($I, $poId, self::RECEIPT_ONE);

        foreach (['lot', 'untracked'] as $shape) {
            $product = $this->products[$shape];

            $I->assertSame(
                '0.4000',
                $this->detailTotal($I, $product, InventoryDetail::STATUS_AVAILABLE),
                sprintf('%s: receiving 0.4 must put 0.4 on the shelf — inventory_detail.quantity', $shape),
            );
            $I->assertSame(
                '0.4000',
                $this->productInventoryColumn($I, $product, 'received_quantity'),
                sprintf('%s: product_inventory.received_quantity must hold the 0.4 received', $shape),
            );
            $I->assertSame(
                '0.4000',
                $this->poLineColumn($I, $poId, $product, 'quantity_received'),
                sprintf('%s: the purchase order line must be credited the 0.4 that arrived', $shape),
            );
            $I->assertSame(
                '2.1000',
                $this->poLineOutstanding($I, $poId, $product),
                sprintf('%s: 2.5 ordered less 0.4 received leaves 2.1 outstanding', $shape),
            );
        }

        // ---- Step 4: the second receipt — exactly the 2.1 outstanding, closing the line at 2.5.
        $this->receive($I, $poId, self::RECEIPT_TWO);

        foreach (['lot', 'untracked'] as $shape) {
            $product = $this->products[$shape];

            $I->assertSame(
                '2.5000',
                $this->detailTotal($I, $product, InventoryDetail::STATUS_AVAILABLE),
                sprintf('%s: 0.4 + 2.1 is 2.5 on the shelf — and is exactly 2.5, not 2.4999…', $shape),
            );
            $I->assertSame(
                '2.5000',
                $this->poLineColumn($I, $poId, $product, 'quantity_received'),
                sprintf('%s: the purchase order line must be fully credited', $shape),
            );
            $I->assertSame(
                '0.0000',
                $this->poLineOutstanding($I, $poId, $product),
                sprintf('%s: 0.4 + 2.1 must close the line — a float comparison leaves it open forever', $shape),
            );
        }

        // The rest of the chain — invoice, ship, complete, void — is told over the same numbers by
        // theSameChainResumedFromASeededShelf(), which does not depend on receiving to put the
        // fraction on the shelf. See this class's docblock.
    }

    // ================================================== the chain, resumed over a seeded shelf

    /**
     * The same story from the invoice onward: 2.5 on the shelf, bill 1.5, ship 0.6 then 0.9, void
     * the 0.9.
     *
     * The 2.5 is placed by {@see self::placeOnShelf()} — a direct SQL fixture, because the column is
     * `NUMERIC(14, 4)` but the entity behind it is an `int`, so there is no way to write a fraction
     * into it through the ORM at all. Everything after that is the real screens.
     */
    public function theSameChainResumedFromASeededShelf(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);

        foreach (['lot', 'untracked'] as $shape) {
            $this->placeOnShelf($I, $this->products[$shape], self::ORDERED);

            // The fixture is worthless if it did not take, so it is asserted like anything else.
            $I->assertSame(
                '2.5000',
                $this->detailTotal($I, $this->products[$shape], InventoryDetail::STATUS_AVAILABLE),
                sprintf('guard: the %s shelf fixture must actually hold 2.5', $shape),
            );
        }

        // ---- Step 5: an invoice line for 1.5 of the 2.5 on the shelf.
        $orderId = $this->seedAcceptAndConvertEstimate($I, self::INVOICED);
        $invoiceId = $this->createAndIssueInvoice($I, $orderId, self::INVOICED);

        foreach (['lot', 'untracked'] as $shape) {
            $product = $this->products[$shape];

            // The quote carried 1.5; converting it to an order must not round the figure on the way.
            $I->assertSame(
                '1.5000',
                $this->salesOrderLineQuantity($I, $orderId, $product),
                sprintf('%s: sales_order_line.quantity must carry the 1.5 the quote was accepted at', $shape),
            );
            $I->assertSame(
                '1.5000',
                $this->invoiceLineColumn($I, $invoiceId, $product, 'quantity'),
                sprintf('%s: invoice_line.quantity must hold the 1.5 billed', $shape),
            );
            // Billing is paperwork. The shelf must not have moved.
            $I->assertSame(
                '2.5000',
                $this->detailTotal($I, $product, InventoryDetail::STATUS_AVAILABLE),
                sprintf('%s: issuing an invoice must not take anything off the shelf', $shape),
            );
        }

        // ---- Step 6: a fractional pick on the LOT-tracked line. 0.6 of the 1.5 billed, off the lot
        // it was received under.
        //
        // A lot names the batch a quantity came from. It is not a thing that can only be had whole,
        // and nothing about a batch code makes 0.6 kg of it a different kind of number from 0.6 kg
        // of anything else. So the expectation here is the same decimal answer the untracked line
        // gets in step 7 — deliberately NOT a refusal. Writing today's refusal into this expectation
        // is what would turn this file from a measurement into a guard on the bug.
        $this->ship($I, $invoiceId, 'lot', self::SHIPMENT_ONE);

        $lot = $this->products['lot'];
        $I->assertSame(
            '0.6000',
            $this->shipmentLineTotal($I, $invoiceId, $lot),
            'lot: shipment_line.quantity must hold the 0.6 picked from the lot',
        );
        $I->assertSame(
            '1.9000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_AVAILABLE),
            'lot: 2.5 on the shelf less the 0.6 that left is 1.9',
        );
        $I->assertSame(
            '0.6000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_SOLD),
            'lot: the 0.6 that left must land in the sold rows, not vanish',
        );

        // The row that must NOT have changed: shipping the lot line is no reason for the untracked
        // line's stock to move a gram. Without this, "1.9 on the shelf" above could pass for the
        // wrong reason — a shipment that decremented whatever it found first.
        $I->assertSame(
            '2.5000',
            $this->detailTotal($I, $this->products['untracked'], InventoryDetail::STATUS_AVAILABLE),
            'untracked: nothing has shipped for this line yet, so its shelf must be untouched',
        );
        $I->assertSame(
            '0.0000',
            $this->shipmentLineTotal($I, $invoiceId, $this->products['untracked']),
            'untracked: and it must have no shipment line yet',
        );

        // ---- Step 7: the same 0.6, on the untracked line. Shipped as its own shipment rather than
        // alongside the lot line, because `assertRequestIsShippable()` refuses a request WHOLE — one
        // refused line would take the other down with it and this step would measure step 6 twice.
        $this->ship($I, $invoiceId, 'untracked', self::SHIPMENT_ONE);

        $I->assertSame(
            '0.6000',
            $this->shipmentLineTotal($I, $invoiceId, $this->products['untracked']),
            'untracked: shipment_line.quantity must hold the 0.6 that shipped',
        );

        $I->assertSame(
            'Partially Shipped',
            $this->invoiceColumn($I, $invoiceId, 'shipping_status'),
            '0.6 of 1.5 is a part shipment — an invoice that reads Shipped or Not Shipped here has rounded',
        );

        // ---- Step 9: the second shipment on each line — exactly the 0.9 still owed, which closes
        // the invoice line at 1.5 and the invoice with it.
        $secondRound = [
            $this->ship($I, $invoiceId, 'lot', self::SHIPMENT_TWO),
            $this->ship($I, $invoiceId, 'untracked', self::SHIPMENT_TWO),
        ];

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '1.5000',
                $this->shipmentLineTotal($I, $invoiceId, $this->products[$shape]),
                sprintf('%s: 0.6 + 0.9 shipped is 1.5 — exactly what was billed', $shape),
            );
        }

        $I->assertSame(
            '1.0000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_AVAILABLE),
            'lot: 2.5 received less 1.5 shipped leaves 1.0 on the shelf',
        );
        $I->assertSame(
            '1.5000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_SOLD),
            'lot: the whole 1.5 billed must now be sold',
        );

        $I->assertSame(
            'Shipped',
            $this->invoiceColumn($I, $invoiceId, 'shipping_status'),
            '0.6 + 0.9 is 1.5 — the line is fully shipped. A float comparison leaves it Partially Shipped forever',
        );
        $I->assertSame(
            'Completed',
            $this->invoiceColumn($I, $invoiceId, 'status'),
            'a fully shipped invoice completes itself (InvoiceCompletedWhenFullyShippedSubscriber)',
        );

        // The approved -> shipped bucket relabelling that completion fires.
        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '1.5000',
                $this->productInventoryColumn($I, $this->products[$shape], 'shipped_quantity'),
                sprintf('%s: completing the invoice must move the whole 1.5 into the shipped bucket', $shape),
            );
            $I->assertSame(
                '0.0000',
                $this->productInventoryColumn($I, $this->products[$shape], 'approved_quantity'),
                sprintf('%s: and must leave nothing behind in approved — this is a relabelling', $shape),
            );
        }

        // ---- Step 10: void both of the 0.9 shipments — by id, so this measures the void and not
        // some earlier shipment that happened to be the most recent one left standing.
        $this->voidShipments($I, $secondRound);

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '0.6000',
                $this->shipmentLineTotal($I, $invoiceId, $this->products[$shape]),
                sprintf('%s: voiding the 0.9 leaves only the first 0.6 shipped', $shape),
            );
        }

        $I->assertSame(
            '1.9000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_AVAILABLE),
            'lot: voiding the 0.9 puts 0.9 back — 1.0 + 0.9 is 1.9',
        );
        $I->assertSame(
            '0.6000',
            $this->detailTotal($I, $lot, InventoryDetail::STATUS_SOLD),
            'lot: only the first shipment is still sold',
        );

        $I->assertSame(
            'Partially Shipped',
            $this->invoiceColumn($I, $invoiceId, 'shipping_status'),
            'voiding the second shipment takes the invoice back to the part-shipped state the first one left it in',
        );
    }

    // =========================================================== the four-decimal case, on its own

    /**
     * 12.3456 — every one of `NUMERIC(14, 4)`'s four decimal places in use at once.
     *
     * Kept out of the chained story deliberately: the chain is meant to be checkable by eye, and a
     * figure whose point is its precision rather than its arithmetic would only obscure that. What
     * it adds is the question the chain's one-decimal figures cannot ask — whether a column declared
     * to four places actually keeps four.
     */
    public function aFourDecimalQuantityKeepsAllFourDecimals(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);
        $this->seedVendor($I);

        $poId = $this->createAndIssuePurchaseOrder($I, self::FOUR_DECIMALS);

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '12.3456',
                $this->poLineColumn($I, $poId, $this->products[$shape], 'quantity_ordered'),
                sprintf(
                    '%s: purchase_order_line.quantity_ordered is NUMERIC(14, 4) and must keep all four places',
                    $shape,
                ),
            );
        }

        // The invoice half needs stock to bill against and a lot for the tracked line's mandatory
        // outbound capture, so the shelf is seeded with the same figure — see placeOnShelf()'s
        // docblock for why a fraction cannot be put there through the ORM.
        foreach (['lot', 'untracked'] as $shape) {
            $this->placeOnShelf($I, $this->products[$shape], self::FOUR_DECIMALS);

            $I->assertSame(
                '12.3456',
                $this->detailTotal($I, $this->products[$shape], InventoryDetail::STATUS_AVAILABLE),
                sprintf('%s: inventory_detail.quantity is NUMERIC(14, 4) and must keep all four places', $shape),
            );
        }

        $orderId = $this->seedAcceptAndConvertEstimate($I, self::FOUR_DECIMALS);
        $invoiceId = $this->createAndIssueInvoice($I, $orderId, self::FOUR_DECIMALS);

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                '12.3456',
                $this->salesOrderLineQuantity($I, $orderId, $this->products[$shape]),
                sprintf('%s: sales_order_line.quantity is NUMERIC(14, 4) and must keep all four places', $shape),
            );
            $I->assertSame(
                '12.3456',
                $this->invoiceLineColumn($I, $invoiceId, $this->products[$shape], 'quantity'),
                sprintf('%s: invoice_line.quantity is NUMERIC(14, 4) and must keep all four places', $shape),
            );
        }
    }

    // ==================================================== the one place whole units are correct

    /**
     * A serial-tracked line, and the ONE whole-number expectation in this file that is right.
     *
     * A serial names one physical unit, so 0.4 of one is not a quantity that exists. This asserts
     * both halves of that: three serials received are three whole rows of exactly 1, and a shipment
     * that tries to pick a fraction of one is refused by name with nothing written.
     *
     * The refusal is asserted here — and NOT for the lot-tracked line — because here it is the
     * correct answer rather than today's answer.
     */
    public function aSerialTrackedLineIsTheOneWholeUnitRule(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);

        $serial = 'FRAC-SN-' . strtoupper(substr(uniqid(), -8));
        $this->placeSerialOnShelf($I, $this->products['serial'], $serial);

        $I->assertSame(
            '1.0000',
            $this->detailTotal($I, $this->products['serial'], InventoryDetail::STATUS_AVAILABLE),
            'a serial row is exactly one unit',
        );

        // The screen itself must offer a serial line no quantity box at all — a serial is counted by
        // ticking it, so there is no figure for a fraction to be typed into.
        $orderId = $this->seedAcceptAndConvertEstimate($I, '1', ['serial']);
        $invoiceId = $this->createAndIssueInvoice($I, $orderId, '1', ['serial'], $serial);

        $lineId = $this->invoiceLineId($I, $invoiceId, $this->products['serial']);
        $I->amOnPage('/admin/bundles/inventory-depth/shipments/new?invoice[]=' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(sprintf('input[name="allocations[%d][serial][]"]', $lineId));
        $I->dontSeeElement(sprintf('input[name="lines[%d]"]', $lineId));

        // And a fraction posted at one anyway is refused rather than rounded into something the
        // ledger can hold. Paired with a positive control below so "nothing moved" cannot pass for
        // the wrong reason.
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/shipments/new', [
            '_token' => $I->csrfToken(),
            'invoice' => [(string) $invoiceId],
            'allocations' => [(string) $lineId => ['lot' => ['0' => '0.4']]],
        ]);

        $I->assertSame(
            '1.0000',
            $this->detailTotal($I, $this->products['serial'], InventoryDetail::STATUS_AVAILABLE),
            'a fractional pick at a serial must move nothing — there is no part of a serial',
        );
        $I->assertSame(
            '0.0000',
            $this->detailTotal($I, $this->products['serial'], InventoryDetail::STATUS_SOLD),
            'and must sell nothing',
        );

        // The positive control: the same screen, the same line, one whole serial ticked, does move.
        $I->amOnPage('/admin/bundles/inventory-depth/shipments/new?invoice[]=' . $invoiceId);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/shipments/new', [
            '_token' => $I->csrfToken(),
            'invoice' => [(string) $invoiceId],
            'allocations' => [(string) $lineId => ['serial' => [$serial]]],
        ]);

        $I->assertSame(
            '0.0000',
            $this->detailTotal($I, $this->products['serial'], InventoryDetail::STATUS_AVAILABLE),
            'positive control: one whole serial ticked must actually ship, or the refusal above proved nothing',
        );
        $I->assertSame(
            '1.0000',
            $this->detailTotal($I, $this->products['serial'], InventoryDetail::STATUS_SOLD),
            'positive control: and must land in the sold rows',
        );
        $I->assertSame(
            '1.0000',
            $this->shipmentLineTotal($I, $invoiceId, $this->products['serial']),
            'positive control: one serial is one unit on the shipment line',
        );
    }

    // ======================================================================================= seeding

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('fractional-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A warehouse, a bin, and three products based in KILOGRAMS — a unit whose rounding precision is
     * 0.0001, so halves and tenths are quantities in it (`UnitOfMeasure::accepts()`).
     *
     * The base unit is the whole point of the fixture: #601 denominates every quantity in the
     * product's base unit, and a product based in `EA` has no halves by definition. Picking a
     * measured base unit is what makes 0.4 a legitimate quantity of this product rather than a
     * typing mistake, and therefore what makes every refusal below a finding rather than a rule.
     *
     * Ordering follows WarehouseToInvoiceWalkthroughCest::seedWarehouseAndProducts() exactly —
     * warehouse first, reference data after — for the reason its docblock gives.
     */
    private function seedWarehouseAndProducts(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName(self::REGION);
        $entityManager->persist($region);
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('F-01')->setSortKey(1);
        $entityManager->persist($this->bin);
        $entityManager->flush();

        $I->haveSeededReferenceData();

        $kilogram = (new UnitOfMeasure())
            ->setCode('KG-FRAC-' . strtoupper(substr(uniqid(), -5)))
            ->setName('Kilogram')
            ->setFamily(UnitOfMeasure::FAMILY_WEIGHT)
            ->setFactorToFamilyBase('1')
            // Four decimal places — the exact precision of every quantity column in the app.
            ->setRoundingPrecision('0.0001');
        $entityManager->persist($kilogram);

        $I->assertTrue(
            $kilogram->accepts('0.4'),
            'guard: the fixture unit must actually measure tenths, or nothing below is a fair question',
        );
        $I->assertTrue($kilogram->accepts('12.3456'), 'guard: and ten-thousandths');

        $policies = [
            // Lot-tracked in BOTH directions: capturing on the way in is not enough — shipping has
            // to be able to withdraw by lot too, or the shipment takes the untracked path and the
            // lot half of this test proves nothing.
            'lot' => (new TrackingPolicy())->setName('Fractional Lot ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true),
            // Neither direction — the truly untracked shape, and the row that must not move when
            // the lot line does.
            'untracked' => (new TrackingPolicy())->setName('Fractional None ' . uniqid())->setMode(TrackingPolicy::MODE_NONE),
            'serial' => (new TrackingPolicy())->setName('Fractional Serial ' . uniqid())->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(true)->setTrackOut(true),
        ];

        foreach ($policies as $shape => $policy) {
            $entityManager->persist($policy);

            $product = (new ProductCore())
                ->setSku('FRAC-' . strtoupper($shape) . '-' . strtoupper(substr(uniqid(), -6)))
                ->setName('Fractional ' . ucfirst($shape) . ' Powder')
                ->setUnit($kilogram->getCode())
                ->setBaseUnit($kilogram)
                ->setSalesTaxCode('E')
                ->setCostPrice('4.00')
                ->setDefaultPrice('10.00')
                ->setOriginalPrice('10.00')
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
                ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
                ->setTrackingPolicy($policy);
            $entityManager->persist($product);
            $entityManager->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse));

            $this->products[$shape] = $product;
        }

        $entityManager->flush();
    }

    private function seedVendor(FunctionalTester $I): void
    {
        $this->vendor = (new Vendor())->setName('Fractional Vendor ' . uniqid());
        $I->haveInRepository($this->vendor);
    }

    /**
     * Puts a fractional quantity on the shelf directly, and says plainly that it is a fixture.
     *
     * `inventory_detail.quantity` is `NUMERIC(14, 4)` in the database and a PHP `int` on the entity
     * (`App\Doctrine\Type\QuantityType` extends `IntegerType`), so a fraction cannot be written
     * through the ORM at all — `setQuantity()` will not take one and a flush would write the
     * truncated value back over whatever is there. The row is therefore created through the ORM and
     * its quantity set by raw SQL afterwards, with the entity manager cleared so that nothing stale
     * can flush over it.
     *
     * This is a fixture for the steps DOWNSTREAM of receiving. It is not a claim that the receiving
     * screen can do this — {@see self::theFractionalChainSurvivesEveryStep()} is what asks that
     * question, through the front door.
     */
    private function placeOnShelf(FunctionalTester $I, ProductCore $product, string $quantity): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $freshProduct = $entityManager->find(ProductCore::class, $product->getId());
        $warehouse = $entityManager->find(Warehouse::class, $this->warehouse->getId());
        $location = $entityManager->find(WarehouseLocation::class, $this->bin->getId());

        $lot = null;
        if ($freshProduct->getTrackingPolicy()?->getMode() === TrackingPolicy::MODE_LOT) {
            $this->lotCode = 'FRAC-LOT-' . strtoupper(substr(uniqid(), -8));
            $lot = (new InventoryLot())
                ->setProduct($freshProduct)
                ->setCode($this->lotCode)
                ->setReceivedAt(new \DateTimeImmutable());
            $entityManager->persist($lot);
        }

        $detail = (new InventoryDetail())
            ->setProduct($freshProduct)
            ->setWarehouse($warehouse)
            ->setLocation($location)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE);
        if ($lot instanceof InventoryLot) {
            $detail->setLot($lot);
        }
        $entityManager->persist($detail);
        $entityManager->flush();

        $detailId = (int) $detail->getId();
        $productId = (int) $freshProduct->getId();
        $warehouseId = (int) $warehouse->getId();

        $entityManager->clear();

        $connection = $entityManager->getConnection();
        $connection->executeStatement('UPDATE inventory_detail SET quantity = ? WHERE id = ?', [$quantity, $detailId]);
        $connection->executeStatement(
            'UPDATE product_inventory SET quantity = ?, received_quantity = ? WHERE product_id = ? AND warehouse_id = ?',
            [$quantity, $quantity, $productId, $warehouseId],
        );
    }

    /** One serial, one row, quantity exactly 1 — no SQL needed, because a serial is a whole unit. */
    private function placeSerialOnShelf(FunctionalTester $I, ProductCore $product, string $serial): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $freshProduct = $entityManager->find(ProductCore::class, $product->getId());
        $warehouse = $entityManager->find(Warehouse::class, $this->warehouse->getId());

        $entityManager->persist(
            (new InventoryDetail())
                ->setProduct($freshProduct)
                ->setWarehouse($warehouse)
                ->setLocation($entityManager->find(WarehouseLocation::class, $this->bin->getId()))
                ->setSerial($serial)
                ->setStatus(InventoryDetail::STATUS_AVAILABLE)
                ->setQuantity(1)
        );
        $entityManager->flush();

        $productId = (int) $freshProduct->getId();
        $warehouseId = (int) $warehouse->getId();
        $entityManager->clear();
        $entityManager->getConnection()->executeStatement(
            'UPDATE product_inventory SET quantity = 1, received_quantity = 1 WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        );
    }

    // ================================================================================== buy side

    /** The real new-purchase-order screen, its real save, and its real Issue button. */
    private function createAndIssuePurchaseOrder(FunctionalTester $I, string $quantity = self::ORDERED): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach (['lot', 'untracked'] as $shape) {
            $lines[$i] = [
                'product_id' => (string) $this->products[$shape]->getId(),
                'qty' => $quantity,
                'unit_cost' => '4.00',
            ];
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $this->vendor->getId(),
            'warehouse_id' => (string) $this->warehouse->getId(),
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $poId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM purchase_order WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->vendor->getId()],
        );
        $I->assertGreaterThan(0, $poId, 'guard: the save must have created a purchase order');

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $poId);
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $poId . '/issue', [
            '_token' => $I->csrfToken(),
        ]);

        $entityManager->clear();
        $status = (string) $entityManager->getConnection()->fetchOne('SELECT status FROM purchase_order WHERE id = ?', [$poId]);
        $I->assertNotSame('Draft', $status, 'guard: issuing must have moved the PO off Draft — ' . $status);

        return $poId;
    }

    /** The real receiving screen, receiving $quantity against every line of the order. */
    private function receive(FunctionalTester $I, int $poId, string $quantity): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var PurchaseOrder $order */
        $order = $entityManager->find(PurchaseOrder::class, $poId);

        $lineIdByProduct = [];
        foreach ($order->getLines() as $line) {
            $lineIdByProduct[(int) $line->getProduct()->getId()] = (string) $line->getId();
        }

        $I->amOnPage('/admin/bundles/procurement/receiving/new?po=' . $poId);
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach (['lot', 'untracked'] as $shape) {
            $product = $this->products[$shape];
            $row = [
                'purchase_order_line_id' => $lineIdByProduct[(int) $product->getId()],
                'product_id' => (string) $product->getId(),
                'quantity' => $quantity,
                'location_id' => (string) $this->bin->getId(),
                'unit_cost' => '4.00',
            ];

            if ($shape === 'lot') {
                // The SAME lot across both receipts, so the two partials land on one row and the
                // shelf balance is a sum rather than two separate stories.
                if ($this->lotCode === '') {
                    $this->lotCode = 'FRAC-LOT-' . strtoupper(substr(uniqid(), -8));
                }
                $row['lot_code'] = $this->lotCode;
            }

            $lines[$i] = $row;
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/new', [
            '_token' => $token,
            'purchase_order_id' => (string) $poId,
            'packing_slip' => 'FRAC-PS-' . uniqid(),
            'received_by' => 'fractional-cest',
            'lines' => $lines,
        ]);
    }

    // ================================================================================= sell side

    /**
     * A Priced quote for $quantity of each shape, accepted and converted through the real buttons.
     *
     * The quote itself is built directly rather than through the create form — the same shortcut
     * WarehouseToInvoiceWalkthroughCest::seedPricedEstimate() takes, and for the same reason: the
     * accept/convert write path is what matters here, and the create form's own field names are
     * AdminEstimateFormCest's coverage. Everything the fraction has to survive after this point IS
     * driven through a real screen.
     *
     * @param list<string> $shapes
     */
    private function seedAcceptAndConvertEstimate(FunctionalTester $I, string $quantity, array $shapes = ['lot', 'untracked']): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $this->company = (new Company())->setName('Fractional Buyer Co')->setCode('FRAC-' . uniqid());
        $entityManager->persist($this->company);
        $entityManager->persist(
            (new CompanyAddress())
                ->setCompany($this->company)->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')->setCity('Vancouver')->setProvince('BC')->setPostalCode('V5K0A1')->setCountry('CA')
                ->setIsDefaultShipping(true)->setIsDefaultBilling(true)
        );
        $entityManager->flush();

        $I->haveActiveFulfillmentRegionFor($this->company, self::REGION);

        $subtotal = (float) $quantity * 10.0 * \count($shapes);

        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('FRAC-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax('0.00')
            ->setTotal(number_format($subtotal, 2, '.', ''));
        $estimate->setStatus('Priced', DocumentActor::system());

        foreach ($shapes as $shape) {
            // Re-fetched into the CURRENT entity manager by id — several real requests have rebooted
            // the kernel since seedWarehouseAndProducts(), so the objects this class is holding are
            // detached. Same gotcha WarehouseToInvoiceWalkthroughCest documents at length.
            $freshProduct = $entityManager->find(ProductCore::class, $this->products[$shape]->getId());
            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($freshProduct)
                    ->setName($freshProduct->getName())
                    ->setSku($freshProduct->getSku())
                    ->setLocation(self::REGION)
                    ->setQuantity($quantity)
                    ->setPrice('10.00')
                    ->setSubtotal(number_format((float) $quantity * 10.0, 2, '.', ''))
            );
        }

        $entityManager->persist($estimate);
        $entityManager->flush();
        $estimateId = (int) $estimate->getId();

        $I->amOnPage('/admin/estimate/detail/' . $estimateId);
        $I->sendFormPostRequest('/admin/estimate/accept/' . $estimateId, ['_token' => $I->csrfToken()]);

        $I->amOnPage('/admin/estimate/detail/' . $estimateId);
        $I->sendFormPostRequest('/admin/estimate/convert/' . $estimateId, ['_token' => $I->csrfToken()]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $orderId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->company->getId()],
        );
        $I->assertGreaterThan(0, $orderId, 'guard: the conversion must have raised a sales order');

        return $orderId;
    }

    /**
     * The real invoice create screen, issued straight away, then moved to Processing so that it can
     * be shipped against.
     *
     * @param list<string> $shapes
     */
    private function createAndIssueInvoice(
        FunctionalTester $I,
        int $orderId,
        string $quantity,
        array $shapes = ['lot', 'untracked'],
        ?string $serial = null,
    ): int {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $orderId);

        $shapeByProductId = [];
        foreach ($shapes as $shape) {
            $shapeByProductId[(int) $this->products[$shape]->getId()] = $shape;
        }

        $lines = [];
        foreach ($order->getLines() as $line) {
            $productId = (int) $line->getProduct()->getId();
            $shape = $shapeByProductId[$productId] ?? null;
            if ($shape === null) {
                continue;
            }

            $row = [
                'sales_order_line_id' => (string) $line->getId(),
                'product_id' => (string) $productId,
                'qty' => $quantity,
            ];

            // MandatoryCaptureGuard refuses a tracked-outbound line leaving Draft without an
            // identity, so the real form's own lot_id/serial fields are posted, exactly as the
            // picker renders them.
            if ($shape === 'lot') {
                $row['lot_id'] = (string) $this->lotIdFor($I, $this->products['lot']);
            }
            if ($shape === 'serial' && $serial !== null) {
                $row['serial'] = $serial;
            }

            $lines[] = $row;
        }

        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'fulfillment_region' => self::REGION,
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $invoiceId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
        $I->assertGreaterThan(0, $invoiceId, 'guard: creating the invoice must have written one');
        $I->assertSame(
            \count($shapes),
            (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_line WHERE invoice_id = ?', [$invoiceId]),
            'guard: the invoice must carry one line per product it was created with',
        );

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', ['_token' => $I->csrfToken()]);

        return $invoiceId;
    }

    /**
     * The real shipment screen: load the form for this invoice, scrape ITS token, post $quantity
     * against every line.
     *
     * The token is re-scraped per shipment on purpose — `_token` is hashed into the shipment's
     * `clientOperationId`, so posting the same one twice is a resubmission and `ship()` would hand
     * back the first shipment rather than record a second. Symfony randomises the rendered token per
     * request, so loading the form again is what makes the second shipment a second shipment.
     */
    private function ship(FunctionalTester $I, int $invoiceId, string $shape, string $quantity): ?int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $before = (int) $entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM shipment');

        $lineId = $this->invoiceLineId($I, $invoiceId, $this->products[$shape]);

        $I->amOnPage('/admin/bundles/inventory-depth/shipments/new?invoice[]=' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        $params = ['_token' => $I->csrfToken(), 'invoice' => [(string) $invoiceId]];

        if ($shape === 'lot') {
            // The lot breakdown the screen renders for a lot-tracked line: one box per lot, named
            // `allocations[line][lot][lotId]`. There is no plain quantity box on such a row at all.
            $params['allocations'][(string) $lineId]['lot'][(string) $this->lotIdFor($I, $this->products['lot'])] = $quantity;
        } else {
            $params['lines'][(string) $lineId] = $quantity;
        }

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/shipments/new', $params);

        // Which shipment this POST actually recorded, or null if the screen refused it. Returned
        // rather than rediscovered later so that the void step can name the exact shipments it means
        // to reverse: "the most recent two" would silently reverse the wrong pair the moment one of
        // these POSTs is refused, and the void step would then be measuring the refusal above rather
        // than the void it exists to test.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $after = (int) $entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM shipment');

        return $after > $before ? $after : null;
    }

    /**
     * The real Void button on each named shipment.
     *
     * @param list<?int> $shipmentIds nulls (a refused shipment) are skipped — there is nothing to
     *                                void, and the refusal is already reported where it happened
     */
    private function voidShipments(FunctionalTester $I, array $shipmentIds): void
    {
        foreach ($shipmentIds as $shipmentId) {
            if ($shipmentId === null) {
                continue;
            }

            $I->amOnPage('/admin/bundles/inventory-depth/shipments/' . $shipmentId);
            $I->sendFormPostRequest('/admin/bundles/inventory-depth/shipments/' . $shipmentId . '/void', [
                '_token' => $I->csrfToken(),
                'reason' => 'Fractional walkthrough void',
            ]);
        }
    }

    // ============================================================================ database reads

    /**
     * Every assertion in this file reads its number back out of a column with raw SQL, never off an
     * entity and never off the page (#627): `see('0.4')` matches `10.4000`, and an entity read comes
     * back through `QuantityType`, which is the very thing under test.
     *
     * Returned exactly as the driver hands it over, for the columns that hold text — a status, a
     * document number. Quantities go through {@see self::decimal()} instead.
     */
    private function scalar(FunctionalTester $I, string $sql, array $params = []): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return (string) $entityManager->getConnection()->fetchOne($sql, $params);
    }

    /**
     * A quantity column, restated at the four decimal places the column is declared to.
     *
     * SQLite hands `NUMERIC(14, 4)` back in its shortest form — `2.5` for a column holding 2.5000,
     * `0` for one holding zero — so comparing the driver's string against a written-out `'2.5000'`
     * would fail over punctuation while the fraction was perfectly intact. That is the opposite of
     * this file's job: it would report a loss that did not happen and bury the ones that did.
     *
     * So the read is normalised and the EXPECTATION stays a four-decimal literal. Nothing is
     * rounded away by doing this — the normalisation is the same whole-ten-thousandths arithmetic
     * the application itself uses, at the column's own precision, so a value that really was
     * truncated still reads back as `'0.0000'` against an expected `'0.4000'` and still fails.
     */
    private function decimal(FunctionalTester $I, string $sql, array $params = []): string
    {
        return $this->format(self::units($this->scalar($I, $sql, $params)));
    }

    private function detailTotal(FunctionalTester $I, ProductCore $product, string $status): string
    {
        return $this->decimal(
            $I,
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [(int) $product->getId(), $status],
        );
    }

    private function productInventoryColumn(FunctionalTester $I, ProductCore $product, string $column): string
    {
        return $this->decimal(
            $I,
            sprintf('SELECT %s FROM product_inventory WHERE product_id = ? AND warehouse_id = ?', $column),
            [(int) $product->getId(), (int) $this->warehouse->getId()],
        );
    }

    private function poLineColumn(FunctionalTester $I, int $poId, ProductCore $product, string $column): string
    {
        return $this->decimal(
            $I,
            sprintf('SELECT %s FROM purchase_order_line WHERE purchase_order_id = ? AND product_id = ?', $column),
            [$poId, (int) $product->getId()],
        );
    }

    /** Ordered less received, as the column pair actually holds it. */
    private function poLineOutstanding(FunctionalTester $I, int $poId, ProductCore $product): string
    {
        $ordered = $this->poLineColumn($I, $poId, $product, 'quantity_ordered');
        $received = $this->poLineColumn($I, $poId, $product, 'quantity_received');

        // Subtracted as whole ten-thousandths rather than as floats, so the ASSERTION itself cannot
        // be the thing that loses the fraction it is checking for.
        return $this->format(self::units($ordered) - self::units($received));
    }

    private function salesOrderLineQuantity(FunctionalTester $I, int $orderId, ProductCore $product): string
    {
        return $this->decimal(
            $I,
            'SELECT quantity FROM sales_order_line WHERE order_id = ? AND product_id = ?',
            [$orderId, (int) $product->getId()],
        );
    }

    private function invoiceLineColumn(FunctionalTester $I, int $invoiceId, ProductCore $product, string $column): string
    {
        return $this->decimal(
            $I,
            sprintf('SELECT %s FROM invoice_line WHERE invoice_id = ? AND product_id = ?', $column),
            [$invoiceId, (int) $product->getId()],
        );
    }

    private function invoiceLineId(FunctionalTester $I, int $invoiceId, ProductCore $product): int
    {
        $id = (int) $this->scalar(
            $I,
            'SELECT id FROM invoice_line WHERE invoice_id = ? AND product_id = ?',
            [$invoiceId, (int) $product->getId()],
        );
        $I->assertGreaterThan(0, $id, 'guard: the invoice line must exist to be shipped against');

        return $id;
    }

    private function invoiceColumn(FunctionalTester $I, int $invoiceId, string $column): string
    {
        return $this->scalar($I, sprintf('SELECT %s FROM invoice WHERE id = ?', $column), [$invoiceId]);
    }

    /** Everything shipped for one product against one invoice, across every shipment and every split. */
    private function shipmentLineTotal(FunctionalTester $I, int $invoiceId, ProductCore $product): string
    {
        $rows = $this->scalarList(
            $I,
            'SELECT sl.quantity FROM shipment_line sl'
            . ' JOIN shipment s ON s.id = sl.shipment_id'
            . ' JOIN invoice_line il ON il.id = sl.invoice_line_id'
            . ' WHERE il.invoice_id = ? AND sl.product_id = ? AND s.voided_at IS NULL',
            [$invoiceId, (int) $product->getId()],
        );

        $units = 0;
        foreach ($rows as $row) {
            $units += self::units($row);
        }

        return $this->format($units);
    }

    /** @return list<string> */
    private function scalarList(FunctionalTester $I, string $sql, array $params = []): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(static fn ($value): string => (string) $value, $entityManager->getConnection()->fetchFirstColumn($sql, $params));
    }

    private function lotIdFor(FunctionalTester $I, ProductCore $product): int
    {
        $lotId = (int) $this->scalar(
            $I,
            'SELECT id FROM inventory_lot WHERE product_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $product->getId()],
        );
        $I->assertGreaterThan(0, $lotId, 'guard: the lot this line was received under must be findable');

        return $lotId;
    }

    // ------------------------------------------------- the test's own arithmetic, in exact units

    /**
     * A decimal string as whole ten-thousandths — the exact precision of every quantity column here.
     *
     * The same arithmetic `ShipmentQuantity::units()` and `InvoiceShippingStatusDeriver::units()` do,
     * written out here rather than imported so that this test's own sums cannot be wrong in the same
     * direction as the code they are checking. Floats are not used anywhere an expectation is
     * computed: 0.6 + 0.9 as floats is 1.4999999999999998, which would make this file report a
     * failure the application did not commit.
     */
    private static function units(string $quantity): int
    {
        return (int) round((float) $quantity * 10000);
    }

    private function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return sprintf('%s%d.%04d', $sign, intdiv($units, 10000), $units % 10000);
    }
}
