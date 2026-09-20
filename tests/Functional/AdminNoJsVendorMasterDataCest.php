<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Vendor master data, driven the way a browser with scripting off drives it (#605, #606).
 *
 * `/admin/bundles/procurement/vendors/{id}` grew four panels — Contacts, Addresses with purposes,
 * Notes, and the payment-term dropdown — and every one of them has to work with JavaScript
 * disabled, because this application's stated baseline is that JS is a veneer.
 *
 * **Every POST below goes through sendFormPostRequest()**, i.e. a plain browser form post with no
 * `X-Requested-With` header — what a browser with scripting off sends when a submit button is
 * pressed. Each one carries only field names the page actually renders, and each test first asserts
 * that those fields ARE rendered, in the table, carrying the `form=` attribute that joins them to a
 * real `<form>` emitted outside it. (submitForm() cannot be used on an admin screen at all: the
 * crawler resolves the action against the admin.localhost Host header and the module then refuses it
 * as an external URL — see Tests\Support\Helper\Functional::sendFormPostRequest.)
 *
 * The `form=` attribute is what makes the contact grid legal HTML rather than merely convenient: a
 * `<form>` inside a `<form>` is invalid and every parser silently drops the inner one, so the row
 * controls have to live in the table while their forms live beside it.
 *
 * Assertions are about rows — which `vendor_contact`, `vendor_note` and `vendor_address` rows exist
 * afterwards and what their columns read. Seeing a flash is not evidence that anything was stored.
 *
 * The last test in the file is not about no-JS at all: it is the security boundary. A vendor contact
 * must not be able to authenticate, and that is asserted against the real login form rather than
 * inferred from the absence of a password column.
 */
final class AdminNoJsVendorMasterDataCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nojs-vendor-master-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** A vendor with nothing on it — no address, no contacts, no notes — which is what every existing vendor is. */
    private function seedVendor(FunctionalTester $I, string $name = 'No-JS Supply'): Vendor
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $vendor = (new Vendor())->setName($name . ' ' . uniqid());
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    /** @return list<VendorContact> */
    private function contactsOf(FunctionalTester $I, int $vendorId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $vendor = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $vendor);

        return array_values($vendor->getContacts()->toArray());
    }

    /** @param array<string, string> $params */
    private function post(FunctionalTester $I, string $uri, array $params): void
    {
        $I->sendFormPostRequest($uri, array_merge(['_token' => $I->csrfToken()], $params));
    }

    // -------------------------------------------------- the add-a-row loop, no JavaScript

    /**
     * The loop the Contacts panel exists for: fill the blank row at the bottom of the table, press
     * Add contact, and the page that comes back has the contact as a row AND a fresh blank row under
     * it. Repeat for as many people as the supplier has — an orders desk, an accounts contact, a rep
     * is three, which is #605's own example.
     *
     * Asserted after every hop, not just the first — without the fresh row this is a one-shot form
     * with extra steps.
     */
    /**
     * The vendor record's Contacts panel is now a read-only list plus a separate Add Contact page —
     * the customer record's own Users-card shape (#605's "records, never accounts" still holds, only
     * the page layout changed): no inline row-editing on the detail page any more, faithfully mirroring
     * `admin_company_user_create`.
     */
    public function addingAContactGoesThroughTheAddContactPageAndListsReadOnly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        // A detail page carries no scroll region — that class is for list screens only.
        $I->dontSeeElement('.table-scroll-region');
        $I->seeLink('Add Contact');

        $I->amOnPage($url . '/contacts');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form input[name="first_name"]');
        $I->seeElement('form input[name="email"]');

        $this->post($I, $url . '/contacts', [
            'first_name' => 'Priya',
            'last_name' => 'Raman',
            'job_title' => 'Orders desk',
            'email' => 'orders@nojs-supply.example',
            'phone' => '604-555-0101',
            'is_primary' => '1',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $contacts = $this->contactsOf($I, $vendorId);
        $I->assertCount(1, $contacts, 'Add contact did not append a vendor_contact row');
        $I->assertSame('Priya', $contacts[0]->getFirstName());
        $I->assertSame('Orders desk', $contacts[0]->getJobTitle());
        $I->assertSame('orders@nojs-supply.example', $contacts[0]->getEmail());
        $I->assertTrue($contacts[0]->isPrimary());

        // Back on the detail page: the contact is a read-only row, not an editable one.
        $I->amOnPage($url);
        $I->see('Priya Raman');
        $I->see('orders@nojs-supply.example');
        $I->see('Orders desk');
        $I->dontSeeElement('input[name="first_name"][value="Priya"]');

        // Second contact, same page: the accounts contact.
        $this->post($I, $url . '/contacts', [
            'first_name' => 'Dana',
            'last_name' => 'Okafor',
            'job_title' => 'Accounts payable',
            'email' => 'ap@nojs-supply.example',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $contacts = $this->contactsOf($I, $vendorId);
        $I->assertCount(2, $contacts, 'the Add Contact page did not take a second contact');

        $I->amOnPage($url);
        $I->see('Dana Okafor');
    }

    /**
     * Editing a contact in place, through its own row's form.
     *
     * Also pins the one-primary rule: ticking Primary on the second person must clear it on the
     * first, because "who does a purchase order go to" has to have exactly one answer.
     */
    public function editingAContactRowSavesItAndMovesThePrimaryFlag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/contacts', ['first_name' => 'Priya', 'email' => 'a@nojs.example', 'is_primary' => '1', 'status' => 'Active']);
        $this->post($I, $url . '/contacts', ['first_name' => 'Dana', 'email' => 'b@nojs.example', 'status' => 'Active']);

        $contacts = $this->contactsOf($I, $vendorId);
        $I->assertCount(2, $contacts);
        $priya = $contacts[0]->getFirstName() === 'Priya' ? $contacts[0] : $contacts[1];
        $dana = $contacts[0]->getFirstName() === 'Priya' ? $contacts[1] : $contacts[0];
        $I->assertTrue($priya->isPrimary());

        $this->post($I, $url . '/contacts/update', [
            'contact_id' => (string) $dana->getId(),
            'first_name' => 'Dana',
            'last_name' => 'Okafor',
            'email' => 'b@nojs.example',
            'job_title' => 'Accounts payable',
            'is_primary' => '1',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloadedDana = $em->find(VendorContact::class, (int) $dana->getId());
        $reloadedPriya = $em->find(VendorContact::class, (int) $priya->getId());

        $I->assertInstanceOf(VendorContact::class, $reloadedDana);
        $I->assertInstanceOf(VendorContact::class, $reloadedPriya);
        $I->assertSame('Okafor', $reloadedDana->getLastName());
        $I->assertTrue($reloadedDana->isPrimary(), 'vendor_contact.is_primary did not move to the edited row');
        $I->assertFalse($reloadedPriya->isPrimary(), 'two rows claim vendor_contact.is_primary at once');
    }

    /** A row with nothing in it at all is not a contact and must not become one. */
    public function submittingAnEmptyContactRowStoresNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $vendorId = (int) $vendor->getId();

        $this->post($I, '/admin/bundles/procurement/vendors/' . $vendorId . '/contacts', [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'job_title' => '',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertCount(0, $this->contactsOf($I, $vendorId), 'a blank row became a vendor_contact');
    }

    /**
     * The delete route itself is unchanged and still works — only the detail page's inline delete
     * button is gone now, along with the rest of the row-level controls (#605's Users-card shape).
     */
    public function deletingAContactRemovesItsRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/contacts', ['first_name' => 'Temp', 'email' => 'temp@nojs.example', 'status' => 'Active']);
        $contacts = $this->contactsOf($I, $vendorId);
        $I->assertCount(1, $contacts);
        $contactId = (int) $contacts[0]->getId();

        $I->amOnPage($url);
        $I->see('Temp');

        $this->post($I, $url . '/contacts/delete', ['contact_id' => (string) $contactId]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertCount(0, $this->contactsOf($I, $vendorId), 'the vendor_contact row is still there');
    }

    // ------------------------------------------------------------------------ notes (#605)

    /**
     * A note becomes a ROW with an author and a timestamp — and there is no second place for one.
     *
     * This test used to end by asserting that the legacy `vendor.notes` CLOB was "left exactly as it
     * was", because #605 added the threaded rows beside it and deliberately kept both. Item 43
     * reversed that: `Company` has no plain notes column at all, so "notes, just like the customer
     * side" means ONE list. The CLOB's contents were migrated into a `vendor_note` row by
     * `Version20260913090000` and the column was then dropped, so the old assertion no longer has a
     * column to read. What replaces it is the same claim in its current form — a note has exactly
     * one home — asserted against the table rather than against an entity getter that is gone.
     */
    public function addingANoteWritesARowAndThereIsNowhereElseForOneToGo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName('Noted Supply ' . uniqid());
        $em->persist($vendor);
        $em->flush();
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $I->amOnPage($url);
        // The customer record's own quick-add note control is a plain text input, not a textarea
        // (templates/admin/company/detail.html.twig's `.notes-form`) — the vendor page now matches it.
        $I->seeElement('#vendor-notes input[name="note"]');

        $this->post($I, $url . '/notes', ['note' => 'Short-shipped twice this quarter.']);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);

        $notes = array_values($reloaded->getNoteEntries()->toArray());
        $I->assertCount(1, $notes, 'no vendor_note row was written');
        $I->assertSame('Short-shipped twice this quarter.', $notes[0]->getText());
        $I->assertNotNull($notes[0]->getUserName(), 'vendor_note.user_name is empty — nobody knows who wrote it');

        // There is no second home for a note. Read from the table, because the mappings no longer
        // declare the column either way and asking them would be asking the code under test.
        $columns = array_column(
            $em->getConnection()->fetchAllAssociative('PRAGMA table_info(vendor)'),
            'name',
        );
        $I->assertNotContains('notes', $columns, 'vendor.notes came back — a note has two homes again');
        // Positive control: the reader does find this table's columns.
        $I->assertContains('name', $columns);

        $I->amOnPage($url);
        $I->see('Short-shipped twice this quarter.');
    }

    /**
     * Editing a note in place corrects the wording and leaves the date and the author alone —
     * `CompanyController::updateNote()`'s rule, mirrored.
     */
    public function editingANoteKeepsItsDateAndAuthor(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Edited Note Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/notes', ['note' => 'Shortshipped twice.']);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);
        $note = array_values($reloaded->getNoteEntries()->toArray())[0];
        $noteId = (int) $note->getId();
        $createdAt = $note->getCreatedAt();
        $author = $note->getUserName();

        $I->amOnPage($url);
        // Notes are edited through the customer record's own JS modal now (.js-note-edit reads
        // this entry's data-id/data-note); the entry itself carries no per-row form any more.
        $I->seeElement('.note-entry[data-id="' . $noteId . '"] .js-note-edit');

        $this->post($I, $url . '/notes/update', ['note_id' => (string) $noteId, 'note' => 'Short-shipped twice this quarter.']);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $edited = $em->find(VendorNote::class, $noteId);
        $I->assertInstanceOf(VendorNote::class, $edited);
        $I->assertSame('Short-shipped twice this quarter.', $edited->getText());
        $I->assertSame($createdAt->format('Y-m-d H:i:s'), $edited->getCreatedAt()->format('Y-m-d H:i:s'), 'editing a note moved its date');
        $I->assertSame($author, $edited->getUserName(), 'editing a note reassigned its author');
    }

    public function deletingANoteRemovesThatRowAndNotItsNeighbour(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Two Notes Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/notes', ['note' => 'First note.']);
        $this->post($I, $url . '/notes', ['note' => 'Second note.']);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);
        $notes = array_values($reloaded->getNoteEntries()->toArray());
        $I->assertCount(2, $notes);

        $doomed = null;
        $survivor = null;
        foreach ($notes as $note) {
            if ($note->getText() === 'First note.') {
                $doomed = $note;
            } else {
                $survivor = $note;
            }
        }
        $I->assertInstanceOf(VendorNote::class, $doomed);
        $I->assertInstanceOf(VendorNote::class, $survivor);
        $survivorId = (int) $survivor->getId();

        // Addressed by id, never by position — the bug class #358 removed from the sell side.
        $this->post($I, $url . '/notes/delete', ['note_id' => (string) $doomed->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertNull($em->find(VendorNote::class, (int) $doomed->getId()));
        $I->assertInstanceOf(VendorNote::class, $em->find(VendorNote::class, $survivorId), 'deleting one note took the other with it');
    }

    // ------------------------------------------------------------- addresses and purposes (#606)

    /**
     * An address saved with contact details and purposes writes all of them, and the purposes are
     * what the screen then shows.
     */
    public function savingAnAddressWritesItsContactFieldsAndPurposes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Purpose Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        // The address form is its own page now (admin_company_address_create's shape), not inline
        // on the detail page.
        $I->amOnPage($url . '/address');
        $I->seeElement('input[name="is_remit_to"][type="checkbox"]');
        $I->seeElement('input[name="email_primary"]');

        $this->post($I, $url . '/address', [
            'address_id' => '0',
            'label' => 'Factor',
            'company_name' => 'Northbridge Factoring Inc.',
            'first_name' => 'Dana',
            'last_name' => 'Okafor',
            'email_primary' => 'ar@northbridge.example',
            'phone_number' => '604-555-0143',
            'address_line_1' => '77 Finance Ave',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6B 1A1',
            'country' => 'CA',
            'is_remit_to' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);

        $addresses = array_values($reloaded->getAddresses()->toArray());
        $I->assertCount(1, $addresses);
        $address = $addresses[0];

        $I->assertSame('Northbridge Factoring Inc.', $address->getCompanyName());
        $I->assertSame('Dana Okafor', $address->getContactName());
        $I->assertSame('ar@northbridge.example', $address->getEmailPrimary());
        $I->assertTrue($address->isRemitTo(), 'vendor_address.is_remit_to was not set');
        $I->assertFalse($address->isOrderTo());
        // Codes, not names — the column is VARCHAR(8)/VARCHAR(2) and stays that way.
        $I->assertSame('BC', $address->getProvince());
        $I->assertSame('CA', $address->getCountry());

        // The only address a vendor has answers every purpose, assigned or not.
        $I->assertSame($address->getId(), $reloaded->getOrderToAddress()?->getId());

        // Addresses are shown on their own card-based Address Book page now (the customer
        // record's own address_book.html.twig shape), not on the detail page.
        $I->amOnPage($url . '/address-book');
        $I->see('Remit to');
        $I->see('Northbridge Factoring Inc.');
    }

    /**
     * The one-row-per-purpose rule, which is why these are flags on a row rather than a free-for-all:
     * claiming remit-to on a second address must clear it on the first.
     */
    public function claimingAPurposeMovesItOffTheOtherAddress(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Moving Purpose Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Head office', 'address_line_1' => '1 Main St',
            'city' => 'Vancouver', 'province' => 'BC', 'country' => 'CA',
            'is_default' => '1', 'is_remit_to' => '1',
        ]);
        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Factor', 'address_line_1' => '77 Finance Ave',
            'city' => 'Vancouver', 'province' => 'BC', 'country' => 'CA',
            'is_remit_to' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);

        $claiming = [];
        foreach ($reloaded->getAddresses() as $address) {
            if ($address->isRemitTo()) {
                $claiming[] = $address->getLabel();
            }
        }

        $I->assertSame(['Factor'], $claiming, 'more than one vendor_address row claims is_remit_to');
        $I->assertSame('Factor', $reloaded->getRemitToAddress()?->getLabel());
        // The head office keeps being the default and therefore still answers the other three.
        $I->assertSame('Head office', $reloaded->getOrderToAddress()?->getLabel());
    }

    public function deletingAnAddressRemovesTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Deletable Address Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Typo', 'address_line_1' => '1 Wrong St',
            'city' => 'Vancouver', 'country' => 'CA',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);
        $addresses = array_values($reloaded->getAddresses()->toArray());
        $I->assertCount(1, $addresses);
        $addressId = (int) $addresses[0]->getId();

        // The delete form lives on the Address Book page now, alongside the address it removes.
        $I->amOnPage($url . '/address-book');
        $I->seeElement('form#delete-address-' . $addressId . '[method="post"]');

        $this->post($I, $url . '/address/delete', ['address_id' => (string) $addressId]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertNull($em->find(VendorAddress::class, $addressId), 'the vendor_address row survived the delete');
    }

    /** `?address={id}` pre-fills the form, which is how a row is edited without JavaScript. */
    public function theEditLinkPrefillsTheAddressForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Editable Address Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Head office', 'address_line_1' => '1 Main St',
            'city' => 'Vancouver', 'province' => 'BC', 'country' => 'CA',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $reloaded);
        $addressId = (int) array_values($reloaded->getAddresses()->toArray())[0]->getId();

        // The address form is its own page now; `?address=` still pre-fills it, the same way
        // admin/company/address_form.html.twig's `?address=`-less separate update route does.
        $I->amOnPage($url . '/address?address=' . $addressId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="address_id"][value="' . $addressId . '"]');
        $I->seeElement('input[name="address_line_1"][value="1 Main St"]');
        $I->see('Edit Address');
    }

    /**
     * The vendor list finds a supplier by the name of the person you deal with.
     *
     * Most of the value of recording contacts is being able to search for them: nobody remembers
     * which of four similarly-named distributors employs Priya. The search is an EXISTS subquery
     * rather than a join precisely so that a vendor with three contacts appears ONCE — a join to a
     * one-to-many would multiply both the rows and the count above the table.
     */
    public function theVendorListFindsAVendorByItsContactAndListsItOnce(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Searchable Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;
        $surname = 'Ramanathan' . strtoupper(substr(uniqid(), -6));

        foreach (['Priya', 'Dana', 'Marc'] as $first) {
            $this->post($I, $url . '/contacts', [
                'first_name' => $first,
                'last_name' => $surname,
                'email' => strtolower($first) . '@searchable.example',
                'status' => 'Active',
            ]);
        }
        $I->assertCount(3, $this->contactsOf($I, $vendorId));

        // `filters[contact]`, not the old single `filters[q]` box: the vendor list was conformed to
        // the customer list's one-input-per-column filter row (#660), and contacts got their own
        // column. Same predicate, same EXISTS subquery, same guarantee that the vendor is listed
        // once — it is now reachable from the box sitting under the Primary Contact header.
        $I->amOnPage('/admin/bundles/procurement/vendors?filters[contact]=' . $surname);
        $I->seeResponseCodeIsSuccessful();
        $I->see($vendor->getName());
        $I->seeNumberOfElements('tbody tr.data-item-row', 1);
    }

    /**
     * A bill freezes the REMIT-TO address when it is entered (#606).
     *
     * The case that costs money: the remit-to is often a different legal entity from the vendor — a
     * factoring company, a lockbox, a parent's accounts department — and paying the address on the
     * purchase order is how the money goes to the wrong company. So the bill, which is the document
     * that leads to a payment, has to carry its own copy.
     */
    public function enteringABillFreezesTheRemitToAddress(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Factored Supply');
        $vendorId = (int) $vendor->getId();
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;

        // Head office is the default; the factor is the remit-to. Before purposes, a bill could only
        // have pointed at the head office.
        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Head office', 'address_line_1' => '1 Main St',
            'city' => 'Vancouver', 'province' => 'BC', 'country' => 'CA', 'is_default' => '1',
        ]);
        $this->post($I, $url . '/address', [
            'address_id' => '0', 'label' => 'Factor', 'company_name' => 'Northbridge Factoring Inc.',
            'address_line_1' => '77 Finance Ave', 'city' => 'Vancouver', 'province' => 'BC', 'country' => 'CA',
            'is_remit_to' => '1',
        ]);

        $this->post($I, '/admin/bundles/procurement/bills/save', [
            'id' => '0',
            'vendor_id' => (string) $vendorId,
            'vendor_invoice_no' => 'THEIR-INV-' . uniqid(),
            'document_date' => '2026-08-20',
            'tax' => '0.00',
            'lines' => [['name' => 'Widget', 'qty' => '1', 'unit_cost' => '10.0000']],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $bills = $em->getRepository(\ProcurementBundle\Entity\VendorBill::class)->findBy(['vendor' => $vendorId]);
        $I->assertCount(1, $bills, 'the bill was not created');

        $remitTo = (string) $bills[0]->getRemitToAddress();
        $I->assertStringContainsString('77 Finance Ave', $remitTo, 'vendor_bill.remit_to_address did not freeze the remit-to address');
        $I->assertStringContainsString('Northbridge Factoring Inc.', $remitTo, 'the factor\'s own entity name is missing from the frozen copy');
        $I->assertStringNotContainsString('1 Main St', $remitTo);
    }

    // -------------------------------------------------------- the boundary: no login, ever

    /**
     * A vendor contact CANNOT authenticate. This is #605's one explicit exclusion.
     *
     * Asserted against the real customer login form rather than inferred: even with an email that
     * exists in `vendor_contact`, there is no user to find, no credential to check and no session to
     * issue. Adding a contact must also not create a `customer_user` row as a side effect — which is
     * exactly what a well-meaning "let them see their POs" change would do.
     */
    public function aVendorContactCannotSignIn(FunctionalTester $I): void
    {
        // Deliberately NOT signed in as an admin and NOT on admin.localhost: this test is about the
        // public login form, and the contact is seeded straight into the table so that nothing about
        // the admin session can be mistaken for the reason the login fails.
        $em = $I->grabService('doctrine.orm.entity_manager');

        $email = 'no-login-' . uniqid() . '@supplier.example';
        $vendor = (new Vendor())->setName('No Login Supply ' . uniqid());
        $contact = (new VendorContact())
            ->setFirstName('Priya')
            ->setLastName('Raman')
            ->setJobTitle('Orders desk')
            ->setEmail($email);
        $vendor->addContact($contact);
        $em->persist($vendor);
        $em->persist($contact);
        $em->flush();

        // The row exists, and no account exists anywhere for the same address.
        $I->assertNotNull($contact->getId());
        $I->dontSeeInRepository(\App\Entity\CustomerUser::class, ['email' => $email]);
        $I->dontSeeInRepository(AdminUser::class, ['email' => $email]);

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => $email,
            '_password' => 'test-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Email or password is incorrect.');
        $I->dontSeeAuthentication();
    }

    /**
     * Adding a contact through the admin screen creates a `vendor_contact` row AND NOTHING ELSE.
     *
     * The companion to the test above: that one proves the address cannot log in, this one proves
     * nothing on the way in quietly minted an account for it. A "let them see their POs" change is
     * exactly what would break this, and it should have to break a test to do so.
     */
    public function addingAContactCreatesNoAccountAnywhere(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'No Account Supply');
        $vendorId = (int) $vendor->getId();
        $email = 'no-account-' . uniqid() . '@supplier.example';

        $this->post($I, '/admin/bundles/procurement/vendors/' . $vendorId . '/contacts', [
            'first_name' => 'Priya',
            'last_name' => 'Raman',
            'email' => $email,
            'job_title' => 'Orders desk',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $contacts = $this->contactsOf($I, $vendorId);
        $I->assertCount(1, $contacts);
        $I->assertSame($email, $contacts[0]->getEmail());

        $I->dontSeeInRepository(\App\Entity\CustomerUser::class, ['email' => $email]);
        $I->dontSeeInRepository(AdminUser::class, ['email' => $email]);
    }
}
