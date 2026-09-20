<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Service\DocumentActor;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Delivery instructions on a quote's address cards (#235).
 *
 * The order form has always rendered the field; the quote form never did. But both forms hand their
 * address card to the same AbstractAdminController::applyAddressEditsFromRequest(), which set all
 * fourteen fields unconditionally — so every quote save posted no delivery instructions and the
 * setter dutifully wrote null over whatever was there. Silent, and it travelled: conversion copies
 * the quote's frozen address into the order, so the packing slip and invoice lost it too.
 *
 * Two halves, and both matter. The controller now only applies fields the POST actually carried, so
 * a form that does not render a box cannot destroy it. The quote form now renders the box, so an
 * admin can read and edit what the customer wrote.
 */
final class QuoteDeliveryInstructionsCest
{
    private const INSTRUCTIONS = 'Back gate only; forklift on site until 3pm.';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('quote-delivery-instructions@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** A quote carrying its own shipping snapshot, with instructions already on it. */
    private function quoteWithShippingInstructions(FunctionalTester $I): Estimate
    {
        $company = (new Company())
            ->setName('Quote Delivery Instructions Co')
            ->setCode('QDI-' . uniqid());
        $I->haveInRepository($company);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QDI-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Widget')
                ->setSku('QDI-SKU-1')
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );

        $estimate->addressForWriting('shipping')
            ->setAddressLine1('4 Receiving Way')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V5K0A1')
            ->setDeliveryInstructions(self::INSTRUCTIONS);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function theQuoteFormRendersTheDeliveryInstructionsBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWithShippingInstructions($I);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Both cards, the same pair the order form renders.
        $I->seeElement('textarea[name="shipping_delivery_instructions"]');
        $I->seeElement('textarea[name="billing_delivery_instructions"]');
        // And what the customer wrote is actually in it, not just an empty box.
        $I->see(self::INSTRUCTIONS);
    }

    /**
     * The regression itself. The POST carries the shipping card — so the card IS being applied —
     * but omits the instructions field, exactly as the old template's submit did. Before the fix
     * that nulled the column while reporting "Estimate saved."
     */
    public function aSaveThatOmitsTheFieldLeavesTheStoredInstructionsAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWithShippingInstructions($I);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => 'Widget', 'qty' => '2', 'price' => '50.00'],
            ],
            'shipping_address_id' => '',
            'shipping_address_1' => '4 Receiving Way',
            'shipping_city' => 'Burnaby',
            'shipping_province' => 'BC',
            'shipping_country' => 'CA',
            'shipping_postal_code' => 'V5K0A1',
            // No shipping_delivery_instructions — the field the old form never rendered.
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $shipping = $saved->getShippingAddress();

        // The city change proves the card was applied rather than skipped wholesale, which would
        // pass the instructions assertion for the wrong reason.
        $I->assertSame('Burnaby', $shipping->getCity());
        $I->assertSame(self::INSTRUCTIONS, $shipping->getDeliveryInstructions());
    }

    /** Absent means unchanged; present-and-empty still means the admin cleared it on purpose. */
    public function anEmptySubmittedFieldStillClearsTheInstructions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWithShippingInstructions($I);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => 'Widget', 'qty' => '2', 'price' => '50.00'],
            ],
            'shipping_address_id' => '',
            'shipping_address_1' => '4 Receiving Way',
            'shipping_city' => 'Vancouver',
            'shipping_province' => 'BC',
            'shipping_country' => 'CA',
            'shipping_postal_code' => 'V5K0A1',
            'shipping_delivery_instructions' => '',
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());

        $I->assertNull($saved->getShippingAddress()->getDeliveryInstructions());
    }

    /** And a submitted value is written, so the new box is not decorative. */
    public function anAdminCanEditTheInstructionsFromTheQuoteForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWithShippingInstructions($I);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => 'Widget', 'qty' => '2', 'price' => '50.00'],
            ],
            'shipping_address_id' => '',
            'shipping_address_1' => '4 Receiving Way',
            'shipping_city' => 'Vancouver',
            'shipping_province' => 'BC',
            'shipping_country' => 'CA',
            'shipping_postal_code' => 'V5K0A1',
            'shipping_delivery_instructions' => 'Call ahead; gate code 4471.',
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());

        $I->assertSame('Call ahead; gate code 4471.', $saved->getShippingAddress()->getDeliveryInstructions());
    }

    /**
     * Owner-directed follow-up to #334/#302: the cap applies here too.
     *
     * This card is the one path that can put an unbounded value onto a document directly, without it
     * having come from a (now capped) CompanyAddress via copyFrom(). Leaving it out would make the
     * limit a property of the screen rather than of the field — an instruction that saves at 500 on
     * the address book and at any length here, on the same order, printed on the same packing slip.
     */
    public function theQuoteAddressCardCapsDeliveryInstructions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWithShippingInstructions($I);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => 'Widget', 'qty' => '2', 'price' => '50.00'],
            ],
            'shipping_address_id' => '',
            'shipping_address_1' => '4 Receiving Way',
            'shipping_city' => 'Vancouver',
            'shipping_province' => 'BC',
            'shipping_country' => 'CA',
            'shipping_postal_code' => 'V5K0A1',
            'shipping_delivery_instructions' => str_repeat('Q', 900),
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());

        $I->assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $saved->getShippingAddress()->getDeliveryInstructions())
        );
    }
}
