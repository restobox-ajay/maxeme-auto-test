<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * AP aging: what we owe each vendor, by how overdue it is — conducted per #624.
 *
 * ## Why the assertions look the way they do
 *
 * An aging report is nothing but numbers, so #627 bites harder here than anywhere: `see('30')`
 * matches a bucket heading, a dollar figure and a date on the same page, and `see('250.00')` cannot
 * say WHICH vendor's row or WHICH bucket it landed in — which is the entire question. So every
 * figure is asserted inside a cell selected by its row's vendor and its column's label:
 *
 *     tr[data-vendor="12"] td[data-label="1 to 30 days"]
 *
 * and the cells that should be EMPTY are asserted to read a zero rather than asserted absent. A
 * dontSee() on a figure that appears in another row would pass while the number sat in the wrong
 * cell.
 *
 * The report is read as at a FIXED date through `?filters[asOf]=`, so the buckets do not drift as
 * the calendar moves and the test still means the same thing next year.
 */
final class ApAgingCest
{
    /** Everything is aged against this date rather than against today. */
    private const AS_OF = '2026-09-11';

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('aging-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function seedVendor(FunctionalTester $I, string $name): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName($name . ' ' . strtoupper(substr(uniqid(), -6)))->setCurrency('CAD');
        $em->persist($vendor);
        $em->flush();

        return (int) $vendor->getId();
    }

    /**
     * One bill, raised through the real screen and approved unless asked otherwise.
     *
     * Approved because a DRAFT is not a liability and the report excludes it — which is itself one
     * of the things asserted below.
     */
    private function bill(FunctionalTester $I, int $vendorId, string $amount, string $documentDate, ?string $dueDate, bool $approve = true): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $reference = 'AGE-' . strtoupper(substr(uniqid(), -8));

        $I->amOnPage('/admin/bundles/procurement/bills/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#bill-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => '0',
            'vendor_id' => (string) $vendorId,
            'vendor_invoice_no' => $reference,
            'document_date' => $documentDate,
            'due_date' => $dueDate ?? '',
            'lines' => [
                0 => ['name' => 'Aged goods', 'qty' => '1', 'unit_cost' => $amount],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $billId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM vendor_bill WHERE vendor_invoice_no = ?',
            [$reference],
        );
        $I->assertGreaterThan(0, $billId, 'the bill was not raised');

        if ($approve) {
            $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
            $approveToken = (string) $I->grabAttributeFrom('form[action$="/approve"] input[name="_token"]', 'value');
            $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', ['_token' => $approveToken]);
            $I->seeResponseCodeIsSuccessful();
        }

        return $billId;
    }

    private function openReport(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/bundles/procurement/ap-aging?filters[asOf]=' . self::AS_OF);
        $I->seeResponseCodeIsSuccessful();
        // The positive control for every absence below: the report rendered, with its own heading.
        $I->see('AP Aging');
    }

    private function cell(int $vendorId, string $bucketLabel): string
    {
        return sprintf('tr[data-vendor="%d"] td[data-label="%s"]', $vendorId, $bucketLabel);
    }

    /**
     * Two vendors, two buckets: each lands in its own cell and neither leaks into the other's row
     * or column.
     */
    public function twoVendorsInDifferentBucketsEachLandInTheirOwnCell(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $slowVendor = $this->seedVendor($I, 'Slow Supply');
        $freshVendor = $this->seedVendor($I, 'Fresh Supply');

        // Ten days overdue as at 2026-09-11.
        $this->bill($I, $freshVendor, '250.00', '2026-08-20', '2026-09-01');
        // A hundred days overdue.
        $this->bill($I, $slowVendor, '400.00', '2026-05-20', '2026-06-03');

        $this->openReport($I);

        // Each figure in its own cell ...
        $I->see('CAD 250.00', $this->cell($freshVendor, '1 to 30 days'));
        $I->see('CAD 400.00', $this->cell($slowVendor, 'Over 90 days'));

        // ... and not in the other's. Asserted as a zero rather than as an absence: a dontSee on
        // '400.00' would pass while the figure sat in the wrong row, because it appears elsewhere
        // on the page legitimately.
        $I->see('CAD 0.00', $this->cell($freshVendor, 'Over 90 days'));
        $I->see('CAD 0.00', $this->cell($slowVendor, '1 to 30 days'));
        $I->see('CAD 0.00', $this->cell($freshVendor, 'Current'));
        $I->see('CAD 0.00', $this->cell($slowVendor, 'Current'));

        // Each vendor's own total is its own row's money and nobody else's.
        $I->see('CAD 250.00', sprintf('tr[data-vendor="%d"] td[data-label="Total owed"]', $freshVendor));
        $I->see('CAD 400.00', sprintf('tr[data-vendor="%d"] td[data-label="Total owed"]', $slowVendor));

        // And the totals row adds the two buckets without mixing them.
        $I->see('CAD 250.00', 'tr[data-vendor="all"] td[data-label="1 to 30 days"]');
        $I->see('CAD 400.00', 'tr[data-vendor="all"] td[data-label="Over 90 days"]');
        $I->see('CAD 650.00', 'tr[data-vendor="all"] td[data-label="Total owed"]');
    }

    /**
     * A bill with NO due date is due immediately and ages from its own date — and a dated bill in
     * another bucket does not move because of it.
     *
     * The owner's ruling, and the conservative reading: assuming money is owed sooner never hides a
     * liability. A forty-five-day-old bill with no terms recorded therefore lands in 31 to 60,
     * which is where a person chasing it would look for it.
     */
    public function aBillWithNoDueDateAgesFromItsOwnDate(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $vendorId = $this->seedVendor($I, 'Termless Supply');

        // No due date, dated forty-five days before the as-at date.
        $this->bill($I, $vendorId, '175.00', '2026-07-28', null);
        // A second bill with a real due date, ten days overdue — the row that must not move.
        $this->bill($I, $vendorId, '250.00', '2026-08-20', '2026-09-01');

        $this->openReport($I);

        $I->see('CAD 175.00', $this->cell($vendorId, '31 to 60 days'));
        $I->see('CAD 250.00', $this->cell($vendorId, '1 to 30 days'));

        // Not folded into Current, which is the reading this ruling rejected ...
        $I->see('CAD 0.00', $this->cell($vendorId, 'Current'));
        // ... and not aged past where its own date puts it.
        $I->see('CAD 0.00', $this->cell($vendorId, 'Over 90 days'));
        $I->see('CAD 425.00', sprintf('tr[data-vendor="%d"] td[data-label="Total owed"]', $vendorId));

        // The assumption is stated where a person reading 'Over 90 days' will see it.
        $I->see('treated as due immediately');
    }

    /**
     * Draft and voided bills are not liabilities and are left out; a settled bill has nothing left
     * to age.
     *
     * The row that should not change is the vendor's own total: it must report only the approved,
     * unpaid bill, whatever else is on file for them.
     */
    public function draftVoidAndSettledBillsAreLeftOut(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $vendorId = $this->seedVendor($I, 'Mixed Supply');

        $live = $this->bill($I, $vendorId, '250.00', '2026-08-20', '2026-09-01');
        // A draft for a conspicuous amount: it has authorised nothing.
        $this->bill($I, $vendorId, '999.00', '2026-08-20', '2026-09-01', approve: false);

        // A voided bill for another conspicuous amount.
        $voided = $this->bill($I, $vendorId, '888.00', '2026-08-20', '2026-09-01');
        $I->amOnPage('/admin/bundles/procurement/bills/' . $voided);
        $voidToken = (string) $I->grabAttributeFrom('form[action$="/void"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $voided . '/void', ['_token' => $voidToken, 'reason' => 'Entered twice.']);
        $I->seeResponseCodeIsSuccessful();

        // And one paid in full, which owes nothing.
        $settled = $this->bill($I, $vendorId, '777.00', '2026-08-20', '2026-09-01');
        $I->amOnPage('/admin/bundles/procurement/bills/' . $settled . '/payments');
        $payToken = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $settled . '/payments', [
            '_token' => $payToken,
            'payment_id' => '0',
            'paid_at' => '2026-09-05',
            'method' => 'Bank Transfer',
            'amount' => '777.00',
            'comment' => 'Settled',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $this->openReport($I);

        // Only the live, unpaid bill is aged.
        $I->see('CAD 250.00', $this->cell($vendorId, '1 to 30 days'));
        $I->see('CAD 250.00', sprintf('tr[data-vendor="%d"] td[data-label="Total owed"]', $vendorId));

        // The three that must not be there: asserted through the vendor's own total, which would be
        // 1,247.00, 1,138.00 or 1,027.00 if any of them had been counted. The page rendered — the
        // assertions above prove it — so these absences mean something.
        $I->dontSee('CAD 999.00');
        $I->dontSee('CAD 888.00');
        $I->dontSee('CAD 777.00');
        $I->assertGreaterThan(0, $live, 'the live bill exists');
    }
}
