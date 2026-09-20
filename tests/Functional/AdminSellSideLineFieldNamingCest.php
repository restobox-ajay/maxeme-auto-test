<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\ProductAvailableUnit;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use App\Service\Uom\ProductAvailableUnitService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The three sell-side forms post their line rows under ONE naming convention — `lines[N][field]`
 * (#624, conducted).
 *
 * ## What this file is actually for
 *
 * A field-name rename is the change with the quietest failure mode in the repo: get one name wrong
 * and the form saves a blank where a value should be, with no error, no warning and a green
 * "saved" flash. `see('Estimate saved.')` proves nothing at all here. So every posted field is
 * asserted INDIVIDUALLY, against the COLUMN it is meant to land in, read back out of the database
 * after a real form POST with a CSRF token scraped off the real page. A fumbled name comes back
 * null or default while every column around it still looks right, and the loop names which one.
 *
 * The per-field sweeps are driven off `estimateFieldSweep()` / `standaloneFieldSweep()` rather than
 * written out by hand, so a field added to a form and not to the sweep is visible as a short list
 * rather than as a silently unasserted column — the count is asserted too.
 *
 * ## The defect underneath the rename
 *
 * The quote and standalone-invoice forms used to post fourteen PARALLEL arrays (`line_qty[]`,
 * `line_price[]`, ...). Those APPEND: row N's value is at position N only while every row posts
 * exactly one entry into every array. Both templates carried filler hidden inputs for that reason.
 *
 * The quote's fillers did not cover `line_product_id[]`, which only product rows posted. So on a
 * quote whose first line was a blank/custom line and whose second was a product line, the product
 * arrived at index 0 — attached to the blank line, whose typed name was discarded — and the real
 * product row was read as empty and dropped. `mixedRowTypesKeepEveryValueOnItsOwnRow()` is that
 * case, and it fails on the parallel-array code.
 *
 * Under `lines[N][field]` a row's fields are grouped under the row's own index, so there is no
 * array left to slip and no filler input left to forget.
 *
 * ## Conventions
 *
 * Plain form POSTs (no XHR), data created by this file, columns re-read after every POST rather
 * than entities held from before it, and no `see()` of a bare number or word (#627). Every absence
 * assertion is paired with a positive control on the same column.
 */
final class AdminSellSideLineFieldNamingCest
{
    /**
     * Codeception reuses ONE Cest instance for every method in the file, so anything cached on
     * `$this` would leak across tests — and these tests create their own companies and products.
     * Nothing is kept here between methods; `_before` restates that by clearing the one slot that
     * exists, so adding a cache later cannot silently become cross-test state.
     */
    private ?int $sweepProductId = null;

    public function _before(FunctionalTester $I): void
    {
        $this->sweepProductId = null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // The field sweeps: posted name → the column it must land in
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every field a quote's product row posts, with a value chosen so that it can only have come
     * from the POST: never the column default, never the product snapshot, never what the form
     * rendered.
     *
     * `numeric` columns are compared as floats — a decimal column round-trips out of SQLite as
     * `11.11` and out of MySQL as `11.110000`, and this file is about names, not about decimal
     * formatting.
     *
     * @return array<string, array{value: string, column: string, expected: string, numeric: bool}>
     */
    private function estimateFieldSweep(): array
    {
        return [
            'location' => ['value' => 'Aisle 7',    'column' => 'location',         'expected' => 'Aisle 7',    'numeric' => false],
            'sku'      => ['value' => 'SWEEP-SKU-9','column' => 'sku',              'expected' => 'SWEEP-SKU-9','numeric' => false],
            'qty'      => ['value' => '7',          'column' => 'quantity',         'expected' => '7',          'numeric' => true],
            'weight'   => ['value' => '3.250',      'column' => 'weight',           'expected' => '3.250',      'numeric' => false],
            'unit'     => ['value' => 'CRATE',      'column' => 'unit',             'expected' => 'CRATE',      'numeric' => false],
            'tax_code' => ['value' => 'S',          'column' => 'tax_code',         'expected' => 'S',          'numeric' => false],
            'cost'     => ['value' => '11.11',      'column' => 'cost',             'expected' => '11.11',      'numeric' => true],
            'price'    => ['value' => '22.22',      'column' => 'price',            'expected' => '22.22',      'numeric' => true],
        ];
    }

    /**
     * The standalone invoice's own sweep. Same shape; the screen has no weight cell of its own, so
     * `weight` is not posted here — `theStandaloneInvoiceFormPostsExactlyTheFieldsItsReaderReads()`
     * is what holds the form and its reader to the same list.
     *
     * @return array<string, array{value: string, column: string, expected: string, numeric: bool}>
     */
    private function standaloneFieldSweep(): array
    {
        return [
            'location' => ['value' => 'Aisle 3',     'column' => 'location', 'expected' => 'Aisle 3',     'numeric' => false],
            'sku'      => ['value' => 'INVSWEEP-SKU','column' => 'sku',      'expected' => 'INVSWEEP-SKU','numeric' => false],
            'qty'      => ['value' => '6',           'column' => 'quantity', 'expected' => '6',           'numeric' => true],
            'unit'     => ['value' => 'DRUM',        'column' => 'unit',     'expected' => 'DRUM',        'numeric' => false],
            'tax_code' => ['value' => 'S',           'column' => 'tax_code', 'expected' => 'S',           'numeric' => false],
            'cost'     => ['value' => '13.13',       'column' => 'cost',     'expected' => '13.13',       'numeric' => true],
            'price'    => ['value' => '24.24',       'column' => 'price',    'expected' => '24.24',       'numeric' => true],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // QUOTE
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every field on the quote's line row, posted with a real value and read back out of the
     * column it names.
     *
     * One POST, then one assertion per field. A name that was fumbled leaves its own column at the
     * default while the rest of the row saves perfectly, which is exactly the failure this file
     * exists to make loud.
     */
    public function everyQuoteLineFieldLandsInItsOwnColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-sweep');
        $company = $this->makeCompany($I, 'Quote Sweep Co');
        $product = $this->makeProduct($I, 'QSWEEP');
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $sweep = $this->estimateFieldSweep();

        $row = ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => ''];
        foreach ($sweep as $field => $spec) {
            $row[$field] = $spec['value'];
        }

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [0 => $row],
        ]);

        $saved = $this->estimateLineRows($I, (int) $estimate->getId());
        $I->assertCount(1, $saved, 'the quote still has exactly the one line that was posted');
        $stored = $saved[0];

        // `id` named the row: the line that was already there was UPDATED rather than deleted and
        // re-inserted. A fumbled `id` shows up here as a different primary key, and nowhere else —
        // every other column would still look right.
        $I->assertSame($lineId, (int) $stored['id'], 'lines[0][id] named the existing row');

        // `product_id` is the one field that cannot be checked by its own value alone: a row with no
        // product is dropped as blank, so a fumbled name would delete the line rather than null a
        // column. The count assertion above is half of it; this is the other half.
        $I->assertSame((int) $product->getId(), (int) $stored['product_id'], 'lines[0][product_id] attached the product');

        foreach ($sweep as $field => $spec) {
            $message = sprintf('lines[0][%s] must land in estimate_line.%s', $field, $spec['column']);
            if ($spec['numeric']) {
                $I->assertEqualsWithDelta((float) $spec['expected'], (float) $stored[$spec['column']], 0.0001, $message);
            } else {
                $I->assertSame($spec['expected'], (string) $stored[$spec['column']], $message);
            }
        }

        // This row was entered in BASE units, and DenominatedQuantity encodes that as both
        // `quantity_entered` and `unit_id` NULL — the same figure in the same unit is not stored
        // twice with a factor of one between them. So the pair of NULLs is what says "7 base units"
        // here, and `quantity` above is the figure `qty` actually landed in.
        //
        // Both absences are paired with a positive control on these SAME two columns in
        // aQuoteLineUnitIdLandsInTheUnitColumnAndConvertsTheRow() below, where a row that names a
        // rung stores 4 and that rung's id.
        $I->assertNull($stored['quantity_entered'], 'a base-unit row records no separate entered figure');
        $I->assertNull($stored['unit_id'], 'an empty lines[0][unit_id] stores NULL, meaning the base unit');
    }

    /**
     * The positive control for the NULL `unit_id` asserted just above, on the same column: a row
     * naming a real unit stores that unit's id, and the quantity and price are converted through
     * its factor.
     */
    public function aQuoteLineUnitIdLandsInTheUnitColumnAndConvertsTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-unit');
        $company = $this->makeCompany($I, 'Quote Unit Co');
        $product = $this->makeProduct($I, 'QUNIT');
        $box = $this->availableUnit($I, $product, 'QUNIT-BOX-12', '12');
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [
                0 => [
                    'id' => (string) $lineId,
                    'product_id' => (string) $product->getId(),
                    'unit_id' => (string) $box->getId(),
                    'qty' => '4',
                    'price' => '120.00',
                    'sku' => '',
                    'location' => 'Main',
                    'weight' => '',
                    'unit' => '',
                    'tax_code' => 'G',
                    'cost' => '',
                ],
            ],
        ]);

        $stored = $this->estimateLineRows($I, (int) $estimate->getId())[0];

        $I->assertSame((int) $box->getId(), (int) $stored['unit_id'], 'lines[0][unit_id] named the row\'s unit');
        $I->assertEqualsWithDelta(4.0, (float) $stored['quantity_entered'], 0.0001, 'four boxes were entered');
        $I->assertEqualsWithDelta(48.0, (float) $stored['quantity'], 0.0001, 'four boxes of twelve is 48 base units');
        $I->assertEqualsWithDelta(10.0, (float) $stored['price'], 0.000001, '$120 a box of twelve is $10 a base unit');
    }

    /**
     * `qty_rendered` and `price_rendered` are what the server put in the boxes, posted straight
     * back. The save compares them with what came back so that switching the unit RE-EXPRESSES the
     * line rather than reinterpreting it.
     *
     * They are the two fields whose name cannot be checked by reading a column they write — they
     * write none. So they are checked by their EFFECT, with a positive control on the same two
     * columns: an untouched box keeps the stored size, and a touched one replaces it.
     */
    public function theRenderedBoxesKeepAQuoteLineSizeWhenTheUnitChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-rendered');
        $company = $this->makeCompany($I, 'Quote Rendered Co');
        $product = $this->makeProduct($I, 'QREND');
        $box = $this->availableUnit($I, $product, 'QREND-BOX-12', '12');
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        // Put 48 base units on the line, entered as 4 boxes of twelve at $10 a base unit.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [0 => [
                'id' => (string) $lineId, 'product_id' => (string) $product->getId(),
                'unit_id' => (string) $box->getId(), 'qty' => '4', 'price' => '120.00',
                'location' => 'Main', 'tax_code' => 'G',
            ]],
        ]);
        $before = $this->estimateLineRows($I, (int) $estimate->getId())[0];
        $I->assertEqualsWithDelta(48.0, (float) $before['quantity'], 0.0001, 'the line starts at 48 base units');

        // Now switch the row back to base units WITHOUT touching either box: both come back exactly
        // as rendered. The line's SIZE did not change, so 48 base units must survive — not 4.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [0 => [
                'id' => (string) $lineId, 'product_id' => (string) $product->getId(),
                'unit_id' => '',
                'qty' => '4', 'qty_rendered' => '4',
                'price' => '120.000000', 'price_rendered' => '120.000000',
                'location' => 'Main', 'tax_code' => 'G',
            ]],
        ]);
        $untouched = $this->estimateLineRows($I, (int) $estimate->getId())[0];
        $I->assertEqualsWithDelta(
            48.0,
            (float) $untouched['quantity'],
            0.0001,
            'lines[0][qty_rendered] said the box was untouched, so the line kept its 48 base units',
        );
        $I->assertEqualsWithDelta(
            10.0,
            (float) $untouched['price'],
            0.000001,
            'lines[0][price_rendered] said the price box was untouched, so the stored rate was kept verbatim',
        );

        // The positive control, on the same two columns: a box that does NOT match what was
        // rendered is a real edit and replaces the figure.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [0 => [
                'id' => (string) $lineId, 'product_id' => (string) $product->getId(),
                'unit_id' => '',
                'qty' => '9', 'qty_rendered' => '48',
                'price' => '33.00', 'price_rendered' => '10.000000',
                'location' => 'Main', 'tax_code' => 'G',
            ]],
        ]);
        $touched = $this->estimateLineRows($I, (int) $estimate->getId())[0];
        $I->assertEqualsWithDelta(9.0, (float) $touched['quantity'], 0.0001, 'a touched quantity box replaces the size');
        $I->assertEqualsWithDelta(33.0, (float) $touched['price'], 0.000001, 'a touched price box replaces the rate');
    }

    /**
     * The no-JS product id box: past the catalogue's inline limit the row's <select> can offer
     * nothing, and this is the only field carrying a product. It posts under its own name rather
     * than as a second `product_id`, and the save reads it when `product_id` is empty.
     */
    public function theNoJsProductIdBoxNamesTheProductOnAQuoteLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-manual');
        $company = $this->makeCompany($I, 'Quote Manual Co');
        $product = $this->makeProduct($I, 'QMANUAL');
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => '',
                'product_id_manual' => (string) $product->getId(),
                'unit_id' => '',
                'qty' => '5',
                'price' => '12.00',
                'location' => 'Main',
                'tax_code' => 'G',
            ]],
        ]);

        $stored = $this->estimateLineRows($I, (int) $estimate->getId())[0];
        $I->assertSame(
            (int) $product->getId(),
            (int) $stored['product_id'],
            'lines[0][product_id_manual] attached the product when lines[0][product_id] posted empty',
        );
        $I->assertEqualsWithDelta(5.0, (float) $stored['quantity'], 0.0001, 'and the rest of the row saved with it');
    }

    /**
     * A blank/custom line's typed description is the only thing identifying it, so it has its own
     * column and its own field.
     */
    public function aBlankQuoteLineStoresItsTypedNameInTheNameColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-blank');
        $company = $this->makeCompany($I, 'Quote Blank Co');
        $product = $this->makeProduct($I, 'QBLANK');
        $estimate = $this->makeEstimate($I, $company, $product);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [
                0 => [
                    'id' => (string) $lineId, 'product_id' => (string) $product->getId(),
                    'unit_id' => '', 'qty' => '2', 'price' => '10.00',
                    'location' => 'Main', 'tax_code' => 'G',
                ],
                1 => [
                    'id' => '', 'name' => 'Crating and handling',
                    'unit_id' => '', 'qty' => '3', 'price' => '5.00',
                    'location' => 'Main', 'tax_code' => 'E',
                ],
            ],
        ]);

        $rows = $this->estimateLineRows($I, (int) $estimate->getId());
        $I->assertCount(2, $rows, 'the blank line was added beside the product line');
        $I->assertSame('Crating and handling', (string) $rows[1]['name'], 'lines[1][name] landed in estimate_line.name');
        $I->assertNull($rows[1]['product_id'], 'a blank line carries no product');
        // Positive control on the SAME column: the product row's name is its snapshot, not blank.
        $I->assertSame($product->getName(), (string) $rows[0]['name'], 'the product row kept its own snapshot name');
    }

    /**
     * THE case the filler hidden inputs existed for, and the one they did not in fact cover.
     *
     * A quote whose first line is a blank/custom line and whose second is a product line. Under the
     * parallel arrays only product rows posted `line_product_id[]`, so the product landed at index
     * 0 — on the BLANK row, whose typed name was discarded — and the real product row was read as
     * empty and dropped. Under `lines[N][field]` each row's fields are grouped under its own index
     * and there is nothing left to slip.
     *
     * Asserted by column, per row, matching each value to the row it was typed on.
     */
    public function mixedRowTypesKeepEveryValueOnItsOwnRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-mixed');
        $company = $this->makeCompany($I, 'Quote Mixed Co');
        $first = $this->makeProduct($I, 'QMIX-A');
        $second = $this->makeProduct($I, 'QMIX-B');
        $estimate = $this->makeEstimate($I, $company, $first);
        $seedId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Three rows, deliberately mixed: blank, product, blank. Every value is distinct so that a
        // row that slipped a place cannot accidentally match.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'lines' => [
                0 => ['id' => '', 'name' => 'Blank row on top', 'unit_id' => '', 'qty' => '2', 'price' => '3.00', 'location' => 'Main', 'tax_code' => 'E'],
                1 => ['id' => (string) $seedId, 'product_id' => (string) $second->getId(), 'unit_id' => '', 'qty' => '5', 'price' => '7.00', 'location' => 'Main', 'tax_code' => 'G'],
                2 => ['id' => '', 'name' => 'Blank row at the bottom', 'unit_id' => '', 'qty' => '11', 'price' => '13.00', 'location' => 'Main', 'tax_code' => 'E'],
            ],
        ]);

        $rows = $this->estimateLineRows($I, (int) $estimate->getId());
        $I->assertCount(3, $rows, 'all three rows survived — none was read as empty and dropped');

        $I->assertSame('Blank row on top', (string) $rows[0]['name'], 'row 0 kept its own typed name');
        $I->assertNull($rows[0]['product_id'], 'row 0 did NOT pick up row 1\'s product');
        $I->assertEqualsWithDelta(2.0, (float) $rows[0]['quantity'], 0.0001, 'row 0 kept its own quantity');
        $I->assertEqualsWithDelta(3.0, (float) $rows[0]['price'], 0.000001, 'row 0 kept its own price');

        $I->assertSame((int) $second->getId(), (int) $rows[1]['product_id'], 'row 1 kept its own product');
        $I->assertEqualsWithDelta(5.0, (float) $rows[1]['quantity'], 0.0001, 'row 1 kept its own quantity');
        $I->assertEqualsWithDelta(7.0, (float) $rows[1]['price'], 0.000001, 'row 1 kept its own price');

        $I->assertSame('Blank row at the bottom', (string) $rows[2]['name'], 'row 2 kept its own typed name');
        $I->assertNull($rows[2]['product_id'], 'row 2 carries no product');
        $I->assertEqualsWithDelta(11.0, (float) $rows[2]['quantity'], 0.0001, 'row 2 kept its own quantity');
        $I->assertEqualsWithDelta(13.0, (float) $rows[2]['price'], 0.000001, 'row 2 kept its own price');
    }

    /**
     * The no-JS row loop with mixed row types: a row is dropped by its own index and the rows that
     * remain keep their values.
     *
     * Under the parallel arrays the ✕ posted an index into fourteen arrays that had to be aligned
     * for it to mean anything. Under the indexed names it removes one group.
     */
    public function removingAMixedQuoteRowDropsThatRowAndLeavesTheOthersIntact(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'quote-remove');
        $company = $this->makeCompany($I, 'Quote Remove Co');
        $product = $this->makeProduct($I, 'QREMOVE');
        $estimate = $this->makeEstimate($I, $company, $product);
        $seedId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            // Row 1 — the product row in the middle — is the one being dropped.
            'remove_line' => '1',
            'lines' => [
                0 => ['id' => '', 'name' => 'Kept blank row', 'unit_id' => '', 'qty' => '2', 'price' => '3.00', 'location' => 'Main', 'tax_code' => 'E'],
                1 => ['id' => (string) $seedId, 'product_id' => (string) $product->getId(), 'unit_id' => '', 'qty' => '5', 'price' => '7.00', 'location' => 'Main', 'tax_code' => 'G'],
                2 => ['id' => '', 'name' => 'Also kept', 'unit_id' => '', 'qty' => '11', 'price' => '13.00', 'location' => 'Main', 'tax_code' => 'E'],
            ],
        ]);

        $rows = $this->estimateLineRows($I, (int) $estimate->getId());
        $I->assertCount(2, $rows, 'exactly the pressed row was dropped');
        $I->assertSame('Kept blank row', (string) $rows[0]['name']);
        $I->assertEqualsWithDelta(2.0, (float) $rows[0]['quantity'], 0.0001, 'the row above the removed one kept its quantity');
        $I->assertSame('Also kept', (string) $rows[1]['name']);
        $I->assertEqualsWithDelta(11.0, (float) $rows[1]['quantity'], 0.0001, 'the row BELOW the removed one kept its own quantity');
        $I->assertEqualsWithDelta(13.0, (float) $rows[1]['price'], 0.000001, 'and its own price — this is the value that slipped a place before');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // STANDALONE INVOICE
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every field the standalone invoice's line row posts, read back out of `invoice_line`.
     */
    public function everyStandaloneInvoiceLineFieldLandsInItsOwnColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-sweep');
        $company = $this->makeCompany($I, 'Invoice Sweep Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'ISWEEP');

        $sweep = $this->standaloneFieldSweep();
        $row = ['product_id' => (string) $product->getId(), 'unit_id' => ''];
        foreach ($sweep as $field => $spec) {
            $row[$field] = $spec['value'];
        }

        $invoiceId = $this->postStandaloneInvoice($I, $company, [0 => $row]);
        $rows = $this->invoiceLineRows($I, $invoiceId);
        $I->assertCount(1, $rows, 'the invoice has exactly the one line that was posted');
        $stored = $rows[0];

        $I->assertSame((int) $product->getId(), (int) $stored['product_id'], 'lines[0][product_id] attached the product');

        foreach ($sweep as $field => $spec) {
            $message = sprintf('lines[0][%s] must land in invoice_line.%s', $field, $spec['column']);
            if ($spec['numeric']) {
                $I->assertEqualsWithDelta((float) $spec['expected'], (float) $stored[$spec['column']], 0.0001, $message);
            } else {
                $I->assertSame($spec['expected'], (string) $stored[$spec['column']], $message);
            }
        }

        // Entered in base units, so both denomination columns are NULL — see the quote sweep's note.
        // The positive control for the pair is
        // aStandaloneInvoiceLineUnitIdLandsInTheUnitColumnAndConvertsTheRow() below.
        $I->assertNull($stored['quantity_entered'], 'a base-unit row records no separate entered figure');
        $I->assertNull($stored['unit_id'], 'an empty lines[0][unit_id] stores NULL, meaning the base unit');
    }

    /**
     * The positive control for that NULL `unit_id`, on the same column.
     */
    public function aStandaloneInvoiceLineUnitIdLandsInTheUnitColumnAndConvertsTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-unit');
        $company = $this->makeCompany($I, 'Invoice Unit Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IUNIT');
        $box = $this->availableUnit($I, $product, 'IUNIT-BOX-12', '12');

        $invoiceId = $this->postStandaloneInvoice($I, $company, [
            0 => [
                'product_id' => (string) $product->getId(),
                'unit_id' => (string) $box->getId(),
                'qty' => '4', 'price' => '120.00',
                'name' => '', 'sku' => '', 'location' => 'Main', 'unit' => '', 'tax_code' => 'G', 'cost' => '',
            ],
        ]);

        $stored = $this->invoiceLineRows($I, $invoiceId)[0];
        $I->assertSame((int) $box->getId(), (int) $stored['unit_id'], 'lines[0][unit_id] named the row\'s unit');
        $I->assertEqualsWithDelta(4.0, (float) $stored['quantity_entered'], 0.0001, 'four boxes were entered');
        $I->assertEqualsWithDelta(48.0, (float) $stored['quantity'], 0.0001, 'four boxes of twelve is 48 base units');
        $I->assertEqualsWithDelta(10.0, (float) $stored['price'], 0.000001, '$120 a box of twelve is $10 a base unit');
    }

    /**
     * The standalone invoice's blank line and its no-JS product id box, on one document.
     */
    public function aStandaloneInvoiceStoresABlankLineNameAndReadsTheNoJsProductIdBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-blank');
        $company = $this->makeCompany($I, 'Invoice Blank Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IBLANK');

        $invoiceId = $this->postStandaloneInvoice($I, $company, [
            0 => ['product_id' => '', 'name' => 'Pallet deposit', 'unit_id' => '', 'qty' => '1', 'price' => '25.00', 'location' => 'Main', 'tax_code' => 'E', 'sku' => '', 'unit' => '', 'cost' => ''],
            1 => ['product_id' => '', 'product_id_manual' => (string) $product->getId(), 'name' => '', 'unit_id' => '', 'qty' => '2', 'price' => '9.00', 'location' => 'Main', 'tax_code' => 'G', 'sku' => '', 'unit' => '', 'cost' => ''],
        ]);

        $rows = $this->invoiceLineRows($I, $invoiceId);
        $I->assertCount(2, $rows, 'both rows saved');
        $I->assertSame('Pallet deposit', (string) $rows[0]['name'], 'lines[0][name] landed in invoice_line.name');
        $I->assertNull($rows[0]['product_id'], 'the blank line carries no product');
        $I->assertSame(
            (int) $product->getId(),
            (int) $rows[1]['product_id'],
            'lines[1][product_id_manual] attached the product when lines[1][product_id] posted empty',
        );
        // Positive control on the same column as the NULL above.
        $I->assertSame($product->getName(), (string) $rows[1]['name'], 'the product row took its snapshot name');
    }

    /**
     * Mixed row kinds on a standalone invoice, with the product row NOT first — the arrangement
     * that made the parallel arrays slip.
     */
    public function mixedStandaloneInvoiceRowsKeepEveryValueOnTheirOwnRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-mixed');
        $company = $this->makeCompany($I, 'Invoice Mixed Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IMIX');

        $invoiceId = $this->postStandaloneInvoice($I, $company, [
            0 => ['product_id' => '', 'name' => 'Rebill on top', 'unit_id' => '', 'qty' => '2', 'price' => '3.00', 'location' => 'Main', 'tax_code' => 'E', 'sku' => '', 'unit' => 'JOB', 'cost' => ''],
            1 => ['product_id' => (string) $product->getId(), 'name' => '', 'unit_id' => '', 'qty' => '5', 'price' => '7.00', 'location' => 'Main', 'tax_code' => 'G', 'sku' => 'MIX-SKU', 'unit' => '', 'cost' => '4.00'],
            2 => ['product_id' => '', 'name' => 'Rebill at the bottom', 'unit_id' => '', 'qty' => '11', 'price' => '13.00', 'location' => 'Main', 'tax_code' => 'E', 'sku' => '', 'unit' => 'JOB', 'cost' => ''],
        ]);

        $rows = $this->invoiceLineRows($I, $invoiceId);
        $I->assertCount(3, $rows, 'all three rows survived');

        $I->assertSame('Rebill on top', (string) $rows[0]['name']);
        $I->assertNull($rows[0]['product_id'], 'row 0 did NOT pick up row 1\'s product');
        $I->assertEqualsWithDelta(2.0, (float) $rows[0]['quantity'], 0.0001);
        $I->assertEqualsWithDelta(3.0, (float) $rows[0]['price'], 0.000001);

        $I->assertSame((int) $product->getId(), (int) $rows[1]['product_id'], 'row 1 kept its own product');
        $I->assertSame('MIX-SKU', (string) $rows[1]['sku'], 'row 1 kept its own SKU');
        $I->assertEqualsWithDelta(5.0, (float) $rows[1]['quantity'], 0.0001);
        $I->assertEqualsWithDelta(7.0, (float) $rows[1]['price'], 0.000001);
        $I->assertEqualsWithDelta(4.0, (float) $rows[1]['cost'], 0.0001, 'row 1 kept its own cost');

        $I->assertSame('Rebill at the bottom', (string) $rows[2]['name']);
        $I->assertNull($rows[2]['product_id']);
        $I->assertEqualsWithDelta(11.0, (float) $rows[2]['quantity'], 0.0001);
        $I->assertEqualsWithDelta(13.0, (float) $rows[2]['price'], 0.000001);
    }

    /**
     * The standalone screen re-renders itself on "Add another line" and on a refusal, and it must
     * come back with what was typed still in the boxes — under the new names, read back off the
     * page by the field each value belongs to.
     *
     * Asserted with `seeInField` against the row-scoped name rather than `see()`, so a value that
     * came back in the WRONG row's box is a failure (#627).
     */
    public function addingARowRerendersEveryTypedValueInItsOwnRowsBoxes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-rerender');
        $company = $this->makeCompany($I, 'Invoice Rerender Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IRERENDER');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?company_id=' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'add_line' => '1',
            'lines' => [
                0 => ['product_id' => '', 'name' => 'Typed on row zero', 'unit_id' => '', 'qty' => '2', 'price' => '3.00', 'location' => 'Main', 'tax_code' => 'E', 'sku' => 'ROW-ZERO', 'unit' => '', 'cost' => ''],
                1 => ['product_id' => (string) $product->getId(), 'name' => '', 'unit_id' => '', 'qty' => '5', 'price' => '7.00', 'location' => 'Main', 'tax_code' => 'G', 'sku' => 'ROW-ONE', 'unit' => '', 'cost' => ''],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Positive control that the workspace really re-rendered before anything is read off it.
        // Row 0 is a name-typed blank line and — matching order's own convention, which invoice's
        // product cell now shares (#full-parity, 2026-09-13) — a blank row holds the free-text name
        // box, not the product picker; row 1 is the one with a real product behind it.
        $I->seeElement('select[name="lines[1][product_id]"]');

        $I->seeInField('input[name="lines[0][name]"]', 'Typed on row zero');
        $I->seeInField('input[name="lines[0][sku]"]', 'ROW-ZERO');
        $I->seeInField('input[name="lines[0][qty]"]', '2');
        $I->seeInField('input[name="lines[1][sku]"]', 'ROW-ONE');
        $I->seeInField('input[name="lines[1][qty]"]', '5');
        // The row that was added is empty and is the third one — the two typed rows kept theirs.
        $I->seeInField('input[name="lines[2][sku]"]', '');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // ORDER-ROOTED INVOICE — the qty/quantity reconciliation
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The order-rooted invoice create screen spelled its quantity `lines[N][quantity]` while its
     * own sibling path in the same controller spelled it `lines[N][qty]`. One spelling now: `qty`,
     * which is what the order form — the convention's reference, and unchanged here — has always
     * said.
     *
     * Conducted on the real screen: an order is raised through the order form, then part of it is
     * invoiced through the invoice-from-order form, and `invoice_line.quantity` is what says the
     * box was read.
     */
    public function theOrderRootedInvoiceReadsItsQuantityUnderTheSameSpellingAsTheOrderForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-from-order');
        $company = $this->makeCompany($I, 'Invoice From Order Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IFROMORDER');

        $order = $this->makeOrderWithLine($I, $company, $product, 10, '5.00');
        $orderLineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        // Positive control on the same element the field assertion below depends on.
        $I->seeElement('input[name="lines[0][qty]"]');
        $I->dontSeeElement('input[name="lines[0][quantity]"]');

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'lines' => [
                0 => [
                    'product_id' => (string) $product->getId(),
                    'sales_order_line_id' => (string) $orderLineId,
                    'qty' => '4',
                    'qty_rendered' => '10',
                    'price' => '6.50',
                    'price_rendered' => '5.000000',
                ],
            ],
        ]);

        $invoiceId = $this->newestInvoiceIdFor($I, (int) $company->getId());
        $rows = $this->invoiceLineRows($I, $invoiceId);
        $I->assertCount(1, $rows, 'one line was invoiced');
        $I->assertSame($orderLineId, (int) $rows[0]['sales_order_line_id'], 'lines[0][sales_order_line_id] named the order line');
        $I->assertEqualsWithDelta(4.0, (float) $rows[0]['quantity'], 0.0001, 'lines[0][qty] billed four of the ten');
        $I->assertEqualsWithDelta(6.5, (float) $rows[0]['price'], 0.000001, 'lines[0][price] billed at the typed rate');
    }

    /**
     * The `qty_rendered` / `price_rendered` pair on the order-rooted screen, by their effect: boxes
     * that come back exactly as rendered mean "bill the whole remainder at the order's own price",
     * which is what the screen pre-fills.
     *
     * This is the positive control's opposite number on the same two columns as the test above.
     */
    public function untouchedBoxesOnTheOrderRootedScreenBillTheWholeRemainderAtTheOrdersPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'inv-from-order-untouched');
        $company = $this->makeCompany($I, 'Invoice Untouched Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'IUNTOUCHED');

        $order = $this->makeOrderWithLine($I, $company, $product, 10, '5.00');
        $orderLineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $rendered = $I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value');
        $renderedPrice = $I->grabAttributeFrom('input[name="lines[0][price]"]', 'value');

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'lines' => [
                0 => [
                    'product_id' => (string) $product->getId(),
                    'sales_order_line_id' => (string) $orderLineId,
                    'qty' => $rendered,
                    'qty_rendered' => $rendered,
                    'price' => $renderedPrice,
                    'price_rendered' => $renderedPrice,
                ],
            ],
        ]);

        $invoiceId = $this->newestInvoiceIdFor($I, (int) $company->getId());
        $rows = $this->invoiceLineRows($I, $invoiceId);
        $I->assertCount(1, $rows);
        $I->assertEqualsWithDelta(10.0, (float) $rows[0]['quantity'], 0.0001, 'the whole remainder was billed');
        $I->assertEqualsWithDelta(5.0, (float) $rows[0]['price'], 0.000001, 'at the order\'s own price');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // The forms and their readers agree — structurally, off the rendered page
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * No sell-side form posts a parallel `line_*[]` array any more, and each posts its rows under
     * `lines[N][...]`.
     *
     * Read off the REAL rendered pages rather than off a list written down here, so a field added
     * to a row under the old convention is caught without anyone remembering to update this file.
     * Paired with a positive control on the same page: the indexed names really are there.
     */
    public function noSellSideFormPostsAParallelLineArray(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'naming-structural');
        $company = $this->makeCompany($I, 'Naming Structural Co');
        $this->giveShippingAddress($I, $company);
        $product = $this->makeProduct($I, 'NSTRUCT');
        $estimate = $this->makeEstimate($I, $company, $product);
        $order = $this->makeOrderWithLine($I, $company, $product, 10, '5.00');

        $pages = [
            'quote edit' => '/admin/estimate/edit/' . $estimate->getId(),
            'quote create' => '/admin/estimate/create?company_id=' . $company->getId(),
            'standalone invoice create' => '/admin/invoice/create?company_id=' . $company->getId(),
            'invoice from order' => '/admin/invoice/create?order_id=' . $order->getId(),
        ];

        foreach ($pages as $label => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $html = $I->grabPageSource();

            // The positive control, on the same page as the absence below: this page really does
            // render line rows, under the indexed convention.
            $I->assertMatchesRegularExpression(
                '/name="lines\[[^\]]+\]\[[a-z_]+\]"/',
                $html,
                sprintf('%s renders its line fields as lines[N][field]', $label),
            );

            preg_match_all('/name="(line_[a-z_]+)\[\]"/', $html, $matches);
            $I->assertSame(
                [],
                array_values(array_unique($matches[1])),
                sprintf('%s must post no parallel line_*[] arrays', $label),
            );
        }
    }

    /**
     * The quote's line rows post one field group per row, and the row indexes are unique.
     *
     * This is the structural half of `mixedRowTypesKeepEveryValueOnItsOwnRow()`: two rows sharing
     * an index would overwrite each other silently, which no per-field column assertion on a
     * single row could see.
     */
    public function everyQuoteLineRowRendersUnderItsOwnUniqueIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'naming-indexes');
        $company = $this->makeCompany($I, 'Naming Index Co');
        $product = $this->makeProduct($I, 'NINDEX');
        $estimate = $this->makeEstimate($I, $company, $product);
        $this->addBlankLine($I, $estimate, 'A custom line');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Scoped to the line table's own tbody, NOT the whole page: the two <template> elements
        // app.js clones carry the literal `__INDEX__` placeholder, which is not a rendered row and
        // is asserted separately below. Qty is the field every row type posts, so it is the honest
        // census of rows.
        $indexes = [];
        foreach ($I->grabMultiple('#estimate-line-rows input[name$="[qty]"]', 'name') as $name) {
            preg_match('/^lines\[([^\]]+)\]/', (string) $name, $m);
            $indexes[] = $m[1] ?? $name;
        }

        $I->assertGreaterThan(2, \count($indexes), 'the two lines plus the spare no-JS rows all render a quantity box');
        $I->assertSame(
            \count($indexes),
            \count(array_unique($indexes)),
            'no two quote line rows may share an index: ' . implode(', ', $indexes),
        );
        $I->assertNotContains('__INDEX__', $indexes, 'a RENDERED row never carries the clone placeholder');
    }

    /**
     * The JS add-line path mints a fresh index per cloned row.
     *
     * The row templates app.js clones carry `__INDEX__`, and the form states the first index no
     * rendered row has taken — the two no-JS spare rows included, because they are hidden with
     * scripting on but still POST, so a clone reusing one of their indexes would let an empty spare
     * clobber a typed row. This suite executes no JavaScript, so what it can hold is the CONTRACT
     * between the two halves: the placeholder is there to substitute, and the counter starts past
     * every rendered row.
     */
    public function theClonedRowTemplateCarriesAPlaceholderAndTheFormStatesTheNextFreeIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'naming-clone');
        $company = $this->makeCompany($I, 'Naming Clone Co');
        $product = $this->makeProduct($I, 'NCLONE');
        $estimate = $this->makeEstimate($I, $company, $product);
        $this->addBlankLine($I, $estimate, 'A custom line');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $next = (int) $I->grabAttributeFrom('#estimate-form', 'data-next-line-index');

        // Two quote lines plus the two no-JS spare rows, so the first free index is 4.
        $rendered = [];
        foreach ($I->grabMultiple('#estimate-line-rows input[name$="[qty]"]', 'name') as $name) {
            preg_match('/^lines\[([^\]]+)\]/', (string) $name, $m);
            $rendered[] = (int) ($m[1] ?? -1);
        }
        $I->assertGreaterThan(
            max($rendered),
            $next,
            'the next line index must be past every rendered row, spare rows included',
        );

        // The placeholder really is in the clone source — the positive control for the absence
        // asserted on the rendered rows above.
        $I->assertStringContainsString(
            'name="lines[__INDEX__][qty]"',
            $I->grabPageSource(),
            'the cloned row template carries the placeholder app.js substitutes',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I, string $slug): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($slug . '-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name . ' ' . uniqid())
            ->setCode('LFN-' . strtoupper(substr(uniqid(), -8)))
            ->setPrimaryEmail('buyer@line-field-naming.example');
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /**
     * A standalone invoice is refused without a tax province, so the customer needs an address
     * before one can be raised — see InvoiceController::taxProvinceRefusal().
     */
    private function giveShippingAddress(FunctionalTester $I, Company $company): void
    {
        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setIsDefaultShipping(true)
            ->setIsDefaultBilling(true);
        $address
            ->setLabel('Warehouse')
            ->setAddressLine1('1 Line Field Way')
            ->setCity('Toronto')
            ->setProvince('ON')
            ->setCountry('CA')
            ->setPostalCode('M5V 2T6');
        $I->haveInRepository($address);
    }

    private function makeProduct(FunctionalTester $I, string $sku): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku . '-' . strtoupper(substr(uniqid(), -6)))
            ->setName($sku . ' Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('G')
            ->setCostPrice('2.00')
            ->setDefaultPrice('9.00')
            ->setOriginalPrice('9.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setBaseUnit($this->each($I));
        $I->haveInRepository($product);
        $I->haveStockFor($product, 100000);

        return $product;
    }

    /** `EA`, the base unit everything here is counted in. */
    private function each(FunctionalTester $I): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA']);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }

        $each = (new UnitOfMeasure())
            ->setCode('EA')
            ->setName('Each')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('1')
            ->setRoundingPrecision('1');
        $I->haveInRepository($each);

        return $each;
    }

    /** Defines a packaging rung and lists it on $product, so a line may be entered in it. */
    private function availableUnit(FunctionalTester $I, ProductCore $product, string $code, string $factor): UnitOfMeasure
    {
        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName('Box of ' . $factor)
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision('1');
        $I->haveInRepository($unit);

        $em = $I->grabService(EntityManagerInterface::class);
        $fresh = $em->find(ProductCore::class, (int) $product->getId());

        $ids = [(int) $unit->getId()];
        foreach ($em->getRepository(ProductAvailableUnit::class)->forProduct($fresh) as $existing) {
            $ids[] = (int) $existing->getUnit()->getId();
        }

        $I->grabService(ProductAvailableUnitService::class)->apply($fresh, array_values(array_unique($ids)), null);
        $em->flush();

        return $unit;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, ProductCore $product): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('LFN-' . strtoupper(substr(uniqid(), -10)))
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $I->haveInRepository($estimate);

        $line = (new EstimateLine())
            ->setEstimate($estimate)
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setUnit('EA')
            ->setQuantity('1.00')
            ->setPrice('9.00')
            ->setSubtotal('9.00');
        $I->haveInRepository($line);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Estimate::class, (int) $estimate->getId());
    }

    private function addBlankLine(FunctionalTester $I, Estimate $estimate, string $name): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $fresh = $em->find(Estimate::class, (int) $estimate->getId());
        $line = (new EstimateLine())
            ->setEstimate($fresh)
            ->setName($name)
            ->setQuantity('1.00')
            ->setSortOrder(1);
        $I->haveInRepository($line);
        $em->clear();
    }

    private function makeOrderWithLine(
        FunctionalTester $I,
        Company $company,
        ProductCore $product,
        int $quantity,
        string $price,
    ): SalesOrder {
        $total = number_format($quantity * (float) $price, 2, '.', '');

        // Re-read both through the CURRENT EntityManager: a caller that built a quote first has
        // been through an $em->clear() since, and a detached Company here is an unrelated
        // "multiple non-persisted new entities" error rather than anything about field names.
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, (int) $company->getId());
        $product = $em->find(ProductCore::class, (int) $product->getId());

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('LFN-ORD-' . strtoupper(substr(uniqid(), -8)))
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setUnit('EA')
                ->setQuantity((string) $quantity)
                ->setPrice($price)
                ->setSubtotal($total)
        );
        $I->haveInRepository($order);

        // The status seam owns the transition; an order has to be approved before it can be
        // invoiced, and going through approve() is how every other Cest does it.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $em->flush();
        $em->clear();

        return $em->find(SalesOrder::class, (int) $order->getId());
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Reading COLUMNS back
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `estimate_line` rows for this quote, in document order, as raw columns.
     *
     * Raw SQL over the shared connection rather than entity getters: the subject is which COLUMN a
     * posted field landed in, and a getter can read a value the column does not hold.
     *
     * @return list<array<string, mixed>>
     */
    private function estimateLineRows(FunctionalTester $I, int $estimateId): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT * FROM estimate_line WHERE estimate_id = ? ORDER BY sort_order, id',
            [$estimateId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceLineRows(FunctionalTester $I, int $invoiceId): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order, id',
            [$invoiceId],
        );
    }

    private function newestInvoiceIdFor(FunctionalTester $I, int $companyId): int
    {
        $id = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$companyId],
        );
        $I->assertNotFalse($id, 'an invoice was created for this customer');

        return (int) $id;
    }

    /**
     * Raises a standalone invoice through the real two-step screen and returns its id.
     *
     * @param array<int, array<string, string>> $lines
     */
    private function postStandaloneInvoice(FunctionalTester $I, Company $company, array $lines): int
    {
        $url = '/admin/invoice/create?company_id=' . $company->getId();
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'due_date' => '2026-10-11',
            'payment_term' => 'Net 30',
            'lines' => $lines,
        ]);

        return $this->newestInvoiceIdFor($I, (int) $company->getId());
    }
}
