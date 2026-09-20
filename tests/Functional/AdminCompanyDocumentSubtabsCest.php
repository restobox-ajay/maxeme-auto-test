<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Controller\Admin\CompanyController;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The documents panel on a customer record (/admin/company/detail/{id}), now behind subtabs:
 * Estimates | Sales Orders | Invoices, defaulting to Sales Orders.
 *
 * Conducted per #624: every test drives the real screen with a plain GET, against documents this
 * file created, and asserts the CELL rather than the page (#627) — `see('50')` is a substring
 * match over the whole document and passes on '1050'.
 *
 * Two properties get most of the attention here, because they are the ones that fail silently:
 *
 *  - **A tab shows this customer's documents and nobody else's.** Asserted as an exact set of
 *    Number cells, not as a dontSee: an absence assertion against a selector that matches nothing
 *    passes for the wrong reason, so every negative below is either an exact-set comparison or is
 *    paired with a positive control on the same selector.
 *  - **The tab list is discovered from CompanyController::DOCUMENT_TABS, never hand-listed.** A
 *    fourth tab added without extending this file fails documentTabKeys()'s guard rather than
 *    quietly skipping the rule.
 */
final class AdminCompanyDocumentSubtabsCest
{
    private const CARD = '.company-documents-card';
    private const TABS = '.company-document-subtabs';
    private const CURRENT_TAB = '.company-document-subtabs a.is-current';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-docs-subtabs-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The tab table, read out of the controller rather than copied into this file.
     *
     * This is the enumeration the house rule asks for: the subjects come from the application, so
     * a tab added, renamed or removed is covered on arrival instead of on the day somebody
     * remembers to refresh a fixture.
     *
     * @return array<string, array<string, string|null>>
     */
    private static function documentTabs(): array
    {
        /** @var array<string, array<string, string|null>> $tabs */
        $tabs = (new \ReflectionClass(CompanyController::class))->getConstant('DOCUMENT_TABS');

        return $tabs;
    }

    /**
     * The tab keys, checked against whatever this test has an expectation for.
     *
     * Without this guard the per-tab loops below would iterate the controller's tabs and silently
     * skip any tab the expectation map does not name — which is exactly the failure mode the
     * enumerate-don't-hand-list rule exists to prevent, reintroduced one level up.
     *
     * @param array<string, mixed> $expectations
     * @return list<string>
     */
    private static function documentTabKeys(FunctionalTester $I, array $expectations): array
    {
        $keys = array_keys(self::documentTabs());
        $I->assertSame(
            $keys,
            array_keys($expectations),
            'Every tab CompanyController declares needs an expectation here, in declared order.',
        );

        return $keys;
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode(strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8)));
        $I->haveInRepository($company);

        return $company;
    }

    /** A priced quote with one line, dated and attributed. */
    private function makeEstimate(FunctionalTester $I, Company $company, string $number, ?string $total = '115.00'): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setSource('Admin')
            ->setDocumentDate('2026-08-18')
            ->setUserName('Quinn Quoter')
            ->setSubtotal($total === null ? null : '100.00')
            ->setTax($total === null ? null : '15.00')
            ->setTotal($total);
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())->setName('Widget')->setSku('WIDGET-1')->setQuantity('2.00')->setCost('40.00')->setPrice('50.00')->setSubtotal('100.00'),
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** An approved order with two lines — two, so the "# of Items" cell cannot pass by reading 1. */
    private function makeSalesOrder(FunctionalTester $I, Company $company, string $number): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($number)
            ->setDocumentDate('2026-08-20')
            ->setUserName('Olive Orderer')
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addLine((new SalesOrderLine())->setName('Order Widget')->setQuantity('2.00')->setPrice('20.00')->setSubtotal('40.00'));
        $order->addLine((new SalesOrderLine())->setName('Order Gasket')->setQuantity('1.00')->setPrice('10.00')->setSubtotal('10.00'));
        $I->haveInRepository($order);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    /** An issued invoice with one line. */
    private function makeInvoice(FunctionalTester $I, Company $company, string $number): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-08-22')
            ->setInvoiceDate('2026-08-22')
            ->setUserName('Ivan Invoicer')
            ->setSubtotal('75.00')
            ->setTax('0.00')
            ->setTotal('75.00');
        $invoice->addLine(
            (new InvoiceLine())->setName('Invoice Widget')->setQuantity('3.00')->setPrice('25.00')->setSubtotal('75.00'),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return $invoice;
    }

    /**
     * One customer holding exactly one of each document type, each number carrying $tag so a row
     * belonging to the wrong customer or the wrong tab is identifiable on sight.
     *
     * @return array{estimates: string, 'sales-orders': string, invoices: string}
     */
    private function customerDocuments(FunctionalTester $I, Company $company, string $tag): array
    {
        $numbers = [
            'estimates'    => 'EST-' . $tag,
            'sales-orders' => 'SO-' . $tag,
            'invoices'     => 'INV-' . $tag,
        ];

        $this->makeEstimate($I, $company, $numbers['estimates']);
        $this->makeSalesOrder($I, $company, $numbers['sales-orders']);
        $this->makeInvoice($I, $company, $numbers['invoices']);

        return $numbers;
    }

    /**
     * The Number column as rendered, trimmed.
     *
     * Read as a set and compared with assertSame rather than probed with see()/dontSee(): the
     * question "which documents are on this tab" has one right answer, and an exact comparison
     * answers it in both directions at once — the row that should be there and the row that
     * should not.
     *
     * @return list<string>
     */
    private function documentNumbersOnScreen(FunctionalTester $I): array
    {
        return array_values(array_map(trim(...), $I->grabMultiple(self::CARD . ' td[data-label="Number"]')));
    }

    private function openDetail(FunctionalTester $I, Company $company, ?string $docs = null): void
    {
        $I->amOnPage('/admin/company/detail/' . $company->getId() . ($docs === null ? '' : '?docs=' . $docs));
        $I->seeResponseCodeIsSuccessful();
    }

    // ---------------------------------------------------------------------------------------

    /**
     * No ?docs= at all is the link every other screen in the app already points at, so the default
     * has to be the tab the panel held before it had tabs.
     */
    public function withNoQueryStringThePanelOpensOnSalesOrders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Default Co');
        $numbers = $this->customerDocuments($I, $company, 'DEFAULT');

        $this->openDetail($I, $company);

        $I->assertSame(['Sales Orders'], array_map(trim(...), $I->grabMultiple(self::CURRENT_TAB)));
        $I->assertSame([$numbers['sales-orders']], $this->documentNumbersOnScreen($I));
    }

    /**
     * Existing behaviour, column by column: the Sales Orders tab has to show exactly what the
     * single hardcoded panel showed before the tabs existed.
     *
     * Every figure is asserted at its own cell. `see('50')` would pass here on the '$50.00' total,
     * on the '2.00' quantity, and on any '50' that wandered onto the page from the sidebar (#627).
     */
    public function theSalesOrdersTabShowsEveryColumnThePanelShowedBefore(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Columns Co');
        $order = $this->makeSalesOrder($I, $company, 'SO-COLUMNS');

        $this->openDetail($I, $company, 'sales-orders');

        $cell = static fn (string $label): string => self::CARD . ' td[data-label="' . $label . '"]';

        $I->assertSame(['2026-08-20'], array_map(trim(...), $I->grabMultiple($cell('Date'))));
        $I->assertSame(['SO-COLUMNS'], array_map(trim(...), $I->grabMultiple($cell('Number'))));
        $I->assertSame(['2'], array_map(trim(...), $I->grabMultiple($cell('# of Items'))));
        $I->assertSame(['Olive Orderer'], array_map(trim(...), $I->grabMultiple($cell('User'))));
        $I->assertSame(['$50.00'], array_map(trim(...), $I->grabMultiple($cell('Amount'))));
        $I->assertSame(['Approved'], array_map(trim(...), $I->grabMultiple($cell('Status'))));

        // And the number is still the link through to the document it names.
        $I->assertSame(
            '/admin/order/detail/' . $order->getId(),
            $I->grabAttributeFrom($cell('Number') . ' a', 'href'),
        );
    }

    /**
     * Each tab lists its own document type and only its own — the reason the owner rejected one
     * mixed list with a Type column.
     *
     * Enumerated over the controller's own tab table, and each tab's link route is checked too, so
     * an Invoices row that linked to /admin/order/detail/{id} (same id space, different document)
     * fails here rather than on somebody's screen.
     */
    public function eachTabListsItsOwnDocumentTypeAndOnlyThat(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Per Tab Co');
        $numbers = $this->customerDocuments($I, $company, 'PERTAB');

        $paths = [
            'estimates'    => '/admin/estimate/detail/',
            'sales-orders' => '/admin/order/detail/',
            'invoices'     => '/admin/invoice/detail/',
        ];

        foreach (self::documentTabKeys($I, $numbers) as $key) {
            $this->openDetail($I, $company, $key);

            $I->assertSame(
                [$numbers[$key]],
                $this->documentNumbersOnScreen($I),
                sprintf('The %s tab must list its own document and no other type.', $key),
            );
            $I->assertStringStartsWith(
                $paths[$key],
                (string) $I->grabAttributeFrom(self::CARD . ' td[data-label="Number"] a', 'href'),
                sprintf('The %s tab must link through to its own document type.', $key),
            );
        }
    }

    /**
     * The cross-customer negative, which is the cheap half and the one worth having (#624).
     *
     * Their document existing at all is the positive control: the same selector that returns only
     * our numbers on our record returns theirs on theirs, so an empty tab cannot pass this by
     * rendering nothing.
     */
    public function aTabNeverShowsAnotherCustomersDocuments(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $ours = $this->makeCompany($I, 'Docs Ours Co');
        $theirs = $this->makeCompany($I, 'Docs Theirs Co');
        $ourNumbers = $this->customerDocuments($I, $ours, 'OURS');
        $theirNumbers = $this->customerDocuments($I, $theirs, 'THEIRS');

        foreach (self::documentTabKeys($I, $ourNumbers) as $key) {
            $this->openDetail($I, $ours, $key);
            $I->assertSame(
                [$ourNumbers[$key]],
                $this->documentNumbersOnScreen($I),
                sprintf("Docs Theirs Co's %s must not appear on Docs Ours Co's record.", $key),
            );

            // Positive control: the row we just proved absent is genuinely rendered by this
            // selector — on the record it belongs to.
            $this->openDetail($I, $theirs, $key);
            $I->assertSame([$theirNumbers[$key]], $this->documentNumbersOnScreen($I));
        }
    }

    /**
     * A ?docs= value nobody serves is a stale bookmark — somebody's saved link to a tab that was
     * renamed, or a guess. The useful answer is the customer's record on its default tab, not a
     * 404 that throws away the customer they asked for.
     */
    public function anUnknownDocsValueFallsBackToTheDefaultTabInsteadOfFailing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Stale Bookmark Co');
        $numbers = $this->customerDocuments($I, $company, 'STALE');

        foreach (['credit-memos', 'purchase-orders', '', 'Sales-Orders', '../../etc'] as $docs) {
            $I->amOnPage('/admin/company/detail/' . $company->getId() . '?docs=' . urlencode($docs));
            $I->seeResponseCodeIsSuccessful();

            $I->assertSame(
                ['Sales Orders'],
                array_map(trim(...), $I->grabMultiple(self::CURRENT_TAB)),
                sprintf('?docs=%s should land on the default tab.', $docs),
            );
            $I->assertSame([$numbers['sales-orders']], $this->documentNumbersOnScreen($I));
        }
    }

    /**
     * The same fallback for a ?docs= that is not even a string.
     *
     * Separate from the test above because the failure is a different one: Symfony's InputBag::get()
     * throws a BadRequestException on an array-valued parameter, so ?docs[]=invoices would have been
     * a 400 — a different status code for the same "this tab does not exist" situation, and just as
     * useless to the admin who arrived holding a bad link.
     */
    public function anArrayShapedDocsValueAlsoFallsBackInsteadOfFailing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Array Param Co');
        $numbers = $this->customerDocuments($I, $company, 'ARRAYPARAM');

        $I->amOnPage('/admin/company/detail/' . $company->getId() . '?docs[]=invoices');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(['Sales Orders'], array_map(trim(...), $I->grabMultiple(self::CURRENT_TAB)));
        $I->assertSame([$numbers['sales-orders']], $this->documentNumbersOnScreen($I));
    }

    /**
     * No-JS: the tabs are links, so a plain GET on each one renders that tab's list.
     *
     * Asserted structurally as well as behaviourally — the strip holds three anchors and no
     * button, with the anchor count as the positive control so "no buttons" cannot pass because
     * the strip failed to render at all.
     */
    public function theTabsAreLinksAndWorkWithScriptingOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs No Js Co');
        $numbers = $this->customerDocuments($I, $company, 'NOJS');
        $tabs = self::documentTabs();

        $this->openDetail($I, $company);

        $I->seeNumberOfElements(self::TABS . ' a[href]', count($tabs));
        $I->dontSeeElement(self::TABS . ' button');
        $I->assertSame(
            array_values(array_map(static fn (array $tab): string => (string) $tab['label'], $tabs)),
            array_map(trim(...), $I->grabMultiple(self::TABS . ' a')),
            'The strip reads Estimates | Sales Orders | Invoices, in the order the controller declares.',
        );

        foreach (self::documentTabKeys($I, $numbers) as $key) {
            // The href really carries the tab, and following it as a plain GET really renders it.
            $I->seeElement(sprintf('%s a[href="/admin/company/detail/%d?docs=%s"]', self::TABS, $company->getId(), $key));

            $this->openDetail($I, $company, $key);
            $I->assertSame([$numbers[$key]], $this->documentNumbersOnScreen($I));
            $I->assertSame([$tabs[$key]['label']], array_map(trim(...), $I->grabMultiple(self::CURRENT_TAB)));
        }
    }

    /**
     * The footer drill-through belongs only to the tab whose list screen can actually be filtered
     * to one customer.
     *
     * /admin/order takes OrderSearch[company_id]. /admin/invoice and /admin/estimate filter by
     * company NAME only (filters[company], a LIKE) and take no id, so a link from here would land
     * on every customer whose name shares a substring with this one. The absence is paired with
     * the Sales Orders case as its positive control: the same selector does match a link there.
     */
    public function onlyTheSalesOrdersTabOffersADrillThroughToAListScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Footer Co');
        $this->customerDocuments($I, $company, 'FOOTER');

        $footerLink = self::CARD . ' .table-footer-actions a';

        $this->openDetail($I, $company, 'sales-orders');
        $I->seeNumberOfElements($footerLink, 1);
        $I->assertSame('View all orders', trim((string) $I->grabTextFrom($footerLink)));
        $href = (string) $I->grabAttributeFrom($footerLink, 'href');
        $I->assertStringStartsWith('/admin/order', $href);
        $I->assertStringContainsString(
            urlencode('OrderSearch[company_id]') . '=' . $company->getId(),
            $href,
            'The drill-through has to name THIS customer by id, not by a name the list matches loosely.',
        );

        foreach (['estimates', 'invoices'] as $key) {
            $this->openDetail($I, $company, $key);
            $I->dontSeeElement($footerLink);
        }
    }

    /**
     * The rename the owner asked for: this is an ADMIN console, and these are our sales orders.
     *
     * "Purchase Orders" now names a real, different document elsewhere in the same app — the
     * ProcurementBundle screens under Purchases — so the phrase appearing on a customer record
     * would make one string mean two documents. Scoped to the card on purpose: the sidebar's
     * Purchases entry is the other meaning and must keep it.
     */
    public function theCustomerPanelSaysSalesOrdersAndNotPurchaseOrders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Naming Co');
        $this->customerDocuments($I, $company, 'NAMING');

        $this->openDetail($I, $company);

        // Positive control first: the selector the dontSee below uses does match this card's text.
        $I->see('Sales Orders', self::CARD);
        $I->dontSee('Purchase Orders', self::CARD);
        $I->dontSee('purchase orders', self::CARD);
    }

    /**
     * An empty tab says which document type is empty. "No documents yet" on the Invoices tab
     * would read as "this customer has never traded".
     *
     * Two assertions, and the second one is the point. Comparing the rendered sentence to
     * DOCUMENT_TABS['empty'] only proves the wiring — that each tab renders ITS OWN string and not
     * one shared between them — and is otherwise the constant checking itself: it stays green for
     * any value, including a generic one. A mutation run proved exactly that, so the content is
     * asserted against the tab's LABEL instead, which the empty string does not get to choose.
     */
    public function anEmptyTabNamesItsOwnDocumentType(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Empty Co');
        $tabs = self::documentTabs();

        foreach (self::documentTabKeys($I, $tabs) as $key) {
            $this->openDetail($I, $company, $key);

            $I->assertSame([], $this->documentNumbersOnScreen($I));

            $rendered = array_map(trim(...), $I->grabMultiple(self::CARD . ' .empty-table-cell'));
            $I->assertSame([(string) $tabs[$key]['empty']], $rendered);
            $I->assertStringContainsString(
                strtolower((string) $tabs[$key]['label']),
                strtolower($rendered[0]),
                sprintf('The %s tab has to say which documents it has none of.', $key),
            );
        }
    }

    /**
     * The status cell, for all three document types.
     *
     * SalesOrder::getStatus() returns a string and the other two return backed enums, so this is
     * the assertion that the controller normalises them. Left to Twig, an enum prints as nothing
     * useful at best and stops the page at worst.
     */
    public function theStatusCellReadsTheStatusForEveryDocumentType(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Status Co');
        $this->customerDocuments($I, $company, 'STATUS');

        $expected = [
            'estimates'    => 'Priced',
            'sales-orders' => 'Approved',
            'invoices'     => 'Pending',
        ];

        foreach (self::documentTabKeys($I, $expected) as $key) {
            $this->openDetail($I, $company, $key);
            $I->assertSame(
                [$expected[$key]],
                array_map(trim(...), $I->grabMultiple(self::CARD . ' td[data-label="Status"]')),
                sprintf('The %s tab must print the status value, not an enum object.', $key),
            );
        }
    }

    /**
     * A quote still being priced states no total, and "$0.00" is not the same claim as "not priced
     * yet" — it says the quote settled at nothing. 'TBD' is the word the Quotes grid already uses.
     */
    public function anUnpricedEstimateReadsTbdRatherThanZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Unpriced Co');
        $this->makeEstimate($I, $company, 'EST-UNPRICED', null);

        $this->openDetail($I, $company, 'estimates');

        $I->assertSame(['TBD'], array_map(trim(...), $I->grabMultiple(self::CARD . ' td[data-label="Amount"]')));
    }

    /**
     * Switching tabs must not take the rest of the record off the screen — that is the whole reason
     * these are panel subtabs and not another row in the page-level tab bar.
     */
    public function switchingTabsKeepsTheRestOfTheRecordOnScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Docs Context Co');
        $this->customerDocuments($I, $company, 'CONTEXT');

        foreach (self::documentTabKeys($I, self::documentTabs()) as $key) {
            $this->openDetail($I, $company, $key);

            $I->see('Docs Context Co', 'h1');
            $I->seeElement('.company-summary-body');
            $I->see('Address Book');
            $I->see('Notes');
        }
    }
}
