<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A vendor bill payment can be MOVED to another bill, keeping its identity — conducted per #624.
 *
 * ## What was wrong
 *
 * A payment could only be deleted and re-keyed. That loses the date the money left, the method, the
 * reference and the admin who recorded it unless somebody copies all four by hand first — and a
 * cheque number retyped from memory is how a payment goes missing in a reconciliation six weeks
 * later. Queue item 34.
 *
 * ## Two ids, not one, since #708
 *
 * Before #708 one row, `vendor_bill_payment`, was both the money and the one bill it settled, so
 * "the payment" and "the row" were the same id. #708 split that row into a POOL
 * (`vendor_bill_payment` — the money, the date, the method, the reference, no longer pointed at any
 * bill) and a CLAIM against one bill (`vendor_bill_payment_application` — an amount and a bill,
 * pointed at the pool). A move withdraws the losing claim and creates a brand new one on the gaining
 * bill, so the claim's own id is NOT what survives a move — the pool's is. This file checks both ids
 * separately for exactly that reason: the route and the dropdown work in APPLICATION ids (what a
 * claim is addressed by), and "kept its id" in the flash message and the assertions below is a claim
 * about the POOL underneath.
 *
 * ## What every test here proves, and why each half matters
 *
 * The four assertions the feature lives or dies on, and none of them is provable from a flash
 * message or an HTTP 200:
 *
 *  1. the bill LOSING the payment changed status — it may cease to be Paid;
 *  2. the bill GAINING it changed status — it may become Paid;
 *  3. the underlying pool kept its **id**, and its date, method, reference and recorded-by with it.
 *     This is the one a delete-and-recreate implementation would fail while passing everything else;
 *  4. a third, unrelated bill did not move at all. The cheap half, and the one that catches a move
 *     that recalculated every bill in the vendor's ledger or re-pointed more than one row.
 *
 * Every figure is re-read out of `vendor_bill_payment`, `vendor_bill_payment_application` and
 * `vendor_bill` BY COLUMN after the POST, never from an entity fetched beforehand, and every refusal
 * test asserts the database is untouched rather than that an error appeared.
 */
final class VendorBillPaymentMoveCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('billmove-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor of this test's own making. Returns its ID and never the object.
     *
     * The ID and not the entity, deliberately: `amOnPage()` drives a request that can reboot the
     * kernel, and an entity grabbed before one is DETACHED from the entity manager afterwards — a
     * setter plus flush() on it writes nothing at all and the test then asserts against a fixture
     * that was never saved. That is how the currency case in this file first failed.
     */
    private function makeVendor(FunctionalTester $I, string $name, string $currency = 'CAD'): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName($name . ' ' . strtoupper(substr(uniqid(), -6)))->setCurrency($currency);
        $em->persist($vendor);
        $em->flush();

        return (int) $vendor->getId();
    }

    /** Re-found through the CURRENT entity manager — see makeVendor() for why that matters. */
    private function setVendorCurrency(FunctionalTester $I, int $vendorId, string $currency): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $vendor, 'the vendor under test vanished');
        $vendor->setCurrency($currency);
        $em->flush();

        $I->assertSame($currency, (string) $em->getConnection()->fetchOne('SELECT currency FROM vendor WHERE id = ?', [$vendorId]), 'vendor.currency was not written');
    }

    /**
     * A bill for $lineQty x $100, raised and approved through the real screens.
     *
     * Approved deliberately: `VendorBillStatusDeriver` leaves a DRAFT alone, so a test watching Open
     * become Paid would watch nothing at all on a draft.
     */
    private function raiseApprovedBill(FunctionalTester $I, int $vendorId, string $lineQty, string $marker): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $I->amOnPage('/admin/bundles/procurement/bills/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#bill-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => '0',
            'vendor_id' => (string) $vendorId,
            'vendor_invoice_no' => $marker,
            'document_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'lines' => [
                0 => ['name' => 'Pallets ' . $marker, 'qty' => $lineQty, 'unit_cost' => '100.0000'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $billId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM vendor_bill WHERE vendor_invoice_no = ?',
            [$marker],
        );
        $I->assertGreaterThan(0, $billId, 'the bill ' . $marker . ' was not raised');

        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->seeResponseCodeIsSuccessful();
        $approveToken = (string) $I->grabAttributeFrom('form[action$="/approve"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', ['_token' => $approveToken]);
        $I->seeResponseCodeIsSuccessful();

        return $billId;
    }

    private function recordPayment(FunctionalTester $I, int $billId, string $amount, string $method, string $comment, string $paidAt): void
    {
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/payments', [
            '_token' => $token,
            'payment_id' => '0',
            'paid_at' => $paidAt,
            'method' => $method,
            'amount' => $amount,
            'comment' => $comment,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** The pool row itself — the money, the date, the method, the reference, who recorded it. */
    private function poolRow(FunctionalTester $I, int $poolId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        /** @var array<string, mixed>|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, user_id, paid_at, method, amount, comment FROM vendor_bill_payment WHERE id = ?',
            [$poolId],
        );

        return $row === false ? [] : $row;
    }

    /** One claim — which bill it is against, which pool it draws from, its own amount and date. */
    private function applicationRow(FunctionalTester $I, int $applicationId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        /** @var array<string, mixed>|false $row */
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, vendor_bill_id, vendor_bill_payment_id, amount, applied_at FROM vendor_bill_payment_application WHERE id = ?',
            [$applicationId],
        );

        return $row === false ? [] : $row;
    }

    /** @return list<int> the claim ids sitting on $billId, in id order */
    private function applicationIdsOn(FunctionalTester $I, int $billId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return array_map('intval', $em->getConnection()->fetchFirstColumn(
            'SELECT id FROM vendor_bill_payment_application WHERE vendor_bill_id = ? ORDER BY id ASC',
            [$billId],
        ));
    }

    private function billStatus(FunctionalTester $I, int $billId): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (string) $em->getConnection()->fetchOne('SELECT status FROM vendor_bill WHERE id = ?', [$billId]);
    }

    private function billNumber(FunctionalTester $I, int $billId): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (string) $em->getConnection()->fetchOne('SELECT bill_number FROM vendor_bill WHERE id = ?', [$billId]);
    }

    /**
     * POST the move form, with the CSRF token scraped off the payments screen it is served from.
     * $applicationId is the claim's own id — what the route and the dropdown both address a payment by.
     */
    private function postMove(FunctionalTester $I, int $billId, int $applicationId, string $targetId, string $reason = ''): void
    {
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest(
            '/admin/bundles/procurement/bills/' . $billId . '/payments/move/' . $applicationId,
            ['_token' => $token, 'target_id' => $targetId, 'reason' => $reason],
        );
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * The load-bearing test: the payment moves, both bills' statuses follow the rows, the underlying
     * pool keeps its id and everything else on it, the claim lands as a new row on the gaining bill,
     * and a third bill is untouched.
     */
    public function aPaymentMovesAndBothBillsFollowWhileAThirdDoesNot(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Moving Vendor');
        $tag = strtoupper(substr(uniqid(), -6));

        // Two $900 bills for the same vendor, and a $300 bill that has nothing to do with any of it.
        $losing = $this->raiseApprovedBill($I, $vendor, '9', 'MOVE-FROM-' . $tag);
        $gaining = $this->raiseApprovedBill($I, $vendor, '9', 'MOVE-TO-' . $tag);
        $bystander = $this->raiseApprovedBill($I, $vendor, '3', 'MOVE-BYSTANDER-' . $tag);

        $this->recordPayment($I, $losing, '900.00', 'Cheque', 'Cheque 8801', '2026-09-15');
        $this->recordPayment($I, $bystander, '300.00', 'Cash', 'Petty cash', '2026-09-17');

        $before = $this->applicationIdsOn($I, $losing);
        $I->assertCount(1, $before, 'the payment under test was not recorded');
        $applicationId = $before[0];
        $bystanderApplications = $this->applicationIdsOn($I, $bystander);
        $I->assertCount(1, $bystanderApplications, 'the bystander payment was not recorded');
        $bystanderApplicationId = $bystanderApplications[0];

        // The starting statuses, so that "changed" below means changed and not merely "is".
        $I->assertSame('Paid', $this->billStatus($I, $losing), 'vendor_bill.status of the bill about to lose the payment');
        $I->assertSame('Open', $this->billStatus($I, $gaining), 'vendor_bill.status of the bill about to gain it');
        $I->assertSame('Paid', $this->billStatus($I, $bystander), 'vendor_bill.status of the bystander before the move');

        $originalApplication = $this->applicationRow($I, $applicationId);
        $I->assertSame($losing, (int) $originalApplication['vendor_bill_id'], 'vendor_bill_payment_application.vendor_bill_id before the move');
        $poolId = (int) $originalApplication['vendor_bill_payment_id'];
        $originalPool = $this->poolRow($I, $poolId);
        $I->assertNotNull($originalPool['user_id'], 'the payment was recorded with no admin against it, so the move cannot prove it kept one');

        // The dropdown offers the legal target and nothing else about it is guessed at: the
        // bystander is a legal target too (same vendor, same currency), so both appear. This is the
        // positive control for the absence assertions in the refusal tests below (#627).
        $I->amOnPage('/admin/bundles/procurement/bills/' . $losing . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="target_id"] option[value="' . $gaining . '"]');
        $I->seeElement('select[name="target_id"] option[value="' . $bystander . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $losing . '"]');

        $this->postMove($I, $losing, $applicationId, (string) $gaining, 'Recorded against the wrong bill');

        // 3. The underlying pool kept its identity — same row, same date, method, reference and
        // actor, re-read from the table by the id that never changed.
        $afterPool = $this->poolRow($I, $poolId);
        $I->assertNotSame([], $afterPool, 'the underlying payment was deleted by the move — a move is not a delete and recreate');
        $I->assertSame($originalPool['paid_at'], $afterPool['paid_at'], 'vendor_bill_payment.paid_at was not preserved');
        $I->assertSame('Cheque', $afterPool['method'], 'vendor_bill_payment.method was not preserved');
        $I->assertSame('Cheque 8801', $afterPool['comment'], 'vendor_bill_payment.comment (the reference) was not preserved');
        $I->assertSame($originalPool['user_id'], $afterPool['user_id'], 'vendor_bill_payment.user_id — who recorded it — was not preserved');
        $I->assertSame(900.0, (float) $afterPool['amount'], 'vendor_bill_payment.amount was not preserved');

        // The claim itself is a NEW row on the gaining bill, pointed at that same pool.
        $I->assertSame([], $this->applicationIdsOn($I, $losing), 'the losing bill still holds a claim');
        $gainingApplications = $this->applicationIdsOn($I, $gaining);
        $I->assertCount(1, $gainingApplications, 'the gaining bill does not hold exactly one claim');
        $newApplicationId = $gainingApplications[0];
        $newApplication = $this->applicationRow($I, $newApplicationId);
        $I->assertSame($poolId, (int) $newApplication['vendor_bill_payment_id'], 'the moved claim draws from a different underlying payment');
        $I->assertSame($gaining, (int) $newApplication['vendor_bill_id'], 'vendor_bill_payment_application.vendor_bill_id after the move');
        $I->assertSame($originalApplication['applied_at'], $newApplication['applied_at'], 'vendor_bill_payment_application.applied_at was not preserved');
        $I->assertSame(900.0, (float) $newApplication['amount'], 'vendor_bill_payment_application.amount was not preserved');

        // 1 and 2. Both derived statuses moved, in opposite directions.
        $I->assertSame('Open', $this->billStatus($I, $losing), 'vendor_bill.status of the losing bill did not come back down from Paid');
        $I->assertSame('Paid', $this->billStatus($I, $gaining), 'vendor_bill.status of the gaining bill did not become Paid');

        // 4. The bystander did not move at all — not its status, not its payment, not its row id.
        $I->assertSame('Paid', $this->billStatus($I, $bystander), 'the bystander bill changed status');
        $I->assertSame([$bystanderApplicationId], $this->applicationIdsOn($I, $bystander), 'the bystander bill lost or gained a claim');
        $bystanderRow = $this->applicationRow($I, $bystanderApplicationId);
        $I->assertSame(300.0, (float) $bystanderRow['amount'], 'the bystander payment amount changed');

        // The person is told, in money with its currency, what happened on both documents.
        $I->see('moved from bill ' . $this->billNumber($I, $losing) . ' to bill ' . $this->billNumber($I, $gaining));
        $I->see('It kept its date, its reference and its id.');
        $I->see('balance of CAD 900.00');
        $I->see('balance of CAD 0.00');

        // And both timelines say so, rather than one balance changing unexplained.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $losing);
        $I->seeResponseCodeIsSuccessful();
        $I->see('moved to bill ' . $this->billNumber($I, $gaining));
        $I->amOnPage('/admin/bundles/procurement/bills/' . $gaining);
        $I->seeResponseCodeIsSuccessful();
        $I->see('moved in from bill ' . $this->billNumber($I, $losing));
    }

    /**
     * Moving a payment onto a bill that is already covered OVERPAYS it, which is allowed and said
     * out loud rather than refused.
     *
     * Allowed because RECORDING an overpayment already is — `getBalance()` calls a negative balance
     * "information" in as many words — and a move that refused what a record accepts would leave
     * delete-and-rekey as the only route to the same state, losing everything queue item 34 exists
     * to preserve.
     */
    public function aMoveThatOverpaysTheTargetIsAllowedAndSaysSo(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Overpay Vendor');
        $tag = strtoupper(substr(uniqid(), -6));

        $losing = $this->raiseApprovedBill($I, $vendor, '9', 'OVER-FROM-' . $tag);   // $900
        $gaining = $this->raiseApprovedBill($I, $vendor, '1', 'OVER-TO-' . $tag);    // $100

        $this->recordPayment($I, $losing, '900.00', 'Bank Transfer', 'EFT 22', '2026-09-18');
        $applicationId = $this->applicationIdsOn($I, $losing)[0];

        $this->postMove($I, $losing, $applicationId, (string) $gaining);

        $newApplicationId = $this->applicationIdsOn($I, $gaining)[0];
        $I->assertSame($gaining, (int) $this->applicationRow($I, $newApplicationId)['vendor_bill_id'], 'vendor_bill_payment_application.vendor_bill_id after an overpaying move');
        $I->assertSame('Open', $this->billStatus($I, $losing), 'vendor_bill.status of the losing bill');
        $I->assertSame('Paid', $this->billStatus($I, $gaining), 'vendor_bill.status of the overpaid bill');

        $I->see('is now OVERPAID by CAD 800.00');
        $I->see('CAD 900.00 applied against a total of CAD 100.00');
    }

    /**
     * Every illegal move is refused with the reason, and NOTHING is written.
     *
     * One test rather than five, because the assertion after each refusal is the same one and it is
     * the important half: the payment is still exactly where it was, and neither bill's status
     * moved. A refusal that printed the right sentence and moved the row anyway would pass five
     * tests that only read the flash.
     */
    public function anIllegalMoveIsRefusedLoudlyAndChangesNothing(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = strtoupper(substr(uniqid(), -6));

        $vendor = $this->makeVendor($I, 'Refusing Vendor');
        $stranger = $this->makeVendor($I, 'Other Vendor');

        $source = $this->raiseApprovedBill($I, $vendor, '9', 'REF-FROM-' . $tag);
        $legal = $this->raiseApprovedBill($I, $vendor, '9', 'REF-LEGAL-' . $tag);
        $voided = $this->raiseApprovedBill($I, $vendor, '9', 'REF-VOID-' . $tag);
        $strangers = $this->raiseApprovedBill($I, $stranger, '9', 'REF-STRANGER-' . $tag);

        // A void bill: withdrawn, owed nothing. Voided through the real screen, and it can be
        // because it holds no payments.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $voided);
        $I->seeResponseCodeIsSuccessful();
        $voidToken = (string) $I->grabAttributeFrom('form[action$="/void"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $voided . '/void', ['_token' => $voidToken, 'reason' => 'Duplicate']);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Void', $this->billStatus($I, $voided), 'the bill under test was not voided');

        // A same-vendor bill in a DIFFERENT currency. The bill copies the vendor's currency at save,
        // so the vendor is moved to USD between the two saves — which is the only way one vendor
        // legitimately ends up with bills in two currencies, and exactly the case the counterparty
        // rule does NOT catch.
        $this->setVendorCurrency($I, $vendor, 'USD');
        $foreign = $this->raiseApprovedBill($I, $vendor, '9', 'REF-USD-' . $tag);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $I->assertSame('USD', (string) $em->getConnection()->fetchOne('SELECT currency FROM vendor_bill WHERE id = ?', [$foreign]), 'the foreign-currency bill is not in USD');
        $this->setVendorCurrency($I, $vendor, 'CAD');

        $this->recordPayment($I, $source, '900.00', 'Cheque', 'Cheque 9001', '2026-09-19');
        $applicationId = $this->applicationIdsOn($I, $source)[0];
        $poolId = (int) $this->applicationRow($I, $applicationId)['vendor_bill_payment_id'];
        $I->assertSame('Paid', $this->billStatus($I, $source), 'vendor_bill.status of the source bill before any refusal');

        // The dropdown does not offer any of the four illegal targets — and the positive control
        // sits right beside it, because "no options at all" would satisfy every dontSee below (#627).
        $I->amOnPage('/admin/bundles/procurement/bills/' . $source . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="target_id"] option[value="' . $legal . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $voided . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $strangers . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $foreign . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $source . '"]');

        $refusals = [
            'onto itself' => [(string) $source, 'That payment is already on ' . $this->billNumber($I, $source)],
            'onto a void bill' => [(string) $voided, 'is Void; it is owed nothing and cannot take a payment'],
            'onto another vendor' => [(string) $strangers, "would leave both parties' balances wrong"],
            'across currencies' => [(string) $foreign, 'this application does not convert between currencies'],
            'with nothing chosen' => ['0', 'Choose the bill to move this payment to.'],
        ];

        foreach ($refusals as $what => [$target, $expected]) {
            $this->postMove($I, $source, $applicationId, $target);
            $I->see($expected);

            // The database, after each one. This is the assertion that matters.
            $row = $this->applicationRow($I, $applicationId);
            $I->assertSame($source, (int) $row['vendor_bill_id'], 'the payment MOVED despite the refusal: ' . $what);
            $I->assertSame(900.0, (float) $row['amount'], 'the payment amount changed on a refused move: ' . $what);
            $pool = $this->poolRow($I, $poolId);
            $I->assertSame('Cheque 9001', $pool['comment'], 'the payment comment changed on a refused move: ' . $what);
            $I->assertSame('Paid', $this->billStatus($I, $source), 'the source bill status changed on a refused move: ' . $what);
            $I->assertSame([$applicationId], $this->applicationIdsOn($I, $source), 'the source bill claims changed on a refused move: ' . $what);
        }

        // None of the four refused targets gained anything, and the legal one did not either —
        // nothing was moved at all in this test.
        foreach ([$legal, $voided, $strangers, $foreign] as $untouched) {
            $I->assertSame([], $this->applicationIdsOn($I, $untouched), 'a refused target gained a claim');
        }

        // The positive control for all of the above: the SAME POST, to the legal target, works. A
        // suite in which every move is refused would otherwise pass this file entirely.
        $this->postMove($I, $source, $applicationId, (string) $legal);
        $legalApplicationId = $this->applicationIdsOn($I, $legal)[0];
        $I->assertSame($legal, (int) $this->applicationRow($I, $legalApplicationId)['vendor_bill_id'], 'the legal move was refused too, so the refusals above prove nothing');
        $I->assertSame('Open', $this->billStatus($I, $source), 'vendor_bill.status of the source after the legal move');
        $I->assertSame('Paid', $this->billStatus($I, $legal), 'vendor_bill.status of the legal target after the move');
    }

    /**
     * A forged claim id in the URL cannot move somebody else's row.
     *
     * The route takes a bill and a claim id independently, so a hand-made POST can name a claim
     * belonging to a different bill. `moveApplication()` asserts the claim is on THIS bill before
     * reading anything else — the same protection `deletePayment()` gets from `withdrawApplication()`.
     */
    public function aPaymentOnAnotherBillCannotBeMovedThroughThisBillsRoute(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Forging Vendor');
        $tag = strtoupper(substr(uniqid(), -6));

        $mine = $this->raiseApprovedBill($I, $vendor, '9', 'FORGE-MINE-' . $tag);
        $theirs = $this->raiseApprovedBill($I, $vendor, '9', 'FORGE-THEIRS-' . $tag);
        $target = $this->raiseApprovedBill($I, $vendor, '9', 'FORGE-TARGET-' . $tag);

        $this->recordPayment($I, $theirs, '450.00', 'E-Transfer', 'Not yours', '2026-09-20');
        $foreignApplicationId = $this->applicationIdsOn($I, $theirs)[0];

        // Posted through MINE's route, naming THEIRS' claim.
        $this->postMove($I, $mine, $foreignApplicationId, (string) $target);
        $I->see('That payment is not on bill ' . $this->billNumber($I, $mine));

        $I->assertSame($theirs, (int) $this->applicationRow($I, $foreignApplicationId)['vendor_bill_id'], 'a forged claim id moved another bill\'s row');
        $I->assertSame([$foreignApplicationId], $this->applicationIdsOn($I, $theirs), 'the other bill lost its claim to a forged move');
        $I->assertSame([], $this->applicationIdsOn($I, $target), 'the target gained a claim it was never legally sent');
        $I->assertSame('Partially Paid', $this->billStatus($I, $theirs), 'the other bill status changed on a forged move');
        $I->assertSame('Open', $this->billStatus($I, $target), 'the target bill status changed on a forged move');
    }
}
