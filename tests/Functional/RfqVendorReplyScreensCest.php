<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The RFQ screen and the vendor reply's own document screen, CONDUCTED (#624).
 *
 * Every request below is a plain form POST carrying a scraped CSRF token and no `X-Requested-With`
 * — what a browser with scripting off sends — and every assertion re-reads the actual
 * `table.column` from the database rather than trusting a flash message, a redirect or an HTTP 200.
 *
 * ## The test this file exists for
 *
 * `pricingOneVendorsQuoteLeavesEveryOtherVendorsQuoteUntouched`. Three vendors are asked the same
 * requirement; one is priced; the other two are asserted, row by row, to be exactly as they were.
 * That is the whole claim of the tender model — N competing quotes against one requirement, priced
 * independently — and it is the half that no amount of "the page said 200" can establish.
 *
 * ## And the one that says the RFQ still states no money
 *
 * `theRfqTableItselfHoldsNoMoneyAndTheReplyTableHoldsItAll` asserts it STRUCTURALLY, off
 * `pragma_table_info`, because that is the property this work had to preserve and a screenshot of a
 * page with no total on it proves nothing about the next person adding a column. `Rfq` is an argued
 * exception in `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`: a requirement put to several
 * vendors has no single counterparty, so it has no currency and no total until one of them answers.
 *
 * ## Numbers are asserted in cells, never with see() (#627)
 *
 * `see('50')` matches `'1050'`, and this feature is nothing but prices and quantities. Page-level
 * figures are asserted inside the element that should carry them; everything else is read back out
 * of the database and compared as a formatted decimal, because SQLite's NUMERIC affinity hands a
 * raw scalar fetch back as an int or a float rather than the string Doctrine would give.
 */
final class RfqVendorReplyScreensCest
{
    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here survives the rollback and is read by whatever runs next. Every document
     * raised below allocates a number through PurchaseDocumentNumberGenerator, which reads its
     * prefix through that cache — and one test below deliberately writes `company_state`, which must
     * not outlive it. Hence both hooks.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('rfq-reply-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A warehouse, three vendors and two products, created directly — this is the fixture, not the
     * subject. What is conducted is every screen below it.
     *
     * @return array{warehouse: int, vendors: array{0: int, 1: int, 2: int}, products: array{0: int, 1: int}}
     */
    private function seed(FunctionalTester $I, string $taxCode = 'G'): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Reply Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendorIds = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $vendor = (new Vendor())->setName('Quote Vendor ' . $letter . ' ' . uniqid());
            $em->persist($vendor);
            $em->flush();
            $vendorIds[] = (int) $vendor->getId();
        }

        $productIds = [];
        foreach (['BOLT', 'NUT'] as $kind) {
            $product = (new ProductCore())
                ->setSku('RFQ-' . $kind . '-' . random_int(10000, 99999))
                ->setName('RFQ ' . $kind)
                ->setSalesTaxCode($taxCode)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $em->persist($product);
            $em->flush();
            $productIds[] = (int) $product->getId();
        }

        return [
            'warehouse' => (int) $warehouse->getId(),
            'vendors' => [$vendorIds[0], $vendorIds[1], $vendorIds[2]],
            'products' => [$productIds[0], $productIds[1]],
        ];
    }

    /**
     * Raises an RFQ for two lines through the real form and tenders it to the vendors named.
     *
     * @param list<int> $vendorIds
     *
     * @return array{rfq: int, lines: array{0: int, 1: int}, replies: array<int, int>} replies keyed by vendor id
     */
    private function raiseAndTender(FunctionalTester $I, array $seed, array $vendorIds, string $marker): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $I->amOnPage('/admin/bundles/procurement/rfqs/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form#rfq-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/save', [
            '_token' => $token,
            'id' => '0',
            'warehouse_id' => (string) $seed['warehouse'],
            'delivery_address' => "Dock 4, 18 Wharf Street\nVictoria BC V8W 1T3",
            'notes' => $marker,
            'lines' => [
                0 => ['product_id' => (string) $seed['products'][0], 'name' => 'RFQ BOLT', 'sku' => 'BOLT-1', 'quantity' => '10', 'notes' => 'Zinc plated'],
                1 => ['product_id' => (string) $seed['products'][1], 'name' => 'RFQ NUT', 'sku' => 'NUT-1', 'quantity' => '5', 'notes' => ''],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        /** @var Rfq|null $rfq */
        $rfq = $em->getRepository(Rfq::class)->findOneBy(['notes' => $marker]);
        $I->assertNotNull($rfq, 'the requirement form actually created an RFQ');
        $rfqId = (int) $rfq->getId();

        $lineIds = [];
        foreach ($rfq->getLines() as $line) {
            $lineIds[] = (int) $line->getId();
        }
        $I->assertCount(2, $lineIds, 'both requirement rows were saved as lines');

        $I->amOnPage('/admin/bundles/procurement/rfqs/' . $rfqId);
        $I->seeResponseCodeIsSuccessful();
        $sendToken = $I->grabAttributeFrom('form[action$="/send"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/' . $rfqId . '/send', [
            '_token' => $sendToken,
            'vendor_ids' => array_map('strval', $vendorIds),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $replies = [];
        foreach ($vendorIds as $vendorId) {
            $reply = $em->getRepository(RfqVendorReply::class)->findOneBy(['rfq' => $rfqId, 'vendor' => $vendorId]);
            $I->assertNotNull($reply, sprintf('sending created a reply for vendor %d', $vendorId));
            $replies[$vendorId] = (int) $reply->getId();
        }

        return ['rfq' => $rfqId, 'lines' => [$lineIds[0], $lineIds[1]], 'replies' => $replies];
    }

    /** Prices a reply through its own screen, exactly as an admin with no JavaScript would. */
    private function priceThrough(FunctionalTester $I, int $replyId, array $unitCosts, array $lineNotes = []): void
    {
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form#reply-form input[name="_token"]', 'value');

        $payload = ['_token' => $token, 'document_date' => '2026-09-11', 'currency' => 'CAD', 'notes' => 'Quoted by phone.'];
        $payload['unit_cost'] = $unitCosts;
        if ($lineNotes !== []) {
            $payload['line_notes'] = $lineNotes;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/rfq-replies/' . $replyId . '/save', $payload);
        $I->seeResponseCodeIsSuccessful();
    }

    /** @return array<string, string> the reply's stored money and status, formatted for comparison */
    private function replyRow(FunctionalTester $I, int $replyId): array
    {
        $row = $I->grabService('doctrine.orm.entity_manager')->getConnection()->fetchAssociative(
            'SELECT status, currency, document_date, subtotal, tax, total, replied_at FROM rfq_vendor_reply WHERE id = ?',
            [$replyId],
        );
        $I->assertNotFalse($row, sprintf('reply %d exists', $replyId));

        // SQLite's NUMERIC affinity hands a raw scalar fetch back as an int/float, not the decimal
        // string Doctrine's type conversion would give. Formatted, so 0 and '0.00' cannot read as
        // different rows.
        return [
            'status' => (string) $row['status'],
            'currency' => (string) $row['currency'],
            'document_date' => (string) $row['document_date'],
            'subtotal' => number_format((float) $row['subtotal'], 2, '.', ''),
            'tax' => number_format((float) $row['tax'], 2, '.', ''),
            'total' => number_format((float) $row['total'], 2, '.', ''),
            'replied' => $row['replied_at'] === null ? 'never' : 'yes',
        ];
    }

    /**
     * THE test in this file.
     *
     * One requirement, three vendors, one of them priced. The other two are asserted row by row to
     * be untouched — same status, same zero money, and not one `rfq_vendor_reply_line` between them.
     * With several replies against one RFQ, "pricing one leaves the others alone" is the property
     * the whole design rests on, and nothing else in the suite establishes it.
     */
    public function pricingOneVendorsQuoteLeavesEveryOtherVendorsQuoteUntouched(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $conn = $em->getConnection();

        $tender = $this->raiseAndTender($I, $seed, $seed['vendors'], 'Three-vendor tender ' . uniqid());
        [$vendorA, $vendorB, $vendorC] = $seed['vendors'];

        $before = [
            $vendorB => $this->replyRow($I, $tender['replies'][$vendorB]),
            $vendorC => $this->replyRow($I, $tender['replies'][$vendorC]),
        ];

        // --- Price vendor A only ------------------------------------------------------------
        $this->priceThrough($I, $tender['replies'][$vendorA], [
            (string) $tender['lines'][0] => '4.00',
            (string) $tender['lines'][1] => '10.00',
        ], [
            (string) $tender['lines'][0] => 'Their part 88-ZP',
        ]);

        // --- The vendor that was priced -------------------------------------------------------
        $after = $this->replyRow($I, $tender['replies'][$vendorA]);
        $I->assertSame('Replied', $after['status'], 'a fully priced quote is recorded as Replied');
        $I->assertSame('90.00', $after['subtotal'], '10 at 4.00 plus 5 at 10.00');
        $I->assertSame('0.00', $after['tax'], 'no province is configured in this fixture, so the shared calculators state no tax');
        $I->assertSame('90.00', $after['total'], 'total is the subtotal plus that tax and nothing else');
        $I->assertSame('2026-09-11', $after['document_date'], 'the quote date the form posted is the one stored');
        $I->assertSame('yes', $after['replied'], 'and the moment the prices went in is recorded');

        $linesA = $conn->fetchAllAssociative(
            'SELECT rfq_line_id, unit_cost, subtotal, notes FROM rfq_vendor_reply_line WHERE reply_id = ? ORDER BY rfq_line_id',
            [$tender['replies'][$vendorA]],
        );
        $I->assertCount(2, $linesA, 'one quoted line per requirement line');
        $I->assertSame($tender['lines'][0], (int) $linesA[0]['rfq_line_id'], 'the first quote prices the first requirement');
        $I->assertSame('4.0000', number_format((float) $linesA[0]['unit_cost'], 4, '.', ''));
        $I->assertSame('40.00', number_format((float) $linesA[0]['subtotal'], 2, '.', ''), '10 units at 4.00');
        $I->assertSame('Their part 88-ZP', (string) $linesA[0]['notes'], 'the per-line note the vendor gave is kept');
        $I->assertSame('10.0000', number_format((float) $linesA[1]['unit_cost'], 4, '.', ''));
        $I->assertSame('50.00', number_format((float) $linesA[1]['subtotal'], 2, '.', ''), '5 units at 10.00');

        // --- The two vendors that were NOT priced ---------------------------------------------
        foreach ([$vendorB, $vendorC] as $untouched) {
            $replyId = $tender['replies'][$untouched];

            $I->assertSame(
                $before[$untouched],
                $this->replyRow($I, $replyId),
                sprintf('reply %d is byte-for-byte what it was before another vendor was priced', $replyId),
            );
            $I->assertSame('Invited', $this->replyRow($I, $replyId)['status'], 'and is still merely invited');

            $lineCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM rfq_vendor_reply_line WHERE reply_id = ?', [$replyId]);
            $I->assertSame(0, $lineCount, sprintf('reply %d has no quoted lines at all — pricing one vendor wrote nothing to another', $replyId));
        }

        // --- And the requirement itself is unchanged by anybody's prices ------------------------
        $rfqRow = $conn->fetchAssociative('SELECT status, warehouse_id FROM rfq WHERE id = ?', [$tender['rfq']]);
        $I->assertSame('Sent', (string) $rfqRow['status'], 'pricing a reply does not close the tender');
        $I->assertSame($seed['warehouse'], (int) $rfqRow['warehouse_id']);
    }

    /**
     * A quote recorded half way stays Invited, and the blank line is stored as NOT QUOTED rather
     * than as a price of nothing.
     *
     * `rfq_vendor_reply_line.unit_cost` is nullable precisely so the two can be told apart, and
     * `RfqVendorReply::isFullyPriced()` reads exactly that — which is what decides whether the quote
     * can be accepted at all. The screen this replaced skipped a blank box entirely, so a
     * half-recorded reply and one nobody had opened were the same rows in the same state.
     */
    public function aQuoteWithALineLeftBlankStaysInvitedAndStoresTheBlankAsNotQuoted(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);
        $conn = $I->grabService('doctrine.orm.entity_manager')->getConnection();

        $tender = $this->raiseAndTender($I, $seed, [$seed['vendors'][0]], 'Half-priced tender ' . uniqid());
        $replyId = $tender['replies'][$seed['vendors'][0]];

        $this->priceThrough($I, $replyId, [
            (string) $tender['lines'][0] => '4.00',
            (string) $tender['lines'][1] => '',
        ]);

        $row = $this->replyRow($I, $replyId);
        $I->assertSame('Invited', $row['status'], 'a quote missing a price is not a quote the vendor has given');
        $I->assertSame('40.00', $row['subtotal'], 'what was quoted is still totalled — the record is partial, not discarded');
        $I->assertSame('never', $row['replied'], 'and the document does not claim the vendor has replied');

        $lines = $conn->fetchAllAssociative(
            'SELECT rfq_line_id, unit_cost, subtotal FROM rfq_vendor_reply_line WHERE reply_id = ? ORDER BY rfq_line_id',
            [$replyId],
        );
        $I->assertCount(2, $lines, 'both requirement lines have a row, so the missing price is visible as a gap');
        $I->assertSame('4.0000', number_format((float) $lines[0]['unit_cost'], 4, '.', ''));
        $I->assertNull($lines[1]['unit_cost'], 'the blank box is stored as NULL — not quoted, which is not a quote of 0.00');
        $I->assertNull($lines[1]['subtotal'], 'and it has no line total either');

        // The screen has to SAY so, not merely store it. Asserted inside the cell that carries it,
        // never as a bare page-wide string (#627).
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('TBD', 'td[data-label="Unit cost"]');
        $I->see('CAD 40.00', 'td[data-label="Line total"]');
    }

    /**
     * The three screens the reply now has, and the figures each one puts on the page.
     *
     * A template is the one part of a bundle a unit test cannot reach, and `path()` naming a route
     * that does not exist is a 500 behind a green unit suite. Each assertion here names the cell it
     * expects its figure in — `see('90.00')` on a page that also says 1,090.00 proves nothing.
     */
    public function theQuoteHasADetailAnEditAndAPrintScreenAndEachStatesItsMoney(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);

        $tender = $this->raiseAndTender($I, $seed, [$seed['vendors'][0]], 'Screens tender ' . uniqid());
        $replyId = $tender['replies'][$seed['vendors'][0]];

        $this->priceThrough($I, $replyId, [
            (string) $tender['lines'][0] => '4.25',
            (string) $tender['lines'][1] => '10.00',
        ]);

        $number = (string) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT reply_number FROM rfq_vendor_reply WHERE id = ?', [$replyId]);

        // --- Detail --------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);
        $I->see('RFQ BOLT', 'td[data-label="Item"]');
        $I->see('CAD 42.50', 'td[data-label="Line total"]');   // 10 at 4.25
        $I->see('CAD 92.50', '.order-total-box');              // plus 5 at 10.00
        $I->see('Deliver To');
        $I->see('Dock 4, 18 Wharf Street');

        // --- Edit ----------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form#reply-form');
        $recorded = $I->grabValueFrom('input[name="unit_cost[' . $tender['lines'][0] . ']"]');
        $I->assertSame('4.2500', number_format((float) $recorded, 4, '.', ''), 'the box comes back holding what was recorded');
        // The requirement is read-only here: one question asked of every vendor, so a quantity box
        // on this screen would let a buyer compare quotes for different things.
        $I->dontSeeElement('input[name="lines[0][quantity]"]');
        $I->seeElement('input[name="line_notes[' . $tender['lines'][0] . ']"]');

        // --- Print ---------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId . '/print');
        $I->seeResponseCodeIsSuccessful();
        $I->see('VENDOR QUOTE');
        $I->see($number);
        $I->see('92.50', '.grand-total-row');
        // A quote is not an order and not a payable: the print must not grow the invoice's half.
        // Paired with the positive assertions above, which prove the page rendered at all (#627).
        $I->dontSee('AMOUNT DUE');
        $I->dontSee('Remit to');
    }

    /**
     * Tax on a purchase document comes from the SELL side's calculators — one tax model, not two.
     *
     * BC is the province with a calculator of its own in this repo: 5% GST plus 7% PST on class 'S'
     * goods. Nothing buy-side computes either figure; `PurchaseDocumentTax` only answers "which
     * province" and hands the lines to `OrderTaxBreakdownService`, which is the same service the
     * order screens, the invoice PDFs and checkout already go through.
     */
    public function taxOnAVendorQuoteIsComputedByTheSharedSalesTaxCalculators(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I, 'S');
        $em = $I->grabService('doctrine.orm.entity_manager');

        // Where THIS company is — the buy-side mirror of a sales document's shipping province. The
        // row is written inside the test transaction and rolled back with it; _after() drops the
        // settings cache so the value cannot outlive the rollback into another test.
        foreach (['company_state' => 'BC', 'company_country' => 'CA'] as $key => $value) {
            $em->persist((new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value));
        }
        $em->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $tender = $this->raiseAndTender($I, $seed, [$seed['vendors'][0]], 'Taxed tender ' . uniqid());
        $replyId = $tender['replies'][$seed['vendors'][0]];

        $this->priceThrough($I, $replyId, [
            (string) $tender['lines'][0] => '4.00',
            (string) $tender['lines'][1] => '10.00',
        ]);

        $row = $this->replyRow($I, $replyId);
        $I->assertSame('90.00', $row['subtotal'], '10 at 4.00 plus 5 at 10.00');
        $I->assertSame('10.80', $row['tax'], '5% GST and 7% PST on 90.00, from the shared BC calculator');
        $I->assertSame('100.80', $row['total'], 'and the document total carries that tax');

        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('CAD 10.80', '.order-total-box');
        $I->see('GST', '.order-total-box');
        $I->see('PST', '.order-total-box');
    }

    /**
     * A quote that has been declined cannot be priced, and the attempt writes nothing.
     *
     * The refusal is the point, but the assertion is the row: a controller that flashed an error and
     * saved anyway would pass every check but this one.
     */
    public function pricingIsRefusedOnceAQuoteIsDeclinedAndTheStoredFiguresDoNotMove(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);

        $tender = $this->raiseAndTender($I, $seed, [$seed['vendors'][0], $seed['vendors'][1]], 'Declined tender ' . uniqid());
        $declinedId = $tender['replies'][$seed['vendors'][1]];
        $liveId = $tender['replies'][$seed['vendors'][0]];

        // Price it first, so there is something real to try to overwrite afterwards.
        $this->priceThrough($I, $declinedId, [
            (string) $tender['lines'][0] => '4.00',
            (string) $tender['lines'][1] => '10.00',
        ]);
        $priced = $this->replyRow($I, $declinedId);
        $I->assertSame('90.00', $priced['total'], 'the quote stands at 90.00 before it is declined');

        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $declinedId);
        $I->seeResponseCodeIsSuccessful();
        $declineToken = $I->grabAttributeFrom('form[action$="/decline"] input[name="_token"]', 'value');
        $I->sendFormPostRequest(
            '/admin/bundles/procurement/rfqs/' . $tender['rfq'] . '/reply/' . $declinedId . '/decline',
            ['_token' => $declineToken, 'reason' => 'Cannot supply before March.'],
        );
        $I->seeResponseCodeIsSuccessful();

        $afterDecline = $this->replyRow($I, $declinedId);
        $I->assertSame('Declined', $afterDecline['status']);

        // The edit screen refuses to open, and says so rather than 404ing a document that exists.
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $declinedId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('form#reply-form');
        $I->see('can no longer be priced');

        // And the POST behind it refuses too — a form the screen declines to render is not a guard,
        // because the route is still there to post to.
        //
        // The token comes off another live page, which is a REAL one: this app mints one token per
        // session (App\Security\Csrf\Csrf — same approach as Rails and Django), so that is exactly
        // what a browser would send. A forged token would be rejected by CsrfProtectionSubscriber
        // before the controller ran, and the test would then be asserting the framework rather than
        // the guard — the trap two security tests in this suite fell into and #594 had to fix.
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $liveId . '/edit');
        $validToken = $I->grabAttributeFrom('form#reply-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfq-replies/' . $declinedId . '/save', [
            '_token' => $validToken,
            'document_date' => '2026-01-01',
            'currency' => 'USD',
            'unit_cost' => [(string) $tender['lines'][0] => '1.00', (string) $tender['lines'][1] => '1.00'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame($afterDecline, $this->replyRow($I, $declinedId), 'not one column moved: not the status, not the date, not the currency, not the money');

        // And the reply that was NOT the target is where it was: still invited, still unpriced.
        $I->assertSame('Invited', $this->replyRow($I, $liveId)['status']);
        $I->assertSame('0.00', $this->replyRow($I, $liveId)['total']);
    }

    /**
     * The requirement form: lines, warehouse and the delivery address, asserted in the columns they
     * land in — and a second RFQ, created first and never posted again, asserted to be untouched.
     */
    public function theRequirementFormSavesItsLinesWarehouseAndAddressAndLeavesTheOtherRfqAlone(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $conn = $em->getConnection();

        // The bystander, raised through the same form so it is a real row and not a hand-built one.
        $bystanderMarker = 'Bystander requirement ' . uniqid();
        $bystander = $this->raiseAndTender($I, $seed, [$seed['vendors'][2]], $bystanderMarker);
        $bystanderBefore = $conn->fetchAssociative('SELECT status, warehouse_id, delivery_address, notes FROM rfq WHERE id = ?', [$bystander['rfq']]);
        $bystanderLinesBefore = $conn->fetchAllAssociative('SELECT name, sku, quantity, sort_order FROM rfq_line WHERE rfq_id = ? ORDER BY sort_order', [$bystander['rfq']]);

        // --- The subject ----------------------------------------------------------------------
        $marker = 'Subject requirement ' . uniqid();
        $I->amOnPage('/admin/bundles/procurement/rfqs/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form#rfq-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/save', [
            '_token' => $token,
            'id' => '0',
            'warehouse_id' => (string) $seed['warehouse'],
            'delivery_address' => "Gate 9, 300 Industrial Way\nNanaimo BC V9S 1A1",
            'notes' => $marker,
            'lines' => [
                0 => ['product_id' => (string) $seed['products'][0], 'name' => 'Hex bolt M8', 'sku' => 'HEX-M8', 'quantity' => '250.5', 'notes' => 'Galvanised'],
                1 => ['product_id' => '0', 'name' => 'Pallet wrap', 'sku' => '', 'quantity' => '12', 'notes' => ''],
                // Blank rows are not lines. The form renders three spares on every load, and they
                // must cost nothing when nobody fills them in.
                2 => ['product_id' => '0', 'name' => '', 'sku' => '', 'quantity' => '', 'notes' => ''],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        /** @var Rfq $rfq */
        $rfq = $em->getRepository(Rfq::class)->findOneBy(['notes' => $marker]);
        $I->assertNotNull($rfq, 'the form created the RFQ');
        $rfqId = (int) $rfq->getId();

        $row = $conn->fetchAssociative('SELECT status, warehouse_id, delivery_address FROM rfq WHERE id = ?', [$rfqId]);
        $I->assertSame('Draft', (string) $row['status']);
        $I->assertSame($seed['warehouse'], (int) $row['warehouse_id'], 'the destination warehouse is stored on the requirement');
        $I->assertSame("Gate 9, 300 Industrial Way\nNanaimo BC V9S 1A1", (string) $row['delivery_address'], 'and so is the address every vendor quotes freight to');

        $lines = $conn->fetchAllAssociative('SELECT name, sku, quantity, notes, product_id, sort_order FROM rfq_line WHERE rfq_id = ? ORDER BY sort_order', [$rfqId]);
        $I->assertCount(2, $lines, 'two filled rows became lines; the blank spare did not');
        $I->assertSame('Hex bolt M8', (string) $lines[0]['name']);
        $I->assertSame('HEX-M8', (string) $lines[0]['sku']);
        $I->assertSame('250.5000', number_format((float) $lines[0]['quantity'], 4, '.', ''), 'a fractional quantity survives at the column\'s four decimals');
        $I->assertSame('Galvanised', (string) $lines[0]['notes']);
        $I->assertSame($seed['products'][0], (int) $lines[0]['product_id'], 'the product picker\'s choice is stored as a real link');
        $I->assertSame('Pallet wrap', (string) $lines[1]['name']);
        $I->assertNull($lines[1]['product_id'], 'a line naming nothing in the catalog keeps its text and no link');

        // --- The row that should NOT have changed ------------------------------------------------
        $I->assertSame(
            $bystanderBefore,
            $conn->fetchAssociative('SELECT status, warehouse_id, delivery_address, notes FROM rfq WHERE id = ?', [$bystander['rfq']]),
            'saving one requirement did not touch another',
        );
        $I->assertSame(
            $bystanderLinesBefore,
            $conn->fetchAllAssociative('SELECT name, sku, quantity, sort_order FROM rfq_line WHERE rfq_id = ? ORDER BY sort_order', [$bystander['rfq']]),
            'and left its lines exactly where they were',
        );
    }

    /**
     * Removing a requirement line without JavaScript: a real submit carrying the row index.
     *
     * The app works with scripting off (`public/assets/js/app.js`), and this bundle ships no line
     * script at all — so a line table you can only add to is a line table nobody can correct.
     */
    public function removingARequirementLineDropsThatLineAndKeepsTheRest(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $conn = $em->getConnection();

        $marker = 'Removal requirement ' . uniqid();
        $I->amOnPage('/admin/bundles/procurement/rfqs/new');
        $token = $I->grabAttributeFrom('form#rfq-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/save', [
            '_token' => $token,
            'id' => '0',
            'warehouse_id' => (string) $seed['warehouse'],
            'notes' => $marker,
            'lines' => [
                0 => ['product_id' => '0', 'name' => 'First line', 'sku' => 'F-1', 'quantity' => '1'],
                1 => ['product_id' => '0', 'name' => 'Second line', 'sku' => 'S-2', 'quantity' => '2'],
                2 => ['product_id' => '0', 'name' => 'Third line', 'sku' => 'T-3', 'quantity' => '3'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        /** @var Rfq $rfq */
        $rfq = $em->getRepository(Rfq::class)->findOneBy(['notes' => $marker]);
        $rfqId = (int) $rfq->getId();
        $I->assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM rfq_line WHERE rfq_id = ?', [$rfqId]));

        // Press Remove on the middle row, posting the whole form exactly as a browser would.
        $I->amOnPage('/admin/bundles/procurement/rfqs/' . $rfqId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $editToken = $I->grabAttributeFrom('form#rfq-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/save', [
            '_token' => $editToken,
            'id' => (string) $rfqId,
            'warehouse_id' => (string) $seed['warehouse'],
            'notes' => $marker,
            'remove_line' => '1',
            'lines' => [
                0 => ['product_id' => '0', 'name' => 'First line', 'sku' => 'F-1', 'quantity' => '1'],
                1 => ['product_id' => '0', 'name' => 'Second line', 'sku' => 'S-2', 'quantity' => '2'],
                2 => ['product_id' => '0', 'name' => 'Third line', 'sku' => 'T-3', 'quantity' => '3'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $names = $conn->fetchFirstColumn('SELECT name FROM rfq_line WHERE rfq_id = ? ORDER BY sort_order', [$rfqId]);
        $I->assertSame(['First line', 'Third line'], array_map('strval', $names), 'the row that was removed is gone and its neighbours are not');
        $I->assertSame(
            ['1.0000', '3.0000'],
            array_map(
                static fn ($q): string => number_format((float) $q, 4, '.', ''),
                $conn->fetchFirstColumn('SELECT quantity FROM rfq_line WHERE rfq_id = ? ORDER BY sort_order', [$rfqId]),
            ),
            'and the survivors kept their own quantities rather than shifting into each other',
        );
    }

    /**
     * The RFQ states no money, asserted against the schema rather than against a screenshot.
     *
     * An RFQ names a requirement put to SEVERAL vendors, so it has no single counterparty to owe
     * anything to and nothing to denominate a price in — the argued exception in
     * `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`. A page with no total on it today
     * proves nothing about the next person who adds a column; the table does.
     *
     * The second half is the positive control that keeps the first half meaningful: the money is not
     * missing from the model, it is on the REPLY, one per vendor.
     */
    public function theRfqTableItselfHoldsNoMoneyAndTheReplyTableHoldsItAll(FunctionalTester $I): void
    {
        $conn = $I->grabService('doctrine.orm.entity_manager')->getConnection();

        $rfqColumns = array_map('strval', $conn->fetchFirstColumn("SELECT name FROM pragma_table_info('rfq')"));
        $I->assertNotEmpty($rfqColumns, 'the rfq table exists and this sweep is looking at something');

        foreach (['subtotal', 'tax', 'total', 'currency', 'vendor_id', 'vendor_name', 'unit_cost', 'price', 'amount'] as $money) {
            $I->assertNotContains(
                $money,
                $rfqColumns,
                sprintf(
                    'rfq.%s exists. An RFQ has no counterparty and therefore no money: putting a figure here means'
                    . ' the requirement and one vendor\'s priced reply have been merged back into one document.',
                    $money,
                ),
            );
        }

        $replyColumns = array_map('strval', $conn->fetchFirstColumn("SELECT name FROM pragma_table_info('rfq_vendor_reply')"));
        foreach (['subtotal', 'tax', 'total', 'currency', 'vendor_id', 'vendor_name', 'document_date'] as $money) {
            $I->assertContains($money, $replyColumns, sprintf('rfq_vendor_reply.%s is where this flow keeps its money', $money));
        }

        $lineColumns = array_map('strval', $conn->fetchFirstColumn("SELECT name FROM pragma_table_info('rfq_line')"));
        foreach (['unit_cost', 'price', 'subtotal', 'total'] as $money) {
            $I->assertNotContains($money, $lineColumns, sprintf('rfq_line.%s exists — a requirement line is what is needed, not what it costs', $money));
        }
        $I->assertContains('unit_cost', array_map('strval', $conn->fetchFirstColumn("SELECT name FROM pragma_table_info('rfq_vendor_reply_line')")));
    }
}
