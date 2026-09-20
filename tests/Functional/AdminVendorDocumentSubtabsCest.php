<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Controller\Admin\VendorController;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Entity\VendorReturnLine;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The vendor record answers what we owe and what is on order (item 43).
 *
 * Before this, not one link on a vendor's page reached a document raised against them: every link
 * edited the vendor record itself. AP Aging made that visible — the vendor name in each aging row
 * IS a link and it lands here — so the drill-down from "Blackrock owes CAD 1,142.22, over 90 days"
 * arrived at a contact-card editor.
 *
 * Three things are pinned here, and they are the three that can regress silently:
 *
 *  - **A tab shows this vendor's documents and nobody else's.** Asserted as an exact set of cells
 *    compared with assertSame, never with see()/dontSee(): "which documents are on this tab" has one
 *    right answer and an exact comparison answers it in both directions at once — the row that must
 *    be there and the row that must not.
 *  - **The tab list is discovered from VendorController::DOCUMENT_TABS, never hand-listed** (#621's
 *    rule). A fifth tab added without extending this file fails documentTabKeys()'s guard rather
 *    than quietly skipping every assertion below.
 *  - **The balance is the AP report's figure, not a second one.** Asserted by reading BOTH screens
 *    and comparing them in cents, so the two cannot drift apart while both stay green.
 *
 * Numbers are never asserted with see() (#627): `see('50')` is a substring match over the whole
 * document and passes on '1050'. Every figure below is read from its own cell by `data-label`.
 */
final class AdminVendorDocumentSubtabsCest
{
    private const VENDORS = '/admin/bundles/procurement/vendors';

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-subtabs-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $I->grabService('doctrine.orm.entity_manager');

        return $em;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $this->em($I)->getConnection();
    }

    /**
     * The tab table, read out of the controller rather than copied into this file.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function documentTabs(): array
    {
        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = (new \ReflectionClass(VendorController::class))->getConstant('DOCUMENT_TABS');

        return $tabs;
    }

    /**
     * The tab keys, checked against whatever this file has an expectation for.
     *
     * Without this guard the per-tab loops would iterate the controller's tabs and silently skip any
     * tab the expectation map does not name — the enumerate-don't-hand-list failure reintroduced one
     * level up.
     *
     * @param array<string, mixed> $expectations
     * @return list<string>
     */
    private static function documentTabKeys(FunctionalTester $I, array $expectations): array
    {
        $keys = array_keys(self::documentTabs());
        $I->assertSame(
            $keys,
            array_keys($expectations),
            'Every tab VendorController declares needs an expectation here, in declared order.',
        );

        return $keys;
    }

    // ------------------------------------------------------------------------------- fixtures

    private function makeVendor(FunctionalTester $I, string $name): Vendor
    {
        $em = $this->em($I);
        $vendor = (new Vendor())->setName($name)->setCurrency('CAD')->setStatus(Vendor::STATUS_ACTIVE);
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    /**
     * The same vendor, read back out of the CURRENT entity manager.
     *
     * Every fixture builder starts here because the ones that drive a real screen — recording a
     * payment — end the request that built them, and the Vendor object from before it is detached
     * afterwards. Persisting a document against a detached parent throws, and the version of this
     * that did not throw would have been worse: a second vendor row written from a stale object.
     */
    private function liveVendor(FunctionalTester $I, Vendor $vendor): Vendor
    {
        $live = $this->em($I)->find(Vendor::class, (int) $vendor->getId());
        $I->assertInstanceOf(Vendor::class, $live, 'the fixture vendor is not in the database');

        return $live;
    }

    private function makeWarehouse(FunctionalTester $I): Warehouse
    {
        $em = $this->em($I);
        $warehouse = (new Warehouse())->setName('Subtabs Warehouse ' . uniqid());
        $em->persist($warehouse);
        $em->flush();

        return $warehouse;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $em = $this->em($I);
        $product = (new ProductCore())
            ->setSku('VST-' . strtoupper(substr(uniqid(), -8)))
            ->setName('Subtabs Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    /** A purchase order with one line, dated, totalled from the line. */
    private function makePurchaseOrder(FunctionalTester $I, Vendor $vendor, string $number): PurchaseOrder
    {
        $em = $this->em($I);
        $vendor = $this->liveVendor($I, $vendor);
        $order = (new PurchaseOrder())
            ->setPoNumber($number)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            // Item 37 removed setWarehouse(): the receiving warehouse and the tax province are one
            // fact, written together and only here.
            ->deriveTaxProvinceFrom($this->makeWarehouse($I))
            ->setDocumentDate('2026-08-18')
            ->setCurrency('CAD');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setName('Subtabs Widget')
            ->setSku('VST-LINE')
            ->setQuantityOrdered('4.00')
            ->setUnitCost('50.0000')
            ->setSubtotal('200.00')
            ->setSortOrder(0);
        $order->addLine($line);
        $em->persist($line);
        $order->recalculateTotals();
        $em->flush();

        return $order;
    }

    /** A bill for $210, optionally approved so it is a live payable. */
    private function makeBill(FunctionalTester $I, Vendor $vendor, string $number, bool $approve = true): VendorBill
    {
        $em = $this->em($I);
        $vendor = $this->liveVendor($I, $vendor);
        $bill = (new VendorBill())
            ->setBillNumber($number)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setDocumentDate('2026-08-20')
            ->setDueDate('2026-09-20')
            ->setCurrency('CAD');
        $em->persist($bill);

        $line = (new VendorBillLine())
            ->setName('Subtabs Widget')
            ->setSku('VST-LINE')
            ->setQuantity('4.00')
            ->setUnitCost('50.0000')
            ->setSubtotal('200.00')
            ->setSortOrder(0);
        $bill->addLine($line);
        $em->persist($line);
        $bill->setTax('10.00');
        $bill->recalculateTotals();
        $em->flush();

        if ($approve) {
            $bill->approve(DocumentActor::system());
            $em->flush();
        }

        return $bill;
    }

    private function makeReturn(FunctionalTester $I, Vendor $vendor, string $number): VendorReturn
    {
        $em = $this->em($I);
        $vendor = $this->liveVendor($I, $vendor);
        $return = (new VendorReturn())->setDocumentNumber($number)->setVendor($vendor);
        $em->persist($return);

        $line = (new VendorReturnLine())
            ->setProduct($this->makeProduct($I))
            ->setName('Subtabs Widget')
            ->setSku('VST-LINE')
            ->setQuantity('3.00')
            ->setSortOrder(0);
        $return->addLine($line);
        $em->persist($line);
        $em->flush();

        return $return;
    }

    /**
     * One of everything, so every tab has exactly one row belonging to this vendor.
     *
     * @return array<string, string> tab key => the cell that identifies its row
     */
    private function documentsFor(FunctionalTester $I, Vendor $vendor, string $tag): array
    {
        $this->makePurchaseOrder($I, $vendor, 'PO-' . $tag);
        $bill = $this->makeBill($I, $vendor, 'BILL-' . $tag);
        $this->makeReturn($I, $vendor, 'VR-' . $tag);
        $this->payBill($I, $bill, '10.00');

        return [
            'purchase-orders' => 'PO-' . $tag,
            'bills' => 'BILL-' . $tag,
            'returns' => 'VR-' . $tag,
            // The Payments tab names the BILL the money went against — a payment has no identity a
            // person uses.
            'payments' => 'BILL-' . $tag,
        ];
    }

    /** A payment, recorded through the real payments screen with the token scraped off it (#624). */
    private function payBill(FunctionalTester $I, VendorBill $bill, string $amount): void
    {
        $url = '/admin/bundles/procurement/bills/' . $bill->getId() . '/payments';
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest($url, [
            '_token' => $token,
            'payment_id' => '0',
            'paid_at' => '2026-09-01',
            'method' => 'EFT',
            'amount' => $amount,
            'comment' => 'Subtabs fixture',
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    // --------------------------------------------------------------------------------- reading

    private function openVendor(FunctionalTester $I, Vendor $vendor, ?string $docs = null): void
    {
        $I->amOnPage(self::VENDORS . '/' . $vendor->getId() . ($docs === null ? '' : '?docs=' . $docs));
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * The cells of one column of the documents panel, in order, trimmed.
     *
     * Scoped to `#vendor-documents` so the contacts, addresses and notes tables on the same page
     * cannot answer for it.
     *
     * @return list<string>
     */
    private function documentCells(FunctionalTester $I, string $label): array
    {
        return array_values(array_map(
            trim(...),
            $I->grabMultiple('#vendor-documents td[data-label="' . $label . '"]'),
        ));
    }

    /** @return list<string> */
    private function documentHeaders(FunctionalTester $I): array
    {
        return array_values(array_map(trim(...), $I->grabMultiple('#vendor-documents thead th')));
    }

    /** The label of whichever tab is current. @return list<string> */
    private function currentTabLabels(FunctionalTester $I): array
    {
        return array_values(array_map(
            trim(...),
            $I->grabMultiple('#vendor-documents .company-action-links a.is-current'),
        ));
    }

    /** The identifying cell of each row on the tab, whatever that tab calls its link column. */
    private function rowIdentities(FunctionalTester $I, string $tab): array
    {
        /** @var array{link: string} $definition */
        $definition = self::documentTabs()[$tab];

        return $this->documentCells($I, $definition['link']);
    }

    // =========================================================================== the tab strip

    /**
     * No `?docs=` at all is the link AP Aging and every other screen already points at, so the
     * default has to answer the question the vendor page could not answer before: what is on order.
     */
    public function withNoQueryStringThePanelOpensOnPurchaseOrders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Default Supply');
        $numbers = $this->documentsFor($I, $ours, 'DEFAULT');

        $this->openVendor($I, $ours);

        $I->assertSame(['Purchase Orders'], $this->currentTabLabels($I));
        $I->assertSame([$numbers['purchase-orders']], $this->documentCells($I, 'Number'));
    }

    /**
     * An unknown `?docs=` is a stale bookmark, not a bad request.
     *
     * A 404 would throw away the vendor the admin actually asked for and answer a question nobody
     * asked. The array form is here because `InputBag::get()` throws a BadRequestException on
     * `?docs[]=bills`, and a 400 is the same wrong answer as a 404.
     */
    public function anUnusableDocsParameterFallsBackToTheDefaultTab(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Stale Bookmark Supply');
        $numbers = $this->documentsFor($I, $ours, 'STALE');

        foreach (['renamed-tab', ''] as $docs) {
            $this->openVendor($I, $ours, $docs);
            $I->assertSame(['Purchase Orders'], $this->currentTabLabels($I), 'unknown ?docs=' . $docs);
            $I->assertSame([$numbers['purchase-orders']], $this->documentCells($I, 'Number'));
        }

        $I->amOnPage(self::VENDORS . '/' . $ours->getId() . '?docs[]=bills');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(['Purchase Orders'], $this->currentTabLabels($I), '?docs[] must not 400');
    }

    /**
     * Each tab lists its own document type and only its own — the reason one mixed list with a Type
     * column was rejected on the customer side and is rejected here.
     *
     * Enumerated over the controller's own tab table, and each tab's link target is checked too, so
     * a Bills row linking at /purchase-orders/{id} (same id space, different document) fails here
     * rather than on somebody's screen.
     */
    public function eachTabListsItsOwnDocumentTypeAndLinksToIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Per Tab Supply');
        $numbers = $this->documentsFor($I, $ours, 'PERTAB');

        $paths = [
            'purchase-orders' => '/admin/bundles/procurement/purchase-orders/',
            'bills' => '/admin/bundles/procurement/bills/',
            'returns' => '/admin/bundles/procurement/vendor-returns/',
            'payments' => '/admin/bundles/procurement/bills/',
        ];

        foreach (self::documentTabKeys($I, $numbers) as $key) {
            $this->openVendor($I, $ours, $key);

            $I->assertSame(
                [$numbers[$key]],
                $this->rowIdentities($I, $key),
                sprintf('The %s tab must list its own document and no other type.', $key),
            );
            $I->assertStringStartsWith(
                $paths[$key],
                (string) $I->grabAttributeFrom('#vendor-documents tbody td a', 'href'),
                sprintf('The %s tab must link through to its own document.', $key),
            );
        }
    }

    /**
     * The cross-vendor negative, which is the cheap half and the one worth having (#624).
     *
     * Their documents existing at all is the positive control: the same selector that returns only
     * our rows on our record returns theirs on theirs, so an empty tab cannot pass this by rendering
     * nothing.
     */
    public function aTabNeverShowsAnotherVendorsDocuments(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $ours = $this->makeVendor($I, 'Subtabs Ours Supply');
        $theirs = $this->makeVendor($I, 'Subtabs Theirs Supply');
        $ourNumbers = $this->documentsFor($I, $ours, 'OURS');
        $theirNumbers = $this->documentsFor($I, $theirs, 'THEIRS');

        foreach (self::documentTabKeys($I, $ourNumbers) as $key) {
            $this->openVendor($I, $ours, $key);
            $I->assertSame(
                [$ourNumbers[$key]],
                $this->rowIdentities($I, $key),
                sprintf("Subtabs Theirs Supply's %s must not appear on Subtabs Ours Supply's record.", $key),
            );

            // Positive control: the row just proved absent is genuinely rendered by this selector —
            // on the record it belongs to.
            $this->openVendor($I, $theirs, $key);
            $I->assertSame([$theirNumbers[$key]], $this->rowIdentities($I, $key));
        }
    }

    // ============================================================================== the columns

    /**
     * Every column of the Purchase Orders tab, asserted at its own cell.
     *
     * `see('200')` would pass here on the total, on the quantity, and on any 200 that wandered onto
     * the page from a neighbouring panel (#627).
     */
    public function thePurchaseOrdersTabStatesDateNumberItemsTotalAndStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs PO Columns Supply');
        $this->makePurchaseOrder($I, $ours, 'PO-COLUMNS');

        $this->openVendor($I, $ours, 'purchase-orders');

        $I->assertSame(['Date', 'Number', 'Items', 'Total', 'Status'], $this->documentHeaders($I));
        $I->assertSame(['2026-08-18'], $this->documentCells($I, 'Date'));
        $I->assertSame(['PO-COLUMNS'], $this->documentCells($I, 'Number'));
        $I->assertSame(['1'], $this->documentCells($I, 'Items'));
        $I->assertSame(['CAD 200.00'], $this->documentCells($I, 'Total'));
        $I->assertSame(['Draft'], $this->documentCells($I, 'Status'));

        // Spelled the way every other grid spells it (item 41), so one status has one appearance.
        $I->assertSame(
            ['order-status order-status-draft'],
            array_values($I->grabMultiple('#vendor-documents td[data-label="Status"] span', 'class')),
        );
    }

    /**
     * The Bills tab states the total, the balance and the status.
     *
     * Money is two decimals with its currency, always: 'CAD 210.00' and not '210', because a
     * purchase document is read in the currency it was raised in and nothing here converts.
     */
    public function theBillsTabStatesTotalBalanceAndStatusWithCurrency(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Bill Columns Supply');
        $bill = $this->makeBill($I, $ours, 'BILL-COLUMNS');
        $this->payBill($I, $bill, '10.00');

        $this->openVendor($I, $ours, 'bills');

        $I->assertSame(['Date', 'Number', 'Due', 'Total', 'Balance due', 'Status'], $this->documentHeaders($I));
        $I->assertSame(['2026-08-20'], $this->documentCells($I, 'Date'));
        $I->assertSame(['BILL-COLUMNS'], $this->documentCells($I, 'Number'));
        $I->assertSame(['2026-09-20'], $this->documentCells($I, 'Due'));
        $I->assertSame(['CAD 210.00'], $this->documentCells($I, 'Total'));
        $I->assertSame(['CAD 200.00'], $this->documentCells($I, 'Balance due'));
        $I->assertSame(['Partially Paid'], $this->documentCells($I, 'Status'));
    }

    /**
     * A return states units and states no money, because it holds none.
     *
     * The absence is asserted as the exact header list rather than a dontSee, and the Bills tab in
     * the same test is the positive control: the same reader DOES find a Total column one tab over,
     * so this cannot pass by failing to read headers at all.
     */
    public function theReturnsTabStatesUnitsAndNeverAMoneyColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Return Columns Supply');
        $this->makeReturn($I, $ours, 'VR-COLUMNS');
        $this->makeBill($I, $ours, 'BILL-FOR-CONTROL');

        $this->openVendor($I, $ours, 'returns');
        $headers = $this->documentHeaders($I);
        $I->assertSame(['Requested', 'Number', 'Units', 'Status'], $headers);
        $I->assertNotContains('Total', $headers, 'A vendor return holds no money; DebitMemo does.');
        $I->assertSame(['VR-COLUMNS'], $this->documentCells($I, 'Number'));
        $I->assertSame(['3'], $this->documentCells($I, 'Units'));
        $I->assertSame(['Requested'], $this->documentCells($I, 'Status'));

        // Positive control for the header reader.
        $this->openVendor($I, $ours, 'bills');
        $I->assertContains('Total', $this->documentHeaders($I));
    }

    /**
     * Payments are collected across ALL of the vendor's bills, which is the whole reason the tab
     * earns its place: "what have we paid Steelhead" is otherwise four bill screens in a row.
     */
    public function thePaymentsTabCollectsPaymentsAcrossEveryBill(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Payments Supply');
        $first = $this->makeBill($I, $ours, 'BILL-PAY-A');
        $second = $this->makeBill($I, $ours, 'BILL-PAY-B');
        $this->payBill($I, $first, '25.00');
        $this->payBill($I, $second, '40.00');

        $this->openVendor($I, $ours, 'payments');

        $I->assertSame(['Paid', 'Bill', 'Method', 'Amount', 'Recorded by'], $this->documentHeaders($I));
        // Both bills, and the amounts read at their own cells rather than searched for on the page.
        $I->assertSame(['BILL-PAY-B', 'BILL-PAY-A'], $this->documentCells($I, 'Bill'));
        $I->assertSame(['CAD 40.00', 'CAD 25.00'], $this->documentCells($I, 'Amount'));
        $I->assertSame(['EFT', 'EFT'], $this->documentCells($I, 'Method'));
    }

    /**
     * The footer drill-through narrows the real list screen to THIS vendor, or it is not offered.
     *
     * The link is followed and the list it lands on is asserted, rather than the href being pattern
     * matched: a link carrying the right query string at a screen that ignores it looks identical
     * from the template and is exactly the failure the customer side refused to ship. Returns and
     * Payments have no link because their lists cannot be narrowed by vendor id —
     * `VendorReturnRepository::search()` takes `q` and `status` only, and payments have no list
     * screen at all — and a link that widened without saying so would be worse than none.
     */
    public function theDrillThroughLinkNarrowsTheListToThisVendorOrIsNotOffered(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Drillthrough Ours Supply');
        $theirs = $this->makeVendor($I, 'Subtabs Drillthrough Theirs Supply');
        $this->makePurchaseOrder($I, $ours, 'PO-DRILL-OURS');
        $this->makePurchaseOrder($I, $theirs, 'PO-DRILL-THEIRS');
        $this->makeBill($I, $ours, 'BILL-DRILL-OURS');
        $this->makeBill($I, $theirs, 'BILL-DRILL-THEIRS');

        $expectations = [
            'purchase-orders' => ['Purchase Orders', ['PO-DRILL-OURS'], 'PO'],
            'bills' => ['Bills', ['BILL-DRILL-OURS'], 'Bill #'],
            'returns' => null,
            'payments' => null,
        ];

        foreach (self::documentTabKeys($I, $expectations) as $key) {
            $this->openVendor($I, $ours, $key);
            $links = $I->grabMultiple('#vendor-documents .table-footer-actions a');

            if ($expectations[$key] === null) {
                $I->assertSame([], $links, sprintf('The %s tab must offer no drill-through.', $key));

                continue;
            }

            [, $expectedRows, $column] = $expectations[$key];
            $I->assertCount(1, $links, sprintf('The %s tab must offer exactly one drill-through.', $key));

            // The href is followed with amOnPage rather than click(): click() resolves a relative
            // link against the page's host, and this suite reaches the admin app by a Host header,
            // which the Symfony module then refuses as an external URL.
            $I->amOnPage((string) $I->grabAttributeFrom('#vendor-documents .table-footer-actions a', 'href'));
            $I->seeResponseCodeIsSuccessful();
            $listed = array_values(array_map(
                trim(...),
                $I->grabMultiple('tbody td[data-label="' . $column . '"] a'),
            ));
            $I->assertSame(
                $expectedRows,
                $listed,
                sprintf("The %s drill-through must land on a list narrowed to this vendor.", $key),
            );
        }
    }

    /** An empty tab says so, and says it about the right document type. */
    public function anEmptyTabStatesWhatIsMissingRatherThanRenderingNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Subtabs Empty Supply');
        $this->makePurchaseOrder($I, $ours, 'PO-ONLY');

        $expectations = [
            'purchase-orders' => false,
            'bills' => true,
            'returns' => true,
            'payments' => true,
        ];

        foreach (self::documentTabKeys($I, $expectations) as $key) {
            $this->openVendor($I, $ours, $key);
            $empty = $I->grabMultiple('#vendor-documents .empty-table-cell span');

            if ($expectations[$key]) {
                /** @var array{empty: string} $definition */
                $definition = self::documentTabs()[$key];
                $I->assertSame([$definition['empty']], array_map(trim(...), $empty));
            } else {
                // Positive control: the tab that HAS a row renders no empty state at all, so the
                // assertion above is reading a real element and not an empty match every time.
                $I->assertSame([], $empty);
            }
        }
    }
}
