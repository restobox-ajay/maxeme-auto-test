<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\AdminUser;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The quote-side mirror of DocumentCompanySnapshotCest: renames the customer after the quote exists,
 * then loads every quote screen that prints their name, over real HTTP.
 *
 * Quotes inherit the snapshot from AbstractSalesDocument rather than implementing their own, so the
 * mechanism is already covered at the entity level (tests/Entity/CompanyIdentitySnapshotTest.php) and
 * the quote-to-order handover is covered in EstimateConversionServiceTest. What was missing is the
 * template cover the order documents have: nothing stopped a quote template being "tidied up" back to
 * estimate.company.name, which no existing test would notice — the same Twig-level regression the
 * order suite exists to catch.
 *
 * The quotes here deliberately carry no addresses. An address snapshot, where one exists, takes
 * precedence and would mask the company snapshot underneath it; with no address the templates fall
 * through to companyIdentity, which is the expression under test.
 */
final class QuoteCompanySnapshotCest
{
    private const OLD_NAME = 'Northwind Quoting Snapshot Test';
    private const NEW_NAME = 'Umbrella Holdings Snapshot Test';
    private const OLD_EMAIL = 'ap-old@northwind-quote.example';
    private const NEW_EMAIL = 'ap-new@umbrella-quote.example';
    private const OLD_PHONE = '+1 250 555 0142';
    private const NEW_PHONE = '+1 604 555 7788';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('quote-company-snapshot@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function loginAsCustomerOf(FunctionalTester $I, Company $company): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('qcs-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    /** Creates the quote, then renames the company out from under it. */
    private function quoteWhoseCustomerWasRenamed(FunctionalTester $I): Estimate
    {
        $company = (new Company())
            ->setName(self::OLD_NAME)
            ->setCode('QSNAP-' . uniqid())
            ->setPrimaryEmail(self::OLD_EMAIL)
            ->setPhoneNumber(self::OLD_PHONE);
        $I->haveInRepository($company);

        // Priced, not Draft: Draft quotes are admin-only, and the customer screens are half of this.
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QSNAP-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('100.00')
            // Shipping is a row; the header figure is derived from it.
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G',
                'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setTax('5.00')
            ->setTotal('115.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Snapshot Test Widget')
                ->setSku('QSNAP-SKU-1')
                ->setQuantity('2.00')
                ->setCost('40.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );
        $I->haveInRepository($estimate);

        // The rebrand happens after the quote exists, which is the whole point.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company->setName(self::NEW_NAME)->setPrimaryEmail(self::NEW_EMAIL)->setPhoneNumber(self::NEW_PHONE);
        $entityManager->flush();

        return $estimate;
    }

    public function theAdminQuoteDetailPageNamesTheCustomerAsTheyWereQuoted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWhoseCustomerWasRenamed($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see(self::OLD_NAME);
        $I->dontSee(self::NEW_NAME);

        // Detail prints the company's email and phone too, which drift the same way.
        $I->see(self::OLD_EMAIL);
        $I->dontSee(self::NEW_EMAIL);
        $I->see(self::OLD_PHONE);
        $I->dontSee(self::NEW_PHONE);

        // The link to the account is still the live one — an id cannot drift, and the admin wants
        // today's record.
        $I->seeElement('a[href*="/admin/company/"]');
    }

    /** The quote document itself — rendered as HTML here; ?download=1 is the same template via Dompdf. */
    public function theAdminQuoteDocumentNamesTheCustomerAsTheyWereQuoted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWhoseCustomerWasRenamed($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see(self::OLD_NAME);
        $I->dontSee(self::NEW_NAME);
    }

    /**
     * The customer's own view of the quote. Their quote PDF (/estimates/{id}/quote) reads the same
     * snapshot fields but is returned as a Dompdf binary, so its text cannot be asserted over HTTP —
     * it is the one quote surface left without template cover.
     *
     * Asserted against the quote card rather than the whole page: the portal header greets the signed-in
     * user with their company's *live* name, which is correct — that is who they are now, not a record
     * of who was quoted. A page-wide dontSee() would fail on the chrome and hide what is under test.
     */
    public function theCustomerQuoteDetailPageNamesTheCompanyAsItWasQuoted(FunctionalTester $I): void
    {
        $estimate = $this->quoteWhoseCustomerWasRenamed($I);
        $this->loginAsCustomerOf($I, $estimate->getCompany());

        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see(self::OLD_NAME, '.customer-view-order-info');
        $I->dontSee(self::NEW_NAME, '.customer-view-order-info');
        $I->see(self::OLD_EMAIL, '.customer-view-order-info');
        $I->dontSee(self::NEW_EMAIL, '.customer-view-order-info');
        $I->see(self::OLD_PHONE, '.customer-view-order-info');
        $I->dontSee(self::NEW_PHONE, '.customer-view-order-info');
    }

    /**
     * The list screen is not a document, but it is where a rename is most visible: every quote the
     * customer ever had, side by side. It reads companyIdentity for the same reason.
     */
    public function theAdminQuoteListNamesTheCustomerAsTheyWereQuoted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->quoteWhoseCustomerWasRenamed($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate?filters[documentNumber]=' . $estimate->getDocumentNumber());
        $I->seeResponseCodeIsSuccessful();

        $I->see($estimate->getDocumentNumber());
        $I->see(self::OLD_NAME);
        $I->dontSee(self::NEW_NAME);
    }
}
