<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Service\CompanyCreditExposureCalculator;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Customer credit limits (#724): AR exposure calculation, and the admin surfaces that show it.
 *
 * The blocking hold at order placement is intentionally out of scope for this pass — see the
 * commit this test landed in. What is covered here is the part that has to be correct regardless
 * of how the hold eventually gates a save: `Company.creditLimit` persists, and
 * `CompanyCreditExposureCalculator` counts the right invoices.
 */
final class CompanyCreditLimitCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-credit-limit@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function company(FunctionalTester $I, string $prefix): Company
    {
        $company = (new Company())
            ->setName('Credit Limit Co')
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('buyer@credit-limit.example');
        $I->haveInRepository($company);

        return $company;
    }

    /** @param 'Draft'|'Pending'|'Processing'|'Completed'|'Cancelled' $status */
    private function invoiceFor(FunctionalTester $I, Company $company, string $prefix, string $total, string $status, ?string $paid = null): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($prefix . '-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $invoice->addLine((new InvoiceLine())->setName('Widget')->setQuantity('1.00')->setPrice($total)->setSubtotal($total));
        $em->persist($invoice);

        if ($status !== 'Draft') {
            $invoice->issue(DocumentActor::system());
        }
        if ($status === 'Processing' || $status === 'Completed') {
            $invoice->startProcessing(DocumentActor::system());
        }
        if ($status === 'Completed') {
            $invoice->setStatus('Completed', DocumentActor::system());
        }
        if ($status === 'Cancelled') {
            $invoice->setStatus('Cancelled', DocumentActor::system());
        }

        if ($paid !== null) {
            $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($paid);
            $invoice->recordPayment(DocumentActor::system(), $payment);
            $em->persist($payment);
        }

        $em->flush();

        return $invoice;
    }

    /** The plain case: one issued, unpaid invoice is the whole exposure. */
    public function exposureSumsAnOutstandingInvoicesBalance(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPA');
        $this->invoiceFor($I, $company, 'EXPA-INV', '150.00', 'Pending');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame('150.00', $calculator->exposureFor($company, $em));
    }

    /** A fully paid invoice owes nothing and must not inflate exposure. */
    public function aFullyPaidInvoiceDoesNotCountTowardExposure(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPB');
        $this->invoiceFor($I, $company, 'EXPB-INV', '100.00', 'Completed', paid: '100.00');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame('0.00', $calculator->exposureFor($company, $em));
    }

    /** A partial payment leaves the REMAINDER as exposure, not the whole total again. */
    public function aPartiallyPaidInvoiceCountsOnlyItsRemainingBalance(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPC');
        $this->invoiceFor($I, $company, 'EXPC-INV', '200.00', 'Processing', paid: '75.00');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame('125.00', $calculator->exposureFor($company, $em));
    }

    /** Draft is "written but not issued... counting for nothing" (Invoice::isDraft()) — never a receivable. */
    public function aDraftInvoiceDoesNotCountTowardExposure(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPD');
        $this->invoiceFor($I, $company, 'EXPD-INV', '999.00', 'Draft');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame('0.00', $calculator->exposureFor($company, $em));
    }

    /** Cancelled is settled whatever its payments say (Invoice::isFullyPaid()) — never a receivable. */
    public function aCancelledInvoiceDoesNotCountTowardExposureEvenUnpaid(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPE');
        $this->invoiceFor($I, $company, 'EXPE-INV', '400.00', 'Cancelled');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame('0.00', $calculator->exposureFor($company, $em));
    }

    /** Several outstanding invoices sum together. */
    public function exposureSumsAcrossMultipleOutstandingInvoices(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPF');
        $this->invoiceFor($I, $company, 'EXPF-INV1', '50.00', 'Pending');
        $this->invoiceFor($I, $company, 'EXPF-INV2', '30.00', 'Processing', paid: '10.00');
        $this->invoiceFor($I, $company, 'EXPF-INV3', '999.00', 'Draft');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        // 50.00 + (30.00 - 10.00) = 70.00; the draft's 999.00 is excluded.
        $I->assertSame('70.00', $calculator->exposureFor($company, $em));
    }

    /** overageFor() answers null when there is no limit set — unlimited, not zero. */
    public function overageIsNullWithNoCreditLimitSet(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPG');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertNull($calculator->overageFor($company, '1000000.00', $em));
    }

    /** overageFor() answers null when the addition still fits under the limit. */
    public function overageIsNullWhenTheAdditionFitsUnderTheLimit(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPH');
        $company->setCreditLimit('500.00');
        $I->haveInRepository($company);
        $this->invoiceFor($I, $company, 'EXPH-INV', '200.00', 'Pending');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertNull($calculator->overageFor($company, '250.00', $em));
    }

    /** overageFor() answers the figures once existing exposure plus the addition passes the limit. */
    public function overageReportsTheFiguresOnceTheLimitIsExceeded(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPI');
        $company->setCreditLimit('500.00');
        $I->haveInRepository($company);
        $this->invoiceFor($I, $company, 'EXPI-INV', '400.00', 'Pending');

        $calculator = $I->grabService(CompanyCreditExposureCalculator::class);
        $em = $I->grabService(EntityManagerInterface::class);

        $overage = $calculator->overageFor($company, '200.00', $em);
        $I->assertNotNull($overage);
        $I->assertSame('500.00', $overage->limit);
        $I->assertSame('400.00', $overage->currentExposure);
        $I->assertSame('200.00', $overage->additionalAmount);
        $I->assertSame('600.00', $overage->projectedExposure);
        $I->assertSame('100.00', $overage->over());
    }

    /** A limit of 0 or blank clears the field rather than storing a real constraint (Company::setCreditLimit()). */
    public function settingACreditLimitOfZeroClearsIt(FunctionalTester $I): void
    {
        $company = $this->company($I, 'EXPJ');
        $company->setCreditLimit('500.00');
        $I->haveInRepository($company);

        $company->setCreditLimit('0.00');
        $I->assertNull($company->getCreditLimit());
    }

    /** The limit is editable from the admin Customer form and shows on the detail page. */
    public function theCreditLimitPersistsThroughTheAdminFormAndShowsOnDetail(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'EXPK');

        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="credit_limit"]');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/company/update/' . $company->getId(), [
            '_token' => $token,
            'name' => $company->getName(),
            'status' => 'Active',
            'account_type' => 'Business',
            'credit_limit' => '2500.00',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'creditLimit' => '2500.00']);

        $I->amOnPage('/admin/company/detail/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('2,500.00');
    }
}
