<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPayment;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The rest of queue item 51: the buy-side ids that were still read with `getInt()`.
 *
 * ## What the first pass left behind
 *
 * Item 51's first pass put `?po=`, `?receipt=`, `?vendor=`, `?vendor_return=`, `?bill=` and
 * `?vendor_id=` through `RequestedParent` on the seven create screens that take them. It did not touch the ids
 * that were not the *document's* parent, and there were five more of them on screens a buyer uses
 * every day — every one still a `$request->query->getInt()`, which is to say every one still both
 * failures at once:
 *
 *   - the scan console's `?warehouse=`, `?vendor=`, `?bin=`, `?pending=` and `?pending_q=`. A raw
 *     400 on the one screen in this application somebody drives with a wedge scanner in one hand,
 *     and — on a well-formed id naming nothing — a console silently bound to no warehouse, which is
 *     a whole delivery counted into nowhere;
 *   - `/bills/{id}/payments?payment=`, the link on a payment row that says Correct;
 *   - `/vendors/{id}?address=`, the link on an address row that says Edit.
 *
 * The last two are the ones with money and master data behind them. Both are EDIT links that fall
 * back to a CREATE form, so a stale one — a payment recorded against a different bill, an address
 * copied from another vendor's page — silently rendered the blank "record a payment" / "add an
 * address" form. Somebody who clicked Correct and typed would have added a SECOND payment to the
 * bill rather than fixing the first, and nothing on the screen said so.
 *
 * ## And the false alarm the first pass introduced
 *
 * `0` is not a row id, so `CompanyListScope::isIdShaped()` reads it as junk — but on the buy side it
 * is not junk, it is what the screens' OWN controls submit for "none":
 * `<option value="0">— receive with no purchase order —</option>`, and the console's "Which shelf"
 * form posts `po=0` on every submit when no order is in play. So choosing "receive with no purchase
 * order" and pressing Set answered with "That purchase order doesn't exist. This link asks for
 * purchase order 0" across the top of the screen. A notice that fires on the application's own
 * control is worse than no notice: it is the one that teaches people to ignore the red box, and the
 * red box is the whole of this item.
 *
 * ## How this is asserted (#624, #627)
 *
 * Every case is driven through the real screen, and every absence assertion is paired with a
 * positive control on the SAME element, so a selector that stopped matching fails loudly instead of
 * passing quietly. `#bad-parent-notice` is that element throughout.
 *
 * The two money screens are asserted by reading the database back rather than by what the page
 * says: the bad-id case SUBMITS the form it was left with and proves a new row was written and the
 * row it was pointed at is byte-for-byte unchanged — which is the actual defect, stated as data.
 */
final class EveryBuySideIdInALinkSaysWhenItIsBadCest
{
    private const SCAN = '/admin/bundles/procurement/receiving/scan';

    /** An id no row will ever have. */
    private const MISSING = '99999999';

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('buy-side-ids-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A warehouse with one bin, a vendor, and a product — so every id under test has a REAL value
     * to be the positive control.
     *
     * @return array<string, int>
     */
    private function site(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $tag = strtoupper(substr(uniqid(), -6));

        $warehouse = (new Warehouse())
            ->setName('Link Ids DC ' . $tag)
            ->setStatus('Active')
            ->setAddressLine1('1 Link Way')
            ->setCity('Surrey')
            ->setProvince('BC')
            ->setPostalCode('V3S 0A1')
            ->setCountry('CA');
        $em->persist($warehouse);

        $vendor = (new Vendor())->setName('Link Ids Supply ' . $tag)->setCurrency('CAD')->setStatus('Active');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('LINK-' . $tag)
            ->setName('Link Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('LK-01')->setSortKey(10);
        $em->persist($bin);
        $em->flush();

        return [
            'warehouse' => (int) $warehouse->getId(),
            'vendor' => (int) $vendor->getId(),
            'product' => (int) $product->getId(),
            'bin' => (int) $bin->getId(),
        ];
    }

    /**
     * One id on the scan console, through every shape a URL can carry it in.
     *
     * `$extra` is what the id needs beside it to be meaningful — a bin only exists inside a
     * warehouse, so the warehouse is named alongside it. The positive control runs FIRST, because
     * without it "the notice appeared" proves nothing: a screen that rendered the notice
     * unconditionally would pass every remaining assertion here.
     */
    private function assertConsoleIdIsChecked(
        FunctionalTester $I,
        string $key,
        int $validId,
        string $notice,
        string $extra = '',
    ): void {
        $prefix = self::SCAN . '?' . ($extra === '' ? '' : $extra . '&');

        // 1. Nothing asked for at all.
        $I->amOnPage(self::SCAN . ($extra === '' ? '' : '?' . $extra));
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');

        // 2. THE POSITIVE CONTROL — a real one, reported as nothing wrong.
        $I->amOnPage($prefix . $key . '=' . $validId);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');

        // 3. Not an id at all. This was a raw 400 error page.
        $I->amOnPage($prefix . $key . '=abc');
        $I->seeResponseCodeIs(200, '?' . $key . '=abc still does not render');
        $I->seeElement('#bad-parent-notice');
        $I->see($notice, '#bad-parent-notice');
        $I->see('abc', '#bad-parent-notice');

        // 4. Well formed, names nothing. This opened the console silently unbound.
        $I->amOnPage($prefix . $key . '=' . self::MISSING);
        $I->seeResponseCodeIs(200, '?' . $key . '=' . self::MISSING . ' still does not render');
        $I->seeElement('#bad-parent-notice');
        $I->see($notice, '#bad-parent-notice');
        $I->see(self::MISSING, '#bad-parent-notice');

        // 5. An array where a scalar belongs — the other shape getInt() threw on.
        $I->amOnPage($prefix . $key . '[]=' . $validId);
        $I->seeResponseCodeIs(200, '?' . $key . '[] still does not render');
        $I->seeElement('#bad-parent-notice');
    }

    /** The console's warehouse, vendor, bin and pending product — the four ids `?po=` left behind. */
    public function theScanConsoleChecksItsOtherFourIds(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $site = $this->site($I);

        $this->assertConsoleIdIsChecked($I, 'warehouse', $site['warehouse'], "That warehouse doesn't exist");
        $this->assertConsoleIdIsChecked($I, 'vendor', $site['vendor'], "That vendor doesn't exist");
        $this->assertConsoleIdIsChecked($I, 'pending', $site['product'], "That product doesn't exist");

        // A bin is asked about differently, and deliberately: bin codes are per warehouse, so the
        // question is membership and not existence. See ReceivingController::requestedBin().
        $this->assertConsoleIdIsChecked(
            $I,
            'bin',
            $site['bin'],
            'is not a bin in the warehouse this delivery is going to',
            'warehouse=' . $site['warehouse'],
        );
    }

    /**
     * A bin that IS a bin, in the wrong warehouse.
     *
     * The case existence alone cannot see, and the reason the sentence is not "that bin doesn't
     * exist": the row is real, it is just not one this delivery can be put into. Before, the select
     * simply had no matching option and the shelf was silently dropped — the pallet arriving in the
     * warehouse and in no bin, with nothing said.
     */
    public function aBinFromAnotherWarehouseIsRefusedRatherThanSilentlyDropped(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $site = $this->site($I);
        $elsewhere = $this->site($I);

        // Positive control: this warehouse's own bin is accepted and nothing is reported.
        $I->amOnPage(self::SCAN . '?warehouse=' . $site['warehouse'] . '&bin=' . $site['bin']);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');
        $I->seeElement('select[name="bin"] option[value="' . $site['bin'] . '"][selected]');

        // The other warehouse's bin: a real row, and not one of these.
        $I->amOnPage(self::SCAN . '?warehouse=' . $site['warehouse'] . '&bin=' . $elsewhere['bin']);
        $I->seeResponseCodeIs(200);
        $I->seeElement('#bad-parent-notice');
        $I->see('is not a bin in the warehouse this delivery is going to', '#bad-parent-notice');
        $I->dontSeeElement('select[name="bin"] option[value="' . $elsewhere['bin'] . '"]');
    }

    /**
     * The console's own "— none —" options are not accused of being bad ids.
     *
     * Driven through the rendered form rather than by typing `?po=0` into the address bar, because
     * the point is that the SCREEN produces this URL: `<option value="0">` on three selects and a
     * hidden `po=0` on the shelf form. The positive control is the same submit carrying a genuinely
     * bad id, so "no notice" cannot be a selector that stopped matching.
     */
    public function choosingNoPurchaseOrderOnTheConsoleIsNotABadId(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $site = $this->site($I);

        $I->amOnPage(self::SCAN);
        $I->seeResponseCodeIs(200);
        // Guard: the screen really does spell "none" as zero. If this ever changes to an empty
        // value the rest of this test is asserting something that cannot happen any more.
        $I->seeElement('select[name="po"] option[value="0"]');
        $I->seeElement('select[name="vendor"] option[value="0"]');
        $I->seeElement('select[name="warehouse"] option[value="0"]');

        // What "Set" sends when nothing is chosen — every select on its zero.
        $I->amOnPage(self::SCAN . '?po=0&vendor=0&warehouse=0');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');

        // And what the shelf form sends, which carries po=0 whether or not it is about an order.
        $I->amOnPage(self::SCAN . '?po=0&vendor=' . $site['vendor'] . '&warehouse=' . $site['warehouse'] . '&bin=0');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');

        // THE POSITIVE CONTROL, on the same element: the same submit with one real bad id in it.
        $I->amOnPage(self::SCAN . '?po=' . self::MISSING . '&vendor=0&warehouse=0');
        $I->seeResponseCodeIs(200);
        $I->seeElement('#bad-parent-notice');
        $I->see("That purchase order doesn't exist", '#bad-parent-notice');
    }

    /**
     * `?pending_q=` is a COUNT, not an id: it has no third state and nothing to report. What it may
     * not be is a raw 400, which is what `getInt()` made of it.
     */
    public function aScannedQuantityThatIsNotANumberIsOneUnitRatherThanAnErrorPage(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $site = $this->site($I);

        $url = self::SCAN . '?warehouse=' . $site['warehouse'] . '&pending=' . $site['product'];

        // Positive control: a real quantity renders and is carried onto the form.
        $I->amOnPage($url . '&pending_q=4');
        $I->seeResponseCodeIs(200);
        $I->seeElement('input[name="pending_q"][value="4"]');

        $I->amOnPage($url . '&pending_q=abc');
        $I->seeResponseCodeIs(200, '?pending_q=abc still does not render');
        $I->seeElement('input[name="pending_q"][value="1"]');
        $I->dontSeeElement('#bad-parent-notice');
    }

    /**
     * The payment row link, and the money defect behind it.
     *
     * `?payment=` pointing at another bill's row used to render the blank "record a payment" form.
     * The proof is the database: the form as rendered is SUBMITTED, and what it writes is a new
     * payment on this bill while the payment it was pointed at is untouched. That is the silent
     * failure stated as data — and it is why the notice above the form has to be there.
     */
    public function thePaymentsScreenSaysWhenTheRowIsNotThisBills(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $tag = strtoupper(substr(uniqid(), -6));

        $vendor = (new Vendor())->setName('Payment Link Supply ' . $tag)->setCurrency('CAD')->setStatus('Active');
        $em->persist($vendor);
        $em->flush();

        $mine = $this->bill($I, $vendor, 'MINE-' . $tag);
        $theirs = $this->bill($I, $vendor, 'THEIRS-' . $tag);

        $minePayment = $this->payment($I, $mine, '10.00', 'MY-NOTE-' . $tag);
        $theirPayment = $this->payment($I, $theirs, '25.00', 'THEIR-NOTE-' . $tag);

        $url = '/admin/bundles/procurement/bills/' . $mine->getId() . '/payments';

        // 1. No parameter: the add form, and nothing reported.
        $I->amOnPage($url);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');

        // 2. THE POSITIVE CONTROL — this bill's own payment opens for correction, silently.
        $I->amOnPage($url . '?payment=' . $minePayment);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');
        $I->seeElement('#document-payment-form input[name="payment_id"][value="' . $minePayment . '"]');

        // 3. Not an id at all. A raw 400 before.
        $I->amOnPage($url . '?payment=abc');
        $I->seeResponseCodeIs(200, '?payment=abc still does not render');
        $I->seeElement('#bad-parent-notice');
        $I->see("which is not one of this bill's payments", '#bad-parent-notice');

        // 4. The other bill's payment — a real row, and not one of these.
        $I->amOnPage($url . '?payment=' . $theirPayment);
        $I->seeResponseCodeIs(200);
        $I->seeElement('#bad-parent-notice');
        $I->see("which is not one of this bill's payments", '#bad-parent-notice');
        // The form is in ADD mode, which is the fact the notice exists to declare.
        $I->seeElement('#document-payment-form input[name="payment_id"][value="0"]');

        // And what that means, read off the database rather than off the page: submitting the form
        // the screen was left holding writes a NEW row, and the payment the link named is unchanged.
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'payment_id' => (string) $I->grabAttributeFrom('#document-payment-form input[name="payment_id"]', 'value'),
            'paid_at' => '2026-09-12',
            'amount' => '3.00',
            'method' => 'Cheque',
            'comment' => 'Typed after a bad link',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            '25.00',
            $this->money($this->column($I, 'SELECT amount FROM vendor_bill_payment_application WHERE id = ?', [$theirPayment])),
            'the payment the link named was edited by a form that was not editing it',
        );
        $I->assertSame(
            'THEIR-NOTE-' . $tag,
            $this->column(
                $I,
                'SELECT p.comment FROM vendor_bill_payment_application a'
                    . ' JOIN vendor_bill_payment p ON p.id = a.vendor_bill_payment_id WHERE a.id = ?',
                [$theirPayment],
            ),
            'the other bill\'s payment note changed',
        );
        $I->assertSame(
            2,
            (int) $this->column($I, 'SELECT COUNT(*) FROM vendor_bill_payment_application WHERE vendor_bill_id = ?', [$mine->getId()]),
            'the post should have ADDED a payment to this bill — that is the defect the notice warns about',
        );
    }

    /**
     * The address row link on the vendor page, asserted the same way and for the same reason: it is
     * an EDIT link that silently fell back to the ADD form.
     */
    public function theVendorScreenSaysWhenTheAddressIsNotThisVendors(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $tag = strtoupper(substr(uniqid(), -6));

        $mine = (new Vendor())->setName('Address Link Mine ' . $tag)->setCurrency('CAD')->setStatus('Active');
        $theirs = (new Vendor())->setName('Address Link Theirs ' . $tag)->setCurrency('CAD')->setStatus('Active');
        $em->persist($mine);
        $em->persist($theirs);
        $em->flush();

        $myAddress = $this->address($I, $mine, 'Mine ' . $tag);
        $theirAddress = $this->address($I, $theirs, 'Theirs ' . $tag);

        $url = '/admin/bundles/procurement/vendors/' . $mine->getId();
        // The address form is its own page now (admin_company_address_create/update's shape), not
        // inline on the detail page — every `?address=` case below moved with it.
        $addressUrl = $url . '/address';

        $I->amOnPage($addressUrl);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');
        $I->see('Add Address', '#address-form');

        // THE POSITIVE CONTROL — this vendor's own address opens for editing, silently.
        $I->amOnPage($addressUrl . '?address=' . $myAddress);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#bad-parent-notice');
        $I->see('Edit Address', '#address-form');

        // Not an id at all. A raw 400 before — and this parameter sits in a row link, so a mangled
        // one loses the whole vendor page rather than one form on it.
        $I->amOnPage($addressUrl . '?address=abc');
        $I->seeResponseCodeIs(200, '?address=abc still does not render');
        $I->seeElement('#bad-parent-notice');
        $I->see("which is not one of this vendor's addresses", '#bad-parent-notice');

        // The other vendor's address: real, and not one of these.
        $I->amOnPage($addressUrl . '?address=' . $theirAddress);
        $I->seeResponseCodeIs(200);
        $I->seeElement('#bad-parent-notice');
        $I->see("which is not one of this vendor's addresses", '#bad-parent-notice');
        $I->see('Add Address', '#address-form');

        // Read back rather than believed: the other vendor's address is untouched, and this vendor
        // has gained one — which is exactly what filling the form in would have done in silence.
        $I->sendFormPostRequest($url . '/address', [
            '_token' => $I->csrfToken(),
            'address_id' => '0',
            'label' => 'Typed after a bad link',
            'address_line_1' => '9 Wrong Turn',
            'city' => 'Surrey',
            'province' => 'BC',
            'postal_code' => 'V3S 0A1',
            'country' => 'CA',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            'Theirs ' . $tag,
            $this->column($I, 'SELECT label FROM vendor_address WHERE id = ?', [$theirAddress]),
            'the address the link named was edited by a form that was not editing it',
        );
        $I->assertSame(
            2,
            (int) $this->column($I, 'SELECT COUNT(*) FROM vendor_address WHERE vendor_id = ?', [$mine->getId()]),
            'the post should have ADDED an address to this vendor — the defect the notice warns about',
        );
    }

    // ── fixtures and database reads ─────────────────────────────────────────────────────────

    private function bill(FunctionalTester $I, Vendor $vendor, string $number): VendorBill
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $bill = (new VendorBill())
            ->setBillNumber('BILL-' . $number)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setVendorInvoiceNo('THEIR-' . $number)
            ->setTotal('100.00');
        $em->persist($bill);
        $em->flush();

        return $bill;
    }

    /**
     * Through the entity's own recordPayment(), which is the only supported way onto a bill.
     *
     * Returns the CLAIM's id (`vendor_bill_payment_application`), not the underlying payment's — the
     * `?payment=` link and the form's `payment_id` field both address a payment by its claim since
     * #708, since that is the row that says which bill it is against.
     */
    private function payment(FunctionalTester $I, VendorBill $bill, string $amount, string $note): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $payment = (new VendorBillPayment())
            ->setPaidAt(new \DateTimeImmutable('2026-09-01'))
            ->setAmount($amount)
            ->setMethod('Cheque')
            ->setComment($note);
        $bill->recordPayment(DocumentActor::system(), $payment);
        $em->persist($payment);
        $em->flush();

        return (int) $bill->getApplications()->first()->getId();
    }

    private function address(FunctionalTester $I, Vendor $vendor, string $label): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $address = (new VendorAddress())
            ->setVendor($vendor)
            ->setLabel($label)
            ->setAddressLine1('1 Somewhere')
            ->setCity('Surrey')
            ->setProvince('BC')
            ->setPostalCode('V3S 0A1')
            ->setCountry('CA');
        $em->persist($address);
        $vendor->addAddress($address);
        $em->flush();

        return (int) $address->getId();
    }

    /**
     * @param list<mixed> $params
     */
    private function column(FunctionalTester $I, string $sql, array $params = []): ?string
    {
        $value = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $params);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** SQLite hands a DECIMAL back as '25' or '25.0' depending on how it was written. */
    private function money(?string $raw): string
    {
        return number_format((float) (string) $raw, 2, '.', '');
    }
}
