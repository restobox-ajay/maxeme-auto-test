<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Delivery instructions on the admin address form.
 *
 * Customers have always been able to set this on their own address form, but the admin form had no
 * such field, so a customer could write "back entrance, closed after 3pm" and nobody on the other
 * side would ever see it. These tests pin that it is now both visible and editable by an admin.
 */
final class AdminAddressDeliveryInstructionsCest
{
    private Company $company;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-delivery-instructions@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Delivery Instructions Co')
            ->setCode('DEL-' . uniqid());
        $I->haveInRepository($this->company);
    }

    public function theAddNewAddressFormOffersTheField(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/company/' . $this->company->getId() . '/address/create');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Delivery Instructions');
        $I->seeElement('textarea[name="delivery_instructions"]');
    }

    public function anAdminCanSaveDeliveryInstructions(FunctionalTester $I): void
    {
        // No CSRF token: these admin address routes do not have one (no field in the form, no
        // isCsrfTokenValid in the controller). That gap is real but belongs to the CSRF work, not
        // here — posting without one is simply what the route currently accepts.
        $I->sendAjaxPostRequest('/admin/company/' . $this->company->getId() . '/address/create', [
            '_token' => $I->csrfToken(),
            'address_line_1' => '1 Receiving Lane',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'delivery_instructions' => 'Back entrance, closed after 3pm.',
        ]);

        $I->seeInRepository(CompanyAddress::class, [
            'company' => $this->company,
            'deliveryInstructions' => 'Back entrance, closed after 3pm.',
        ]);
    }

    public function instructionsSetByACustomerAreVisibleToAnAdmin(FunctionalTester $I): void
    {
        // The case that was previously invisible: written on the customer side, read on the admin side.
        $address = (new CompanyAddress())
            ->setCompany($this->company)
            ->setLabel('Customer set')
            ->setAddressLine1('2 Loading Bay')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V5K0A1')
            ->setDeliveryInstructions('Ring the bell twice; forklift needed.');
        $I->haveInRepository($address);

        $I->amOnPage('/admin/company/' . $this->company->getId() . '/address/' . $address->getId() . '/update');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Ring the bell twice; forklift needed.');
    }

    public function savingAnAddressDoesNotWipeExistingInstructions(FunctionalTester $I): void
    {
        $address = (new CompanyAddress())
            ->setCompany($this->company)
            ->setLabel('Keep me')
            ->setAddressLine1('3 Dock Road')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V5K0A1')
            ->setDeliveryInstructions('Do not lose me.');
        $I->haveInRepository($address);

        $editUrl = '/admin/company/' . $this->company->getId() . '/address/' . $address->getId() . '/update';
        $I->amOnPage($editUrl);

        // Re-post the form as rendered, changing only the city.
        $I->sendAjaxPostRequest($editUrl, [
            '_token' => $I->csrfToken(),
            'address_line_1' => '3 Dock Road',
            'city' => 'Burnaby',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'delivery_instructions' => 'Do not lose me.',
        ]);

        $I->seeInRepository(CompanyAddress::class, [
            'company' => $this->company,
            'city' => 'Burnaby',
            'deliveryInstructions' => 'Do not lose me.',
        ]);
    }
}
