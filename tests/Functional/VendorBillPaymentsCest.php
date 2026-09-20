<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A vendor bill's payments are ROWS, the way an invoice's are — conducted per #624.
 *
 * ## What was wrong
 *
 * `vendor_bill.amount_paid` was a single cumulative figure, overwritten by whoever typed last.
 * Three consequences, each of which this file pins:
 *
 *  - you could not see WHICH payments made up the balance;
 *  - you could not correct a mis-keyed one — only overwrite the running total with a number you had
 *    computed by hand;
 *  - two people paying the same bill silently clobbered each other's figure instead of summing.
 *
 * Every assertion reads `vendor_bill_payment` back out of the database, and the delete test asserts
 * the payment that should NOT have changed as well as the one that went: a delete that removed both
 * rows would pass a test that only checked the deleted one.
 */
final class VendorBillPaymentsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('billpay-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor, and a bill for $900 raised through the real screen and then approved.
     *
     * Approved deliberately: the status deriver leaves a DRAFT alone — a draft has authorised
     * nothing — so a test that wanted to watch Open become Partially Paid become Paid would watch
     * nothing at all on a draft.
     *
     * @return array{vendorId: int, billId: int}
     */
    private function seedApprovedBill(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $vendor = (new Vendor())->setName('Paying Vendor ' . $tag)->setCurrency('CAD');
        $em->persist($vendor);
        $em->flush();

        $I->amOnPage('/admin/bundles/procurement/bills/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#bill-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'vendor_invoice_no' => 'PAY-' . $tag,
            'document_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'lines' => [
                0 => ['name' => 'Pallets', 'qty' => '9', 'unit_cost' => '100.0000'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $billId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM vendor_bill WHERE vendor_id = ?',
            [(int) $vendor->getId()],
        );
        $I->assertGreaterThan(0, $billId, 'the bill under test was not raised');

        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->seeResponseCodeIsSuccessful();
        $approveToken = (string) $I->grabAttributeFrom('form[action$="/approve"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', ['_token' => $approveToken]);
        $I->seeResponseCodeIsSuccessful();

        return ['vendorId' => (int) $vendor->getId(), 'billId' => $billId];
    }

    private function recordPayment(FunctionalTester $I, int $billId, string $amount, string $method, string $comment, string $paidAt = '2026-09-15'): void
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

    /**
     * Each claim against the bill, with the method and reference it was via — a join, because #708
     * moved `method`/`comment` onto the pool (`vendor_bill_payment`) and left the claim
     * (`vendor_bill_payment_application`, `a` below) with only the id, the amount, the applied date
     * and which bill it applies to. `a.id` is what the screen's `payment_id` and its delete/edit
     * routes address a payment by.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentRows(FunctionalTester $I, int $billId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return $em->getConnection()->fetchAllAssociative(
            'SELECT a.id, p.method, a.amount, p.comment, a.applied_at AS paid_at FROM vendor_bill_payment_application a'
                . ' JOIN vendor_bill_payment p ON p.id = a.vendor_bill_payment_id'
                . ' WHERE a.vendor_bill_id = ? ORDER BY a.id ASC',
            [$billId],
        );
    }

    private function billStatus(FunctionalTester $I, int $billId): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (string) $em->getConnection()->fetchOne('SELECT status FROM vendor_bill WHERE id = ?', [$billId]);
    }

    /**
     * Two payments are two rows, and deleting one leaves the other exactly as it was.
     *
     * The load-bearing test of this half of the change. Under the old cumulative figure the second
     * payment REPLACED the first, and there was no "other row" for a delete to leave alone.
     */
    public function twoPaymentsAreTwoRowsAndDeletingOneLeavesTheOther(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedApprovedBill($I);
        $billId = $context['billId'];

        $this->recordPayment($I, $billId, '400.00', 'Cheque', 'Cheque 4471', '2026-09-15');
        $this->recordPayment($I, $billId, '200.00', 'E-Transfer', 'Balance instalment', '2026-09-20');

        $rows = $this->paymentRows($I, $billId);
        $I->assertCount(2, $rows, 'two payments were recorded and the second replaced the first');
        $I->assertSame(400.0, (float) $rows[0]['amount'], 'vendor_bill_payment_application.amount of the first payment');
        $I->assertSame(200.0, (float) $rows[1]['amount'], 'vendor_bill_payment_application.amount of the second payment');
        $I->assertSame('Cheque', $rows[0]['method'], 'vendor_bill_payment.method of the first payment');
        $I->assertSame('E-Transfer', $rows[1]['method'], 'vendor_bill_payment.method of the second payment');

        // $600 of $900: the derived figures and the derived status both follow the rows.
        $I->assertSame('Partially Paid', $this->billStatus($I, $billId), 'vendor_bill.status derived from the payment rows');
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        // Never a bare number (#627): the currency prefix and the element both pin it.
        $I->see('CAD 300.00', 'h2');

        // Delete the FIRST payment. The second must be untouched — this is the assertion a delete
        // that removed both rows, or recomputed the total from scratch, would fail.
        $firstId = (int) $rows[0]['id'];
        $secondId = (int) $rows[1]['id'];
        $deleteToken = (string) $I->grabAttributeFrom('form#delete-document-payment-' . $firstId . ' input[name="_token"]', 'value');
        $I->sendFormPostRequest(
            '/admin/bundles/procurement/bills/' . $billId . '/payments/delete/' . $firstId,
            ['_token' => $deleteToken],
        );
        $I->seeResponseCodeIsSuccessful();

        $after = $this->paymentRows($I, $billId);
        $I->assertCount(1, $after, 'exactly one payment should survive the delete');
        $I->assertSame($secondId, (int) $after[0]['id'], 'the wrong payment was deleted');
        $I->assertSame(200.0, (float) $after[0]['amount'], 'the surviving payment amount changed');
        $I->assertSame('E-Transfer', $after[0]['method'], 'the surviving payment method changed');
        $I->assertSame('Balance instalment', $after[0]['comment'], 'the surviving payment comment changed');

        // And the balance went back up by exactly the deleted payment, from the same source the
        // bill screen reads: $900 − $200.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->see('CAD 700.00', 'h2');
        $I->assertSame('Partially Paid', $this->billStatus($I, $billId), 'vendor_bill.status after the delete');
    }

    /**
     * A payment can be CORRECTED rather than overwritten, and the correction moves that row only.
     *
     * With one cumulative figure this was impossible: fixing a mis-keyed $40 meant working out what
     * the total should have been and typing that instead, with nothing recording what it had been.
     */
    public function aMisKeyedPaymentCanBeCorrectedInPlace(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedApprovedBill($I);
        $billId = $context['billId'];

        $this->recordPayment($I, $billId, '400.00', 'Cheque', 'Cheque 4471', '2026-09-15');
        $this->recordPayment($I, $billId, '50.00', 'Cash', 'Typed wrong', '2026-09-16');

        $rows = $this->paymentRows($I, $billId);
        $I->assertCount(2, $rows);
        $wrongId = (int) $rows[1]['id'];
        $untouchedId = (int) $rows[0]['id'];

        // The edit link carries the row into the form — a link, not a script, because correcting a
        // payment has to work with JavaScript off.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments?payment=' . $wrongId);
        $I->seeResponseCodeIsSuccessful();
        // Selected by the `form` ATTRIBUTE, not by descent: the entry row's inputs sit in the table
        // and are bound to the form by `form="document-payment-form"`, which is what lets the row be a
        // table row and a form field at once with no JavaScript. `form#... input` is a descendant
        // selector and matches none of them.
        $I->seeInField('input[form="document-payment-form"][name="amount"]', '50.00');
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/payments', [
            '_token' => $token,
            'payment_id' => (string) $wrongId,
            'paid_at' => '2026-09-16',
            'method' => 'Cash',
            'amount' => '150.00',
            'comment' => 'Corrected from 50.00',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $after = $this->paymentRows($I, $billId);
        $I->assertCount(2, $after, 'the correction created a third row instead of amending one');
        $corrected = $after[1];
        $I->assertSame($wrongId, (int) $corrected['id'], 'the corrected row is the same row');
        $I->assertSame(150.0, (float) $corrected['amount'], 'vendor_bill_payment_application.amount after the correction');

        // The row that should not have changed.
        $I->assertSame($untouchedId, (int) $after[0]['id']);
        $I->assertSame(400.0, (float) $after[0]['amount'], 'the other payment was altered by the correction');
        $I->assertSame('Cheque 4471', $after[0]['comment'], 'the other payment comment was altered by the correction');

        // $550 of $900 paid.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $I->see('CAD 350.00', 'h2');
    }

    /**
     * Paying the whole balance derives Paid, and a payment of nothing is refused.
     *
     * The second half matters because a zero or negative row would sit in the sum forever: money
     * going the other way is a debit memo, which this application has a document for.
     */
    public function theStatusFollowsTheRowsAndAnEmptyPaymentIsRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $context = $this->seedApprovedBill($I);
        $billId = $context['billId'];

        $I->assertSame('Open', $this->billStatus($I, $billId), 'an approved bill with no payments is Open');

        $this->recordPayment($I, $billId, '900.00', 'Bank Transfer', 'Settled in full');
        $I->assertSame('Paid', $this->billStatus($I, $billId), 'vendor_bill.status once the rows cover the total');

        $before = $this->paymentRows($I, $billId);
        $I->assertCount(1, $before);

        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/payments');
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/payments', [
            '_token' => $token,
            'payment_id' => '0',
            'paid_at' => '2026-09-21',
            'method' => 'Cash',
            'amount' => '0.00',
            'comment' => 'Nothing at all',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $after = $this->paymentRows($I, $billId);
        $I->assertCount(1, $after, 'a payment of nothing was recorded');
        $I->assertSame((int) $before[0]['id'], (int) $after[0]['id'], 'the existing payment row changed');
        $I->assertSame(900.0, (float) $after[0]['amount'], 'the existing payment amount changed');
        $I->see('positive amount');
    }
}
