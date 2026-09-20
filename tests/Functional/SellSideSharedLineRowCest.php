<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The sell-side line row, now one partial (templates/admin/_partials/sales_line_row.html.twig)
 * rendering the order form's, the quote form's and the standalone invoice create form's line rows,
 * with the table header and colgroup built from the same per-document column list
 * (sales_line_head.html.twig).
 *
 * ## What this file is FOR
 *
 * An extraction's failure mode is silent: the partial renders beautifully, the cell looks right,
 * and one screen quietly stops posting a column. Nothing about the page says so. So nothing here
 * asserts rendering alone — every screen assertion is paired with a POST whose result is read back
 * out of the database BY COLUMN (#624), and every POST is preceded by an assertion that the form on
 * the page actually renders a control by that name.
 *
 * That last part is not ceremony. A save test that posts hand-written field names is testing the
 * CONTROLLER, not the screen: rename an input in the template and it stays green while the form
 * stops working. The prior worker on these partials hit exactly that, which is why
 * SellSideSharedComponentsCest grew the same guard, and why this file borrows it.
 *
 * ## No bare numbers, ever (#627)
 *
 * `see('3')` matches '1,300.00'. Every figure here is compared as a stored column or read out of a
 * named element's attribute, and every ABSENCE is paired with a positive control on the SAME
 * element — a dontSeeElement on its own passes just as well against a page that failed to render.
 */
final class SellSideSharedLineRowCest
{
    /**
     * Every `lines[N][...]` key the shared row posts on each screen, as the screen should render
     * them. Read off the page and compared as a SET, so a field that is dropped, renamed or gained
     * fails here even though the table still looks complete.
     *
     * The differences between the three are the ones the partial takes arguments for, and each is a
     * documented rule rather than drift:
     *  - only a document with a stored line renders `id`   (the invoice create screen mints its own)
     *  - only a document that re-expresses a denomination renders `qty_rendered` / `price_rendered`
     *  - only the order has `batch` and `restock_eta`      (SalesOrderLine is the only line entity
     *                                                       with those columns)
     *  - only a PRODUCT row renders `unit_id`; only a BLANK row renders `name` on the order and the
     *    quote, where one cell holds either — the invoice shows both, because its rows are one kind
     *  - only a screen whose SAVE measures stock renders `stock_override_reason` (#326): the order
     *    form and the standalone invoice create screen, both of which weigh the POST against the
     *    shelf and refuse only while a shortfall has no reason on it. THE QUOTE MUST NOT HAVE ONE
     *    and its absence below is the control: a quote measures no stock and is refused for none —
     *    its shortfall is answered at conversion, by holding the ORDER as a Draft — so a box there
     *    would post a field nothing reads and promise an override that does not exist.
     */
    private const ORDER_PRODUCT_ROW_FIELDS = [
        'id', 'product_id', 'location', 'sku', 'qty', 'qty_rendered', 'stock_override_reason',
        'weight', 'unit_id', 'unit', 'tax_code', 'cost', 'price', 'price_rendered', 'batch',
    ];

    private const QUOTE_PRODUCT_ROW_FIELDS = [
        'id', 'product_id', 'location', 'sku', 'qty', 'qty_rendered', 'weight',
        'unit_id', 'unit', 'tax_code', 'cost', 'price', 'price_rendered',
    ];

    private const INVOICE_ROW_FIELDS = [
        'product_id', 'name', 'location', 'sku', 'qty', 'stock_override_reason', 'unit',
        'tax_code', 'cost', 'price',
    ];

    private ?Company $company = null;
    private ?ProductCore $product = null;
    private ?UnitOfMeasure $each = null;
    private ?UnitOfMeasure $box = null;

    /**
     * Codeception reuses ONE Cest instance for every method in this file, so a handle left on $this
     * by the previous test is a handle onto a row that has since been rolled back. Every one is
     * cleared here rather than reused.
     */
    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
        $this->each = null;
        $this->box = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('shared-line-row@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---------------------------------------------------------------- fixtures

    private function unit(FunctionalTester $I, string $code, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }
        $unit = (new UnitOfMeasure())
            ->setCode($code)->setName($code)
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase($factor)->setRoundingPrecision('1');
        $I->haveInRepository($unit);

        return $unit;
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }
        $company = (new Company())
            ->setName('Shared Line Row Co')
            ->setCode('SLR-' . uniqid())
            ->setPrimaryEmail('ap@shared-line-row.example');
        $I->haveInRepository($company);

        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Bill')->setCompanyName('Shared Line Row Co')
            ->setFirstName('Bill')->setLastName('Payer')->setAddressLine1('1 Billing Way')
            ->setCity('Vancouver')->setProvince('BC')->setCountry('CA')->setPostalCode('V5K0A1')
            ->setIsDefaultBilling(true));
        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Ship')->setCompanyName('Shared Line Row Co')
            ->setFirstName('Ship')->setLastName('Receiver')->setAddressLine1('2 Shipping Road')
            ->setCity('Burnaby')->setProvince('BC')->setCountry('CA')->setPostalCode('V5K0A2')
            ->setIsDefaultShipping(true));

        $I->haveActiveFulfillmentRegionFor($company, 'Main');
        $this->company = $company;

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        if ($this->product instanceof ProductCore) {
            return $this->product;
        }
        $this->each = $this->unit($I, 'EA', '1');
        $this->box = $this->unit($I, 'BOX-12', '12');

        $product = (new ProductCore())
            ->setSku('SLR-SKU-1')
            ->setName('Shared Line Row Widget')
            ->setUnit('EA')->setWeight('12.500')
            ->setCostPrice('30.55')->setOriginalPrice('99.99')->setSalesTaxCode('G')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setBaseUnit($this->each);
        $I->haveInRepository($product);
        $I->haveInRepository((new ProductAvailableUnit())->setProduct($product)->setUnit($this->box));
        $I->haveStockFor($product, 500, 'Main');
        $this->product = $product;

        return $product;
    }

    /** An order with one PRODUCT line entered as 2.5 BOX-12, so every optional block is on screen. */
    private function makeOrder(FunctionalTester $I): SalesOrder
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SLR-ORDER-' . uniqid())
            ->setFulfillmentRegion('Main')
            ->setSubtotal('0.00')->setTax('0.00')->setTotal('0.00');
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());

        $line = (new SalesOrderLine())
            ->setProduct($product)->setName('Shared Line Row Widget')->setSku('SLR-SKU-1')
            ->setLocation('Main')->setWeight('12.500')->setUnit('EA')->setTaxCode('G')
            ->setCost('30.55')->setPrice('65.25');
        $line->setEnteredQuantity('2.5', $this->box, $this->each);
        $order->addLine($line);
        $I->haveInRepository($order);

        return $order;
    }

    private function makeEstimate(FunctionalTester $I): Estimate
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('SLR-EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Draft', DocumentActor::system());
        $estimate->setFulfillmentRegion('Main');
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());

        $line = (new EstimateLine())
            ->setProduct($product)->setName('Shared Line Row Widget')->setSku('SLR-SKU-1')
            ->setLocation('Main')->setWeight('12.500')->setUnit('EA')->setTaxCode('G')
            ->setCost('30.55')->setPrice('65.25');
        $line->setEnteredQuantity('2.5', $this->box, $this->each);
        $estimate->addLine($line);
        $I->haveInRepository($estimate);

        return $estimate;
    }

    // ------------------------------------------------------------------- tools

    /**
     * The `lines[N][...]` keys a given row on the page actually renders, as a sorted set.
     *
     * Read off the SCREEN and not written down twice, which is the whole point: a partial that
     * renamed or dropped a field still renders a complete-looking table, and this is the only thing
     * that would notice.
     *
     * @return list<string>
     */
    private function postedLineFields(FunctionalTester $I, string $rowSelector): array
    {
        $found = [];
        foreach (['input', 'select', 'textarea'] as $control) {
            foreach ($I->grabMultiple($rowSelector . ' ' . $control, 'name') as $name) {
                if (preg_match('/^lines\[\d+\]\[(\w+)\]$/', (string) $name, $m) === 1) {
                    $found[] = $m[1];
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Asserts the form really renders a control for each of these names before anything posts them.
     *
     * Without it the POSTs below prove only that the controller accepts a field. Proved by mutation
     * while this file was written: renaming the shared row's `[sku]` input left every save
     * assertion green and only this check failed.
     */
    private function seeTheFormPosts(FunctionalTester $I, string $form, array $names): void
    {
        $rendered = [];
        foreach (['input', 'select', 'textarea'] as $control) {
            $rendered = array_merge($rendered, $I->grabMultiple($form . ' ' . $control, 'name'));
        }
        $rendered = array_filter($rendered, static fn (?string $n): bool => (string) $n !== '');

        foreach ($names as $name) {
            $I->assertContains(
                $name,
                $rendered,
                sprintf('%s renders no control named %s — this POST would be testing the controller, not the screen', $form, $name),
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(FunctionalTester $I, string $table, string $fk, int $documentId): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY id', $table, $fk),
            [$documentId],
        );
    }

    private function valueOf(FunctionalTester $I, string $selector): string
    {
        return trim((string) $I->grabAttributeFrom($selector, 'value'));
    }

    /**
     * The four quantity/price values a row renders, scraped off the page exactly as the browser
     * would post them back when nobody touches either box.
     *
     * Scraped and not written down: a hand-written figure here would be testing the controller
     * against a number this file invented, which is precisely the round trip that cannot be
     * verified that way.
     *
     * @return array<string, string>
     */
    private function boxAndTwin(FunctionalTester $I, string $row): array
    {
        $posted = [];
        foreach (['qty', 'qty_rendered', 'price', 'price_rendered'] as $field) {
            $posted[$field] = $this->valueOf($I, $row . ' input[name="lines[0][' . $field . ']"]');
        }

        return $posted;
    }

    // ------------------------------------- 1. the columns the three documents have

    /**
     * Every row has exactly as many cells as its own table has header columns.
     *
     * This is the defect a shared row invites and the reason the header now comes off the same list:
     * a row and a header that disagree leave every figure one column out of alignment, which reads
     * as a wrong number rather than as an error. Counted per screen, over every row on it — the
     * server-rendered lines, the spare no-JS rows, and the computed fee rows that span the table.
     */
    public function everyLineRowHasAsManyCellsAsTheHeaderHasColumns(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        foreach ([
            'the order form' => ['/admin/order/edit/' . $order->getId(), 'table.order-lines-table', 14],
            'the quote form' => ['/admin/estimate/edit/' . $estimate->getId(), 'table.estimate-line-table', 13],
            'the invoice create form' => ['/admin/invoice/create?company_id=' . $company->getId(), 'table.invoice-line-table', 10],
        ] as $what => [$url, $table, $expected]) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $headers = $I->grabMultiple($table . ' thead tr th');
            $I->assertCount($expected, $headers, $what . ' does not render its own column count in the header');

            $rows = $I->grabMultiple($table . ' tbody tr', 'class');
            $I->assertGreaterThan(0, count($rows), $what . ' rendered no line rows at all');

            for ($i = 1; $i <= count($rows); $i++) {
                $cells = $I->grabMultiple(sprintf('%s tbody tr:nth-child(%d) > td', $table, $i));
                $I->assertCount(
                    $expected,
                    $cells,
                    sprintf('%s: row %d has %d cells against %d header columns', $what, $i, count($cells), $expected),
                );
            }
        }
    }

    /**
     * Each table's columns are in the order that table has always had them in, and each ROW's cells
     * are in the same order as its own header.
     *
     * Counting cells against headers is not enough on its own: swap two entries in a document's
     * column list and the header and the row swap together, so the counts still agree and the table
     * is still internally coherent — it is just no longer the table it was. Proved by mutation:
     * swapping SKU and Qty in the order's list was the one planted defect the rest of this file
     * missed, which is what this test exists for.
     *
     * So both are pinned: the header labels in order, and which posted control lives in which cell.
     * The second is what ties the row to the header — a row whose cells drifted out of step with its
     * own header would leave every figure a column out of alignment.
     */
    public function eachTablesColumnsAreInTheOrderThatTableHasAlwaysHadThem(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        foreach ([
            'the order form' => [
                '/admin/order/edit/' . $order->getId(),
                'table.order-lines-table',
                'table.order-lines-table tbody tr.order-line-row:nth-child(1)',
                ['Name', 'Location', 'SKU', 'Qty', 'Weight', 'U/M', 'Tax Code', 'Tax $', 'Cost', 'Original Price', 'Price', 'Subtotal', 'Batch', 'Actions'],
                // The Qty cell holds the override reason box as well as the quantity (#326): the
                // reason is per LINE and has to post under the same index, so it lives in the one
                // cell that already owns this row's N.
                [['product_id'], ['location'], ['sku'], ['qty', 'stock_override_reason'], ['weight'], ['unit', 'unit_id'], ['tax_code'], [], ['cost'], [], ['price'], [], ['batch'], []],
            ],
            'the quote form' => [
                '/admin/estimate/edit/' . $estimate->getId(),
                'table.estimate-line-table',
                'table.estimate-line-table tbody tr.estimate-line-row:nth-child(1)',
                ['Product', 'Location', 'SKU', 'Qty', 'Weight', 'U/M', 'Tax Code', 'Tax $', 'Cost', 'Original Price', 'Price', 'Subtotal', ''],
                [['product_id'], ['location'], ['sku'], ['qty'], ['weight'], ['unit', 'unit_id'], ['tax_code'], [], ['cost'], [], ['price'], [], []],
            ],
            'the invoice create form' => [
                '/admin/invoice/create?company_id=' . $company->getId(),
                'table.invoice-line-table',
                'table.invoice-line-table tbody tr.invoice-line-row:nth-child(1)',
                ['Product', 'Description', 'Location', 'SKU', 'Qty', 'U/M', 'Tax Code', 'Cost', 'Price', ''],
                // A fresh invoice row has no product picked, so its U/M cell is the free-text box a
                // BLANK row gets — nothing declares the unit of a line with no product behind it.
                [['product_id'], ['name'], ['location'], ['sku'], ['qty', 'stock_override_reason'], ['unit'], ['tax_code'], ['cost'], ['price'], []],
            ],
        ] as $what => [$url, $table, $row, $headers, $controlsPerCell]) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $I->assertSame(
                $headers,
                array_map(trim(...), $I->grabMultiple($table . ' thead tr th')),
                $what . ' renders its columns in a different order, or under different names',
            );

            foreach ($controlsPerCell as $i => $expected) {
                $cell = sprintf('%s > td:nth-child(%d)', $row, $i + 1);
                $names = [];
                foreach (['input', 'select'] as $control) {
                    foreach ($I->grabMultiple($cell . ' ' . $control, 'name') as $name) {
                        // `id` and the two `*_rendered` twins are bookkeeping that travels with a
                        // cell rather than being what the column IS, so they are not what pins the
                        // order. Their presence is asserted elsewhere in this file.
                        if (preg_match('/^lines\[0\]\[(\w+)\]$/', (string) $name, $m) === 1
                            && !in_array($m[1], ['id', 'qty_rendered', 'price_rendered'], true)) {
                            $names[] = $m[1];
                        }
                    }
                }
                $names = array_values(array_unique($names));
                sort($names);
                // A computed cell posts nothing, and that is asserted against a cell the header
                // check above has already proved exists — not against an empty page.
                $I->assertSame(
                    $expected,
                    $names,
                    sprintf('%s: cell %d holds the wrong controls for the column above it', $what, $i + 1),
                );
            }
        }
    }

    /**
     * Batch is an ORDER column and only an order column, and so is the restock ETA beside it.
     *
     * A data-model fact rather than drift: SalesOrderLine is the only line entity carrying either,
     * because a lot is allocated at fulfilment and neither a quote nor an invoice raised from
     * nothing allocates one. Both absences are asserted on a row that is PROVED to have rendered,
     * by asserting a cell that is there (#627) — `dontSeeElement` on a 500 page passes perfectly.
     */
    public function theBatchCellIsOrderOnlyAndTheOtherTwoSimplyDoNotAskForIt(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('table.order-lines-table tbody tr.order-line-row td.order-batch-cell input.js-batch-combined');
        $I->seeElement('table.order-lines-table tbody tr.order-line-row input[name="lines[0][batch]"]');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // Positive control on the same row: the quote's line row DID render.
        $I->seeElement('table.estimate-line-table tbody tr.estimate-line-row select[name="lines[0][tax_code]"]');
        $I->dontSeeElement('table.estimate-line-table tbody tr.estimate-line-row td.order-batch-cell');
        $I->dontSeeElement('table.estimate-line-table tbody tr.estimate-line-row input[name="lines[0][batch]"]');
        $I->dontSeeElement('table.estimate-line-table tbody tr.estimate-line-row input[name="lines[0][restock_eta]"]');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeElement('table.invoice-line-table tbody tr.invoice-line-row select[name="lines[0][tax_code]"]');
        $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row td.order-batch-cell');
        $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row input[name="lines[0][batch]"]');
        $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row input[name="lines[0][restock_eta]"]');
    }

    // ---------------------------------------- 2. the hooks each document keeps

    /**
     * Each document keeps its OWN line-row hook names, and the invoice has none.
     *
     * app.js binds a full parallel set for the quote — .js-estimate-qty, .js-estimate-price,
     * .js-estimate-product-select and eleven more — so this is not a case of one document borrowing
     * the other's names. (The ADDRESS panel is: it reuses order's names on both forms because app.js
     * binds those document-wide. Both are true, and the shared row would silently break the quote's
     * handlers if it collapsed them.)
     *
     * Every absence is paired with the presence of the element it is absent FROM.
     */
    public function eachDocumentKeepsItsOwnLineRowHooksAndTheInvoiceHasNone(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        foreach (['qty', 'price', 'tax', 'sku', 'weight', 'cost', 'unit', 'product-select'] as $hook) {
            $I->seeElement('table.order-lines-table tbody tr.order-line-row .js-order-' . $hook);
            $I->dontSeeElement('table.order-lines-table tbody tr.order-line-row .js-estimate-' . $hook);
        }

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        foreach (['qty', 'price', 'tax', 'sku', 'weight', 'cost', 'unit', 'product-select'] as $hook) {
            $I->seeElement('table.estimate-line-table tbody tr.estimate-line-row .js-estimate-' . $hook);
            $I->dontSeeElement('table.estimate-line-table tbody tr.estimate-line-row .js-order-' . $hook);
        }

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        // The positive control: the invoice's row renders the same controls, unhooked.
        $I->seeElement('table.invoice-line-table tbody tr.invoice-line-row input[name="lines[0][qty]"]');
        $I->seeElement('table.invoice-line-table tbody tr.invoice-line-row select[name="lines[0][product_id]"].js-searchable-select');
        foreach (['qty', 'price', 'tax', 'sku'] as $hook) {
            $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row .js-order-' . $hook);
            $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row .js-estimate-' . $hook);
            $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row .js-invoice-' . $hook);
        }
    }

    // ------------------------------------------ 3. what each screen actually posts

    /** The set of line fields each screen renders, read off the page rather than written twice. */
    public function eachScreenPostsExactlyTheLineFieldsItsDocumentHas(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $expectedOrder = self::ORDER_PRODUCT_ROW_FIELDS;
        sort($expectedOrder);
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->assertSame(
            $expectedOrder,
            $this->postedLineFields($I, 'table.order-lines-table tbody tr.order-line-row:nth-child(1)'),
            'the order form does not post the order line row\'s fields',
        );

        $expectedQuote = self::QUOTE_PRODUCT_ROW_FIELDS;
        sort($expectedQuote);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame(
            $expectedQuote,
            $this->postedLineFields($I, 'table.estimate-line-table tbody tr.estimate-line-row:nth-child(1)'),
            'the quote form does not post the quote line row\'s fields',
        );

        $expectedInvoice = self::INVOICE_ROW_FIELDS;
        sort($expectedInvoice);
        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->assertSame(
            $expectedInvoice,
            $this->postedLineFields($I, 'table.invoice-line-table tbody tr.invoice-line-row:nth-child(1)'),
            'the invoice create form does not post the invoice line row\'s fields',
        );
    }

    // ------------------------------------------------ 4. the saves, column by column

    /** The order still saves every field the shared row posts — read back out of sales_order_line. */
    public function theOrderSavesEveryFieldTheSharedRowPosts(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $product = $this->makeProduct($I);
        $lineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $this->seeTheFormPosts($I, 'form#order-form', array_map(
            static fn (string $f): string => 'lines[0][' . $f . ']',
            self::ORDER_PRODUCT_ROW_FIELDS,
        ));

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->makeCompany($I)->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'sku' => 'SLR-POSTED-SKU',
                'qty' => '7',
                'weight' => '9.750',
                'unit_id' => '',
                'unit' => 'EA',
                'tax_code' => 'S',
                'cost' => '31.11',
                'price' => '71.50',
                'batch' => 'LOT-2026-09 x 40',
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        $rows = $this->lineRows($I, 'sales_order_line', 'order_id', (int) $order->getId());
        $I->assertCount(1, $rows, 'the order still has exactly the one line that was posted');
        $stored = $rows[0];

        // `id` named the row, so the line was UPDATED rather than dropped and re-inserted (#267).
        $I->assertSame($lineId, (int) $stored['id'], 'lines[0][id] named the existing row');
        // `product_id` cannot be checked by its own value alone — a row with no product is dropped
        // as blank, so a fumbled name deletes the line rather than nulling a column. The count
        // assertion above is the other half of this one.
        $I->assertSame((int) $product->getId(), (int) $stored['product_id'], 'lines[0][product_id] kept the product');
        $I->assertSame('Main', (string) $stored['location'], 'lines[0][location] landed in sales_order_line.location');
        $I->assertSame('SLR-POSTED-SKU', (string) $stored['sku'], 'lines[0][sku] landed in sales_order_line.sku');
        $I->assertEqualsWithDelta(7.0, (float) $stored['quantity'], 0.0001, 'lines[0][qty] landed in sales_order_line.quantity');
        $I->assertSame('9.750', (string) $stored['weight'], 'lines[0][weight] landed in sales_order_line.weight');
        $I->assertSame('EA', (string) $stored['unit'], 'lines[0][unit] landed in sales_order_line.unit');
        $I->assertNull($stored['unit_id'], 'an empty lines[0][unit_id] means "the product\'s base unit"');
        $I->assertSame('S', (string) $stored['tax_code'], 'lines[0][tax_code] landed in sales_order_line.tax_code');
        $I->assertEqualsWithDelta(31.11, (float) $stored['cost'], 0.000001, 'lines[0][cost] landed in sales_order_line.cost');
        $I->assertEqualsWithDelta(71.50, (float) $stored['price'], 0.000001, 'lines[0][price] landed in sales_order_line.price');
        $I->assertSame('LOT-2026-09 x 40', (string) $stored['batch'], 'lines[0][batch] landed in sales_order_line.batch');
        $I->assertEqualsWithDelta(500.50, (float) $stored['subtotal'], 0.01, 'the line was recalculated from the posted qty and price');
    }

    /** The quote still saves every field the shared row posts — read back out of estimate_line. */
    public function theQuoteSavesEveryFieldTheSharedRowPosts(FunctionalTester $I): void
    {
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $this->seeTheFormPosts($I, 'form#estimate-form', array_map(
            static fn (string $f): string => 'lines[0][' . $f . ']',
            self::QUOTE_PRODUCT_ROW_FIELDS,
        ));

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'sku' => 'SLR-QUOTE-SKU',
                'qty' => '6',
                'weight' => '8.250',
                'unit_id' => (string) $this->box->getId(),
                'unit' => 'EA',
                'tax_code' => 'E',
                'cost' => '29.00',
                'price' => '84.00',
            ]],
        ]);

        $rows = $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId());
        $I->assertCount(1, $rows, 'the quote still has exactly the one line that was posted');
        $stored = $rows[0];

        $I->assertSame($lineId, (int) $stored['id'], 'lines[0][id] named the existing row');
        $I->assertSame((int) $product->getId(), (int) $stored['product_id'], 'lines[0][product_id] kept the product');
        $I->assertSame('Main', (string) $stored['location'], 'lines[0][location] landed in estimate_line.location');
        $I->assertSame('SLR-QUOTE-SKU', (string) $stored['sku'], 'lines[0][sku] landed in estimate_line.sku');
        // Six BOX-12 is 72 eaches. The entered figure and the base figure are both stored, and they
        // are not allowed to disagree — which is what makes lines[0][unit_id] load-bearing here.
        $I->assertEqualsWithDelta(6.0, (float) $stored['quantity_entered'], 0.0001, 'lines[0][qty] landed in estimate_line.quantity_entered');
        $I->assertEqualsWithDelta(72.0, (float) $stored['quantity'], 0.0001, 'lines[0][unit_id] re-expressed the line in base units');
        $I->assertSame((int) $this->box->getId(), (int) $stored['unit_id'], 'lines[0][unit_id] landed in estimate_line.unit_id');
        $I->assertSame('8.250', (string) $stored['weight'], 'lines[0][weight] landed in estimate_line.weight');
        $I->assertSame('EA', (string) $stored['unit'], 'lines[0][unit] landed in estimate_line.unit');
        $I->assertSame('E', (string) $stored['tax_code'], 'lines[0][tax_code] landed in estimate_line.tax_code');
        $I->assertEqualsWithDelta(29.0, (float) $stored['cost'], 0.000001, 'lines[0][cost] landed in estimate_line.cost');
        // $84.00 a box of twelve is $7.00 an each, which is the column's denomination (#644).
        $I->assertEqualsWithDelta(7.0, (float) $stored['price'], 0.000001, 'lines[0][price] landed in estimate_line.price, per base unit');
    }

    /** The standalone invoice still saves every field the shared row posts — out of invoice_line. */
    public function theStandaloneInvoiceSavesEveryFieldTheSharedRowPosts(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $this->seeTheFormPosts($I, 'form#invoice-create-form', array_map(
            static fn (string $f): string => 'lines[0][' . $f . ']',
            self::INVOICE_ROW_FIELDS,
        ));

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                0 => [
                    'product_id' => (string) $product->getId(),
                    'name' => '',
                    'location' => 'Main',
                    'sku' => 'SLR-INV-SKU',
                    'qty' => '4',
                    'unit' => 'EA',
                    'tax_code' => 'G',
                    'cost' => '28.40',
                    'price' => '55.25',
                ],
                // A BLANK row, which is where `lines[N][name]` is the field that decides what the
                // line is called. On a product row the catalogue name wins, so posting a name there
                // would prove nothing about whether the input still arrives.
                1 => [
                    'product_id' => '',
                    'name' => 'Crating and strapping',
                    'location' => 'Main',
                    'sku' => 'CRATE',
                    'qty' => '1',
                    'unit' => 'JOB',
                    'tax_code' => 'E',
                    'cost' => '10.00',
                    'price' => '40.00',
                ],
            ],
            'save_mode' => 'draft',
        ]);

        $invoiceId = (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT id FROM invoice ORDER BY id DESC LIMIT 1',
        );
        $rows = $this->lineRows($I, 'invoice_line', 'invoice_id', $invoiceId);
        $I->assertCount(2, $rows, 'the invoice was raised with exactly the two lines that were posted');
        $stored = $rows[0];
        $blank = $rows[1];

        $I->assertSame((int) $product->getId(), (int) $stored['product_id'], 'lines[0][product_id] attached the product');
        $I->assertSame('Crating and strapping', (string) $blank['name'], 'lines[1][name] landed in invoice_line.name');
        $I->assertNull($blank['product_id'], 'the blank row stayed a blank row');
        $I->assertSame('CRATE', (string) $blank['sku'], 'lines[1][sku] landed in invoice_line.sku on the blank row too');
        $I->assertSame('Main', (string) $stored['location'], 'lines[0][location] landed in invoice_line.location');
        $I->assertSame('SLR-INV-SKU', (string) $stored['sku'], 'lines[0][sku] landed in invoice_line.sku');
        $I->assertEqualsWithDelta(4.0, (float) $stored['quantity'], 0.0001, 'lines[0][qty] landed in invoice_line.quantity');
        $I->assertSame('EA', (string) $stored['unit'], 'lines[0][unit] landed in invoice_line.unit');
        $I->assertSame('G', (string) $stored['tax_code'], 'lines[0][tax_code] landed in invoice_line.tax_code');
        $I->assertEqualsWithDelta(28.40, (float) $stored['cost'], 0.000001, 'lines[0][cost] landed in invoice_line.cost');
        $I->assertEqualsWithDelta(55.25, (float) $stored['price'], 0.000001, 'lines[0][price] landed in invoice_line.price');
    }

    // ------------------------------------ 5. the rules the arguments exist to keep

    /**
     * A BLANK price is legitimate on a quote and means "No pricing" (#254/#255).
     *
     * That is why the quote's price box is type=text with a TBD placeholder where the order's is
     * type=number: a number input with a step cannot express "deliberately unpriced". The rule is
     * asserted on the markup AND conducted through a save, because the markup alone would pass
     * against a controller that quietly stored a 0.
     */
    public function aBlankPriceOnAQuoteStillMeansNoPricing(FunctionalTester $I): void
    {
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $priceBox = 'table.estimate-line-table tbody tr.estimate-line-row input[name="lines[0][price]"]';
        $I->assertSame('text', $I->grabAttributeFrom($priceBox, 'type'), 'the quote\'s price box must stay type=text');
        $I->assertSame('TBD', $I->grabAttributeFrom($priceBox, 'placeholder'), 'the TBD placeholder names the No pricing state');
        $I->assertSame('decimal', $I->grabAttributeFrom($priceBox, 'inputmode'), 'inputmode=decimal keeps a phone keyboard numeric');

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '3',
                'tax_code' => 'G',
                'price' => '',
            ]],
        ]);

        $stored = $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId())[0];
        $I->assertNull($stored['price'], 'a blank price is stored as No pricing, not as 0.00');
        $I->assertNull($stored['subtotal'], 'an unpriced line has no subtotal');

        // And the screen says so where the figure would have been, rather than showing a $0.00 that
        // reads as free. Grabbed out of the cell, never see('TBD') over the page.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame('TBD', trim($I->grabTextFrom('table.estimate-line-table tbody tr.estimate-line-row .js-estimate-subtotal')));
    }

    /**
     * The ORDER's price box is a number input, and that is not the same decision.
     *
     * Asserted beside the quote's so the shared row cannot quietly collapse the two: a blank price
     * on an order is not a documented state, and the difference is an argument, not an accident.
     */
    public function theOrdersPriceBoxIsANumberInput(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $priceBox = 'table.order-lines-table tbody tr.order-line-row input[name="lines[0][price]"]';
        $I->assertSame('number', $I->grabAttributeFrom($priceBox, 'type'), 'the order\'s price box is type=number');
        $I->assertSame('0.01', $I->grabAttributeFrom($priceBox, 'step'), 'and it steps in cents');
        $I->assertNull($I->grabAttributeFrom($priceBox, 'placeholder'), 'the order has no No-pricing state to name');
    }

    /**
     * A save that touches NEITHER the quantity box nor the price box changes neither figure.
     *
     * This is the round trip, and it is the case a rendering change breaks silently. An order and an
     * invoice are SNAPSHOTS — what was stored is stored — and the way a save tells "this box was
     * never touched" from "the admin retyped the same number" is the hidden `qty_rendered` /
     * `price_rendered` twin beside each box: LineDenomination::boxUntouched() compares the two. If
     * the shared row renders a value into the visible input differently from how the hidden field
     * carries it — or differently from how the template did before the extraction — the comparison
     * stops matching and the stored figure is REWRITTEN by a post that changed nothing. 479 eaches
     * shown as 39.9167 cases and multiplied back is 479.0004, which the next save then refuses as
     * more than remains.
     *
     * So nothing here is hand-written. Every value posted is scraped off the rendered form, exactly
     * as the browser would send it, and the columns are compared before and after.
     */
    public function aSaveThatTouchesNeitherBoxRewritesNeitherFigure(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);
        $orderLineId = (int) $order->getLines()->first()->getId();
        $quoteLineId = (int) $estimate->getLines()->first()->getId();

        $before = [
            'order' => $this->lineRows($I, 'sales_order_line', 'order_id', (int) $order->getId())[0],
            'quote' => $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId())[0],
        ];
        // 2.5 BOX-12 is 30 eaches: a line that IS denominated in something other than its base unit,
        // which is the only shape the round trip can go wrong on.
        $I->assertEqualsWithDelta(30.0, (float) $before['order']['quantity'], 0.0001, 'the order line starts at 30 base units');
        $I->assertEqualsWithDelta(30.0, (float) $before['quote']['quantity'], 0.0001, 'the quote line starts at 30 base units');

        // --- the order ---------------------------------------------------------------------
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $orderBoxes = $this->boxAndTwin($I, 'table.order-lines-table tbody tr.order-line-row');
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->makeCompany($I)->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [0 => array_merge([
                'id' => (string) $orderLineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'sku' => 'SLR-SKU-1',
                'unit_id' => $this->valueOf($I, 'table.order-lines-table tbody tr.order-line-row select[name="lines[0][unit_id]"] option[selected]') ?: (string) $this->box->getId(),
                'tax_code' => 'G',
            ], $orderBoxes)],
            'save_mode' => 'draft_recalc',
        ]);
        $afterOrder = $this->lineRows($I, 'sales_order_line', 'order_id', (int) $order->getId())[0];
        $I->assertEqualsWithDelta((float) $before['order']['quantity'], (float) $afterOrder['quantity'], 0.0001, 'an untouched quantity box left sales_order_line.quantity alone');
        $I->assertEqualsWithDelta((float) $before['order']['quantity_entered'], (float) $afterOrder['quantity_entered'], 0.0001, 'and left sales_order_line.quantity_entered alone');
        $I->assertEqualsWithDelta((float) $before['order']['price'], (float) $afterOrder['price'], 0.000001, 'an untouched price box left sales_order_line.price alone');

        // --- the quote ---------------------------------------------------------------------
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $quoteBoxes = $this->boxAndTwin($I, 'table.estimate-line-table tbody tr.estimate-line-row');
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'fulfillment_region' => 'Main',
            'lines' => [0 => array_merge([
                'id' => (string) $quoteLineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'sku' => 'SLR-SKU-1',
                'unit_id' => (string) $this->box->getId(),
                'tax_code' => 'G',
            ], $quoteBoxes)],
        ]);
        $afterQuote = $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId())[0];
        $I->assertEqualsWithDelta((float) $before['quote']['quantity'], (float) $afterQuote['quantity'], 0.0001, 'an untouched quantity box left estimate_line.quantity alone');
        $I->assertEqualsWithDelta((float) $before['quote']['quantity_entered'], (float) $afterQuote['quantity_entered'], 0.0001, 'and left estimate_line.quantity_entered alone');
        $I->assertEqualsWithDelta((float) $before['quote']['price'], (float) $afterQuote['price'], 0.000001, 'an untouched price box left estimate_line.price alone');
    }

    /**
     * The hidden round-trip twin carries EXACTLY what the visible box was filled with.
     *
     * Byte-identical, not merely equal: boxUntouched() compares the strings first, and a partial
     * that rendered `2.5` into the box and `2.5000` into its twin would leave the fallback numeric
     * comparison as the only thing holding the round trip together. This is the invariant the whole
     * mechanism rests on, and it is one assertion.
     */
    public function eachBoxAndItsHiddenTwinCarryTheSameString(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        foreach ([
            'the order form' => ['/admin/order/edit/' . $order->getId(), 'table.order-lines-table tbody tr.order-line-row'],
            'the quote form' => ['/admin/estimate/edit/' . $estimate->getId(), 'table.estimate-line-table tbody tr.estimate-line-row'],
        ] as $what => [$url, $row]) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            foreach (['qty' => 'qty_rendered', 'price' => 'price_rendered'] as $box => $twin) {
                $boxValue = $this->valueOf($I, $row . ' input[name="lines[0][' . $box . ']"]');
                $twinValue = $this->valueOf($I, $row . ' input[name="lines[0][' . $twin . ']"]');
                // The positive control: the box is not empty, so this is not two blanks agreeing.
                $I->assertNotSame('', $boxValue, sprintf('%s rendered no value in the %s box at all', $what, $box));
                $I->assertSame($boxValue, $twinValue, sprintf('%s: lines[0][%s] must carry exactly what the %s box was filled with', $what, $twin, $box));
            }
        }
    }

    /**
     * Money keeps its two places, on the same rows whose quantities just lost theirs.
     *
     * The two rules are different and the row applies both: a quantity of 3 is "3" and a subtotal of
     * 3 is "$3.00". Read out of the cells, never see() (#627).
     */
    public function moneyCellsKeepTwoDecimalPlaces(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        foreach (['.js-order-line-tax', '.js-order-original', '.js-order-subtotal'] as $cell) {
            $text = trim($I->grabTextFrom('table.order-lines-table tbody tr.order-line-row ' . $cell));
            $I->assertMatchesRegularExpression(
                '/^\$-?[\d,]+\.\d{2}$/',
                $text,
                sprintf('%s must render money at exactly two places, got "%s"', $cell, $text),
            );
        }
    }

    /**
     * The stock hint says the same thing on both forms, because it IS the same figure.
     *
     * It is availability with this document's own holds netted back in — computed by the same
     * service on both screens — so the quote form's old "N in stock" overstated it. One figure, one
     * wording. The invoice create screen shows none at all, which is asserted beside a positive
     * control proving its row rendered.
     */
    public function theStockHintIsWordedTheSameOnBothFormsThatShowOne(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->assertSame('500 available', trim($I->grabTextFrom('table.order-lines-table tbody tr.order-line-row .line-stock-hint')));

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame('500 available', trim($I->grabTextFrom('table.estimate-line-table tbody tr.estimate-line-row .line-stock-hint')));

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeElement('table.invoice-line-table tbody tr.invoice-line-row input[name="lines[0][qty]"]');
        $I->dontSeeElement('table.invoice-line-table tbody tr.invoice-line-row .line-stock-hint');
    }
}
