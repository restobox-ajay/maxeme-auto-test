<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The exceptions screen says what a row is WORTH and what to DO about it (item 49) — conducted per
 * #624, through the real screens, with plain form POSTs carrying a scraped CSRF token.
 *
 * ## What was wrong
 *
 * Two things, and they are the same omission twice. The biggest table on the screen had no money
 * column, so a $5 discrepancy and a $5,000 one read identically on the one screen whose job is to
 * say which to work first. And every row stated the FINDING and stopped: "billed but not received"
 * means chase the receipt or refuse to pay, and neither was reachable from the row.
 *
 * ## Why the ordering is tested in BOTH directions
 *
 * `'1000.00'` sorts before `'9.00'` as text. The three figures here — 9.00, 80.00 and 1000.00 — are
 * chosen so that text ordering is wrong in both directions and wrong in a DIFFERENT way each time:
 *
 * | ordering        | descending          | ascending           |
 * |-----------------|---------------------|---------------------|
 * | by value        | 1000.00, 80.00, 9.00| 9.00, 80.00, 1000.00|
 * | by the string   | 9.00, 80.00, 1000.00| 1000.00, 80.00, 9.00|
 *
 * Note that text-ascending and value-descending produce the SAME sequence, so a test that only
 * checked the default view would pass against a screen sorting its cells as strings. Both
 * directions are asserted for exactly that reason.
 *
 * ## #627 throughout
 *
 * Never `see()` on a bare number — `see('50')` matches '1050', and this screen is nothing but
 * numbers. Every assertion names the cell it is about (`td[data-label="Value at risk"]` inside a
 * numbered row of a named section), and every absence is paired with a positive control: a row
 * that must NOT be on the screen is asserted alongside one that must.
 *
 * ## What is set up directly and what is conducted
 *
 * Vendors, products, purchase orders and goods already received are SETUP — they are the state the
 * exception is a fact about. Everything the tests make a claim about is driven through the real
 * screen: bills are raised through `/bills/save`, approved through the approve form on the bill,
 * disputed through the dispute form the row links to, and every claim is read back out of the
 * database BY COLUMN afterwards rather than believed from a flash message or an HTTP 200.
 */
final class ProcurementExceptionMovesCest
{
    private const SCREEN = '/admin/bundles/procurement/exceptions';
    private const ON_BILLS = '#exceptions-on-bills';
    private const ACCRUALS = '#exceptions-received-not-billed';

    /**
     * AppSettings caches its rows in a pool OUTSIDE the per-test transaction, so a snapshot taken
     * here survives the rollback and is read by whatever runs next. Every bill numbers itself
     * through PurchaseDocumentNumberGenerator, which reads its prefix through that cache.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('exceptions-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor, a product and an ISSUED purchase order carrying the named lines.
     *
     * Each spec is [name, quantityOrdered, quantityReceived, unitCost]. Received is written
     * directly: goods arriving is the receiving screen's operation and is conducted in its own
     * tests — here it is the state the exception is a fact ABOUT, not the thing under test.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $specs
     *
     * @return array{vendorId: int, orderId: int, lineIds: array<string, int>, tag: string}
     */
    private function seed(FunctionalTester $I, array $specs): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('Exception Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Exception Vendor ' . $tag)->setCurrency('CAD');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('EXC-' . $tag)
            ->setName('Exception Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-EXC-' . $tag)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency('CAD');
        $em->persist($order);

        $lineIds = [];
        foreach ($specs as [$name, $ordered, $received, $unitCost]) {
            $line = (new PurchaseOrderLine())
                ->setProduct($product)
                ->setName($name . ' ' . $tag)
                ->setSku($product->getSku())
                ->setQuantityOrdered($ordered)
                ->setUnitCost($unitCost)
                ->setSubtotal(number_format((float) $ordered * (float) $unitCost, 2, '.', ''));
            $line->setQuantityReceived($received);
            $order->addLine($line);
            $em->persist($line);
            $lineIds[$name] = $line;
        }

        $order->setStatus('Issued', DocumentActor::system());
        $order->recalculateTotals();
        $em->flush();

        // Goods arriving moves the order's status, and the accrual feed reads that status. Derived
        // by the application's own deriver rather than typed here, so this setup cannot disagree
        // with what receiving would actually have left behind.
        $I->grabService(PurchaseOrderStatusDeriver::class)->recalculate($order);
        $em->flush();

        return [
            'vendorId' => (int) $vendor->getId(),
            'orderId' => (int) $order->getId(),
            'lineIds' => array_map(static fn (PurchaseOrderLine $line): int => (int) $line->getId(), $lineIds),
            'tag' => $tag,
        ];
    }

    /**
     * Raise a bill against the purchase order through the real form, and approve it.
     *
     * A DRAFT bill is deliberately invisible to these screens — it has authorised nothing — so a
     * test that stopped at the save would be asserting against an empty table. Approval goes
     * through the approve form on the bill's own page, token scraped from that page.
     *
     * @param list<array<string, string>> $lines
     */
    private function postAndApproveBill(FunctionalTester $I, int $orderId, array $lines, string $vendorInvoiceNo): int
    {
        $I->amOnPage('/admin/bundles/procurement/bills/new?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#bill-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => (string) $orderId,
            'vendor_invoice_no' => $vendorInvoiceNo,
            'document_date' => '2026-09-10',
            'lines' => $lines,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $billId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM vendor_bill WHERE vendor_invoice_no = ?',
            [$vendorInvoiceNo],
        );
        $I->assertGreaterThan(0, $billId, 'the bill form did not save a bill');

        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->seeResponseCodeIsSuccessful();
        $approveToken = (string) $I->grabAttributeFrom('form[action$="/approve"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', ['_token' => $approveToken]);
        $I->seeResponseCodeIsSuccessful();

        // Read back by column: 'Open' is what approval derives to with nothing paid, and it is the
        // state the dispute control below is gated on.
        $I->assertSame('Open', $this->billStatus($I, $billId));

        return $billId;
    }

    private function billStatus(FunctionalTester $I, int $billId): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (string) $em->getConnection()->fetchOne('SELECT status FROM vendor_bill WHERE id = ?', [$billId]);
    }

    /** Every bill line charged against one purchase order line, as the database holds them. */
    private function billedQuantity(FunctionalTester $I, int $orderLineId): float
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (float) $em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM vendor_bill_line WHERE purchase_order_line_id = ?',
            [$orderLineId],
        );
    }

    private function amountCell(int $position): string
    {
        return sprintf('%s tbody tr:nth-child(%d) td[data-label="Value at risk"]', self::ON_BILLS, $position);
    }

    private function lineCell(int $position): string
    {
        return sprintf('%s tbody tr:nth-child(%d) td[data-label="Line"]', self::ON_BILLS, $position);
    }

    // ------------------------------------------------------------------ the money column

    /**
     * The biggest exception is at the top, and the ordering is by VALUE and not by the text of the
     * cell — asserted in both directions, because text-ascending and value-descending agree.
     *
     * Three lines are billed in full while nothing has arrived against them, at $1.0000 a unit, so
     * each one's exposure is its quantity in dollars: 9.00, 80.00 and 1000.00. A fourth line is
     * ordered, received and billed at four, which is clean and is the row that must NOT appear.
     */
    public function theBiggestExceptionIsTopAndTheOrderIsByValueNotByText(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Small Gap', '9.00', '0.00', '1.0000'],
            ['Medium Gap', '80.00', '0.00', '1.0000'],
            ['Large Gap', '1000.00', '0.00', '1.0000'],
            ['Settled Line', '4.00', '4.00', '1.0000'],
        ]);
        $tag = $context['tag'];

        $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Small Gap'], 'name' => 'Small Gap', 'qty' => '9', 'unit_cost' => '1.0000'],
            1 => ['purchase_order_line_id' => (string) $context['lineIds']['Medium Gap'], 'name' => 'Medium Gap', 'qty' => '80', 'unit_cost' => '1.0000'],
            2 => ['purchase_order_line_id' => (string) $context['lineIds']['Large Gap'], 'name' => 'Large Gap', 'qty' => '1000', 'unit_cost' => '1.0000'],
            3 => ['purchase_order_line_id' => (string) $context['lineIds']['Settled Line'], 'name' => 'Settled Line', 'qty' => '4', 'unit_cost' => '1.0000'],
        ], 'THEIR-SORT-' . $tag);

        // Default view: biggest money first, without anybody clicking anything.
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $I->see('CAD 1000.00', $this->amountCell(1));
        $I->see('Large Gap ' . $tag, $this->lineCell(1));
        $I->see('CAD 80.00', $this->amountCell(2));
        $I->see('Medium Gap ' . $tag, $this->lineCell(2));
        $I->see('CAD 9.00', $this->amountCell(3));
        $I->see('Small Gap ' . $tag, $this->lineCell(3));

        // The clean line is not an exception and is not here. Paired with the positive control that
        // the table it would have been in did render rows at all.
        $I->seeElement(self::ON_BILLS . ' tbody tr.data-item-row');
        $I->dontSee('Settled Line ' . $tag, self::ON_BILLS);
        $I->seeNumberOfElements(self::ON_BILLS . ' tbody tr.data-item-row', 3);

        // Ascending. Sorted as text this would be 1000.00, 80.00, 9.00 — the same sequence the
        // descending view above shows — which is how a string sort hides behind a single-direction
        // test.
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId'] . '&sort=amount&dir=asc');
        $I->seeResponseCodeIsSuccessful();

        $I->see('CAD 9.00', $this->amountCell(1));
        $I->see('Small Gap ' . $tag, $this->lineCell(1));
        $I->see('CAD 80.00', $this->amountCell(2));
        $I->see('CAD 1000.00', $this->amountCell(3));
        $I->see('Large Gap ' . $tag, $this->lineCell(3));
    }

    /**
     * Billed but not received offers the two moves the controller docblock has named since #555 —
     * and both of them open a screen that can actually complete them.
     *
     * The point of the column is not that it contains links. It is that following one arrives
     * somewhere the move can be finished: the receiving form for THIS order, with this order's own
     * outstanding line already in the quantity box.
     */
    public function billedButNotReceivedOffersTheReceiptAndTheDisputeAndBothOpen(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Missing Ninety', '90.00', '0.00', '1.0000'],
        ]);
        $tag = $context['tag'];

        $bill = $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Missing Ninety'], 'name' => 'Missing Ninety', 'qty' => '90', 'unit_cost' => '1.0000'],
        ], 'THEIR-MOVES-' . $tag);

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $actions = self::ON_BILLS . ' tr[data-bill="' . $bill . '"] td[data-label="Actions"]';
        $I->see('CAD 90.00', self::ON_BILLS . ' tr[data-bill="' . $bill . '"] td[data-label="Value at risk"]');
        $I->see('Record the receipt', $actions);
        $I->see('Dispute this bill', $actions);
        // Two moves, not a menu — and specifically not the debit memo, which is the fallback for a
        // bill that can no longer be disputed and would be noise on one that can.
        $I->seeNumberOfElements($actions . ' a', 2);
        $I->dontSee('Raise a debit memo', $actions);

        $receive = (string) $I->grabAttributeFrom(
            sprintf('//section[@id="exceptions-on-bills"]//tr[@data-bill="%d"]//a[normalize-space()="Record the receipt"]', $bill),
            'href',
        );
        $I->amOnPage($receive);
        $I->seeResponseCodeIsSuccessful();
        // The receiving form opened against this order, with the ninety units still outstanding on
        // the line the exception is about. That is what "the move is reachable" has to mean.
        $I->see('Missing Ninety ' . $tag);
        $I->seeInField('lines[0][quantity]', '90');
    }

    /**
     * The footer says how many, in the shape item 48's count-link is going to quote back.
     *
     * Counted in PHP from the rows in hand — deliberately not with a COUNT query, because
     * AdminListScreenConventionsCest reads "one request ran a counting AND a limiting query rooted
     * at table X" as "this screen is X's list screen", and this worklist caps two feeds at 200 rows
     * without paging either.
     */
    public function theFilteredScreenCountsWhatItIsShowing(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Small Gap', '9.00', '0.00', '1.0000'],
            ['Medium Gap', '80.00', '0.00', '1.0000'],
        ]);
        $tag = $context['tag'];

        $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Small Gap'], 'name' => 'Small Gap', 'qty' => '9', 'unit_cost' => '1.0000'],
            1 => ['purchase_order_line_id' => (string) $context['lineIds']['Medium Gap'], 'name' => 'Medium Gap', 'qty' => '80', 'unit_cost' => '1.0000'],
        ], 'THEIR-COUNT-' . $tag);

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId'] . '&filters[kind]=billed_not_received');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Showing 1 to 2 of 2 bill exceptions', self::ON_BILLS . ' .table-footer');
        $I->see('CAD 89.00 at risk', self::ON_BILLS . ' .table-footer');

        // Narrowed to one kind, the sections about the other kinds are not on the page at all.
        // Positive control for that absence: the section that IS the chosen kind is.
        $I->seeElement(self::ON_BILLS);
        $I->dontSeeElement(self::ACCRUALS);
    }

    // ------------------------------------------------------------------ the move on the row

    /**
     * Disputing from the row parks THAT bill and leaves the other one alone.
     *
     * Conducted end to end: the link is followed off the exception row, the reason box is found
     * already carrying the match's own finding, the form is posted with its own scraped token, and
     * `vendor_bill.status` is read back out of the database by column for BOTH bills — the one that
     * was disputed and the one that must not have moved.
     */
    public function disputingFromTheRowParksThatBillAndLeavesTheOtherAlone(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Overcharged Ten', '10.00', '10.00', '5.0000'],
            ['Overcharged Twenty', '20.00', '20.00', '5.0000'],
        ]);
        $tag = $context['tag'];

        // Two bills, each charging a dollar a unit over the quoted price: one finding apiece, so
        // the pre-filled reason below is exactly one sentence.
        $disputed = $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Overcharged Ten'], 'name' => 'Overcharged Ten', 'qty' => '10', 'unit_cost' => '6.0000'],
        ], 'THEIR-DISPUTE-A-' . $tag);
        $untouched = $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Overcharged Twenty'], 'name' => 'Overcharged Twenty', 'qty' => '20', 'unit_cost' => '6.0000'],
        ], 'THEIR-DISPUTE-B-' . $tag);

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $disputedRow = self::ON_BILLS . ' tr[data-bill="' . $disputed . '"]';
        $untouchedRow = self::ON_BILLS . ' tr[data-bill="' . $untouched . '"]';
        $I->see('CAD 10.00', $disputedRow . ' td[data-label="Value at risk"]');
        $I->see('CAD 20.00', $untouchedRow . ' td[data-label="Value at risk"]');

        // Follow the move off the row, exactly as somebody working the screen would. The href is
        // read off the row and requested rather than clicked: Codeception resolves a click against
        // the crawler's absolute URI and then refuses it as an external host, which says nothing
        // about the link. The fragment is asserted and then trimmed, because it is the browser's
        // business and not the request's.
        $href = (string) $I->grabAttributeFrom(
            sprintf('//section[@id="exceptions-on-bills"]//tr[@data-bill="%d"]//a[normalize-space()="Dispute this bill"]', $disputed),
            'href',
        );
        $I->assertStringContainsString('#dispute', $href, 'the row should land on the dispute form, not the top of the bill');
        $I->amOnPage(explode('#', $href)[0]);
        $I->seeResponseCodeIsSuccessful();

        // The finding arrives typed into the box, because dispute() refuses an empty reason and a
        // list screen may not invent one on somebody's behalf.
        $I->seeInField('reason', 'Overcharged Ten ' . $tag . ': price variance.');

        $token = (string) $I->grabAttributeFrom('#dispute form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $disputed . '/dispute', [
            '_token' => $token,
            'reason' => 'Overcharged Ten ' . $tag . ': price variance.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Read back by column, for the bill that moved and the bill that must not have.
        $I->assertSame('Disputed', $this->billStatus($I, $disputed));
        $I->assertSame('Open', $this->billStatus($I, $untouched));
    }

    /**
     * A bill that can no longer be disputed is not offered the control — and is offered the one
     * that still works instead.
     *
     * `VendorBill::dispute()` accepts Draft, Open and Partially Paid and refuses everything else,
     * so a second dispute on an already-disputed bill would be refused on arrival. Offering it
     * would be the defect item 47 exists to fix: a control that costs a click and a page load to
     * learn what the row could have said.
     */
    public function anAlreadyDisputedBillIsNotOfferedADisputeThatWouldBeRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Overcharged Ten', '10.00', '10.00', '5.0000'],
            ['Overcharged Twenty', '20.00', '20.00', '5.0000'],
        ]);
        $tag = $context['tag'];

        $disputed = $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Overcharged Ten'], 'name' => 'Overcharged Ten', 'qty' => '10', 'unit_cost' => '6.0000'],
        ], 'THEIR-GATE-A-' . $tag);
        $stillOpen = $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $context['lineIds']['Overcharged Twenty'], 'name' => 'Overcharged Twenty', 'qty' => '20', 'unit_cost' => '6.0000'],
        ], 'THEIR-GATE-B-' . $tag);

        $I->amOnPage('/admin/bundles/procurement/bills/' . $disputed);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('#dispute form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $disputed . '/dispute', [
            '_token' => $token,
            'reason' => 'Charged above the quoted price.',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Disputed', $this->billStatus($I, $disputed));
        $I->assertSame('Open', $this->billStatus($I, $stillOpen));

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $disputedActions = self::ON_BILLS . ' tr[data-bill="' . $disputed . '"] td[data-label="Actions"]';
        $openActions = self::ON_BILLS . ' tr[data-bill="' . $stillOpen . '"] td[data-label="Actions"]';

        // The refused control is gone from the disputed row; the move that still works is there.
        $I->dontSee('Dispute this bill', $disputedActions);
        $I->see('Raise a debit memo', $disputedActions);

        // And the row that must NOT have changed still carries it.
        $I->see('Dispute this bill', $openActions);

        // The state that decided which controls each row got is on the row, in the pill every grid
        // in the application uses — queue item 41's ruling, and here it is also the explanation for
        // the two assertions above. Read off the cell, never off the page: "Open" and "Disputed"
        // are both in the kind dropdown and in the bill's own timeline elsewhere.
        $I->see('Disputed', self::ON_BILLS . ' tr[data-bill="' . $disputed . '"] td[data-label="Status"] span.order-status');
        $I->see('Open', self::ON_BILLS . ' tr[data-bill="' . $stillOpen . '"] td[data-label="Status"] span.order-status');
    }

    /**
     * "Enter the bill" off an accrual row raises the bill it says is missing — and the accrual next
     * to it, which nobody touched, is still there afterwards saying the same thing.
     *
     * The full round trip the screen claims: a row that names money we owe, a link that lands on
     * the bill form pre-filled from the order, a bill raised and approved through the real screens,
     * and the row gone from the screen because the fact behind it has changed.
     */
    public function enteringTheBillFromAnAccrualRowClearsItAndLeavesItsNeighbourAlone(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seed($I, [
            ['Arrived Ten', '10.00', '10.00', '5.0000'],
            ['Arrived Six', '6.00', '6.00', '5.0000'],
        ]);
        $tag = $context['tag'];
        $billedLineId = $context['lineIds']['Arrived Ten'];
        $untouchedLineId = $context['lineIds']['Arrived Six'];

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $billedRow = self::ACCRUALS . ' tr[data-po-line="' . $billedLineId . '"]';
        $untouchedRow = self::ACCRUALS . ' tr[data-po-line="' . $untouchedLineId . '"]';
        $I->see('CAD 50.00', $billedRow . ' td[data-label="Value at risk"]');
        $I->see('CAD 30.00', $untouchedRow . ' td[data-label="Value at risk"]');

        // Nothing has been billed against either line yet — the figure above is a claim about the
        // database, so the database is asked.
        $I->assertSame(0.0, $this->billedQuantity($I, $billedLineId));
        $I->assertSame(0.0, $this->billedQuantity($I, $untouchedLineId));

        $enterTheBill = (string) $I->grabAttributeFrom(
            sprintf('//section[@id="exceptions-received-not-billed"]//tr[@data-po-line="%d"]//a[normalize-space()="Enter the bill"]', $billedLineId),
            'href',
        );
        $I->amOnPage($enterTheBill);
        $I->seeResponseCodeIsSuccessful();
        // The link landed on the bill form for THIS order, with the order's own lines on it.
        $I->see('Arrived Ten ' . $tag);

        $this->postAndApproveBill($I, $context['orderId'], [
            0 => ['purchase_order_line_id' => (string) $billedLineId, 'name' => 'Arrived Ten', 'qty' => '10', 'unit_cost' => '5.0000'],
        ], 'THEIR-ACCRUAL-' . $tag);

        // By column: ten units charged against the line that was worked, nothing at all against the
        // line that was not.
        $I->assertSame(10.0, $this->billedQuantity($I, $billedLineId));
        $I->assertSame(0.0, $this->billedQuantity($I, $untouchedLineId));

        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        // The accrual that was settled is gone; its neighbour is still there, unchanged, which is
        // the positive control for that absence.
        $I->dontSeeElement($billedRow);
        $I->seeElement($untouchedRow);
        $I->see('CAD 30.00', $untouchedRow . ' td[data-label="Value at risk"]');
    }
}
