<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * create()'s "submit" branch used to write status = 'Submitted' unconditionally, even when the
 * admin priced every line plus shipping/tax right there on the create form — leaving a fully
 * priced brand-new quote with no way to reach 'Priced', the one status the customer's Accept
 * form (templates/customer/estimate/detail.html.twig) actually keys off. edit() already promotes
 * Submitted -> Priced once `isFullyPriced()` is true; this file pins the same promotion happening
 * in create()'s single Draft -> {Submitted|Priced} hop.
 *
 * Both cases read the `estimate.status` COLUMN through DBAL rather than an accessor — the same
 * discipline EstimateStatusSeamCest uses — and both check the real customer screen for the Accept
 * form rather than trusting the status alone, because the bug report was "no Accept button", not
 * "wrong status string".
 *
 * Both admin and customer fixtures (and their `amLoggedInAs()` calls) are built before the first
 * real HTTP request: the Codeception Doctrine module keeps ONE EntityManager for the whole test,
 * while a real request reboots the kernel's own — so a `haveInRepository()` after the admin POST
 * would hand it a $company the two EntityManagers no longer agree is managed. Logging in as both
 * admin ('_security_admin') and customer ('_security_main') ahead of time works because each
 * lands under its own key in the same session, and the admin/customer firewalls are chosen by
 * path (`^/admin`), not host.
 */
final class AdminEstimateCreateSubmitPricedCest
{
    private const REGION = 'Estimate Create Priced Region';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('est-create-priced-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function loginAsCustomer(FunctionalTester $I, Company $company): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('est-create-priced-cust-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Buyer')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    /** A company with an active fulfillment region and price list — create() refuses a quote without one (#238). */
    private function makeCompanyWithRegion(FunctionalTester $I): Company
    {
        $company = (new Company())->setName('Estimate Create Priced Co')->setCode('ESTCP-' . uniqid());
        $I->haveInRepository($company);

        $priceList = (new PriceList())->setName('Estimate Create Priced List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $region = (new FulfillmentRegion())->setName(self::REGION)->setStatus('Active');
        $I->haveInRepository($region);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('ESTCP-SKU-' . uniqid())
            ->setName('Estimate Create Priced Product')
            ->setUnit('EA')
            ->setWeight('5.000')
            ->setSalesTaxCode('G')
            ->setCostPrice('30.55')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /** The status COLUMN, read straight out of SQL — not getStatus(), for the same reason EstimateStatusSeamCest does this. */
    private function statusColumn(FunctionalTester $I, int $estimateId): string
    {
        $connection = $I->grabService(Connection::class);

        return (string) $connection->fetchOne('SELECT status FROM estimate WHERE id = ?', [$estimateId]);
    }

    /**
     * The new estimate's id, read straight off the `estimate` table by DBAL — deliberately not
     * through the EntityManager, whose identity map the admin POST's kernel reboot has already
     * moved on from (see the class doc comment).
     */
    private function latestEstimateId(FunctionalTester $I, Company $company): int
    {
        $connection = $I->grabService(Connection::class);
        $id = $connection->fetchOne('SELECT id FROM estimate WHERE company_id = ? ORDER BY id DESC LIMIT 1', [$company->getId()]);
        $I->assertNotFalse($id, 'the create POST must have persisted an estimate');

        return (int) $id;
    }

    /**
     * The headline fix: a brand-new quote, priced in full on the create form (every line plus a
     * resolved shipping and tax charge) and saved with "Save & Make Visible to Customer", lands on
     * 'Priced' rather than 'Submitted' — and the customer's Accept form is actually on the page.
     */
    public function submittingAFullyPricedNewQuoteLandsOnPricedAndShowsTheAcceptButton(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithRegion($I);
        $product = $this->makeProduct($I);
        $this->loginAsCustomer($I, $company);
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'save_mode' => 'submit',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping'],
                ['label' => 'Custom GST', 'amount' => '4.00', 'type' => 'tax'],
            ],
        ]);

        $estimateId = $this->latestEstimateId($I, $company);
        $I->assertSame('Priced', $this->statusColumn($I, $estimateId), 'estimate.status');

        $I->deleteHeader('Host');
        $I->amOnPage('/estimates/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form[action="/estimates/' . $estimateId . '/accept"] button[type="submit"]');
    }

    /**
     * The negative control: a quote submitted with its lines fully priced but no shipping resolved
     * (no charge UI posted at all, so shipping/tax/total stay unset) is NOT fully priced, so it
     * still lands on 'Submitted' exactly as before this fix — and the customer sees no Accept form,
     * because there is genuinely nothing yet for them to accept.
     */
    public function submittingAnIncompletelyPricedNewQuoteStaysOnSubmittedWithNoAcceptButton(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithRegion($I);
        $product = $this->makeProduct($I);
        $this->loginAsCustomer($I, $company);
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'save_mode' => 'submit',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
        ]);

        $estimateId = $this->latestEstimateId($I, $company);
        $I->assertSame('Submitted', $this->statusColumn($I, $estimateId), 'estimate.status');

        $I->deleteHeader('Host');
        $I->amOnPage('/estimates/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('form[action="/estimates/' . $estimateId . '/accept"]');
    }
}
