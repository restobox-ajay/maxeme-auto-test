<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * ONE invoice create screen, reached two ways, with genuinely identical behavior (#full-parity,
 * 2026-09-13): the sales order is a FIELD, not a kind of invoice. Set, it pre-fills the lines and
 * the address/header boxes from the order; unset, they start blank. Everything downstream —
 * columns, editability, add/remove, the save contract — is the SAME code either way. The only
 * things that still differ are the ones with a real, named reason: `OverInvoicingGuard` (checked at
 * ISSUE, not here) and how a charge row's fee is resolved (drawn down from the order vs. minted).
 *
 * ## #627
 *
 * Not one figure on this screen is asserted with see(). Every number is read off the element that
 * holds it — an input by its posted name, a display cell by its data-label — or out of the database
 * column it landed in. see('40') matches '1400', and this screen is nothing but numbers.
 *
 * @group bundle-agnostic
 */
final class OneInvoiceCreateScreenCest
{
    private const ORDER_SCREEN = '/admin/invoice/create?order_id=%d';
    private const STANDALONE_SCREEN = '/admin/invoice/create?company_id=%d';

    private ?Company $company = null;
    private ?ProductCore $product = null;
    private int $seq = 0;

    /**
     * Codeception builds ONE instance of a Cest and runs every method on it, so anything left on
     * $this by the previous method is still there. Both fixtures are re-made per test and the
     * counter that keeps document numbers unique is the only thing deliberately carried over.
     */
    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
        ++$this->seq;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('one-invoice-screen-' . $this->seq . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $company = (new Company())
            ->setName('One Screen Wholesale ' . $this->seq)
            ->setCode('OSW-' . $this->seq . '-' . uniqid());
        $I->haveInRepository($company);
        $company->addAddress(
            (new CompanyAddress())
                ->setLabel('HQ')
                ->setFirstName('One')
                ->setLastName('Screen')
                ->setAddressLine1('1 Merge Street')
                ->setCity('Toronto')
                ->setProvince('ON')
                ->setCountry('CA')
                ->setPostalCode('M4B1B5')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
        );
        $I->grabService(EntityManagerInterface::class)->flush();
        $this->company = $company;

        $product = (new ProductCore())
            ->setSku('OSW-SKU-' . $this->seq . '-' . uniqid())
            ->setName('One Screen Widget')
            ->setDefaultPrice('5.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $this->product = $product;
    }

    // ─────────────────────────────────────────────────────────── 1. it is one screen

    /**
     * Both entry points render the same page.
     *
     * Asserted structurally off the two rendered pages rather than by naming a template: a template
     * name is a fact about the repository, and what matters is that the two URLs answer with the
     * same page. Every selector is asserted on BOTH, so none of them can pass by matching something
     * only one screen has.
     */
    public function bothEntryPointsRenderTheOneCreateScreen(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);

        foreach ([
            'the order-linked screen' => sprintf(self::ORDER_SCREEN, $order->getId()),
            'the standalone screen' => sprintf(self::STANDALONE_SCREEN, $this->company->getId()),
        ] as $what => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $I->seeElement('section.order-create-shell form#invoice-create-form.order-workspace-card');
            $I->seeElement('form#invoice-create-form section.order-form-panel h2');
            $I->seeElement('form#invoice-create-form section.order-lines-section');
            $I->seeElement('table.invoice-line-table thead tr th');
            $I->seeElement('table.invoice-line-table tbody tr.invoice-line-row');
            $I->seeElement('form#invoice-create-form div.order-workspace-actions button[name="save_mode"][value="issue"]');
            $I->seeElement('form#invoice-create-form div.order-bottom-actions button[name="save_mode"][value="draft"]');
            $I->assertNotEmpty($what, 'named for the failure message');
        }
    }

    /**
     * Every row of either screen has as many cells as its own header has columns — the SAME count on
     * both screens now (#full-parity, 2026-09-13): 12 shared base columns, Ordered/Invoiced spliced
     * in before Qty, then Batch/Actions appended. One shared row rendering two lists is exactly how
     * a table ends up one cell out of alignment, which reads as a wrong figure rather than an error.
     */
    public function eachScreensRowsMatchItsOwnHeader(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);

        foreach ([
            'the order-linked screen' => [sprintf(self::ORDER_SCREEN, $order->getId()), 16],
            'the standalone screen' => [sprintf(self::STANDALONE_SCREEN, $this->company->getId()), 16],
        ] as $what => [$url, $expected]) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $headers = $I->grabMultiple('table.invoice-line-table thead tr th');
            $I->assertCount($expected, $headers, $what . ' does not render its own column count');

            // A bare create page with nothing typed yet is genuinely empty (order's and quote's own
            // create pages start the same way, #full-parity 2026-09-13) — its one-cell placeholder
            // row is not a data row and is excluded here, same as its colspan already says.
            $rows = $I->grabMultiple('table.invoice-line-table tbody tr:not(.invoice-lines-empty)', 'class');
            $I->assertGreaterThan(0, count($rows), $what . ' rendered no line rows at all');

            $cells = $I->grabMultiple('table.invoice-line-table tbody tr:not(.invoice-lines-empty) > td');
            $I->assertCount(
                $expected * count($rows),
                $cells,
                sprintf('%s: %d cells across %d rows does not divide evenly by %d headers', $what, count($cells), count($rows), $expected),
            );
        }
    }

    /** The sales order is a field on the screen: prelinked on one entry point, empty on the other. */
    public function theSalesOrderFieldIsPrelinkedOnOneScreenAndEmptyOnTheOther(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);

        $I->amOnPage(sprintf(self::ORDER_SCREEN, $order->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('section.order-form-panel div.order-field-row.static a[href="/admin/order/detail/' . $order->getId() . '"]');
        $I->assertSame(
            $order->getOrderNumber(),
            trim($I->grabTextFrom('section.order-form-panel div.order-field-row.static a[href="/admin/order/detail/' . $order->getId() . '"]')),
            'the prelinked field names the order this invoice will bill',
        );

        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->seeResponseCodeIsSuccessful();
        // The positive control for the absence: the same panel really did render here too.
        $I->seeElement('section.order-form-panel div.order-field-row.static');
        $I->dontSeeElement('section.order-form-panel div.order-field-row.static a[href^="/admin/order/detail/"]');
    }

    // ────────────────────────────────── 2. the four differences, each only where the field is set

    /**
     * A pre-filled row's product is exactly as editable as a freely-typed one — no locking, matching
     * standalone. The one real difference: a pre-filled row carries a hidden `sales_order_line_id`
     * tag (for `OverInvoicingGuard` at issue time); a freshly-typed row carries none.
     */
    public function theProductIsFixedOnlyWhereThereIsALineBehindIt(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $lineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage(sprintf(self::ORDER_SCREEN, $order->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="hidden"][name="lines[0][sales_order_line_id]"][value="' . $lineId . '"]');
        $I->seeElement('select[name="lines[0][product_id]"]');
        $I->seeElement('input[name="lines[0][sku]"]');
        $I->seeElement('input[name="lines[0][unit]"]');

        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="lines[0][product_id]"]');
        $I->seeElement('input[name="lines[0][sku]"]');
        $I->seeElement('input[name="lines[0][unit]"]');
        // Nothing here names an order line, because there is none to name.
        $I->dontSeeElement('input[type="hidden"][name="lines[0][sales_order_line_id]"]');
    }

    /**
     * Ordered/Invoiced are in the column list on BOTH screens now (#full-parity, 2026-09-13) — blank
     * on a row with no order line behind it, filled in on one that has it. Matches Zoho's own
     * Ordered/Invoiced/Quantity convention.
     */
    public function theTwoExtraColumnsAppearOnlyWithAnOrderBehindTheScreen(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $this->invoiceSomeOf($I, $order, '4.00');

        $expectedHeaders = ['Name', 'Location', 'SKU', 'Ordered', 'Invoiced', 'Qty', 'Weight', 'U/M', 'Tax Code', 'Tax $', 'Cost', 'Original Price', 'Price', 'Subtotal', 'Batch', 'Actions'];

        $I->amOnPage(sprintf(self::ORDER_SCREEN, $order->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            $expectedHeaders,
            array_map(trim(...), $I->grabMultiple('table.invoice-line-table thead tr th')),
            'the order-linked screen renders the same 16 columns as standalone',
        );
        // 10 ordered, 4 already billed on another invoice. Read off the two cells, never off the
        // page — and compared against what the ORDER holds rather than a hand-typed expectation.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(SalesOrder::class, (int) $order->getId());
        $reloadedLine = $reloaded->getLines()->first();
        $I->assertSame(
            $reloadedLine->getQuantityEntered(),
            trim($I->grabTextFrom('tr.invoice-line-row td[data-label="Ordered"]')),
            'the Ordered cell states what the order line was written for',
        );
        // invoicedQuantityFor(), not invoicedQuantityInLineUnitFor(): on a base-unit line the two
        // agree numerically, but the screen prints the line's OWN unit and trims the trailing
        // zeros invoicedQuantityInLineUnitFor()'s NUMERIC(14,4) shape would otherwise carry —
        // matching the "Ordered" cell beside it, which is never printed as "10.0000" either.
        $I->assertSame(
            LineDenomination::trimZeros($reloaded->invoicedQuantityInLineUnitFor($reloadedLine)),
            trim($I->grabTextFrom('tr.invoice-line-row td[data-label="Invoiced"]')),
            'and the Invoiced cell states what other invoices already billed',
        );
        $I->assertSame(10.0, (float) $reloadedLine->getQuantityEntered(), 'guard: the fixture ordered ten');
        $I->assertSame(4.0, (float) $reloaded->invoicedQuantityFor($reloadedLine), 'guard: four of them are billed');

        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            $expectedHeaders,
            array_map(trim(...), $I->grabMultiple('table.invoice-line-table thead tr th')),
            'the standalone screen renders the same 16 columns as order-linked',
        );
    }

    /**
     * Rows can be added and removed on BOTH screens now (#full-parity, 2026-09-13) — an order-linked
     * invoice is exactly as free-form as a standalone one; the order only pre-fills the starting
     * rows. Same JS toolbar + no-JS submit twin pattern order's and quote's own screens use.
     */
    public function rowsAreAddedAndRemovedOnlyWhereTheRowSetIsTheAdmins(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);

        foreach ([
            'the order-linked screen' => sprintf(self::ORDER_SCREEN, $order->getId()),
            'the standalone screen' => sprintf(self::STANDALONE_SCREEN, $this->company->getId()),
        ] as $what => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement('.order-lines-toolbar button.js-invoice-add-product');
            $I->seeElement('.order-lines-toolbar button.js-invoice-add-blank');
            $I->seeElement('template#invoice-product-line-template button.js-invoice-remove-line');
            $I->assertNotEmpty($what, 'named for the failure message');
        }
    }

    // ─────────────────────────────────────────────────────── 3. difference 3: the ceiling

    /**
     * The quantity box is PRE-FILLED with the remainder as a sensible starting default — but carries
     * no ceiling (#full-parity, 2026-09-13: dropped, matching Zoho — an admin can type any quantity
     * on any row, order-linked or not; only `OverInvoicingGuard` at issue time can still refuse).
     */
    public function theQuantityBoxCarriesTheRemainderAsItsCeiling(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $this->invoiceSomeOf($I, $order, '4.00');

        $I->amOnPage(sprintf(self::ORDER_SCREEN, $order->getId()));
        $I->seeResponseCodeIsSuccessful();

        $value = trim((string) $I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value'));
        $cell = trim($I->grabTextFrom('tr.invoice-line-row td[data-label="Ordered"]'));
        $I->assertNotSame($cell, $value, 'guard: Ordered (10) and the pre-filled qty (6 remaining) differ');
        $I->assertSame('6', $value, 'the box is pre-filled with the order line\'s own remainder');
        $I->dontSeeElement('input[name="lines[0][qty]"][max]');
        $I->dontSeeElement('input[name="lines[0][qty]"][min]');

        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="lines[0][qty]"][max]');
        $I->dontSeeElement('input[name="lines[0][qty]"][min]');
    }

    /**
     * No save-time refusal for billing past what's left on the order line — matching Zoho, and the
     * screen-level half of the merge; `OverInvoicingGuard`'s own issue-time refusal is exhaustively
     * covered by AdminInvoiceIssueOverInvoicingCest and not re-tested here. This only proves the
     * create screen itself no longer stands in the way.
     */
    public function aQuantityAboveTheCeilingIsRefusedAndAtItIsAccepted(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $this->invoiceSomeOf($I, $order, '4.00');
        $line = $order->getLines()->first();
        $url = sprintf(self::ORDER_SCREEN, $order->getId());

        // More than the 6 left — saved as a Draft, not issued, so OverInvoicingGuard never runs.
        $I->amOnPage($url);
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'lines' => [['product_id' => (string) $line->getProduct()->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => '7.00', 'price' => '5.00']],
        ]);

        $rows = $this->invoiceLineRowsFor($I, $this->newestInvoiceId($I));
        $I->assertCount(1, $rows, 'the over-quantity save was accepted, not refused');
        $I->assertSame(7.0, (float) $rows[0]['quantity'], 'and billed exactly what was typed, past the order\'s own remainder');
    }

    // ──────────────────────────────────────────────── 4. per-field save evidence, both paths

    /**
     * The order-linked screen posts the SAME field set the standalone one does (#full-parity,
     * 2026-09-13 — no more auto-copy from the order line) — this test is about the one thing unique
     * to it: `sales_order_line_id` attributes the new line back to the order line it was pre-filled
     * from, and that order line itself is untouched except its own uninvoiced remainder.
     */
    public function everyColumnTheOrderLinkedScreenWritesIsReadBack(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, ['batch' => 'LOT-77']);
        $line = $order->getLines()->first();
        $lineId = (int) $line->getId();
        $url = sprintf(self::ORDER_SCREEN, $order->getId());

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [[
                'product_id' => (string) $this->product->getId(),
                'sales_order_line_id' => (string) $lineId,
                'location' => 'Aisle 9',
                'qty' => '4.00',
                'price' => '7.50',
            ]],
        ]);

        $rows = $this->invoiceLineRowsFor($I, $this->newestInvoiceId($I));
        $I->assertCount(1, $rows, 'the invoice has exactly the one line the screen posted');
        $stored = $rows[0];

        $I->assertSame($lineId, (int) $stored['sales_order_line_id'], 'lines[0][sales_order_line_id] linked the pre-filled order line');
        $I->assertSame(4.0, (float) $stored['quantity'], 'lines[0][qty] landed in invoice_line.quantity');
        $I->assertSame(7.5, (float) $stored['price'], 'lines[0][price] landed in invoice_line.price');
        $I->assertSame(30.0, (float) $stored['subtotal'], 'invoice_line.subtotal is the two of them multiplied');
        $I->assertSame('Aisle 9', (string) $stored['location'], 'lines[0][location] landed in invoice_line.location');
        $I->assertSame((int) $this->product->getId(), (int) $stored['product_id'], 'invoice_line.product_id');

        // The order line itself: untouched except its own uninvoiced remainder.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $after = $em->find(SalesOrder::class, (int) $order->getId());
        $afterLine = $after->getLines()->first();
        $I->assertSame('6.00', $after->uninvoicedQuantityFor($afterLine), '10 ordered less the 4 just billed');
        $I->assertSame(10.0, (float) $afterLine->getQuantity(), 'the order line still orders what it ordered');
        $I->assertSame(5.0, (float) $afterLine->getPrice(), 'and still charges what it charged — the invoice priced itself');
        $I->assertSame('LOT-77', (string) $afterLine->getBatch(), 'and its lot was not touched');
    }

    /**
     * `lines[N][lot_id]`/`lines[N][serial]` — the lot/serial picker `sales_line_row.html.twig` has
     * rendered on a tracked-outbound line since the 2026-09-14 lot/serial/expiry plan — must reach
     * `invoice_line.lot_id`/`.serial`, the two columns {@see \App\Service\MandatoryCaptureGuard}
     * reads at issue time.
     *
     * Added alongside the discovery that they never did: `postedInvoiceLineRows()` built its row
     * array without either key, so `applyLineRows()` had nothing to read and every invoice for a
     * tracked-outbound product left Draft unable to issue, regardless of what an admin picked on the
     * form — caught by `WarehouseToInvoiceWalkthroughCest`, which drives the real screens end to end
     * rather than constructing an `InvoiceLine` directly the way {@see \Tests\Entity\
     * InvoiceMandatoryCaptureTest} does; that gap is exactly why the entity-level guard tests never
     * caught a controller that dropped the field before it ever reached the entity.
     */
    public function theLotAndSerialPickersPostedChoiceIsReadBack(FunctionalTester $I): void
    {
        $lotPolicy = (new TrackingPolicy())->setName('OSW Lot ' . $this->seq)->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true);
        $serialPolicy = (new TrackingPolicy())->setName('OSW Serial ' . $this->seq)->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(true)->setTrackOut(true);
        $I->haveInRepository($lotPolicy);
        $I->haveInRepository($serialPolicy);

        $lotProduct = (new ProductCore())
            ->setSku('OSW-LOT-SKU-' . $this->seq . '-' . uniqid())->setName('OSW Lot Widget')->setDefaultPrice('5.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)->setTrackingPolicy($lotPolicy);
        $serialProduct = (new ProductCore())
            ->setSku('OSW-SERIAL-SKU-' . $this->seq . '-' . uniqid())->setName('OSW Serial Widget')->setDefaultPrice('5.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)->setTrackingPolicy($serialPolicy);
        $I->haveInRepository($lotProduct);
        $I->haveInRepository($serialProduct);

        $lotLine = (new SalesOrderLine())->setProduct($lotProduct)->setName('OSW Lot Widget')->setSku((string) $lotProduct->getSku())
            ->setQuantity('10.00')->setPrice('5.00')->setSubtotal('50.00');
        $serialLine = (new SalesOrderLine())->setProduct($serialProduct)->setName('OSW Serial Widget')->setSku((string) $serialProduct->getSku())
            ->setQuantity('1.00')->setPrice('5.00')->setSubtotal('5.00');

        $order = (new SalesOrder())->setCompany($this->company)->setOrderNumber('OSW-ORD-' . uniqid())
            ->setSubtotal('55.00')->setTax('0.00')->setTotal('55.00');
        $order->addLine($lotLine);
        $order->addLine($serialLine);
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $url = sprintf(self::ORDER_SCREEN, $order->getId());
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'fulfillment_region' => 'Main',
            'lines' => [
                [
                    'product_id' => (string) $lotProduct->getId(),
                    'sales_order_line_id' => (string) $lotLine->getId(),
                    'qty' => '10.00',
                    'price' => '5.00',
                    'lot_id' => '4242',
                ],
                [
                    'product_id' => (string) $serialProduct->getId(),
                    'sales_order_line_id' => (string) $serialLine->getId(),
                    'qty' => '1.00',
                    'price' => '5.00',
                    'serial' => 'OSW-SN-77',
                ],
            ],
        ]);

        $rows = $this->invoiceLineRowsFor($I, $this->newestInvoiceId($I));
        $I->assertCount(2, $rows, 'the invoice has exactly the two lines the screen posted');

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row;
        }

        $I->assertSame(
            4242,
            (int) $byProduct[(int) $lotProduct->getId()]['lot_id'],
            'lines[0][lot_id] landed in invoice_line.lot_id',
        );
        $I->assertSame(
            'OSW-SN-77',
            (string) $byProduct[(int) $serialProduct->getId()]['serial'],
            'lines[1][serial] landed in invoice_line.serial',
        );
    }

    /**
     * Every field the STANDALONE screen's row posts, read back out of the column it names.
     *
     * One POST, one assertion per field. A fumbled name leaves its own column at the default while
     * the rest of the row saves perfectly, which is the failure that is otherwise silent.
     */
    public function everyFieldTheStandaloneScreenPostsIsReadBack(FunctionalTester $I): void
    {
        $url = sprintf(self::STANDALONE_SCREEN, $this->company->getId());

        $sweep = [
            'location' => ['posted' => 'Aisle 3', 'column' => 'location', 'numeric' => false],
            'sku' => ['posted' => 'OSW-POSTED-SKU', 'column' => 'sku', 'numeric' => false],
            'qty' => ['posted' => '6', 'column' => 'quantity', 'numeric' => true],
            'unit' => ['posted' => 'DRUM', 'column' => 'unit', 'numeric' => false],
            'tax_code' => ['posted' => 'S', 'column' => 'tax_code', 'numeric' => false],
            'cost' => ['posted' => '13.13', 'column' => 'cost', 'numeric' => true],
            'price' => ['posted' => '24.24', 'column' => 'price', 'numeric' => true],
        ];

        $row = ['product_id' => (string) $this->product->getId(), 'name' => '', 'unit_id' => ''];
        foreach ($sweep as $field => $spec) {
            $row[$field] = $spec['posted'];
        }

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'due_date' => '2026-10-11',
            'po_number' => 'PO-ONE-SCREEN',
            'special_instructions' => 'Leave at the dock',
            'lines' => [0 => $row],
        ]);

        $invoiceId = $this->newestInvoiceId($I);
        $rows = $this->invoiceLineRowsFor($I, $invoiceId);
        $I->assertCount(1, $rows, 'the invoice has exactly the one line that was posted');
        $stored = $rows[0];

        // product_id cannot be checked by its own value alone: a row with no product and no name is
        // dropped as blank, so a fumbled name would delete the line rather than null a column. The
        // count above is half of it; this is the other half.
        $I->assertSame((int) $this->product->getId(), (int) $stored['product_id'], 'lines[0][product_id] attached the product');

        foreach ($sweep as $field => $spec) {
            $message = sprintf('lines[0][%s] must land in invoice_line.%s', $field, $spec['column']);
            if ($spec['numeric']) {
                $I->assertSame((float) $spec['posted'], (float) $stored[$spec['column']], $message);
            } else {
                $I->assertSame($spec['posted'], (string) $stored[$spec['column']], $message);
            }
        }

        // This screen raises an invoice with NO order behind it — the field being empty is the whole
        // difference, and it has to be empty in the COLUMN and not merely on screen.
        $I->assertNull($stored['sales_order_line_id'], 'a standalone invoice line draws nothing down');
        $header = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT sales_order_id, po_number, special_instructions, invoice_date, due_date FROM invoice WHERE id = ?',
            [$invoiceId],
        );
        $I->assertNull($header['sales_order_id'], 'and the invoice itself stands alone');
        $I->assertSame('PO-ONE-SCREEN', (string) $header['po_number'], 'the context panel\'s PO # reached the column');
        $I->assertSame('Leave at the dock', (string) $header['special_instructions'], 'and so did its instructions');
        $I->assertStringStartsWith('2026-09-11', (string) $header['invoice_date'], 'and its invoice date');
        $I->assertStringStartsWith('2026-10-11', (string) $header['due_date'], 'and its due date');
    }

    // ─────────────────────────────────────────────────────────────────────── fixtures

    /** @param array<string, string> $lineFields */
    private function approvedOrder(FunctionalTester $I, array $lineFields = []): SalesOrder
    {
        $line = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName('One Screen Widget')
            ->setSku((string) $this->product->getSku())
            ->setQuantity('10.00')
            ->setPrice('5.00')
            ->setSubtotal('50.00');

        foreach ($lineFields as $field => $value) {
            $line->{'set' . ucfirst($field)}($value);
        }

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('OSW-ORD-' . uniqid())
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addLine($line);
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function invoiceSomeOf(FunctionalTester $I, SalesOrder $order, string $quantity): void
    {
        $line = $order->getLines()->first();
        $url = sprintf(self::ORDER_SCREEN, $order->getId());

        $I->amOnPage($url);
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $line->getProduct()->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => $quantity, 'price' => '5.00']],
        ]);

        $I->assertNotNull(
            $I->grabService(EntityManagerInterface::class)->getRepository(Invoice::class)->findOneBy(['salesOrder' => $order], ['id' => 'DESC']),
            'guard: the fixture this test depends on actually raised an invoice',
        );
    }

    private function newestInvoiceId(FunctionalTester $I): int
    {
        $id = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->company->getId()],
        );
        $I->assertNotFalse($id, 'an invoice was created for this customer');

        return (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function invoiceLineRowsFor(FunctionalTester $I, int $invoiceId): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order, id',
            [$invoiceId],
        );
    }
}
