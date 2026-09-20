<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorContact;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The vendor create PAGE and the vendor list's per-column filters, conducted (#660, #624).
 *
 * `/admin/bundles/procurement/vendors` was the one master-data list in this application that still
 * carried its create form on the list screen. `/admin/company` has never done that —
 * `admin_company_create` is a page — and the owner's instruction was to conform the vendor screens
 * to the customer ones. This file is the proof that the move happened and that nothing the vendor
 * screens already did stopped working.
 *
 * ## What "conducted" means here (#624)
 *
 * Every write below is a plain browser form POST through `sendFormPostRequest()` — no
 * `X-Requested-With`, which is what a browser with scripting off sends — carrying a CSRF token
 * scraped off the page that rendered the form. Nothing calls a service directly and nothing asserts
 * on a flash message: the assertions read `vendor.name`, `vendor.account_number`,
 * `vendor.payment_term_id` and the rest straight out of the table through a raw connection, AFTER
 * the request, so a Doctrine identity map holding a stale object cannot make a green test.
 *
 * And every write asserts **the row that should not have changed**, which is the half that catches
 * the bugs: a create page that edited an existing row instead of inserting, or a filter that
 * narrowed by matching every row, both pass a test that only looks at the row it expects.
 *
 * ## Numbers (#627)
 *
 * No `see()` on a number or a name anywhere below — `see('12')` matches `'120'` and
 * `see('Acme')` matches `'Acme Holdings'`. Row membership is asserted by grabbing the Vendor Name
 * cells and comparing the LIST, so a filter that returns one row too many fails on the array
 * comparison rather than passing because the extra row was never looked at.
 */
final class ProcurementVendorScreensParityCest
{
    private const LIST_URL = '/admin/bundles/procurement/vendors';
    private const CREATE_URL = '/admin/bundles/procurement/vendors/new';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-parity-' . uniqid() . '@example.test');
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

    /**
     * One `vendor` row, read straight off the table.
     *
     * Deliberately NOT `$em->find()`: an entity fetched before the request under test is still in
     * the identity map afterwards, so asserting on it can pass while the column never changed.
     *
     * @return array<string, mixed>
     */
    private function vendorRow(FunctionalTester $I, int $id): array
    {
        /** @var Connection $connection */
        $connection = $this->em($I)->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM vendor WHERE id = ?', [$id]);
        $I->assertIsArray($row, 'no vendor row with id ' . $id);

        return $row;
    }

    private function vendorCount(FunctionalTester $I): int
    {
        return (int) $this->em($I)->getConnection()->fetchOne('SELECT COUNT(*) FROM vendor');
    }

    /** The id of the `vendor` row carrying this exact name, or 0. Exact match, never LIKE. */
    private function vendorIdByName(FunctionalTester $I, string $name): int
    {
        return (int) $this->em($I)->getConnection()->fetchOne('SELECT id FROM vendor WHERE name = ?', [$name]);
    }

    private function seedVendor(FunctionalTester $I, string $name): Vendor
    {
        $em = $this->em($I);
        $vendor = (new Vendor())->setName($name);
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    /** @param array<string, string> $params */
    private function post(FunctionalTester $I, string $uri, array $params): void
    {
        $I->sendFormPostRequest($uri, array_merge(['_token' => $I->csrfToken()], $params));
    }

    /**
     * Every Vendor Name cell currently on the list, in order.
     *
     * The list rather than a count, so that an extra row fails the comparison instead of slipping
     * past an assertion that only looked for the row it wanted (#627).
     *
     * @return list<string>
     */
    private function listedNames(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => trim($text),
            $I->grabMultiple('tbody td[data-label="Vendor Name"] a'),
        );
    }

    // ------------------------------------------------------------------ the create page exists

    /**
     * The list offers Create Vendor and no longer carries a create form.
     *
     * The absence assertion is paired with a positive control: the same selector — a POST form
     * aimed at the save route — still matches on the vendor's own record, which is where the edit
     * form lives. Without that control the `dontSeeElement` would pass just as happily if the
     * selector were misspelled (#627).
     */
    public function theListLinksToACreatePageAndNoLongerCarriesTheCreateForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Create Button Supply ' . uniqid());

        $I->amOnPage(self::LIST_URL);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a.button.primary[href="' . self::CREATE_URL . '"]');
        $I->dontSeeElement('form[action="/admin/bundles/procurement/vendors/save"]');

        // Positive control for the selector above. Editing is its own page now (the customer
        // record's own separate Edit Profile shape) rather than an inline form on the detail page.
        $I->amOnPage(self::LIST_URL . '/' . $vendor->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form[action="/admin/bundles/procurement/vendors/save"]');
    }

    /** The create page is a real page with a real form, submittable with scripting off. */
    public function theCreatePageRendersEveryVendorFieldOnAPlainForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage(self::CREATE_URL);
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form[method="post"][action="' . self::CREATE_URL . '"] input[name="name"][required]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] input[name="account_number"]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] input[name="email"]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] input[name="phone"]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] input[name="currency"]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] select[name="status"]');
        // No notes box: item 43 took the legacy `vendor.notes` CLOB off both vendor screens, and a
        // vendor's notes are threaded rows added from its record — exactly as a customer's are.
        // The fields above are the positive control for this absence: the same reader finds six
        // other controls on the same form.
        $I->dontSeeElement('form[action="' . self::CREATE_URL . '"] textarea[name="notes"]');
        // A real submit button on the form itself — not a `js-` hook — is what makes the page work
        // without scripting.
        $I->seeElement('form[action="' . self::CREATE_URL . '"] button[type="submit"]');
        $I->seeElement('form[action="' . self::CREATE_URL . '"] input[name="_token"]');
    }

    // ------------------------------------------------------------------ conducted: the insert

    /**
     * Filling the create page writes ONE new `vendor` row with the columns that were typed, and
     * leaves every other vendor alone.
     */
    public function creatingAVendorWritesItsColumnsAndTouchesNoOtherRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $bystander = $this->seedVendor($I, 'Untouched Supply ' . uniqid());
        $bystanderId = (int) $bystander->getId();
        $before = $this->vendorRow($I, $bystanderId);
        $countBefore = $this->vendorCount($I);

        $name = 'Conducted Supply ' . uniqid();
        $account = 'ACCT-' . strtoupper(substr(uniqid(), -6));

        $I->amOnPage(self::CREATE_URL);
        $this->post($I, self::CREATE_URL, [
            'name' => $name,
            'account_number' => $account,
            'email' => 'ap@conducted.example',
            'phone' => '604-555-0142',
            'currency' => 'USD',
            'status' => 'Inactive',
            // Still posted, because a stale bookmarked form would. Item 43 dropped the column, so
            // the assertion below is that it lands nowhere rather than that it round-trips.
            'notes' => 'Opened on the create page.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame($countBefore + 1, $this->vendorCount($I), 'the create page did not insert exactly one vendor row');

        $id = $this->vendorIdByName($I, $name);
        $I->assertGreaterThan(0, $id, 'no vendor row was written under the name that was typed');

        $row = $this->vendorRow($I, $id);
        $I->assertSame($name, $row['name']);
        $I->assertSame($account, $row['account_number']);
        $I->assertSame('ap@conducted.example', $row['email']);
        $I->assertSame('604-555-0142', $row['phone']);
        $I->assertSame('USD', $row['currency']);
        $I->assertSame('Inactive', $row['status']);
        $I->assertArrayNotHasKey('notes', $row, 'the dropped legacy column came back');

        // The row that should NOT have changed (#624). A create that found an existing row and
        // edited it instead of inserting would pass every assertion above.
        $after = $this->vendorRow($I, $bystanderId);
        $I->assertSame($before, $after, 'creating a vendor rewrote a different vendor row');
    }

    /**
     * A create with no name stores nothing, comes back 422, and keeps what was typed.
     *
     * The count is the assertion that matters: a screen that flashes an error and inserts anyway
     * looks identical from the browser.
     */
    public function creatingAVendorWithNoNameStoresNothingAndKeepsWhatWasTyped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $countBefore = $this->vendorCount($I);
        $account = 'REJECT-' . strtoupper(substr(uniqid(), -6));

        $I->amOnPage(self::CREATE_URL);
        $this->post($I, self::CREATE_URL, [
            'name' => '   ',
            'account_number' => $account,
            'email' => 'nobody@rejected.example',
            'currency' => 'GBP',
            'status' => 'Active',
        ]);

        $I->seeResponseCodeIs(422);
        $I->assertSame($countBefore, $this->vendorCount($I), 'a nameless vendor was stored');

        // What was typed survives the rejection rather than being thrown away by a redirect.
        $I->seeElement('input[name="account_number"][value="' . $account . '"]');
        $I->seeElement('input[name="email"][value="nobody@rejected.example"]');
        $I->seeElement('input[name="currency"][value="GBP"]');
    }

    /**
     * The record's own Save still writes every column, through the same partial and the same
     * writer the create page uses.
     *
     * This is the regression the shared `_vendor_fields.html.twig` exists to make impossible: one
     * template and one `applyVendorRequest()`, so a field cannot be stored by one screen and
     * dropped by the other. Asserted by conducting BOTH and comparing the columns.
     */
    public function savingFromTheRecordWritesTheSameColumnsTheCreatePageDoes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $bystander = $this->seedVendor($I, 'Save Bystander ' . uniqid());
        $bystanderId = (int) $bystander->getId();
        $before = $this->vendorRow($I, $bystanderId);

        $vendor = $this->seedVendor($I, 'Edited Supply ' . uniqid());
        $id = (int) $vendor->getId();
        $newName = 'Renamed Supply ' . uniqid();

        $I->amOnPage(self::LIST_URL . '/' . $id);
        $this->post($I, '/admin/bundles/procurement/vendors/save', [
            'id' => (string) $id,
            'name' => $newName,
            'account_number' => 'SAVED-001',
            'email' => 'desk@edited.example',
            'phone' => '250-555-0199',
            'currency' => 'EUR',
            'status' => 'Inactive',
            'notes' => 'Edited on the record.',
        ]);
        // As above: posted, stored nowhere.
        $I->seeResponseCodeIsSuccessful();

        $row = $this->vendorRow($I, $id);
        $I->assertSame($newName, $row['name']);
        $I->assertSame('SAVED-001', $row['account_number']);
        $I->assertSame('desk@edited.example', $row['email']);
        $I->assertSame('250-555-0199', $row['phone']);
        $I->assertSame('EUR', $row['currency']);
        $I->assertSame('Inactive', $row['status']);
        $I->assertArrayNotHasKey('notes', $row, 'the dropped legacy column came back');

        $I->assertSame($before, $this->vendorRow($I, $bystanderId), 'saving one vendor rewrote another');
    }

    // ------------------------------------------------------------------ conducted: the filters

    /**
     * Each column's filter narrows by ITS OWN column and nothing else.
     *
     * Three vendors, each carrying a token in exactly one column. Filtering on that column returns
     * that vendor and only that vendor; filtering the SAME token through a different column's box
     * returns none of them. The second half is the one that catches a controller wiring
     * `filters[email]` to the name predicate, which the first half alone cannot see.
     */
    public function eachColumnFilterNarrowsByItsOwnColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);
        $token = strtoupper(substr(uniqid(), -8));

        $byName = (new Vendor())->setName('Namehit ' . $token);
        $byAccount = (new Vendor())->setName('Accounthit ' . $token . ' Co')->setAccountNumber('ACC' . $token);
        $byEmail = (new Vendor())->setName('Emailhit ' . $token . ' Co')->setEmail($token . '@mail.example');
        $byCurrency = (new Vendor())->setName('Currencyhit ' . $token . ' Co')->setCurrency('ZAR');
        $byTerm = (new Vendor())->setName('Termhit ' . $token . ' Co')->setPaymentTerm('Net ' . $token);
        foreach ([$byName, $byAccount, $byEmail, $byCurrency, $byTerm] as $vendor) {
            $em->persist($vendor);
        }
        $em->flush();

        // Name: every one of the five carries the token in its name, so this is also the positive
        // control proving the token reaches the screen at all.
        $I->amOnPage(self::LIST_URL . '?filters[name]=' . $token);
        $I->seeResponseCodeIsSuccessful();
        $I->assertCount(5, $this->listedNames($I), 'the name filter did not return the five seeded vendors');

        $I->amOnPage(self::LIST_URL . '?filters[accountNumber]=ACC' . $token);
        $I->assertSame([$byAccount->getName()], $this->listedNames($I));

        $I->amOnPage(self::LIST_URL . '?filters[email]=' . $token . '@mail.example');
        $I->assertSame([$byEmail->getName()], $this->listedNames($I));

        $I->amOnPage(self::LIST_URL . '?filters[paymentTerm]=Net ' . $token);
        $I->assertSame([$byTerm->getName()], $this->listedNames($I));

        // The cross-column negative: the account token typed into the email box matches nothing,
        // even though a row does hold it — in a different column.
        $I->amOnPage(self::LIST_URL . '?filters[email]=ACC' . $token);
        $I->assertSame([], $this->listedNames($I), 'the email filter matched a value that lives in account_number');

        $I->amOnPage(self::LIST_URL . '?filters[accountNumber]=' . $token . '@mail.example');
        $I->assertSame([], $this->listedNames($I), 'the account number filter matched a value that lives in email');
    }

    /**
     * The id box is an exact lookup, not a substring one.
     *
     * `filters[id]=1` returning vendor 1, 10, 11 and 100 is the same defect class as `see('50')`
     * matching `'1050'`, and an id column is exactly where it bites.
     */
    public function theIdFilterMatchesOneRowAndNotItsPrefixes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Exact Id Supply ' . uniqid());
        $id = (int) $vendor->getId();

        $I->amOnPage(self::LIST_URL . '?filters[id]=' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$vendor->getName()], $this->listedNames($I));

        // A non-numeric id narrows to nothing rather than being dropped and returning the table.
        $I->amOnPage(self::LIST_URL . '?filters[id]=not-a-number');
        $I->assertSame([], $this->listedNames($I), 'a non-numeric id filter returned rows');
    }

    /** The status filter is a select, and it narrows by status. */
    public function theStatusFilterNarrowsByStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);
        $token = strtoupper(substr(uniqid(), -8));

        $active = (new Vendor())->setName('Active ' . $token);
        $inactive = (new Vendor())->setName('Inactive ' . $token)->setStatus(Vendor::STATUS_INACTIVE);
        $em->persist($active);
        $em->persist($inactive);
        $em->flush();

        $I->amOnPage(self::LIST_URL);
        $I->seeElement('tr.filter-row select[name="filters[status]"]');

        $I->amOnPage(self::LIST_URL . '?filters[name]=' . $token . '&filters[status]=Inactive');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$inactive->getName()], $this->listedNames($I));

        $I->amOnPage(self::LIST_URL . '?filters[name]=' . $token . '&filters[status]=Active');
        $I->assertSame([$active->getName()], $this->listedNames($I));
    }

    /**
     * The contact filter finds a vendor through `vendor_contact`, and lists it ONCE.
     *
     * Most of the value of recording contacts is being able to search for them: nobody remembers
     * which of four similarly-named distributors employs Priya. The predicate is an EXISTS subquery
     * rather than a join precisely so that a vendor with three contacts appears once — a join to a
     * one-to-many would multiply both the rows and the count under the table.
     */
    public function theContactFilterFindsAVendorByItsPeopleAndListsItOnce(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);
        $surname = 'Ramanathan' . strtoupper(substr(uniqid(), -6));

        $vendor = $this->seedVendor($I, 'Contact Search Supply ' . uniqid());
        $bystander = $this->seedVendor($I, 'No Contacts Supply ' . uniqid());

        foreach (['Priya', 'Dana', 'Marc'] as $first) {
            $contact = (new VendorContact())->setFirstName($first)->setLastName($surname);
            $vendor->addContact($contact);
            $em->persist($contact);
        }
        $em->flush();

        $I->amOnPage(self::LIST_URL . '?filters[contact]=' . $surname);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$vendor->getName()], $this->listedNames($I));
        $I->assertNotContains($bystander->getName(), $this->listedNames($I));
    }

    /**
     * The Remit-To column reads the REMIT-TO address, not the default one.
     *
     * This is the column that is deliberately NOT the customer list's Billing Address: the remit-to
     * is often a different legal entity from the vendor — a factor, a lockbox, a parent's accounts
     * department — and showing the head office where the money address belongs is how a payment
     * goes to the wrong company (#606). A vendor with a default address and no remit-to reads as
     * nothing recorded rather than borrowing the head office.
     */
    public function theRemitToColumnShowsTheRemitToAddressAndNotTheDefaultOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);

        $vendor = $this->seedVendor($I, 'Factored Parity Supply ' . uniqid());
        $head = (new VendorAddress())->setAddressLine1('1 Head Office Way')->setCity('Vancouver')->setCountry('CA')->setIsDefault(true);
        $factor = (new VendorAddress())->setAddressLine1('77 Lockbox Ave')->setCity('Calgary')->setCountry('CA')->setIsRemitTo(true);
        $vendor->addAddress($head);
        $vendor->addAddress($factor);
        $em->persist($head);
        $em->persist($factor);

        $noRemit = $this->seedVendor($I, 'Head Office Only Supply ' . uniqid());
        $onlyHead = (new VendorAddress())->setAddressLine1('9 Sole Street')->setCity('Victoria')->setCountry('CA')->setIsDefault(true);
        $noRemit->addAddress($onlyHead);
        $em->persist($onlyHead);
        $em->flush();

        $I->amOnPage(self::LIST_URL . '?filters[id]=' . $vendor->getId());
        $I->seeResponseCodeIsSuccessful();
        $cell = trim(implode(' ', $I->grabMultiple('tbody td[data-label="Remit-To Address"]')));
        $I->assertStringContainsString('77 Lockbox Ave', $cell);
        $I->assertStringNotContainsString('1 Head Office Way', $cell, 'the remit-to column borrowed the default address');

        $I->amOnPage(self::LIST_URL . '?filters[id]=' . $noRemit->getId());
        $sole = trim(implode(' ', $I->grabMultiple('tbody td[data-label="Remit-To Address"]')));
        $I->assertSame('-', $sole, 'a vendor with no remit-to address borrowed its default one');
    }

    /**
     * The remit-to filter matches through the remit-to address and not through any other one.
     */
    public function theRemitToFilterMatchesOnlyTheRemitToAddress(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $this->em($I);
        $token = strtoupper(substr(uniqid(), -8));

        $vendor = $this->seedVendor($I, 'Remit Filter Supply ' . uniqid());
        $head = (new VendorAddress())->setAddressLine1('1 Headoffice' . $token . ' Way')->setCity('Vancouver')->setCountry('CA')->setIsDefault(true);
        $factor = (new VendorAddress())->setAddressLine1('77 Lockbox' . $token . ' Ave')->setCity('Calgary')->setCountry('CA')->setIsRemitTo(true);
        $vendor->addAddress($head);
        $vendor->addAddress($factor);
        $em->persist($head);
        $em->persist($factor);
        $em->flush();

        $I->amOnPage(self::LIST_URL . '?filters[remitTo]=Lockbox' . $token);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$vendor->getName()], $this->listedNames($I));

        // The head office street is on the same vendor, on a row that is not the remit-to.
        $I->amOnPage(self::LIST_URL . '?filters[remitTo]=Headoffice' . $token);
        $I->assertSame([], $this->listedNames($I), 'the remit-to filter matched an address that is not the remit-to');
    }

    // ------------------------------------------------------------------ the grid itself

    /**
     * Every header, filter and body row is the same width.
     *
     * `AdminListScreenConventionsCest` asserts this across every list screen and would catch it
     * too; it is here as well because this screen's header rows are generated from one `columns`
     * list and the Actions column is appended by hand, which is precisely where the two can drift.
     */
    public function theHeaderTheFilterRowAndTheBodyAllSpanTenColumns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedVendor($I, 'Width Supply ' . uniqid());

        $I->amOnPage(self::LIST_URL);
        $I->seeResponseCodeIsSuccessful();
        $I->seeNumberOfElements('table.vendor-table thead tr:first-child th', 10);
        $I->seeNumberOfElements('table.vendor-table thead tr.filter-row th', 10);
        $I->seeNumberOfElements('table.vendor-table tbody tr.data-item-row:first-child td', 10);
    }

    /**
     * Every filter box the screen renders is read by the controller.
     *
     * The UI audit found three filters that were read with no input rendered for them; this is the
     * same check from the other end. The names are discovered from the markup rather than listed
     * here, so a column added to the screen is covered the day it appears.
     */
    public function everyFilterBoxOnTheScreenIsOneTheControllerReads(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = strtoupper(substr(uniqid(), -8));
        $vendor = $this->seedVendor($I, 'Wiring Supply ' . $token);

        $I->amOnPage(self::LIST_URL);
        $names = $I->grabMultiple('tr.filter-row [name^="filters["]', 'name');
        $I->assertGreaterThan(5, count($names), 'the filter row stopped rendering inputs; this test is now asserting nothing');

        foreach ($names as $name) {
            $key = substr($name, strlen('filters['), -1);
            $I->assertContains(
                $key,
                \ProcurementBundle\Repository\VendorRepository::FILTER_KEYS,
                sprintf('the screen renders filters[%s] and the repository does not read it', $key),
            );
        }

        // And the other direction: a key the repository declares but nothing renders would leave a
        // predicate no user can reach.
        $rendered = array_map(
            static fn (string $name): string => substr($name, strlen('filters['), -1),
            $names,
        );
        foreach (\ProcurementBundle\Repository\VendorRepository::FILTER_KEYS as $key) {
            $I->assertContains($key, $rendered, sprintf('the repository reads filters[%s] and the screen renders no box for it', $key));
        }

        // Positive control that the page under assertion is the vendor list and not an error page.
        $I->amOnPage(self::LIST_URL . '?filters[name]=' . $token);
        $I->assertSame([$vendor->getName()], $this->listedNames($I));
    }
}
