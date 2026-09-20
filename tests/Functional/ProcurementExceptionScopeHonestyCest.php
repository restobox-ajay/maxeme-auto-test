<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Controller\Admin\ExceptionController;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Two screens counted different populations of bill and neither admitted it — conducted per #624.
 *
 * ## What a real user saw
 *
 * A vendor bill's detail page said "10 exception(s). Every one needs a human." The Exceptions
 * worklist, opened seconds later, said every bill matched its purchase order and its receipts.
 * Both numbers were arithmetically correct:
 *
 *  - the bill page counts the exceptions on the bill you opened, in WHATEVER state it is in. The
 *    three-way match has no status predicate — it reports on the document in front of you;
 *  - the worklist covers only the bills `VendorBillStatus::counts()` is true for, which excludes
 *    Draft and Void, and reads at most `ExceptionController::DEFAULT_SCAN_CAP` of them.
 *
 * The bill was a Draft. So one screen was counting ten exceptions that the other screen was, by
 * design, never going to show — and the sentence the other screen printed was not "nothing on this
 * list" but "Every bill matches", which is a claim about a population it had excluded. Flip that
 * bill's `status` column from Draft to Open with no other change and the worklist goes from zero
 * rows to ten.
 *
 * Nothing about the arithmetic is under test here and nothing about it changed. What is under test
 * is what the two screens SAY.
 *
 * ## Three cases, and case 2 is case 1's positive control
 *
 *  1. a Draft bill carrying exceptions states its count AND that the count is not on the worklist;
 *     the worklist's empty state does not claim every bill matches, and says how many drafts it is
 *     holding back.
 *  2. the SAME bill approved through the real form: the detail page reverts to the ordinary
 *     wording, the scope sentence is gone, and the bill turns up on the worklist. Without this,
 *     case 1 would pass against a page that never says "Every one needs a human" at all.
 *  3. more exception-carrying bills than the cap: the footer states the true number available
 *     rather than the number it managed to show, and does NOT state it when the cap did not bite.
 *
 * ## #627 throughout
 *
 * Never `see()` on a bare number — `see('10')` matches '210', and every figure on these screens is
 * a small integer. Every assertion below names the element it is about by id or by a class inside a
 * named section, and every absence is paired with a positive control on that SAME element: the
 * element that must not say X is asserted to be present and to say Y instead, so a renamed id or a
 * silently empty section fails rather than passing as an absence.
 *
 * ## What is set up and what is conducted
 *
 * Vendors, products and purchase orders are SETUP — they are the state an exception is a fact
 * about. Every bill whose STATUS this file makes a claim about is raised through the real bill form
 * and approved through the real approve form, tokens scraped off the page, and the status is read
 * back out of the `vendor_bill.status` COLUMN after every POST rather than believed from rendered
 * text or an HTTP 200.
 *
 * Case 3's bulk bills are the one exception and deliberately so: the claim there is about the
 * screen's cap, not about how a bill comes into being, and two hundred conducted form posts would
 * be a fixture suite pretending to be a conformance test. Their status is still read back from the
 * column, in bulk, before the screen is asked anything.
 */
final class ProcurementExceptionScopeHonestyCest
{
    private const SCREEN = '/admin/bundles/procurement/exceptions';
    private const ON_BILLS = '#exceptions-on-bills';
    private const EMPTY_STATE = '#exceptions-on-bills .empty-table-cell';
    private const DRAFTS_EXCLUDED = '#exceptions-drafts-excluded';
    private const SCAN_NOTE = '#exceptions-on-bills-scan';
    private const MATCH_LEAD = '#bill-three-way-match .lead';
    private const EXCEPTION_COUNT = '#bill-exception-count';
    private const EXCEPTION_SCOPE = '#bill-exception-scope';

    /**
     * Codeception reuses ONE instance of this class across every method in it, so anything stashed
     * on `$this` survives into the next test and reads as state that test created. Nothing is
     * stashed here — every case keeps its ids in locals and passes them along — and this method
     * exists to keep that true rather than to clear anything.
     *
     * `AppSettings` caches its rows in a pool OUTSIDE the per-test transaction, so a snapshot taken
     * by an earlier test survives the rollback. Every bill numbers itself through
     * `PurchaseDocumentNumberGenerator`, which reads its prefix through that cache.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('scope-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor, a product and an ISSUED purchase order with one line ordered and nothing received.
     *
     * Nothing received is what makes any bill against it an exception: billed-but-not-received, the
     * most expensive finding the match has and the one the worklist exists for.
     *
     * @param array<string, string> $lines name => quantity ordered
     *
     * @return array{vendorId: int, orderId: int, lineId: int, lineIds: array<string, int>, warehouseId: int, tag: string}
     */
    private function seedOrder(FunctionalTester $I, string $ordered = '10.00', array $lines = []): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('Scope Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Scope Vendor ' . $tag)->setCurrency('CAD');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('SCOPE-' . $tag)
            ->setName('Scope Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-SCOPE-' . $tag)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency('CAD');
        $em->persist($order);

        $specs = $lines === [] ? ['Scope Line' => $ordered] : $lines;
        $created = [];

        foreach ($specs as $name => $quantity) {
            $line = (new PurchaseOrderLine())
                ->setProduct($product)
                ->setName($name . ' ' . $tag)
                ->setSku($product->getSku())
                ->setQuantityOrdered($quantity)
                ->setUnitCost('1.0000')
                ->setSubtotal(number_format((float) $quantity, 2, '.', ''));
            $line->setQuantityReceived('0.00');
            $order->addLine($line);
            $em->persist($line);
            $created[$name] = $line;
        }

        $order->setStatus('Issued', DocumentActor::system());
        $order->recalculateTotals();
        $em->flush();

        $I->grabService(PurchaseOrderStatusDeriver::class)->recalculate($order);
        $em->flush();

        $lineIds = array_map(static fn (PurchaseOrderLine $line): int => (int) $line->getId(), $created);

        return [
            'vendorId' => (int) $vendor->getId(),
            'orderId' => (int) $order->getId(),
            'lineId' => (int) reset($lineIds),
            'lineIds' => $lineIds,
            'warehouseId' => (int) $warehouse->getId(),
            'tag' => $tag,
        ];
    }

    /**
     * Raise a bill against the order through the real form and leave it as a DRAFT.
     *
     * The draft is the whole point here, so unlike every other conducted bill in this suite this
     * one is not approved. The status is read back out of the column, because "the form returned
     * 200" is not evidence that a row in state Draft exists.
     *
     * @return array{billId: int, invoiceNo: string}
     */
    private function postDraftBill(FunctionalTester $I, int $orderId, int $lineId, string $quantity, string $invoiceNo, string $lineName = 'Scope Line'): array
    {
        $I->amOnPage('/admin/bundles/procurement/bills/new?po=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#bill-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => (string) $orderId,
            'vendor_invoice_no' => $invoiceNo,
            'document_date' => '2026-09-10',
            'lines' => [
                0 => [
                    'purchase_order_line_id' => (string) $lineId,
                    'name' => $lineName,
                    'qty' => $quantity,
                    'unit_cost' => '1.0000',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $billId = (int) $this->column($I, 'SELECT id FROM vendor_bill WHERE vendor_invoice_no = ?', $invoiceNo);
        $I->assertGreaterThan(0, $billId, 'the bill form did not save a bill');
        $I->assertSame('Draft', $this->billStatus($I, $billId), 'a saved bill should start as a Draft');

        return ['billId' => $billId, 'invoiceNo' => $invoiceNo];
    }

    /** Approve through the form on the bill's own page, then read the column back. */
    private function approve(FunctionalTester $I, int $billId): void
    {
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action$="/approve"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('Open', $this->billStatus($I, $billId), 'approving a bill with nothing paid derives it to Open');
    }

    private function billStatus(FunctionalTester $I, int $billId): string
    {
        return (string) $this->column($I, 'SELECT status FROM vendor_bill WHERE id = ?', (string) $billId);
    }

    private function column(FunctionalTester $I, string $sql, string $parameter): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (string) $em->getConnection()->fetchOne($sql, [$parameter]);
    }

    // ───────────────────────────────────────────────────────── case 1: the draft states its scope

    /**
     * A Draft bill with exceptions says how many, and says they are not on the worklist — and the
     * worklist, looking at the same data, does not claim every bill matches.
     */
    public function aDraftBillStatesItsCountAndThatTheCountIsNotOnTheWorklist(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedOrder($I);
        $bill = $this->postDraftBill($I, $context['orderId'], $context['lineId'], '10', 'SCOPE-DRAFT-' . $context['tag']);

        // ── the bill's own page ──────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/bundles/procurement/bills/' . $bill['billId']);
        $I->seeResponseCodeIsSuccessful();

        // The count, in the element that holds it — never as a bare number against the whole page.
        $I->see('1 exception(s).', self::EXCEPTION_COUNT);

        // And the scope that count holds for. "Every one needs a human" is what this said before,
        // about a bill nothing was going to pick up.
        $I->see('not on the Exceptions worklist', self::EXCEPTION_SCOPE);
        $I->see('A Draft bill', self::EXCEPTION_SCOPE);

        // The absence, on the element that would carry it. Paired with the positive control in
        // `approvingTheBillRestoresTheOrdinaryWordingAndPutsItOnTheWorklist()`, which asserts this
        // same selector DOES say it once the bill is approved.
        $I->dontSee('Every one needs a human', self::MATCH_LEAD);
        $I->see('exception(s).', self::MATCH_LEAD);

        // ── the worklist, same data ──────────────────────────────────────────────────────────
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        // The table is empty, which is correct: a draft is not on this list. What was NOT correct
        // was the sentence under it.
        $I->dontSeeElement(self::ON_BILLS . ' tbody tr.data-item-row');
        $I->seeElement(self::EMPTY_STATE);
        $I->dontSee('Every bill matches its purchase order and its receipts', self::EMPTY_STATE);
        $I->see('No exceptions on the bills this worklist covers.', self::EMPTY_STATE);
        $I->see('Draft and void bills are not on this list.', self::EMPTY_STATE);

        // And what it is holding back, with the figure rather than a vague warning.
        $I->see('1 draft bill(s) carry exceptions', self::DRAFTS_EXCLUDED);
        $I->dontSee('No draft bill carries an exception either.', self::DRAFTS_EXCLUDED);
    }

    // ───────────────────────────────────────────── case 2: approved — the positive control for 1

    /**
     * The same bill, approved through the real form: the detail page goes back to the ordinary
     * wording and the bill turns up on the worklist.
     *
     * This is case 1's positive control in both halves. Without it, case 1's `dontSee` assertions
     * would pass just as happily against a page that had lost the sentence entirely, or against a
     * worklist that showed nothing for any bill at all.
     */
    public function approvingTheBillRestoresTheOrdinaryWordingAndPutsItOnTheWorklist(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        // Two lines on one order, so a SECOND draft bill can sit beside the one being approved
        // without either claiming quantity the other needs. That second bill is the row that must
        // not change: approving bill A must not drag draft bill B onto the worklist, and must not
        // touch B's status column or restate B's own page.
        $context = $this->seedOrder($I, '10.00', ['Scope Line' => '10.00', 'Control Line' => '7.00']);

        $approved = $this->postDraftBill($I, $context['orderId'], $context['lineIds']['Scope Line'], '10', 'SCOPE-OPEN-' . $context['tag']);
        $control = $this->postDraftBill($I, $context['orderId'], $context['lineIds']['Control Line'], '7', 'SCOPE-CTRL-' . $context['tag'], 'Control Line');

        // The count BEFORE the status moves, so the assertion after it is a comparison and not a
        // fresh guess. Nothing about the match arithmetic is supposed to change here — only the
        // sentence wrapped around it — and this is the pair that says so.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $approved['billId']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('1 exception(s).', self::EXCEPTION_COUNT);
        $I->seeElement(self::EXCEPTION_SCOPE);

        $this->approve($I, $approved['billId']);

        // ── the bill's own page ──────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/bundles/procurement/bills/' . $approved['billId']);
        $I->seeResponseCodeIsSuccessful();

        // Same figure as before the transition: the status moved, the arithmetic did not.
        $I->see('1 exception(s).', self::EXCEPTION_COUNT);
        // The sentence case 1 asserts is absent. Same selector, opposite expectation.
        $I->see('Every one needs a human', self::MATCH_LEAD);
        // And the scope sentence is gone, because the scope no longer needs stating.
        $I->dontSeeElement(self::EXCEPTION_SCOPE);
        $I->seeElement(self::EXCEPTION_COUNT);

        // ── the worklist ─────────────────────────────────────────────────────────────────────
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        $row = self::ON_BILLS . ' tr[data-bill="' . $approved['billId'] . '"]';
        $I->seeElement($row);
        $I->see('Scope Line', $row . ' td[data-label="Line"]');
        $I->see('Open', $row . ' td[data-label="Status"]');

        // The empty state and its draft note are gone, and the row above is the positive control
        // saying the section rendered at all rather than having been renamed out from under us.
        $I->dontSeeElement(self::EMPTY_STATE);
        $I->dontSeeElement(self::DRAFTS_EXCLUDED);

        // ── the row that should NOT have changed ─────────────────────────────────────────────
        //
        // The control bill is still a draft, so it is still off this list — asserted on the same
        // table that just proved it can show a row, which is what makes the absence mean something.
        $I->dontSeeElement(self::ON_BILLS . ' tr[data-bill="' . $control['billId'] . '"]');
        $I->seeNumberOfElements(self::ON_BILLS . ' tbody tr.data-item-row', 1);

        // Read back by column, both of them, after the POST: approving one bill moved one row.
        $I->assertSame('Open', $this->billStatus($I, $approved['billId']));
        $I->assertSame('Draft', $this->billStatus($I, $control['billId']));

        // And the control's own page still says what case 1 says, unchanged by its neighbour.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $control['billId']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('1 exception(s).', self::EXCEPTION_COUNT);
        $I->see('A Draft bill', self::EXCEPTION_SCOPE);
        $I->dontSee('Every one needs a human', self::MATCH_LEAD);
    }

    // ─────────────────────────────────────────────────────────────── case 3: the cap says so now

    /**
     * More exception-carrying bills than the screen reads: the footer states the true number
     * available instead of passing the number it showed off as the total.
     *
     * Two vendors, one over the cap and one under it, asserted in the same test against the SAME
     * element. The under-cap vendor is the positive control for the absence: a screen that never
     * renders the note at all would pass a one-sided test, and a screen that always renders it
     * would be noise on every page view.
     */
    public function overTheCapTheFooterStatesWhatItDidNotRead(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $cap = ExceptionController::DEFAULT_SCAN_CAP;
        $over = $this->seedOrder($I, (string) ($cap * 10) . '.00');
        $this->bulkApprovedBills($I, $over, $cap + 5);

        // A second vendor, well under the cap, through the same seeding path.
        $under = $this->seedOrder($I);
        $this->bulkApprovedBills($I, $under, 2);

        // ── over the cap ─────────────────────────────────────────────────────────────────────
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $over['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        // The screen states the population, not the slice of it that fitted. Anchored to the note's
        // own id and asserted as a phrase, so no bare number is matched anywhere.
        $I->seeElement(self::SCAN_NOTE);
        $I->see('of ' . ($cap + 5) . ' bills', self::SCAN_NOTE);
        $I->see('the most recent ' . $cap . ' of', self::SCAN_NOTE);
        $I->see('5 not examined', self::SCAN_NOTE);

        // ── under the cap: same element, absent ──────────────────────────────────────────────
        $I->amOnPage(self::SCREEN . '?filters[vendor]=' . $under['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        // Positive control first: this vendor's rows really are on the screen, so the absence below
        // is the note being withheld and not the section failing to render.
        $I->seeElement(self::ON_BILLS . ' tbody tr.data-item-row');
        $I->dontSeeElement(self::SCAN_NOTE);
    }

    /**
     * `$count` approved bills against the seeded order, each billing one unit of a line nothing has
     * arrived against — one billed-but-not-received exception apiece.
     *
     * Written through the entities rather than through the bill form, for the reason given in the
     * class docblock: the claim under test is what the screen says about its own cap, and two
     * hundred conducted form posts would make this a fixture suite. What the form would have done
     * is still done — `approve()` is the entity's own named action, the same one the approve
     * controller calls — and the resulting statuses are read back from the column in bulk below
     * before the screen is asked anything.
     *
     * @param array{vendorId: int, orderId: int, lineId: int, warehouseId: int, tag: string} $context
     */
    private function bulkApprovedBills(FunctionalTester $I, array $context, int $count): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $order = $em->getRepository(PurchaseOrder::class)->find($context['orderId']);
        $orderLine = $em->getRepository(PurchaseOrderLine::class)->find($context['lineId']);
        $warehouse = $em->getRepository(\App\Entity\Warehouse::class)->find($context['warehouseId']);
        $product = $orderLine->getProduct();

        for ($i = 1; $i <= $count; ++$i) {
            $bill = (new VendorBill())
                ->setBillNumber(sprintf('BILL-%s-%04d', $context['tag'], $i))
                ->setVendor($order->getVendor())
                ->setVendorName($order->getVendorName())
                ->setVendorInvoiceNo(sprintf('THEIR-%s-%04d', $context['tag'], $i))
                ->setPurchaseOrder($order)
                ->setDocumentDate('2026-09-10')
                ->setDueDate('2026-10-01');
            $bill->deriveTaxProvinceFrom($warehouse);
            $em->persist($bill);

            $line = (new VendorBillLine())
                ->setPurchaseOrderLine($orderLine)
                ->setProduct($product)
                ->setName('Scope Line ' . $context['tag'])
                ->setQuantity('1.00')
                ->setUnitCost('1.0000')
                ->setSubtotal('1.00');
            $bill->addLine($line);
            $em->persist($line);
            $bill->recalculateTotals();
            $bill->approve(DocumentActor::system(), 'Seeded.');
        }

        $em->flush();

        // Read back by column, in bulk: the screen's query selects on `status`, so this is the
        // figure the rest of the test depends on and it is not taken on trust.
        $open = (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM vendor_bill WHERE vendor_id = ? AND status = 'Open'",
            [$context['vendorId']],
        );
        $I->assertSame($count, $open, 'every seeded bill should be Open before the screen is asked anything');
    }
}
