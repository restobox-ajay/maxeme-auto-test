<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Serial/expiry/batch-lot tracking as it actually exists in this codebase, across Estimate, Sales
 * Order and Invoice — not as three orthogonal fields, but as they really are:
 *
 *   - There is no serial column and no expiry column anywhere. There is ONE free-text column,
 *     `batch`, on SalesOrderLine and InvoiceLine only. `TrackingPolicy::MODE_LOT` / `MODE_SERIAL`
 *     govern whether the CAPTURE UI offers itself for a product, not what gets stored — a lot number
 *     and a serial number both land in the same `batch` string. Expiry rides inside that same free
 *     text (the JS widget appends a date token); there is no structured expiry column to assert
 *     against.
 *   - EstimateLine has no `batch` column at all, by design (#250): a quote allocates nothing, so a
 *     lot/serial value on it would be speculative.
 *   - SalesOrderLine.batch persists correctly through the real order screens.
 *   - InvoiceLine.batch renders in the UI and is even posted by the invoice create/edit screens'
 *     hidden `lines[N][batch]` field — but `InvoiceController::postedInvoiceLineRows()` never reads
 *     that key back out of the request, and nothing in `applyLineRows()` calls `setBatch()`. So it
 *     is never persisted, on either invoice entry path (standalone or order-linked). That gap is
 *     asserted here explicitly, as real behavior, not filed away as a TODO.
 */
final class SellSideBatchCaptureAcrossDocumentsCest
{
    private ?Company $company = null;

    public function _before(FunctionalTester $I): void
    {
        $this->company = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('batch-capture-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---------------------------------------------------------------- fixtures

    private function makeCompany(FunctionalTester $I): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }
        $company = (new Company())
            ->setName('Batch Capture Co')
            ->setCode('BCC-' . uniqid())
            ->setPrimaryEmail('ap@batch-capture.example');
        $I->haveInRepository($company);

        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Bill')->setCompanyName('Batch Capture Co')
            ->setFirstName('Bill')->setLastName('Payer')->setAddressLine1('1 Billing Way')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B3')
            ->setIsDefaultBilling(true));
        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Ship')->setCompanyName('Batch Capture Co')
            ->setFirstName('Ship')->setLastName('Receiver')->setAddressLine1('2 Shipping Road')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B4')
            ->setIsDefaultShipping(true));

        $I->haveActiveFulfillmentRegionFor($company, 'Main');
        $this->company = $company;

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $skuPrefix, ?string $trackingMode): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($skuPrefix . '-' . uniqid())
            ->setName('Batch Capture Widget ' . $skuPrefix)
            ->setUnit('EA')
            ->setSalesTaxCode('G')
            ->setCostPrice('4.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $em = $I->grabService(EntityManagerInterface::class);
        if ($trackingMode !== null) {
            $policy = (new TrackingPolicy())
                ->setName('Policy ' . $skuPrefix)
                ->setMode($trackingMode)
                ->setTrackIn(true)
                ->setTrackOut(true);
            $em->persist($policy);
            $product->setTrackingPolicy($policy);
        }
        $em->persist($product);
        $em->flush();

        $I->haveStockFor($product, 500, 'Main');

        return $product;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    /** @return list<string> column names, as sqlite's own PRAGMA names them */
    private function columnsOf(FunctionalTester $I, string $table): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection($I)->fetchAllAssociative('PRAGMA table_info(' . $table . ')'),
        );
    }

    // ------------------------------------------------------- 1. the schema fact itself

    /**
     * EstimateLine carries no `batch` column at all — a schema fact, not merely a form that omits
     * one. Proved with two positive controls on the SAME check (PRAGMA table_info against the other
     * two line tables), so a typo'd table name that returned nothing for all three could not pass.
     */
    public function estimateLineHasNoBatchColumnWhileTheOtherTwoLineTablesDo(FunctionalTester $I): void
    {
        $I->assertNotContains('batch', $this->columnsOf($I, 'estimate_line'), 'estimate_line must not carry a batch column — #250');
        $I->assertContains('batch', $this->columnsOf($I, 'sales_order_line'), 'guard: sales_order_line really does carry one');
        $I->assertContains('batch', $this->columnsOf($I, 'invoice_line'), 'guard: invoice_line really does carry one');
    }

    // ------------------------------------------------------- 2. the order: persists, either mode

    public function theOrderPersistsABatchValueForALotTrackedProduct(FunctionalTester $I): void
    {
        $this->assertOrderPersistsBatch($I, TrackingPolicy::MODE_LOT, 'LOT-2026-09-30 40');
    }

    public function theOrderPersistsABatchValueForASerialTrackedProduct(FunctionalTester $I): void
    {
        $this->assertOrderPersistsBatch($I, TrackingPolicy::MODE_SERIAL, 'SN-88213');
    }

    /**
     * The `batch` box is the SAME free-text column regardless of the product's tracking mode — only
     * whether the capture UI offers itself differs (OrderLineBatchVisibilityCest). This is the
     * persistence half: posted through the real create screen, read back by column.
     */
    private function assertOrderPersistsBatch(FunctionalTester $I, string $trackingMode, string $batchValue): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'ORD-' . strtoupper($trackingMode), $trackingMode);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '3',
                'price' => '10.00',
                'tax_code' => 'G',
                'batch' => $batchValue,
            ]],
            'save_mode' => 'order',
        ]);

        $row = $this->connection($I)->fetchAssociative(
            'SELECT sol.batch, sol.product_id FROM sales_order_line sol JOIN sales_order so ON so.id = sol.order_id WHERE so.company_id = ? ORDER BY sol.id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertIsArray($row, 'the order was raised with a line');
        $I->assertSame((int) $product->getId(), (int) $row['product_id'], 'guard: the right line was read');
        $I->assertSame($batchValue, (string) $row['batch'], sprintf('sales_order_line.batch for a %s-tracked product', $trackingMode));
    }

    // -------------------------- 2b. the order: MULTIPLE lots/serials on ONE line

    /**
     * The real shape a split line actually carries: `hydrateSellDocBatchRows()` /
     * `serializeSellDocBatchCell()` (app.js) join N `(label, date)` rows with `"; "`, each rendered
     * back as `"<label> <ISO-date>"`. Server-side this string is never parsed (OrderController just
     * calls `setBatch()` on it verbatim) — so this proves the round trip holds for the REAL
     * multi-entry format, not just the single-value string the other tests use, three distinct lots
     * with three distinct expiry dates covering one line's quantity.
     */
    public function theOrderPersistsMultipleLotsWithDistinctExpiryDatesOnOneLine(FunctionalTester $I): void
    {
        $this->assertOrderPersistsBatch(
            $I,
            TrackingPolicy::MODE_LOT,
            'LOT-2026-09-A 2026-09-30; LOT-2026-09-B 2026-10-15; LOT-2026-09-C 2026-11-01',
        );
    }

    /** Same shape, no dates — five serials standing in for a qty-5 serial-tracked line. */
    public function theOrderPersistsMultipleSerialsOnOneLine(FunctionalTester $I): void
    {
        $this->assertOrderPersistsBatch($I, TrackingPolicy::MODE_SERIAL, 'SN-1001; SN-1002; SN-1003; SN-1004; SN-1005');
    }

    /**
     * `batch` is declared `#[ORM\Column(length: 120)]`, but nothing enforces that at write time:
     * OrderController writes it through TextInput::nullableString() (no cap — TextInput also has a
     * nullableStringMax() this path does not call), and SQLite (this app's only database, dev and
     * prod alike — CLAUDE.md) has no VARCHAR length enforcement regardless of what the column says.
     * A real admin serializing ten-plus serials onto one line will produce a string past 120 characters
     * without any error surfacing anywhere — so this asserts what actually happens (full string
     * persisted, uncut), not what the column declaration alone would suggest.
     */
    public function aBatchValueLongerThanItsDeclaredColumnLengthIsNotTruncatedOrRejected(FunctionalTester $I): void
    {
        $serials = array_map(static fn (int $n): string => sprintf('SERIAL-NUMBER-%04d', $n), range(1, 12));
        $longValue = implode('; ', $serials);
        $I->assertGreaterThan(120, strlen($longValue), 'guard: the fixture string really does exceed the declared column length');

        $this->assertOrderPersistsBatch($I, TrackingPolicy::MODE_SERIAL, $longValue);
    }

    /**
     * The admin order list's batch filter (`OrderController::index()`, `filters[batchNumber]`,
     * `l.batch LIKE '%needle%'`) is a plain substring match against the whole free-text column — so
     * searching for the SECOND or THIRD lot on a split line has to find the order too, not only the
     * first. That is the one place in the app "multiple lots on one line" is more than persisted
     * text: it is also a search surface, and a naive implementation keyed on "starts with" or "the
     * first token" would fail exactly this case.
     */
    public function searchingTheOrderListByOneOfSeveralLotsOnASplitLineStillFindsIt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'MULTI-LOT-SEARCH', TrackingPolicy::MODE_LOT);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '15',
                'price' => '10.00',
                'tax_code' => 'G',
                'batch' => 'LOT-ALPHA 2026-09-30; LOT-BRAVO 2026-10-15; LOT-CHARLIE 2026-11-01',
            ]],
            'save_mode' => 'order',
        ]);

        $orderNumber = (string) $this->connection($I)->fetchOne(
            'SELECT so.order_number FROM sales_order so WHERE so.company_id = ? ORDER BY so.id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertNotSame('', $orderNumber, 'guard: the order was actually raised');

        // The THIRD lot, not the first — the case a "first token only" search would miss.
        $I->amOnPage('/admin/order?filters[batchNumber]=' . urlencode('LOT-CHARLIE'));
        $I->seeResponseCodeIsSuccessful();
        $I->see($orderNumber, '.order-list-table');

        // A lot code that was never on this line must not match it.
        $I->amOnPage('/admin/order?filters[batchNumber]=' . urlencode('LOT-DELTA'));
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee($orderNumber, '.order-list-table');
    }

    /**
     * A real order is rarely one product. This is the case every test above assumed away by never
     * posting more than one line: a lot-tracked line, a serial-tracked line, and an untracked line
     * with NO batch at all, all on ONE order, in ONE POST, at once — proving `lines[N][batch]` keeps
     * each row's value with its own row rather than one index bleeding into another (e.g. an
     * off-by-one in the posted array, or a form-hydration bug that reuses the last non-empty value).
     * The untracked line is the sharpest half of this: it has to come back null, not inherit either
     * neighbor's string.
     */
    public function threeLinesWithDifferentTrackingNeedsOnOneOrderEachKeepTheirOwnBatchValue(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $lotProduct = $this->makeProduct($I, 'MIX-LOT', TrackingPolicy::MODE_LOT);
        $serialProduct = $this->makeProduct($I, 'MIX-SERIAL', TrackingPolicy::MODE_SERIAL);
        $plainProduct = $this->makeProduct($I, 'MIX-PLAIN', null);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [
                [
                    'product_id' => (string) $lotProduct->getId(),
                    'location' => 'Main', 'qty' => '20', 'price' => '10.00', 'tax_code' => 'G',
                    'batch' => 'LOT-MIX-A 2026-09-30; LOT-MIX-B 2026-10-31',
                ],
                [
                    'product_id' => (string) $serialProduct->getId(),
                    'location' => 'Main', 'qty' => '3', 'price' => '10.00', 'tax_code' => 'G',
                    'batch' => 'SN-MIX-1; SN-MIX-2; SN-MIX-3',
                ],
                [
                    'product_id' => (string) $plainProduct->getId(),
                    'location' => 'Main', 'qty' => '5', 'price' => '10.00', 'tax_code' => 'G',
                    // No `batch` key at all — the shape an untracked product's row actually posts,
                    // since its capture UI never renders (OrderLineBatchVisibilityCest).
                ],
            ],
            'save_mode' => 'order',
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT sol.product_id, sol.batch FROM sales_order_line sol JOIN sales_order so ON so.id = sol.order_id WHERE so.company_id = ? ORDER BY sol.sort_order ASC',
            [$company->getId()],
        );
        $I->assertCount(3, $rows, 'all three lines were raised, none dropped or merged');

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['batch'];
        }
        $I->assertSame(
            'LOT-MIX-A 2026-09-30; LOT-MIX-B 2026-10-31',
            (string) $byProduct[$lotProduct->getId()],
            "the lot-tracked line's own batch, not the serial line's or the plain line's",
        );
        $I->assertSame(
            'SN-MIX-1; SN-MIX-2; SN-MIX-3',
            (string) $byProduct[$serialProduct->getId()],
            "the serial-tracked line's own batch",
        );
        $I->assertNull(
            $byProduct[$plainProduct->getId()],
            'the untracked line stayed null — it inherited neither neighbor\'s batch string',
        );
    }

    // ---------------------------------------------- 3. estimate -> order conversion

    /**
     * A converted order's line starts with no lot/serial, even for a tracked product — there was
     * nothing on the estimate to carry across (EstimateConversionService never calls setBatch(),
     * because EstimateLine has nothing to read it from). An admin adds it on the order afterwards,
     * once fulfilment actually allocates something real.
     */
    public function estimateToOrderConversionStartsTheNewOrderLinesBatchAtNullEvenForATrackedProduct(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'CONV-LOT', TrackingPolicy::MODE_LOT);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('BCC-EST-' . uniqid())
            ->setSource('Admin')
            // isFullyPriced() (the customer Accept button's own gate) requires shippingTotal to be
            // resolved too, not just the lines — a shipping row is what resolves it.
            ->setFeeLines(json_encode([['slug' => 'shipping', 'label' => 'Shipping', 'taxClass' => 'G', 'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual']]))
            ->setSubtotal('30.00')->setTax('0.00')->setTotal('30.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->setFulfillmentRegion('Main');
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setLocation('Main')->setUnit('EA')->setTaxCode('G')
                ->setQuantity('3.00')->setCost('4.00')->setPrice('10.00')->setSubtotal('30.00')
        );
        $I->haveInRepository($estimate);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('batch-capture-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Buy')->setLastName('Er')->setCompany($company)->setStatus('Active');
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);
        $I->amLoggedInAs($customer, 'main');
        $I->haveHttpHeader('Host', 'localhost');

        $I->amOnPage('/estimates/' . $estimate->getId());
        $html = $I->grabPageSource();
        preg_match('/<form[^>]*action="[^"]*\/accept"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $html, $m);
        $I->assertNotEmpty($m[1] ?? '', 'the accept form rendered a CSRF token');

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $m[1]]);
        $I->seeResponseCodeIsSuccessful();

        $row = $this->connection($I)->fetchAssociative(
            'SELECT sol.batch, sol.product_id FROM sales_order_line sol JOIN sales_order so ON so.id = sol.order_id WHERE so.company_id = ? ORDER BY sol.id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertIsArray($row, 'the accept converted the estimate into an order with a line');
        $I->assertSame((int) $product->getId(), (int) $row['product_id'], 'guard: the right line was read, and it is the tracked product');
        $I->assertNull($row['batch'], 'a converted order line starts with no lot/serial, tracked product or not');
    }

    // ------------------------------------------------ 4/5. invoice: rendered, posted, dropped

    /**
     * The standalone invoice create screen's row posts `lines[0][batch]` (the hidden
     * `.js-batch-combined` twin every document's shared row renders) — but the value never reaches
     * `invoice_line.batch`. This is current, real behavior: InvoiceController::postedInvoiceLineRows()
     * has no `batch` key at all, so `applyLineRows()` never calls setBatch() on the new line.
     */
    public function theStandaloneInvoiceScreenPostsABatchValueButItIsNotPersisted(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'INV-LOT', TrackingPolicy::MODE_LOT);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="lines[0][batch]"]');

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'draft',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '2',
                'price' => '10.00',
                'tax_code' => 'G',
                'batch' => 'LOT-SHOULD-NOT-SAVE',
            ]],
        ]);

        $row = $this->connection($I)->fetchAssociative(
            'SELECT il.batch, il.product_id FROM invoice_line il JOIN invoice i ON i.id = il.invoice_id WHERE i.company_id = ? ORDER BY il.id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertIsArray($row, 'the invoice was raised with a line');
        $I->assertSame((int) $product->getId(), (int) $row['product_id'], 'guard: the right line was read');
        $I->assertNull($row['batch'], 'invoice_line.batch is not written by the standalone create screen — a real, current gap');
    }

    /**
     * The same gap holds on the order-linked path, and the order line's OWN batch is left alone by
     * the attempt — echoed here beside the ledger of what the invoice screen actually wrote, rather
     * than assumed from OneInvoiceCreateScreenCest's own coverage of the untouched-order-line half.
     */
    public function theOrderLinkedInvoiceScreenAlsoDropsTheBatchValueAndLeavesTheOrderLinesBatchAlone(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'CONV-INV-LOT', TrackingPolicy::MODE_LOT);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('BCC-ORD-' . uniqid())
            ->setSubtotal('100.00')->setTax('0.00')->setTotal('100.00');
        $line = (new SalesOrderLine())
            ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
            ->setLocation('Main')->setUnit('EA')->setTaxCode('G')
            ->setQuantity('10.00')->setPrice('10.00')->setSubtotal('100.00')
            ->setBatch('LOT-77');
        $order->addLine($line);
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $url = '/admin/invoice/create?order_id=' . $order->getId();
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'sales_order_line_id' => (string) $line->getId(),
                'qty' => '4.00',
                'price' => '10.00',
                'batch' => 'LOT-SHOULD-NOT-OVERWRITE',
            ]],
        ]);

        $invoiceRow = $this->connection($I)->fetchAssociative(
            'SELECT il.batch, il.product_id FROM invoice_line il JOIN invoice i ON i.id = il.invoice_id WHERE i.sales_order_id = ? ORDER BY il.id DESC LIMIT 1',
            [$order->getId()],
        );
        $I->assertIsArray($invoiceRow, 'the conversion raised an invoice line');
        $I->assertSame((int) $product->getId(), (int) $invoiceRow['product_id'], 'guard: the right line was read');
        $I->assertNull($invoiceRow['batch'], 'invoice_line.batch is not written on the order-linked path either');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $afterOrder = $em->find(SalesOrder::class, (int) $order->getId());
        $I->assertSame('LOT-77', (string) $afterOrder->getLines()->first()->getBatch(), "the order line's own lot was not touched by invoicing against it");
    }

    // --------------------------------------------------- 6. the sharper question: does a save WIPE it

    /**
     * The invoice screen cannot SET a batch — but does re-saving an invoice line that already carries
     * one (written directly, the way a migration or an import would) silently WIPE it? That is the
     * consequential version of the gap above: "cannot enter" is a missing feature, "erases on the
     * next unrelated save" is data loss.
     *
     * It does wipe it, and the reason is sharper than "batch isn't in the field list":
     * `InvoiceController::applyLineRows()` builds every line with `new InvoiceLine()` on EVERY save —
     * unlike the order form (SellSideSharedLineRowCest::theOrderSavesEveryFieldTheSharedRowPosts,
     * which posts `lines[0][id]` to UPDATE the existing row in place), an invoice edit discards and
     * rebuilds every line from the posted fields alone, whatever `lines[N][id]` says. So this is not
     * "an edit that never mentions batch leaves it alone" — the OLD LINE ROW ITSELF is gone after any
     * save, and any column that save never populates (batch is simply the one with no setter call
     * anywhere in the file) cannot survive it, regardless of what the admin intended to touch.
     */
    public function reSavingAnInvoiceRebuildsEveryLineFromScratchAndWipesAPreExistingBatchValue(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'EDIT-LOT', TrackingPolicy::MODE_LOT);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'draft',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '2',
                'price' => '10.00',
                'tax_code' => 'G',
            ]],
        ]);

        $invoiceId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );
        $lineId = (int) $this->connection($I)->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);

        // Written straight onto the line, bypassing the form — the same fixture pattern
        // OrderLineBatchVisibilityCest uses for "data that predates the current save path".
        $em = $I->grabService(EntityManagerInterface::class);
        $line = $em->find(\App\Entity\InvoiceLine::class, $lineId);
        $line->setBatch('LOT-PRE-EXISTING');
        $em->flush();

        $editUrl = '/admin/invoice/edit/' . $invoiceId;
        $I->amOnPage($editUrl);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($editUrl, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'lines' => [[
                'id' => (string) $lineId,
                'product_id' => (string) $product->getId(),
                'location' => 'Main',
                'qty' => '2',
                // The one thing this save actually changes.
                'price' => '11.00',
                'tax_code' => 'G',
            ]],
        ]);

        // The OLD row is gone — applyLineRows() never updates in place — so the surviving line is
        // found by invoice_id, not by the id that was posted. The guard proves that: a lineId that
        // still resolved would mean the old row survived after all, which is the opposite claim.
        $oldRowGone = $this->connection($I)->fetchAssociative('SELECT id FROM invoice_line WHERE id = ?', [$lineId]);
        $I->assertFalse($oldRowGone, 'guard: the pre-edit row id no longer exists — the line really was rebuilt, not updated');

        $after = $this->connection($I)->fetchAssociative('SELECT batch, price FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertIsArray($after, 'the invoice still has a line after the edit, just a new row');
        $I->assertEqualsWithDelta(11.0, (float) $after['price'], 0.000001, 'guard: the edit really did change the price');
        $I->assertNull($after['batch'], 'a pre-existing batch value does not survive a save — the line it lived on was rebuilt without it');
    }
}
