<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Queue item 46 — the purchase order list fits, its money reads as money, and its unit count says
 * it is units. Conducted per #624, asserted per #627.
 *
 * ## Why the fit half is asserted as SHAPE and measured elsewhere
 *
 * A functional test has no layout engine: it cannot see that a cell is 324px wide or that a row is
 * 66px tall. What it CAN see is the markup that produced those numbers, which is the same division
 * of labour `feat/tracking-policy-screens` settled on when it proved a grid clipped to 44px of 852
 * and then asserted the structure rather than the pixels.
 *
 * The pixels were measured separately and are recorded here so the numbers below are not a claim
 * nobody can check. Chromium, JavaScript off, 1366x900, laying out the page this application
 * actually serves with 37 purchase orders on it and the real `public/assets/css/app.css`, against
 * main at b9ee3a85:
 *
 *                            before    after
 *     table width            1164px    1146px   the frame is 1148px
 *     overflow               +16px     -2px     the Actions column stops clipping
 *     scroll region          550px     616px    inside the same 852px core clamp
 *     rows at rest           6 of 37   6 of 37
 *     rows, bar scrolled off 6         7
 *
 * The horizontal half is the half item 46 actually measured and is now fixed outright. The
 * vertical half moves by one row and no further, and the reason is worth writing down rather than
 * rounding up: at 900px the shared chrome takes 374px of the 852px clamp — 59px admin header,
 * 108px bundle title panel, 95px sticky table header, 95px filter bar, 69px table footer — and
 * what is left divides by a row height of 66px. 66px is itself two lines: the Dated cell breaks
 * `2026-09-12` at its hyphens and the Total cell breaks `CAD 3,296.54` at its space, because the
 * table is still over-constrained at nine columns. Stop the body cells wrapping and the same
 * measurement gives 8 at rest and 9 scrolled — but `white-space: nowrap` on a `.table-card` body
 * cell is a trait core does not name (it spells it out three times locally, on `.company-table`,
 * `.user-manager-table` and `.settings-table`, and nowhere generally), so it is reported rather
 * than invented here. Item 41 unhiding `.lead` also cost this screen 52px of scroll region, which
 * is why the before column reads 550px and not the 602px the same measurement gave a day earlier.
 *
 * The cause item 46 names was still the live one: two `<input type="date">` in the Dated filter
 * CELL. A table cell cannot be narrower than its content and a date input has a fixed intrinsic
 * width, so that one column took 324px of the table and the other eight starved — which is what put
 * the vendor name onto a second line and made every row 66px instead of the 54px this grid's
 * row-action button floors it at. Moving the range into the filter bar is therefore not tidying: it
 * is the only place the width can come back from, and it is where core puts a date range on both
 * grids this one is modelled on.
 *
 * So the three structural facts asserted below are the three that produced the measurement:
 * the date inputs are OUT of the table, they are still ON the page, and the tabs and bar are inside
 * the one scroll region rather than in a `flex: 0 0 auto` panel above the card.
 *
 * ## Why every money assertion re-reads the column first
 *
 * `purchase_order.total` is `DECIMAL(12,2)`, but SQLite stores it with NUMERIC affinity and hands
 * 1520.00 back as the string "1520". That is the whole defect: the value is right and the print was
 * raw. Each test below asserts the raw column FIRST — so the short form is proved to still be what
 * the database returns — and then asserts the rendered cell. A test that only looked at the page
 * would pass just as happily if somebody "fixed" this by rewriting stored values, which is the one
 * thing that must not happen.
 *
 * `$em->clear()` before every render for the same reason: Codeception runs one kernel for the
 * suite, so a purchase order this test just built is still in the identity map and the list screen
 * would be handed the in-memory object with its `number_format`ed string intact — the page would
 * read correctly on a template that never formatted anything.
 *
 * ## #627
 *
 * No assertion here is a `see()` on a number. Every figure is compared as the full text of ONE
 * named cell of ONE row located by its own PO number, and every absence is paired with the positive
 * assertion that the thing it is looking in actually exists.
 */
final class PurchaseOrderListFitsCest
{
    private const LIST_PATH = '/admin/bundles/procurement/purchase-orders';

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    /**
     * The Total column prints two decimals and its currency, for a figure SQLite hands back short.
     */
    public function theTotalColumnPrintsTwoDecimalsWithItsCurrency(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I);

        // 4 x 380.0000 = 1520.00 — the shape item 46 names as "CAD 1520".
        $subject = $this->raise($I, $context, '4.00', '380.0000');
        // The row that must not change, and the shape that already looked right: 3296.54.
        $control = $this->raise($I, $context, '1.00', '3296.5400');

        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();

        // The column, first. If this stops being the short string the defect is gone from the data
        // rather than from the template, and the cell assertion below would prove nothing.
        $I->assertSame(
            '1520',
            (string) $connection->fetchOne('SELECT total FROM purchase_order WHERE id = ?', [$subject['id']]),
            'purchase_order.total no longer comes back from SQLite in the short form this test is about',
        );
        $I->assertSame(
            '3296.54',
            (string) $connection->fetchOne('SELECT total FROM purchase_order WHERE id = ?', [$control['id']]),
            'the control purchase order total was not stored as raised',
        );

        $rows = $this->listRows($I);

        $I->assertSame('CAD 1,520.00', $this->cell($I, $rows, $subject['number'], 'Total'));
        $I->assertSame('CAD 3,296.54', $this->cell($I, $rows, $control['number'], 'Total'));
    }

    /**
     * The unit count says it is a unit count, in its own header.
     */
    public function theOutstandingColumnSaysItIsUnits(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I);

        $subject = $this->raise($I, $context, '86.00', '1.0000');

        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();
        // '86' rather than '86.00': SQLite's NUMERIC affinity drops the scale off a SUM, which is
        // the very behaviour that produced the money defect one column to the right. What matters
        // here is that the 86 units are on the lines, so the cell asserted below is reading a real
        // figure rather than a coincidence.
        $I->assertSame(
            '86',
            (string) $connection->fetchOne(
                'SELECT SUM(quantity_ordered - COALESCE(quantity_received, 0)) FROM purchase_order_line WHERE purchase_order_id = ?',
                [$subject['id']],
            ),
            'the units outstanding this test is about are not on the lines',
        );

        $crawler = $this->listCrawler($I);
        $headers = $crawler->filter('table thead tr')->eq(0)->filter('th')->each(
            static fn(Crawler $th): string => trim(preg_replace('/\s+/', ' ', $th->text()) ?? ''),
        );

        // Positive control before the absence: the header row was found and is the whole width.
        $I->assertCount(9, $headers, 'the purchase order grid no longer has nine columns, so the header names below are being read off something else');
        $I->assertContains('Units outstanding', $headers, 'the units column does not say it counts units');
        $I->assertNotContains('Outstanding', $headers, 'a column still says only "Outstanding", which beside a money column reads as money');

        $I->assertSame('86', $this->cell($I, $this->rowsOf($crawler), $subject['number'], 'Units outstanding'));
    }

    /**
     * The date range is out of the table and still on the page, and there is one scroll region.
     *
     * These three are the shape behind the measurement in the class docblock: the 324px column is
     * gone, the filter it held is not, and the tabs are inside the region that scrolls rather than
     * in a panel above it that cannot.
     */
    public function theDateRangeIsOutOfTheColumnAndStillOnTheScreen(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I);

        for ($i = 0; $i < 3; $i++) {
            $this->raise($I, $context, '1.00', '10.0000');
        }

        $crawler = $this->listCrawler($I);

        // Positive control first: the per-column filter row is there and still carries its filters,
        // so the absence asserted next is about the date inputs and not about a missing row.
        $filterRow = $crawler->filter('table thead tr.filter-row');
        $I->assertSame(1, $filterRow->count(), 'the per-column filter row is gone');
        $I->assertGreaterThan(
            3,
            $filterRow->filter('input:not([type="hidden"]), select')->count(),
            'the filter row has lost its per-column controls',
        );
        $I->assertSame(
            0,
            $filterRow->filter('input[type="date"]')->count(),
            'a date input is back in the filter row; that one cell takes 324px of a 1148px table and'
            . ' starves every other column into wrapping',
        );

        // Moved, not deleted: both bounds are in the filter bar, inside the scroll region.
        $bar = $crawler->filter('.table-scroll-region .order-filter-section');
        $I->assertSame(1, $bar->count(), 'the filter bar is not inside the scroll region');
        $I->assertSame(1, $bar->filter('input[type="date"][name="filters[from]"]')->count(), 'the "dated on or after" bound is gone');
        $I->assertSame(1, $bar->filter('input[type="date"][name="filters[to]"]')->count(), 'the "dated on or before" bound is gone');
        $I->assertSame(1, $bar->filter('nav.order-status-tabs')->count(), 'the status tabs are not in the filter bar, so they sit in a flex:0 0 auto panel the scroll region never gets that height back from');

        $I->assertSame(1, $crawler->filter('.table-scroll-region')->count(), 'more than one scroll region competes for the clamped height');
        $I->assertSame(0, $crawler->filter('.panel.compact-panel nav.order-status-tabs')->count(), 'a status bar is back in a panel above the card');

        // Every row the query returned is in the document — the count half of the measurement.
        $rows = $this->rowsOf($crawler);
        $I->assertSame(3, count($rows), 'the grid did not render every purchase order it fetched');
        foreach ($rows as $row) {
            $I->assertSame(9, $row->filter('td')->count(), 'a body row does not span the nine columns the header declares');
        }
    }

    /**
     * The range still narrows the grid from its new home, with JavaScript off.
     */
    public function theDateRangeStillFiltersFromTheBar(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I);

        $early = $this->raise($I, $context, '1.00', '10.0000', '2026-01-05');
        $late = $this->raise($I, $context, '1.00', '10.0000', '2026-08-20');

        // Positive control: unfiltered, both are on the page.
        $unfiltered = $this->numbersOn($I, self::LIST_PATH . '?limit=100');
        $I->assertContains($early['number'], $unfiltered);
        $I->assertContains($late['number'], $unfiltered);

        // Plain GET, exactly what the bar's Filter button submits with scripting off.
        $filtered = $this->numbersOn($I, self::LIST_PATH . '?limit=100&filters%5Bfrom%5D=2026-06-01');
        $I->assertContains($late['number'], $filtered, 'the range dropped a purchase order that is inside it');
        $I->assertNotContains($early['number'], $filtered, 'the range kept a purchase order dated before it');
    }

    /**
     * Cancelling from the list moves that row and leaves the row beside it alone.
     *
     * The restructure moved the tabs and the date range across the template; the row-action form is
     * still outside the filter form, still posts, and still reaches the right document. Asserted as
     * `purchase_order.status` read back by column for BOTH rows — the control is the half that
     * catches a cancel that hit the wrong id.
     */
    public function cancellingFromTheListMovesOnlyThatRow(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I);

        $subject = $this->raise($I, $context, '2.00', '25.0000');
        $control = $this->raise($I, $context, '3.00', '15.0000');

        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();
        $before = $connection->fetchAssociative('SELECT status, total, po_number FROM purchase_order WHERE id = ?', [$control['id']]);
        $I->assertSame('Draft', (string) ($before['status'] ?? ''), 'the control did not start as a draft');

        $I->amOnPage(self::LIST_PATH . '?limit=100');
        $I->seeResponseCodeIsSuccessful();

        // The token off the real form the real screen offers for this document.
        $token = (string) $I->grabAttributeFrom(
            sprintf('form#cancel-po-%d input[name="_token"]', $subject['id']),
            'value',
        );
        $I->assertNotSame('', $token, 'the list offers no cancel form for a draft purchase order');

        $I->sendFormPostRequest(sprintf('%s/%d/cancel', self::LIST_PATH, $subject['id']), ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            'Cancelled',
            (string) $connection->fetchOne('SELECT status FROM purchase_order WHERE id = ?', [$subject['id']]),
            'the cancel posted from the list did not reach the document',
        );

        $after = $connection->fetchAssociative('SELECT status, total, po_number FROM purchase_order WHERE id = ?', [$control['id']]);
        $I->assertSame($before, $after, 'the purchase order beside the cancelled one changed');
    }

    /*
     * ----------------------------------------------------------------------------------------
     * Fixtures and readers
     * ----------------------------------------------------------------------------------------
     */

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('po-fit-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{vendorId: int, warehouseId: int, productId: int} */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('PO Fit Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Fit Vendor ' . $tag)->setCurrency('CAD');
        $em->persist($vendor);

        // Exempt, so the total is exactly the goods and this test is about formatting rather than
        // about whichever tax bundle happens to be active.
        $product = (new ProductCore())
            ->setSku('POFIT-' . $tag)
            ->setName('Fit Widget ' . $tag)
            ->setSalesTaxCode('E')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return [
            'vendorId' => (int) $vendor->getId(),
            'warehouseId' => (int) $warehouse->getId(),
            'productId' => (int) $product->getId(),
        ];
    }

    /**
     * Raise one purchase order through the real screens: GET /new, scrape its CSRF token, POST the
     * form the page carries.
     *
     * @param array{vendorId: int, warehouseId: int, productId: int} $context
     *
     * @return array{id: int, number: string}
     */
    private function raise(
        FunctionalTester $I,
        array $context,
        string $quantity,
        string $unitCost,
        string $documentDate = '2026-09-11',
    ): array {
        $I->amOnPage(self::LIST_PATH . '/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action$="/purchase-orders/save"] input[name="_token"]', 'value');

        $I->sendFormPostRequest(self::LIST_PATH . '/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'warehouse_id' => (string) $context['warehouseId'],
            'document_date' => $documentDate,
            'tax_province' => 'AB',
            'charge_lines_present' => '1',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productId'],
                    'name' => 'Fit Widget',
                    'qty' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_code' => 'E',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();
        $row = $connection->fetchAssociative('SELECT id, po_number FROM purchase_order ORDER BY id DESC LIMIT 1');
        $I->assertIsArray($row, 'the save endpoint wrote no purchase order at all');

        return ['id' => (int) $row['id'], 'number' => (string) $row['po_number']];
    }

    /**
     * The list screen's DOM, rendered from the DATABASE rather than from the identity map.
     */
    private function listCrawler(FunctionalTester $I, string $path = self::LIST_PATH . '?limit=100'): Crawler
    {
        $I->grabService('doctrine.orm.entity_manager')->clear();
        $I->amOnPage($path);
        $I->seeResponseCodeIsSuccessful();

        return new Crawler($I->grabPageSource());
    }

    /** @return list<Crawler> */
    private function listRows(FunctionalTester $I): array
    {
        return $this->rowsOf($this->listCrawler($I));
    }

    /** @return list<Crawler> */
    private function rowsOf(Crawler $crawler): array
    {
        return $crawler->filter('table tbody tr.data-item-row')->each(static fn(Crawler $row): Crawler => $row);
    }

    /**
     * The full text of ONE named cell of the row whose PO cell holds $number.
     *
     * This is the #627 shape: a figure is read out of the cell it is supposed to be in, on the row
     * it is supposed to be on, and compared whole — never searched for as a substring of the page,
     * where "CAD 1,520.00" would be satisfied by the filter dropdown or by another row's total.
     *
     * @param list<Crawler> $rows
     */
    private function cell(FunctionalTester $I, array $rows, string $number, string $label): string
    {
        foreach ($rows as $row) {
            if (trim($row->filter('td[data-label="PO"]')->text()) !== $number) {
                continue;
            }

            $cells = $row->filter(sprintf('td[data-label="%s"]', $label));
            $I->assertSame(1, $cells->count(), sprintf('row %s has no single "%s" cell', $number, $label));

            return trim(preg_replace('/\s+/', ' ', $cells->text()) ?? '');
        }

        $I->fail(sprintf('no row on the purchase order list has %s in its PO cell', $number));
    }

    /** @return list<string> */
    private function numbersOn(FunctionalTester $I, string $path): array
    {
        return $this->listCrawler($I, $path)
            ->filter('table tbody tr.data-item-row td[data-label="PO"]')
            ->each(static fn(Crawler $cell): string => trim($cell->text()));
    }
}
