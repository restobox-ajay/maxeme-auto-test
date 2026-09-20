<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * One address book, two parties, one vocabulary (#635) — driven through the real screens.
 *
 * #605 gave the buy side a parallel spelling for concepts `company_address` already named:
 * `address_1` for `address_line1`, `contact_phone` for `phone`. #635 collapsed the two into
 * `App\Entity\AbstractPartyAddress`, which took the CUSTOMER side's names so that core's schema did
 * not have to move.
 *
 * A mapping refactor is exactly the kind of change that looks right in an entity and stores nothing,
 * or stores it in the wrong column, so nothing here asserts on classes. Every test below drives the
 * screen an admin actually uses — `/admin/company/{id}/address/create` and
 * `/admin/bundles/procurement/vendors/{id}/address` — with a plain form POST, and then reads the
 * COLUMNS back out of SQLite by name through the connection, not through the getters that would
 * happily agree with a mapping that is wrong in the same direction twice.
 *
 * Each test also seeds a SECOND address and asserts, cell by cell, that it did not move. A rename
 * migration that quietly rewrote or blanked a neighbouring row would pass every assertion about the
 * row that was just saved.
 *
 * The POSTs go through `sendFormPostRequest()` — no `X-Requested-With` header — because the stated
 * baseline is that this application works with JavaScript off. (`submitForm()` cannot be used on an
 * admin screen: the crawler resolves the action against the `admin.localhost` Host header and the
 * module then refuses it as an external URL.)
 */
final class PartyAddressBooksShareOneVocabularyCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('party-address-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * One row of a table, keyed by its real column names, straight out of SQLite.
     *
     * @return array<string, mixed>
     */
    private function row(FunctionalTester $I, string $table, int $id): array
    {
        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
        $I->assertIsArray($row, $table . '.id=' . $id . ' should exist');

        return $row;
    }

    // -------------------------------------------------------------- the customer side

    /**
     * `company_address` is the side that did NOT move: #635 made the shared parent adopt its column
     * names precisely so this table would need no migration. So this test is the regression guard on
     * "core's schema did not change" expressed as behaviour — the admin form still posts
     * `address_line_1` / `phone_number` and the values still land in `company_address.address_line1`
     * and `company_address.phone`.
     */
    public function savingACustomerAddressStillWritesTheCoreColumns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Party Vocab Customer Co')
            ->setCode('PVC-' . uniqid());
        $I->haveInRepository($company);

        // The neighbour: written directly, never posted to, and asserted untouched at the end.
        $untouched = (new CompanyAddress())
            ->setLabel('Untouched billing')
            ->setAddressLine1('9 Do Not Touch Road')
            ->setAddressLine2('Suite 9')
            ->setCity('Victoria')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V8W1A1')
            ->setPhone('250-555-0199');
        $untouched->setCompany($company);
        $I->haveInRepository($untouched);
        $untouchedId = (int) $untouched->getId();
        $untouchedBefore = $this->row($I, 'company_address', $untouchedId);

        $I->amOnPage('/admin/company/' . $company->getId() . '/address/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="address_line_1"]');
        $I->seeElement('input[name="phone_number"]');

        $I->sendFormPostRequest('/admin/company/' . $company->getId() . '/address/create', [
            '_token' => $I->csrfToken(),
            'company_name' => 'Party Vocab Customer Address',
            'first_name' => 'Rosa',
            'last_name' => 'Nkemelu',
            'email' => 'ap@party-vocab-customer.test',
            'address_line_1' => '400 Receiving Way',
            'address_line_2' => 'Dock 4',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'phone_number' => '604-555-0177',
            'fax_number' => '604-555-0178',
            'delivery_instructions' => 'Ring the bell at dock 4.',
        ]);

        $saved = $I->grabEntityFromRepository(CompanyAddress::class, [
            'companyName' => 'Party Vocab Customer Address',
        ]);
        $row = $this->row($I, 'company_address', (int) $saved->getId());

        // The columns, by the names the schema actually uses.
        $I->assertSame('400 Receiving Way', $row['address_line1']);
        $I->assertSame('Dock 4', $row['address_line2']);
        $I->assertSame('604-555-0177', $row['phone']);
        $I->assertSame('604-555-0178', $row['fax']);
        $I->assertSame('Rosa', $row['first_name']);
        $I->assertSame('Nkemelu', $row['last_name']);
        $I->assertSame('Vancouver', $row['city']);
        $I->assertSame('BC', $row['province']);
        $I->assertSame('V5K0A1', $row['postal_code']);
        $I->assertSame('ap@party-vocab-customer.test', $row['email_primary']);
        $I->assertSame('Ring the bell at dock 4.', $row['delivery_instructions']);

        // The parallel vocabulary must not have followed the refactor onto this table.
        $I->assertArrayNotHasKey('address_1', $row);
        $I->assertArrayNotHasKey('contact_phone', $row);

        // The sell side's own flags stayed where they are, on the child.
        $I->assertArrayHasKey('is_default_billing', $row);
        $I->assertArrayHasKey('is_default_shipping', $row);

        $I->assertSame(
            $untouchedBefore,
            $this->row($I, 'company_address', $untouchedId),
            'Saving one company_address row must not touch another.'
        );
    }

    // -------------------------------------------------------------- the vendor side

    /**
     * The side that did all the moving. Same screen shape, same field names, same columns —
     * `vendor_address.address_line1`, `.address_line2` and `.phone`, which until #635 were
     * `address_1`, `address_2` and `contact_phone`.
     *
     * The neighbour here is a second vendor address wearing a purpose flag, which is the case the
     * save path can get wrong on its own: VendorController clears a claimed purpose off every OTHER
     * row when a new row claims it, and that clearing is allowed to touch exactly one column.
     */
    public function savingAVendorAddressWritesTheSameColumnsAsTheCustomerSide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName('Party Vocab Supply ' . uniqid());
        $em->persist($vendor);

        // The neighbour: a remit-to nobody is about to edit.
        $untouched = (new VendorAddress())
            ->setLabel('Untouched lockbox')
            ->setAddressLine1('77 Finance Ave')
            ->setAddressLine2('PO Box 12')
            ->setCity('Toronto')
            ->setProvince('ON')
            ->setCountry('CA')
            ->setPostalCode('M5H2N2')
            ->setPhone('416-555-0122')
            ->setIsRemitTo(true);
        $untouched->setVendor($vendor);
        $vendor->addAddress($untouched);
        $em->persist($untouched);
        $em->flush();

        $vendorId = (int) $vendor->getId();
        $untouchedId = (int) $untouched->getId();
        $untouchedBefore = $this->row($I, 'vendor_address', $untouchedId);

        $url = '/admin/bundles/procurement/vendors/' . $vendorId;
        // The address form is its own page now (admin_company_address_create's shape).
        $I->amOnPage($url . '/address');
        $I->seeResponseCodeIsSuccessful();
        // The vendor form now posts the same field names the admin company-address form posts.
        $I->seeElement('input[name="address_line_1"]');
        $I->seeElement('input[name="address_line_2"]');
        $I->seeElement('input[name="phone_number"]');

        $I->sendFormPostRequest($url . '/address', [
            '_token' => $I->csrfToken(),
            'address_id' => '0',
            'label' => 'Party Vocab Ship From',
            'company_name' => 'Party Vocab Supply Depot',
            'first_name' => 'Ines',
            'last_name' => 'Okonkwo',
            'email_primary' => 'depot@party-vocab-supply.test',
            'address_line_1' => '900 Dock Road',
            'address_line_2' => 'Bay 7',
            'city' => 'Delta',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V4G1A1',
            'phone_number' => '604-555-0143',
            'fax' => '604-555-0144',
            'delivery_instructions' => 'Gate code 4412.',
            'is_ship_from' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $saved = $I->grabEntityFromRepository(VendorAddress::class, ['label' => 'Party Vocab Ship From']);
        $row = $this->row($I, 'vendor_address', (int) $saved->getId());

        $I->assertSame('900 Dock Road', $row['address_line1']);
        $I->assertSame('Bay 7', $row['address_line2']);
        $I->assertSame('604-555-0143', $row['phone']);
        $I->assertSame('604-555-0144', $row['fax']);
        $I->assertSame('Ines', $row['first_name']);
        $I->assertSame('Okonkwo', $row['last_name']);
        $I->assertSame('Delta', $row['city']);
        $I->assertSame('BC', $row['province']);
        $I->assertSame('V4G1A1', $row['postal_code']);
        $I->assertSame('CA', $row['country']);
        $I->assertSame('depot@party-vocab-supply.test', $row['email_primary']);
        $I->assertSame('Gate code 4412.', $row['delivery_instructions']);

        // The old spelling is gone from the table, not merely unused by the entity.
        $I->assertArrayNotHasKey('address_1', $row);
        $I->assertArrayNotHasKey('address_2', $row);
        $I->assertArrayNotHasKey('contact_phone', $row);

        // The buy side's own flags stayed on the child, where a purchase order asks for them.
        $I->assertSame(1, (int) $row['is_ship_from']);
        $I->assertSame(0, (int) $row['is_remit_to']);
        $I->assertArrayNotHasKey('is_default_billing', $row);

        // The lockbox kept every cell, including the remit-to nobody claimed.
        $I->assertSame(
            $untouchedBefore,
            $this->row($I, 'vendor_address', $untouchedId),
            'Saving one vendor_address row must not touch another.'
        );
    }

    /**
     * The point of the whole exercise, asserted where it is observable: the same question, asked of
     * a customer address and a supplier address, is spelled the same way.
     *
     * Both rows are read back through `AbstractPartyAddress`'s accessors — one variable, holding
     * either kind — which is only possible because they are now one vocabulary.
     */
    public function bothBooksAnswerTheSameAccessorsAfterASave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('Party Vocab Shared Co')
            ->setCode('PVS-' . uniqid());
        $I->haveInRepository($company);

        $I->sendFormPostRequest('/admin/company/' . $company->getId() . '/address/create', [
            '_token' => $I->csrfToken(),
            'company_name' => 'Shared Vocab Customer Address',
            'address_line_1' => '12 Shared Street',
            'address_line_2' => 'Unit 3',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'phone_number' => '604-555-0000',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName('Party Vocab Shared Supply ' . uniqid());
        $em->persist($vendor);
        $em->flush();

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/' . $vendor->getId() . '/address', [
            '_token' => $I->csrfToken(),
            'address_id' => '0',
            'label' => 'Shared Vocab Vendor Address',
            'address_line_1' => '12 Shared Street',
            'address_line_2' => 'Unit 3',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'phone_number' => '604-555-0000',
        ]);

        $addresses = [
            $I->grabEntityFromRepository(CompanyAddress::class, ['companyName' => 'Shared Vocab Customer Address']),
            $I->grabEntityFromRepository(VendorAddress::class, ['label' => 'Shared Vocab Vendor Address']),
        ];

        foreach ($addresses as $address) {
            $I->assertInstanceOf(\App\Entity\AbstractPartyAddress::class, $address);
            $I->assertSame('12 Shared Street', $address->getAddressLine1());
            $I->assertSame('Unit 3', $address->getAddressLine2());
            $I->assertSame('Vancouver', $address->getCity());
            $I->assertSame('BC', $address->getProvince());
            $I->assertSame('CA', $address->getCountry());
            $I->assertSame('V5K0A1', $address->getPostalCode());
            $I->assertSame('604-555-0000', $address->getPhone());
        }
    }
}
