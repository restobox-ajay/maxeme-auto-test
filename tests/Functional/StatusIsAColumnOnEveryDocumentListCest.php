<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\Warehouse;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\DebitMemoLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Entity\VendorReturnLine;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The other half of queue item 41: status is a COLUMN, on every document list, buy and sell alike.
 *
 * `DocumentStatusIsLoudCest` covers the detail-page half — the badge beside the title, and the lead
 * that stopped carrying the status in a sentence. This one covers the tables, and the rule it
 * asserts is narrower than "a Status column exists" (which `AdminListScreenConventionsCest` already
 * checks by scanning the grids): every Status cell is rendered by the ONE shared partial,
 * `admin/_partials/document_status.html.twig`, rather than by markup each grid wrote for itself.
 *
 * ## Why "looks right" was not good enough to assert
 *
 * Before this, eleven grids each built the pill by hand —
 * `<span class="order-status order-status-{{ x.status.value|lower|replace(…) }}">` — and two of
 * them had already drifted: `/procurement/purchase-orders/expected` printed the status as bare text
 * with no pill at all, and the RFQ's replies table printed a `.badge` with no status class, so it
 * had no colour. Two more (`/admin/company/{id}` and the dashboard's recent orders) kept a SECOND
 * table of colours in Twig — `row.status|lower == 'closed' ? 'success' : …` — which is the repo's
 * named anti-pattern: a colour decided somewhere other than app.css's one `.order-status-*` table.
 *
 * A test asserting classes would have passed on all four. So the partial marks its quiet variant
 * with `data-status-cell`, an attribute written in exactly ONE file — which
 * {@see self::nothingButTheSharedPartialWritesAStatusCell()} asserts — and every list assertion
 * below anchors on it. Copying a class is easy; a cell carrying that attribute came through the
 * partial or it does not exist.
 *
 * ## Shape (#624, #627)
 *
 * Conducted, twice: a purchase order (buy side) and a sales return (sell side) are raised through
 * their own screens, moved to a non-default state by posting the very form the screen offers with
 * the CSRF token scraped off it, and then re-read — the list fetched again and the entity re-fetched
 * after `$em->clear()`. Each is raised alongside a SECOND document that is never touched and is
 * asserted, on the same later page load, to still read the state it was created in.
 *
 * Nothing is asserted with a bare `see('Draft')`: "Draft" is in the status filter dropdown of every
 * grid here, and on a purchase order's own page it is in the timeline too. Every assertion names
 * the row it is about — `//tr[…]/td[@data-label="Status"]/span[@data-status-cell]` — and every
 * absence assertion is paired with a positive one on the SAME element, so a typo'd selector or a
 * 500 into an error template cannot pass as proof.
 */
final class StatusIsAColumnOnEveryDocumentListCest
{
    /** The badge a DETAIL screen carries beside its title. Loud: sized and ringed. */
    private const BADGE = '[data-document-status]';

    /**
     * Every document list screen, and the word its seeded document should read in the Status cell.
     *
     * Keyed by the entity the screen pages, so a reader can check the eleven against
     * `App\Contract\Document\CommercialDocument`'s implementors rather than against this list.
     */
    private const LISTS = [
        SalesOrder::class => ['/admin/order', 'Draft'],
        Invoice::class => ['/admin/invoice', 'Draft'],
        Estimate::class => ['/admin/estimate', 'Draft'],
        CreditMemo::class => ['/admin/credit-memo/index', 'Draft'],
        SalesReturn::class => ['/admin/sales-return/index', 'Requested'],
        PurchaseOrder::class => ['/admin/bundles/procurement/purchase-orders', 'Draft'],
        VendorBill::class => ['/admin/bundles/procurement/bills', 'Draft'],
        GoodsReceipt::class => ['/admin/bundles/procurement/receiving', 'Received'],
        VendorReturn::class => ['/admin/bundles/procurement/vendor-returns', 'Requested'],
        DebitMemo::class => ['/admin/bundles/procurement/debit-memos', 'Draft'],
        Rfq::class => ['/admin/bundles/procurement/rfqs', 'Draft'],
    ];

    /**
     * AppSettings caches its rows in a pool OUTSIDE the per-test transaction, and every document
     * numbered through a screen here allocates its prefix through it.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    // ── The sweep: eleven lists, one document each ──────────────────────────────────────────────

    /**
     * One document of every kind, and every list showing the status of its own in its own row.
     *
     * The documents here are seeded rather than conducted on purpose: this test is about ELEVEN
     * screens agreeing on how a status cell is written, and the two tests below are the ones that
     * prove a status a person changed reaches the cell. Seeding all eleven through their create
     * screens would trade that breadth for a fixture, not for evidence.
     */
    public function everyDocumentListShowsItsStatusThroughTheSharedCell(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $numbers = $this->seedOneOfEverything($I);

        $I->assertSame(
            array_keys(self::LISTS),
            array_keys($numbers),
            'a document type was seeded that no list screen is named for, or the other way round',
        );

        foreach (self::LISTS as $class => [$url, $status]) {
            $number = $numbers[$class];
            $cell = $this->statusCell($number);

            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement($cell);
            $I->see($status, $cell);

            // The cell is the QUIET half of the badge: the detail page's ring and size would make a
            // grid row half again as tall. Paired with the assertion immediately above, which is
            // what stops an empty page or a wrong selector reading as "no loud badge here".
            $I->dontSeeElement(self::BADGE);
        }
    }

    // ── Conducted: the buy side ─────────────────────────────────────────────────────────────────

    /**
     * A purchase order raised, issued, and read back in all three lists that show it.
     *
     * Expected arrivals is the reason this walks to Issued rather than stopping at Draft: it lists
     * only Issued and Partially Received orders, and it is the screen that was printing the status
     * as bare text with no pill until this change. So the assertion on it fails on the code as it
     * was, which is the point of naming it here.
     */
    public function aPurchaseOrderCarriesOneStatusIntoEveryListThatShowsIt(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedPurchaseContext($I);

        $moved = $this->raisePurchaseOrder($I, $context, 'Column PO under test');
        $untouched = $this->raisePurchaseOrder($I, $context, 'Column PO left alone');

        $movedNumber = $this->rereadOrder($I, $moved)->getPoNumber();
        $untouchedNumber = $this->rereadOrder($I, $untouched)->getPoNumber();

        // ── As created, in the grid: both Draft, both through the shared cell.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Draft', $this->statusCell($movedNumber));
        $I->see('Draft', $this->statusCell($untouchedNumber));

        // ── Issue one of them, through the button on its own page.
        $this->postOnPurchaseOrderPage($I, $moved, '/issue');
        $I->assertSame('Issued', $this->rereadOrder($I, $moved)->getStatus(), 'the issue action did not move the order');
        $I->assertSame('Draft', $this->rereadOrder($I, $untouched)->getStatus(), 'issuing one order moved the other');

        // ── The grid, re-read: the moved one says Issued and the other still says Draft.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Issued', $this->statusCell($movedNumber));
        $I->see('Draft', $this->statusCell($untouchedNumber));
        $I->dontSee('Draft', $this->statusCell($movedNumber));

        // ── Expected arrivals: the issued order, with its status in a real cell rather than as
        //    loose text. The untouched draft is not on this screen at all — and the positive
        //    control for that absence is the row that IS, asserted immediately above it.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/expected');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->statusCell($movedNumber));
        $I->see('Issued', $this->statusCell($movedNumber));
        $I->dontSeeElement($this->statusCell($untouchedNumber));

        // ── The vendor's own documents tab, which is a document list like any other.
        $I->amOnPage('/admin/bundles/procurement/vendors/' . $context['vendorId'] . '?docs=purchase-orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Issued', $this->statusCell($movedNumber));
        $I->see('Draft', $this->statusCell($untouchedNumber));
    }

    // ── Conducted: the sell side ────────────────────────────────────────────────────────────────

    /**
     * A sales return authorised through the form on its own page, and the grid row that follows it.
     *
     * The sell side's conducted half. `Requested -> Authorised` is a move a person makes with a
     * button, on a screen that posts a plain form with a scraped token — no JSON endpoint, no
     * fetch — so it is the honest sell-side equivalent of issuing a purchase order.
     */
    public function aSalesReturnCarriesOneStatusIntoItsListWhenItMoves(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $company = $this->makeCompany($I, 'Status Column Customer');

        $movedNumber = 'RMA-COL-' . strtoupper(substr(uniqid(), -6));
        $untouchedNumber = 'RMA-LEFT-' . strtoupper(substr(uniqid(), -6));
        $moved = $this->makeSalesReturn($I, $company, $movedNumber);
        $this->makeSalesReturn($I, $company, $untouchedNumber);

        // ── As created: both Requested, in their own rows.
        $I->amOnPage('/admin/sales-return/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Requested', $this->statusCell($movedNumber));
        $I->see('Requested', $this->statusCell($untouchedNumber));

        // ── Authorise one of them, through the form its own page offers.
        $page = '/admin/sales-return/' . $moved;
        $I->amOnPage($page);
        $I->seeResponseCodeIsSuccessful();
        $selector = 'form#authorise-form input[name="_token"]';
        $I->seeElement($selector);
        $I->sendFormPostRequest($page . '/action/authorise', [
            '_token' => (string) $I->grabAttributeFrom($selector, 'value'),
        ]);

        $I->assertSame('Authorised', $this->rereadReturn($I, $moved)->getStatus()->value, 'the authorise action did not move the return');

        // ── The grid, re-read: one moved, one did not.
        $I->amOnPage('/admin/sales-return/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Authorised', $this->statusCell($movedNumber));
        $I->see('Requested', $this->statusCell($untouchedNumber));
        $I->dontSee('Requested', $this->statusCell($movedNumber));
    }

    // ── The two sizes are one badge ─────────────────────────────────────────────────────────────

    /**
     * The same status, the same colour class, two sizes — and each size only where it belongs.
     *
     * Cancelled is the state queue item 41 was filed about, so it is the one this reads in both
     * places at once.
     */
    public function theListCellAndTheDetailBadgeAreOneBadgeAtTwoSizes(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedPurchaseContext($I);
        $orderId = $this->raisePurchaseOrder($I, $context, 'Column PO cancelled');
        $number = $this->rereadOrder($I, $orderId)->getPoNumber();

        $this->postOnPurchaseOrderPage($I, $orderId, '/cancel', ['reason' => 'Ordered twice.']);
        $I->assertSame('Cancelled', $this->rereadOrder($I, $orderId)->getStatus());

        // ── On the list: the quiet cell, carrying the colour class and NOT the loud one.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Cancelled', $this->statusCell($number));
        $I->seeElement($this->statusCell($number) . '[contains(@class, "order-status-cancelled")]');
        $I->dontSeeElement($this->statusCell($number) . '[contains(@class, "document-status")]');

        // ── On the page: one loud badge, with the same colour class, and no quiet cell — the
        //    detail screen lists nothing that has a status of its own.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeNumberOfElements(self::BADGE, 1);
        $I->see('Cancelled', self::BADGE);
        $I->seeElement('span.order-status.document-status.order-status-cancelled' . self::BADGE);
    }

    /**
     * The marker the assertions above lean on is written in exactly one template.
     *
     * Without this, every list assertion here would be satisfiable by pasting one attribute into
     * eleven templates — which is the hand-rolled markup the ruling is trying to delete, wearing
     * this test's own anchor as a disguise. One file writes it; the rest include that file.
     */
    public function nothingButTheSharedPartialWritesAStatusCell(FunctionalTester $I): void
    {
        $projectDir = (string) $I->grabService('kernel')->getProjectDir();
        $writers = [];

        foreach ($this->templateFiles($projectDir) as $file) {
            if (str_contains((string) file_get_contents($file), 'data-status-cell')) {
                $writers[] = substr($file, strlen($projectDir) + 1);
            }
        }

        $I->assertSame(['templates/admin/_partials/document_status.html.twig'], $writers, sprintf(
            'The status cell marker is meant to be written in one place and included everywhere else;'
                . ' %d template(s) write it: %s',
            count($writers),
            implode(', ', $writers) ?: 'none',
        ));
    }

    // ── Selectors ───────────────────────────────────────────────────────────────────────────────

    /**
     * The Status cell of the row that names $documentNumber, and nothing else on the page.
     *
     * XPath rather than CSS because it is the row CONTAINING the number that has to be found, and
     * the grids do not agree on how the number is written: most link it, `/procurement/rfqs` and
     * `/procurement/vendor-returns` print it as plain text in the cell. `.//*[…text()…]` matches
     * either, since the `<td>` is itself a descendant of the `<tr>`.
     *
     * The `[@data-status-cell]` at the end is load-bearing: it is what makes this an assertion
     * about the shared partial rather than about any span that happens to sit in a Status cell.
     */
    private function statusCell(string $documentNumber): string
    {
        return sprintf(
            '//tr[.//*[normalize-space(text())="%s"]]/td[@data-label="Status"]/span[@data-status-cell]',
            $documentNumber,
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('status-column-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        return $I->grabService('doctrine.orm.entity_manager');
    }

    /**
     * One document of each of the eleven kinds, keyed by class, valued by document number.
     *
     * @return array<class-string, string>
     */
    private function seedOneOfEverything(FunctionalTester $I): array
    {
        $em = $this->em($I);
        $tag = strtoupper(substr(uniqid(), -6));
        $company = $this->makeCompany($I, 'Status Column Co ' . $tag);
        $product = $this->makeProduct($I, 'COL-' . $tag);
        $vendor = $this->makeVendor($I, 'Status Column Supply ' . $tag);
        $warehouse = $this->makeWarehouse($I, 'Status Column Warehouse ' . $tag);

        // ── Sell side.
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SO-COL-' . $tag)
            ->setDocumentDate('2026-09-11')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $order->addLine((new SalesOrderLine())->setName('Widget')->setQuantity('2.00')->setPrice('20.00')->setSubtotal('40.00'));
        $em->persist($order);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('INV-COL-' . $tag)
            ->setDocumentDate('2026-09-11')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $invoice->addLine((new InvoiceLine())->setName('Widget')->setQuantity('2.00')->setPrice('20.00')->setSubtotal('40.00'));
        $em->persist($invoice);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-COL-' . $tag)
            ->setSource('Admin')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $estimate->addLine((new EstimateLine())->setName('Widget')->setSku($product->getSku())->setQuantity('2.00')->setPrice('20.00')->setSubtotal('40.00'));
        $em->persist($estimate);

        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setDocumentNumber('CN-COL-' . $tag)
            ->setDocumentDate('2026-09-11')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $em->persist($memo);

        $salesReturnNumber = 'RMA-COL-' . $tag;
        $this->makeSalesReturn($I, $company, $salesReturnNumber);

        // ── Buy side.
        $purchaseOrder = (new PurchaseOrder())
            ->setPoNumber('PO-COL-' . $tag)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setDocumentDate('2026-09-11')
            ->setCurrency('CAD');
        $em->persist($purchaseOrder);
        $poLine = (new PurchaseOrderLine())
            ->setName('Column Widget')
            ->setSku($product->getSku())
            ->setQuantityOrdered('3.00')
            ->setUnitCost('10.0000');
        $purchaseOrder->addLine($poLine);
        $em->persist($poLine);

        $bill = (new VendorBill())
            ->setBillNumber('BILL-COL-' . $tag)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setDocumentDate('2026-09-11')
            ->setCurrency('CAD');
        $em->persist($bill);
        $billLine = (new VendorBillLine())->setName('Column Widget')->setQuantity('1.00')->setUnitCost('10.0000')->setSubtotal('10.00');
        $bill->addLine($billLine);
        $em->persist($billLine);

        $receipt = (new GoodsReceipt())
            ->setReceiptNumber('RC-COL-' . $tag)
            ->setVendor($vendor)
            ->setWarehouse($warehouse);
        $em->persist($receipt);
        $receiptLine = (new GoodsReceiptLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity('3.00');
        $receipt->addLine($receiptLine);
        $em->persist($receiptLine);

        $vendorReturn = (new VendorReturn())
            ->setDocumentNumber('VR-COL-' . $tag)
            ->setVendor($vendor);
        $em->persist($vendorReturn);
        $vendorReturnLine = (new VendorReturnLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity('1.00');
        $vendorReturn->addLine($vendorReturnLine);
        $em->persist($vendorReturnLine);

        $debitMemo = (new DebitMemo())
            ->setVendor($vendor)
            ->setDocumentNumber('DM-COL-' . $tag)
            ->setDocumentDate('2026-09-11');
        $debitMemo->addLine((new DebitMemoLine())->setName('Column Widget')->setQuantity('1.00')->setUnitCost('10.00')->setSubtotal('10.00'));
        $debitMemo->recalculateTotals();
        $em->persist($debitMemo);

        $rfq = (new Rfq())
            ->setDocumentNumber('RFQ-COL-' . $tag)
            ->setWarehouse($warehouse);
        $em->persist($rfq);

        $em->flush();
        $em->clear();

        return [
            SalesOrder::class => 'SO-COL-' . $tag,
            Invoice::class => 'INV-COL-' . $tag,
            Estimate::class => 'EST-COL-' . $tag,
            CreditMemo::class => 'CN-COL-' . $tag,
            SalesReturn::class => $salesReturnNumber,
            PurchaseOrder::class => 'PO-COL-' . $tag,
            VendorBill::class => 'BILL-COL-' . $tag,
            GoodsReceipt::class => 'RC-COL-' . $tag,
            VendorReturn::class => 'VR-COL-' . $tag,
            DebitMemo::class => 'DM-COL-' . $tag,
            Rfq::class => 'RFQ-COL-' . $tag,
        ];
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name . ' ' . uniqid())
            ->setCode('COL-' . uniqid())
            ->setPrimaryEmail('buyer-' . uniqid() . '@column.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Column Widget ' . $sku)
            ->setSalesTaxCode('E')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makeVendor(FunctionalTester $I, string $name): Vendor
    {
        $vendor = (new Vendor())->setName($name)->setCurrency('CAD');
        $I->haveInRepository($vendor);

        return $vendor;
    }

    private function makeWarehouse(FunctionalTester $I, string $name): Warehouse
    {
        $warehouse = (new Warehouse())->setName($name);
        $I->haveInRepository($warehouse);

        return $warehouse;
    }

    /** An RMA with one line on it, which is what `authorise()` requires to be a real move. */
    private function makeSalesReturn(FunctionalTester $I, Company $company, string $number): int
    {
        $em = $this->em($I);
        $product = $this->makeProduct($I, 'RMA-' . strtoupper(substr(uniqid(), -8)));

        $return = (new SalesReturn())
            ->setCompany($company)
            ->setDocumentNumber($number);
        $return->addLine((new SalesReturnLine())->setProduct($product)->setName('Returned widget')->setQuantity('1.00'));
        $em->persist($return);
        $em->flush();

        return (int) $return->getId();
    }

    private function rereadReturn(FunctionalTester $I, int $id): SalesReturn
    {
        $em = $this->em($I);
        $em->clear();
        $return = $em->getRepository(SalesReturn::class)->find($id);
        $I->assertInstanceOf(SalesReturn::class, $return);

        return $return;
    }

    // ── Purchase order plumbing, conducted through the real screens ─────────────────────────────

    /** @return array{vendorId:int, warehouseId:int, productId:int} */
    private function seedPurchaseContext(FunctionalTester $I): array
    {
        $em = $this->em($I);
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('Status Column Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = $this->makeVendor($I, 'Column Supply ' . $tag);
        $product = $this->makeProduct($I, 'COLPO-' . $tag);

        $context = [
            'vendorId' => (int) $vendor->getId(),
            'warehouseId' => (int) $warehouse->getId(),
            'productId' => (int) $product->getId(),
        ];

        $em->clear();

        return $context;
    }

    /** @param array{vendorId:int, warehouseId:int, productId:int} $context */
    private function raisePurchaseOrder(FunctionalTester $I, array $context, string $lineName): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => (string) $I->grabAttributeFrom('form input[name="_token"]', 'value'),
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'warehouse_id' => (string) $context['warehouseId'],
            'document_date' => '2026-09-11',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productId'],
                    'name' => $lineName,
                    'qty' => '3.00',
                    'unit_cost' => '10.00',
                    'tax_code' => 'E',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $this->em($I);
        $em->clear();
        $id = (int) $em->getConnection()->fetchOne(
            'SELECT purchase_order_id FROM purchase_order_line WHERE name = ? ORDER BY id DESC LIMIT 1',
            [$lineName],
        );
        $I->assertGreaterThan(0, $id, 'the save endpoint wrote no purchase order for ' . $lineName);

        return $id;
    }

    /**
     * Posts one of the order's own named actions, with the token scraped off the form that offers
     * it — never a token minted in the test.
     *
     * @param array<string, string> $fields
     */
    private function postOnPurchaseOrderPage(FunctionalTester $I, int $id, string $action, array $fields = []): void
    {
        $page = '/admin/bundles/procurement/purchase-orders/' . $id;
        $I->amOnPage($page);
        $I->seeResponseCodeIsSuccessful();

        $selector = sprintf('form[action="%s%s"] input[name="_token"]', $page, $action);
        $I->seeElement($selector);

        $I->sendFormPostRequest($page . $action, ['_token' => (string) $I->grabAttributeFrom($selector, 'value')] + $fields);
        $I->seeResponseCodeIsSuccessful();
    }

    private function rereadOrder(FunctionalTester $I, int $id): PurchaseOrder
    {
        $em = $this->em($I);
        $em->clear();
        $order = $em->getRepository(PurchaseOrder::class)->find($id);
        $I->assertInstanceOf(PurchaseOrder::class, $order);

        return $order;
    }

    // ── Template scan ───────────────────────────────────────────────────────────────────────────

    /**
     * Every Twig file the application can render: core's own and every bundle's.
     *
     * @return list<string>
     */
    private function templateFiles(string $projectDir): array
    {
        $files = [];

        foreach ([$projectDir . '/templates', $projectDir . '/modules'] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                    $files[] = (string) $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
