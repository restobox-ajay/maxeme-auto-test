<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The vendor's card-based Address Book page (VendorController::addressBook()) — the buy-side
 * counterpart of `admin/company/address_book.html.twig`, which has no Cest of its own to copy from
 * (checked: neither `defaultBilling`/`defaultShipping` nor `_address_card_body.html.twig` appear in
 * any test file on this side of the app). Written fresh, in this suite's own conventions, rather
 * than adjusted from a sell-side test that does not exist.
 *
 * What is fidelity-tested here and nowhere else: the FOUR default-purpose cards (Order To/Ship
 * From/Remit To/Return To) and the fallback-to-default rule they share with every document that
 * freezes one of these onto itself (`Vendor::addressFor()`) — company has only two such cards
 * (Billing/Shipping) and no fallback concept, so this is the one place the vendor page's shape
 * genuinely earns its own coverage rather than mirroring the customer one.
 */
final class AdminVendorAddressBookCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-address-book-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function seedVendor(FunctionalTester $I, string $name): Vendor
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $vendor = (new Vendor())->setName($name . ' ' . uniqid());
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    /** @param array<string, string> $params */
    private function postAddress(FunctionalTester $I, int $vendorId, array $params): void
    {
        $I->sendFormPostRequest(
            '/admin/bundles/procurement/vendors/' . $vendorId . '/address',
            array_merge(['_token' => $I->csrfToken(), 'address_id' => '0'], $params),
        );
    }

    private function reloadVendor(FunctionalTester $I, int $vendorId): Vendor
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $vendor = $em->find(Vendor::class, $vendorId);
        $I->assertInstanceOf(Vendor::class, $vendor);

        return $vendor;
    }

    /** The text inside one default-purpose card, found by its own "Default X" heading. */
    private function cardText(FunctionalTester $I, string $heading): string
    {
        return $I->grabTextFrom('//h2[contains(text(), "' . $heading . '")]/ancestor::article');
    }

    /**
     * A vendor with no addresses at all shows every default card empty, with its own Add Address
     * link, and the all-addresses list states there is nothing rather than rendering an empty table.
     */
    public function aVendorWithNoAddressesShowsFourEmptyDefaultCards(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Empty Book Supply');
        $url = '/admin/bundles/procurement/vendors/' . $vendor->getId() . '/address-book';

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        foreach (['Order To', 'Ship From', 'Remit To', 'Return To'] as $label) {
            $I->see('Default ' . $label);
        }
        $I->seeNumberOfElements('.address-book-grid .address-book-card', 4);
        $I->see('No address selected yet.');
        $I->see('No addresses found.');
        $I->dontSeeElement('.address-list-card');
    }

    /**
     * One address wearing every purpose (the common small-supplier case) answers all four cards —
     * the same fallback `Vendor::addressFor()` gives every document, read here instead of re-derived.
     */
    public function oneDefaultAddressAnswersAllFourCards(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Single Address Supply');
        $vendorId = (int) $vendor->getId();

        $this->postAddress($I, $vendorId, [
            'label' => 'Head Office',
            'company_name' => 'Single Address Depot',
            'address_line_1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'is_default' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address-book');
        $I->seeResponseCodeIsSuccessful();
        $I->seeNumberOfElements('.address-book-grid .address-book-card', 4);
        // Every one of the four cards resolves to the same single address, by fallback.
        foreach (['Order To', 'Ship From', 'Remit To', 'Return To'] as $label) {
            $I->assertStringContainsString(
                'Single Address Depot',
                $this->cardText($I, 'Default ' . $label),
                'the one default address must answer the ' . $label . ' card too, not just the purposes it explicitly claims',
            );
        }
        $I->dontSee('No address selected yet.');
        $I->seeElement('.address-list-card');
    }

    /**
     * A second address that explicitly claims Remit To moves that ONE card off the default, while
     * the other three keep showing the default — the one-row-per-purpose rule
     * (AdminNoJsVendorMasterDataCest::claimingAPurposeMovesItOffTheOtherAddress), now asserted
     * against what four separate cards each show rather than a single table's Purposes column.
     */
    public function aClaimedPurposeMovesOnlyItsOwnCardOffTheDefault(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Two Address Supply');
        $vendorId = (int) $vendor->getId();

        $this->postAddress($I, $vendorId, [
            'label' => 'Head Office',
            'company_name' => 'Two Address Depot',
            'address_line_1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'is_default' => '1',
        ]);
        $this->postAddress($I, $vendorId, [
            'label' => 'Factor',
            'company_name' => 'Northbridge Factoring Inc.',
            'address_line_1' => '77 Finance Ave',
            'city' => 'Toronto',
            'province' => 'ON',
            'country' => 'CA',
            'is_remit_to' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address-book');
        $I->seeResponseCodeIsSuccessful();

        $I->assertStringContainsString('Northbridge Factoring Inc.', $this->cardText($I, 'Default Remit To'), 'Remit To must show the address that claimed it');
        $I->assertStringNotContainsString('Two Address Depot', $this->cardText($I, 'Default Remit To'), 'the default must not still be shown once another address claims the purpose');
        $I->assertStringContainsString('Two Address Depot', $this->cardText($I, 'Default Order To'), 'unclaimed purposes still fall back to the default');
        $I->assertStringContainsString('Two Address Depot', $this->cardText($I, 'Default Ship From'));
        $I->assertStringContainsString('Two Address Depot', $this->cardText($I, 'Default Return To'));

        // And both addresses appear once each in the full list, by their own company name (the
        // card body shows companyName/contactName/street — never the address book's own `label`,
        // matching the customer card body's own omission of it).
        $I->seeNumberOfElements('.address-list-card', 2);
        $I->see('Two Address Depot');
        $I->see('Northbridge Factoring Inc.');
        $I->see('1 Main St');
        $I->see('77 Finance Ave');
    }

    /**
     * Editing an address from its card's Edit link — through the separate Address form page, the
     * same round trip AdminNoJsVendorMasterDataCest::theEditLinkPrefillsTheAddressForm already
     * covers for the form itself — lands back on the Address Book page showing the new values.
     */
    public function editingAnAddressFromItsCardUpdatesTheBook(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Editable Book Supply');
        $vendorId = (int) $vendor->getId();

        $this->postAddress($I, $vendorId, [
            'label' => 'Head Office',
            'company_name' => 'Original Name Inc.',
            'address_line_1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'is_default' => '1',
        ]);

        $vendor = $this->reloadVendor($I, $vendorId);
        $addressId = (int) $vendor->getAddresses()->first()->getId();

        // The card's own Edit link, followed for real.
        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address-book');
        $I->seeElement('a[href="/admin/bundles/procurement/vendors/' . $vendorId . '/address?address=' . $addressId . '"]');

        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address?address=' . $addressId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="address_id"][value="' . $addressId . '"]');
        $I->seeInField('company_name', 'Original Name Inc.');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/' . $vendorId . '/address', [
            '_token' => $I->csrfToken(),
            'address_id' => (string) $addressId,
            'label' => 'Head Office',
            'company_name' => 'Renamed Depot Ltd.',
            'address_line_1' => '2 Second St',
            'city' => 'Burnaby',
            'province' => 'BC',
            'country' => 'CA',
            'is_default' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address-book');
        $I->see('Renamed Depot Ltd.');
        $I->see('2 Second St');
        $I->dontSee('Original Name Inc.');
    }

    /**
     * #778: email_primary/email_secondary took whatever string was typed, unvalidated — unlike
     * VendorContact's own email (addContact() below), which has always been checked with
     * filter_var()/FILTER_VALIDATE_EMAIL before anything is saved. Same check, same refusal
     * message, now on the address form too.
     */
    public function aMalformedEmailRefusesTheSaveAndKeepsTheOldAddress(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Email Guard Supply');
        $vendorId = (int) $vendor->getId();

        $this->postAddress($I, $vendorId, [
            'address_line_1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'email_primary' => 'valid@example.test',
        ]);
        $vendor = $this->reloadVendor($I, $vendorId);
        $I->assertCount(1, $vendor->getAddresses(), 'guard: the valid control address saved');
        $addressId = (int) $vendor->getAddresses()->first()->getId();

        $this->postAddress($I, $vendorId, [
            'address_id' => (string) $addressId,
            'address_line_1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'email_primary' => 'not-an-email',
        ]);

        $vendor = $this->reloadVendor($I, $vendorId);
        $I->assertCount(1, $vendor->getAddresses(), 'the malformed save added nothing and changed nothing');
        $I->assertSame('valid@example.test', $vendor->getAddresses()->first()->getEmailPrimary(), 'the refused edit left the original value in place');
    }

    /**
     * Deleting from the book removes exactly that address's card and no other — the same POST
     * `AdminNoJsVendorMasterDataCest::deletingAnAddressRemovesTheRow` already exercises, now driven
     * from the page that actually renders the delete form (the flat table it used to live in is
     * gone from the detail page).
     */
    public function deletingFromTheBookRemovesOnlyThatCard(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I, 'Deletable Book Supply');
        $vendorId = (int) $vendor->getId();

        $this->postAddress($I, $vendorId, ['label' => 'Keeper', 'address_line_1' => '1 Keep St', 'city' => 'Vancouver', 'country' => 'CA']);
        $this->postAddress($I, $vendorId, ['label' => 'Doomed', 'address_line_1' => '2 Gone St', 'city' => 'Vancouver', 'country' => 'CA']);

        $vendor = $this->reloadVendor($I, $vendorId);
        $addresses = $vendor->getAddresses();
        $doomed = null;
        foreach ($addresses as $address) {
            if ($address->getLabel() === 'Doomed') {
                $doomed = $address;
            }
        }
        $I->assertInstanceOf(VendorAddress::class, $doomed);
        $doomedId = (int) $doomed->getId();

        $url = '/admin/bundles/procurement/vendors/' . $vendorId . '/address-book';
        $I->amOnPage($url);
        $I->seeElement('form#delete-address-' . $doomedId . '[method="post"]');

        $I->sendFormPostRequest(
            '/admin/bundles/procurement/vendors/' . $vendorId . '/address/delete',
            ['_token' => $I->csrfToken(), 'address_id' => (string) $doomedId],
        );
        $I->seeResponseCodeIsSuccessful();

        // By street, not by label: the card body never shows the address book's own `label` field
        // (matching the customer card body's own omission of it), only company/contact/address.
        $I->amOnPage($url);
        $I->see('1 Keep St');
        $I->dontSee('2 Gone St');
        $I->seeNumberOfElements('.address-list-card', 1);
    }
}
