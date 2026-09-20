<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Filtering the invoice and quote grids to ONE customer, by id (queue item 7).
 *
 * Both grids have always had a company-NAME filter, and a name cannot mean one customer: the LIKE
 * behind it matches "Acme" and "Acme Holdings" alike, and a drill-through from a customer record
 * has no way to say "this one". `InvoiceSearch[company_id]` / `EstimateSearch[company_id]` — the
 * shape /admin/order already uses — says exactly which. The name filter is untouched and still
 * fuzzy; the two are asserted side by side below, because the point was to add a precise filter,
 * not to replace an imprecise one.
 *
 * ## Why every test here creates a SECOND customer
 *
 * A filter that returned nothing at all would pass a test that only looked for the wanted row.
 * So each case creates two customers with a document each, proves the unwanted one renders on the
 * unfiltered grid (the positive control), and then proves it is ABSENT from the filtered one —
 * and re-reads it from the database afterwards to show it is still there, i.e. that it was
 * filtered out rather than never written (#624).
 *
 * ## Why a bad id is tested as hard as a good one
 *
 * A customer id that does not exist, or is not a number, must not 500 and must not widen back into
 * every customer's documents. That last failure is the one that matters: the grid would look
 * exactly like the one that was asked for, with totals belonging to the whole business. So the
 * unknown-id cases assert BOTH documents are absent, each paired with a positive control proving
 * the page rendered at all.
 *
 * The two customers are named "Zed Scope Alpha Supplies" and "Zed Scope Beta Trading": both
 * contain "Scope", which is what makes the name filter's fuzziness assertable, and neither name
 * nor document number is a bare number (#627).
 *
 * The fail-closed empty state these assert reads "This customer does not exist." on all five
 * document grids. It was "No such customer." here until queue item 30 brought the order, credit
 * note and sales return grids onto the same helper and the owner ruled the wording; the five
 * screens say the same sentence deliberately, so an admin who has seen it once recognises it.
 * `AdminDocumentListFailClosedCest` asserts the other three.
 */
final class AdminDocumentListCompanyScopeCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-document-list-company-scope@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('SCOPE-' . uniqid())
            ->setPrimaryEmail('buyer-' . uniqid() . '@scope.example');
        $I->haveInRepository($company);

        return $company;
    }

    /** A standalone issued invoice — Invoice::$salesOrder is nullable, and the grid lists both kinds. */
    private function makeInvoice(FunctionalTester $I, Company $company, string $tag): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($tag . '-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $invoice->addLine(
            (new InvoiceLine())->setName('Scope Widget')->setQuantity('1.00')->setPrice('40.00')->setSubtotal('40.00'),
        );
        $I->haveInRepository($invoice);

        $invoice->issue(DocumentActor::system());
        $I->grabService(EntityManagerInterface::class)->flush();

        return $invoice;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, string $tag): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber($tag . '-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())->setName('Scope Widget')->setSku('SCOPE-WIDGET')->setQuantity('1.00')->setPrice('40.00')->setSubtotal('40.00'),
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /**
     * Re-read the excluded document from the database.
     *
     * Absence from a filtered page is only evidence of filtering if the row is still in the
     * database and still belongs to the customer it was written for. Read back through the
     * repository, not through the entity created above, which the test already holds in memory.
     */
    private function seeStillFiledUnder(FunctionalTester $I, string $entityClass, string $documentNumber, Company $company): void
    {
        /** @var Invoice|Estimate $stored */
        $stored = $I->grabEntityFromRepository($entityClass, ['documentNumber' => $documentNumber]);
        $I->assertSame(
            $company->getId(),
            $stored->getCompany()?->getId(),
            $documentNumber . ' should still be filed under ' . $company->getName() . ' in the database.',
        );
    }

    // ─────────────────────────────────────────────────────────────────── invoices

    public function theInvoiceGridScopedByCompanyIdShowsThatCustomerAndNoOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeInvoice($I, $alpha, 'INV-SCOPE-ALPHA');
        $theirs = $this->makeInvoice($I, $beta, 'INV-SCOPE-BETA');

        // Positive control: unfiltered, the grid carries both. Without this, a filter that returned
        // nothing — or one whose fixture never saved — would pass every assertion below.
        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/invoice?InvoiceSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        // The screen says WHOSE invoices these are. A filtered grid that reads like an unfiltered
        // one is how somebody takes one customer's total for the whole business's.
        $I->see('Invoices for Zed Scope Alpha Supplies', 'h1');
        $I->dontSee('Zed Scope Beta Trading', 'tbody');

        $this->seeStillFiledUnder($I, Invoice::class, $theirs->getDocumentNumber(), $beta);
    }

    /** The bare `?company_id=` spelling, for a hand-written drill-through link. */
    public function theInvoiceGridAcceptsTheBareCompanyIdParameter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeInvoice($I, $alpha, 'INV-BARE-ALPHA');
        $theirs = $this->makeInvoice($I, $beta, 'INV-BARE-BETA');

        $I->amOnPage('/admin/invoice?company_id=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Invoice::class, $theirs->getDocumentNumber(), $beta);
    }

    /**
     * The name filter is not replaced, and the two narrow together.
     *
     * Both customers' names contain "Scope", which is the whole complaint about filtering by name:
     * it answers with both. Adding the id to the same request answers with one, and contradicting
     * the id with a name answers with neither — an AND, not a fallback.
     */
    public function theNameFilterStillWorksAndCombinesWithTheId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeInvoice($I, $alpha, 'INV-BOTH-ALPHA');
        $theirs = $this->makeInvoice($I, $beta, 'INV-BOTH-BETA');

        // Unchanged behaviour: a name is fuzzy and matches both customers.
        $I->amOnPage('/admin/invoice?filters[company]=Zed+Scope');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        // Same fuzzy name, plus the id: one customer.
        $I->amOnPage('/admin/invoice?filters[company]=Zed+Scope&InvoiceSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        // A name that contradicts the id narrows to nothing rather than picking a winner.
        $I->amOnPage('/admin/invoice?filters[company]=Beta+Trading&InvoiceSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('No invoices found for Zed Scope Alpha Supplies.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Invoice::class, $theirs->getDocumentNumber(), $beta);
        $this->seeStillFiledUnder($I, Invoice::class, $mine->getDocumentNumber(), $alpha);
    }

    /**
     * An id nobody has lists NOTHING — not everything.
     *
     * The tempting implementation ignores an unresolvable id, which hands somebody who asked for one
     * customer's invoices a grid of every customer's that looks exactly like what they asked for.
     */
    public function anUnknownInvoiceCompanyIdListsNothingRatherThanEveryCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeInvoice($I, $alpha, 'INV-GHOST-ALPHA');
        $theirs = $this->makeInvoice($I, $beta, 'INV-GHOST-BETA');

        $missingId = (string) (max((int) $alpha->getId(), (int) $beta->getId()) + 50000);

        $I->amOnPage('/admin/invoice?InvoiceSearch[company_id]=' . $missingId);
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the two dontSees: the page rendered, and it says why it is empty.
        $I->see('This customer does not exist.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        // Both rows are still in the database; the empty grid is a refusal to answer, not a wipe.
        $this->seeStillFiledUnder($I, Invoice::class, $mine->getDocumentNumber(), $alpha);
        $this->seeStillFiledUnder($I, Invoice::class, $theirs->getDocumentNumber(), $beta);
    }

    /**
     * An id that is not a number at all: same answer, and never read as the number inside it.
     *
     * `12abc` is the case a plain (int) cast gets wrong — it answers 12, and silently shows a
     * customer nobody asked for.
     */
    public function anInvoiceCompanyIdThatIsNotANumberListsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $mine = $this->makeInvoice($I, $alpha, 'INV-JUNK-ALPHA');

        $junkIds = [
            'abc',
            $alpha->getId() . 'abc',
            '-' . $alpha->getId(),
            '0',
        ];

        foreach ($junkIds as $junk) {
            $I->amOnPage('/admin/invoice?InvoiceSearch[company_id]=' . urlencode((string) $junk));
            $I->seeResponseCodeIsSuccessful();
            $I->see('This customer does not exist.', 'tbody');
            $I->dontSee($mine->getDocumentNumber(), 'tbody');
        }

        // Not even a scalar. This must not throw either.
        $I->amOnPage('/admin/invoice?company_id[]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('This customer does not exist.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Invoice::class, $mine->getDocumentNumber(), $alpha);
    }

    /**
     * The scope survives the screen's own controls, followed rather than assumed.
     *
     * A scope dropped by the first filter submit or status tab is worse than no scope at all: the
     * grid widens back to every customer mid-session and nothing on it says so.
     *
     * The links are READ OFF THE RENDERED PAGE and then followed, rather than retyped here — a
     * hand-built URL would prove the controller filters, which the tests above already prove, and
     * say nothing about whether the screen's own controls carry the scope. `click()` would be the
     * natural way to do it and cannot be used: the crawler resolves a relative action against the
     * admin Host header and the Symfony module then refuses the result as an external URL, which
     * is why Tests\Support\Helper\Functional exists at all.
     *
     * The no-JS filter submit is asserted through the hidden field it posts, for the same reason.
     */
    public function theInvoiceScopeSurvivesTheFilterFormAndTheStatusTabs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeInvoice($I, $alpha, 'INV-STICKY-ALPHA');
        $theirs = $this->makeInvoice($I, $beta, 'INV-STICKY-BETA');

        $I->amOnPage('/admin/invoice?InvoiceSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();

        // Submitting a column filter is a plain GET form post, so the scope has to be a field in it.
        $I->assertSame(
            (string) $alpha->getId(),
            $I->grabAttributeFrom('//form[@id="invoice-grid-filters-form"]//input[@name="InvoiceSearch[company_id]"]', 'value'),
            'The filter form must carry the scope, or the first search drops it.',
        );

        // A status tab, followed as rendered. Both invoices are Pending, so Beta's absence is the
        // scope holding rather than the status filter doing the work.
        $pendingTab = $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[normalize-space()="Pending"]', 'href');
        $I->amOnPage($pendingTab);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Invoices for Zed Scope Alpha Supplies', 'h1');
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        // And the way back out is a link on the page, not a URL to remember.
        $allInvoices = $I->grabAttributeFrom('//div[contains(@class, "panel-actions")]//a[normalize-space()="All Invoices"]', 'href');
        $I->amOnPage($allInvoices);
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');
    }

    // ─────────────────────────────────────────────────────────────────── quotes

    public function theQuoteGridScopedByCompanyIdShowsThatCustomerAndNoOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeEstimate($I, $alpha, 'EST-SCOPE-ALPHA');
        $theirs = $this->makeEstimate($I, $beta, 'EST-SCOPE-BETA');

        $I->amOnPage('/admin/estimate');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/estimate?EstimateSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        $I->see('Quotes for Zed Scope Alpha Supplies', 'h1');
        $I->dontSee('Zed Scope Beta Trading', 'tbody');

        $this->seeStillFiledUnder($I, Estimate::class, $theirs->getDocumentNumber(), $beta);
    }

    public function theQuoteGridAcceptsTheBareCompanyIdParameter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeEstimate($I, $alpha, 'EST-BARE-ALPHA');
        $theirs = $this->makeEstimate($I, $beta, 'EST-BARE-BETA');

        $I->amOnPage('/admin/estimate?company_id=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Estimate::class, $theirs->getDocumentNumber(), $beta);
    }

    public function theQuoteNameFilterStillWorksAndCombinesWithTheId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeEstimate($I, $alpha, 'EST-BOTH-ALPHA');
        $theirs = $this->makeEstimate($I, $beta, 'EST-BOTH-BETA');

        $I->amOnPage('/admin/estimate?filters[company]=Zed+Scope');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/estimate?filters[company]=Zed+Scope&EstimateSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/estimate?filters[company]=Beta+Trading&EstimateSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('No quotes found for Zed Scope Alpha Supplies.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Estimate::class, $theirs->getDocumentNumber(), $beta);
        $this->seeStillFiledUnder($I, Estimate::class, $mine->getDocumentNumber(), $alpha);
    }

    public function anUnknownQuoteCompanyIdListsNothingRatherThanEveryCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeEstimate($I, $alpha, 'EST-GHOST-ALPHA');
        $theirs = $this->makeEstimate($I, $beta, 'EST-GHOST-BETA');

        $missingId = (string) (max((int) $alpha->getId(), (int) $beta->getId()) + 50000);

        $I->amOnPage('/admin/estimate?EstimateSearch[company_id]=' . $missingId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('This customer does not exist.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Estimate::class, $mine->getDocumentNumber(), $alpha);
        $this->seeStillFiledUnder($I, Estimate::class, $theirs->getDocumentNumber(), $beta);
    }

    public function aQuoteCompanyIdThatIsNotANumberListsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $mine = $this->makeEstimate($I, $alpha, 'EST-JUNK-ALPHA');

        foreach (['abc', $alpha->getId() . 'abc', '-' . $alpha->getId(), '0'] as $junk) {
            $I->amOnPage('/admin/estimate?EstimateSearch[company_id]=' . urlencode((string) $junk));
            $I->seeResponseCodeIsSuccessful();
            $I->see('This customer does not exist.', 'tbody');
            $I->dontSee($mine->getDocumentNumber(), 'tbody');
        }

        $I->amOnPage('/admin/estimate?company_id[]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('This customer does not exist.', 'tbody');
        $I->dontSee($mine->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, Estimate::class, $mine->getDocumentNumber(), $alpha);
    }

    public function theQuoteScopeSurvivesTheFilterFormAndTheStatusTabs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, 'Zed Scope Alpha Supplies');
        $beta = $this->makeCompany($I, 'Zed Scope Beta Trading');
        $mine = $this->makeEstimate($I, $alpha, 'EST-STICKY-ALPHA');
        $theirs = $this->makeEstimate($I, $beta, 'EST-STICKY-BETA');

        $I->amOnPage('/admin/estimate?EstimateSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            (string) $alpha->getId(),
            $I->grabAttributeFrom('//form[@id="estimate-grid-filters-form"]//input[@name="EstimateSearch[company_id]"]', 'value'),
            'The filter form must carry the scope, or the first search drops it.',
        );

        // Both quotes are Submitted, so Beta's absence is the scope, not the status.
        $submittedTab = $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[normalize-space()="Submitted"]', 'href');
        $I->amOnPage($submittedTab);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Quotes for Zed Scope Alpha Supplies', 'h1');
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $allQuotes = $I->grabAttributeFrom('//div[contains(@class, "panel-actions")]//a[normalize-space()="All Quotes"]', 'href');
        $I->amOnPage($allQuotes);
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');
    }
}
