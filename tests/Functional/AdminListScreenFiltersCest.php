<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\DebitMemoLine;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The filters the four remaining offender screens grew when they were conformed (#621), conducted.
 *
 * ## What these prove that the conformance test cannot
 *
 * `AdminListScreenConventionsCest` asserts a status bar EXISTS and that a filter row EXISTS. It
 * fetches every screen against an empty database on purpose, so it can say nothing at all about
 * whether a control that is present does anything — and "present but inert" is exactly the defect
 * two of these screens were listed for. The credit note grid shipped a `.table-search` box wired to
 * a `js-memo-search` handler that was never written, and the sales return grid shipped one that
 * narrowed the rows already on the page rather than the query behind them, so a return on page two
 * was invisible to it. A control that does nothing is worse than no control: a person trusts it,
 * sees an empty grid, and concludes the record is not there.
 *
 * So every test below creates at least two documents, asks the screen for one of them, and asserts
 * **what is absent as well as what is present**. The absence half is the whole point — a filter
 * that returns every row still "contains" the row you searched for — and it is asserted against a
 * positive control on the same data: the unfiltered screen, which has to show both.
 *
 * ## Rows are read by their document number, never by see()
 *
 * `see('50')` is a substring match over the whole page and these grids are nothing but numbers and
 * dates (#627). Every assertion here reads the Memo/RMA/PO column out of the rendered `<tbody>` and
 * compares the WHOLE list to an expected one, so a filter that silently widened its result fails
 * even though the row asked for is still on the page.
 *
 * ## And each filter is a plain GET
 *
 * The last test drives the four screens with scripting irrelevant: it reads each filter control's
 * own form off the markup and checks it is a GET form with a real submit button, which is what
 * makes the URLs the tests above request reachable by a person with JavaScript switched off. That
 * is the reason the two search boxes were defects rather than niceties.
 */
final class AdminListScreenFiltersCest
{
    /*
     * ------------------------------------------------------------------------------------------
     * Credit notes — /admin/credit-memo/index
     * ------------------------------------------------------------------------------------------
     */

    /** One Draft, one Open, and the Open tab shows one of them. */
    public function theCreditNoteStatusFilterExcludesTheOtherStatuses(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Credit Filter Co');
        $draft = $this->creditMemo($I, $company, 'CNF-DRAFT', '40.00');
        $open = $this->creditMemo($I, $company, 'CNF-OPEN', '60.00', issue: true);

        // Positive control: with no filter the grid renders both, so the absence below is the
        // filter working and not the rows failing to exist.
        $I->amOnPage('/admin/credit-memo/index?sort=memo&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$draft, $open], $this->columnOf($I, 'Memo'), 'both notes are on the unfiltered grid');

        $I->amOnPage('/admin/credit-memo/index?status=Open&sort=memo&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$open], $this->columnOf($I, 'Memo'), 'the Open tab shows the issued note');
        $I->dontSeeElement('td[data-label="Memo"] a[href="/admin/credit-memo/' . $this->idOf($I, CreditMemo::class, $draft) . '"]');

        // And the other way round, so neither tab is passing by showing everything.
        $I->amOnPage('/admin/credit-memo/index?status=Draft&sort=memo&dir=asc');
        $I->assertSame([$draft], $this->columnOf($I, 'Memo'), 'the Draft tab shows the draft');
    }

    /**
     * The search box filters the QUERY, not the page.
     *
     * Asked with `limit=1`, so the grid holds one row: the note being searched for is the SECOND of
     * the two by sort order and would sit on page two. A box that filtered the rendered rows — which
     * is what the sales return grid's did — could not possibly find it, and the old credit note box
     * could not find anything at all because nothing was listening to it.
     */
    public function theCreditNoteSearchBoxFiltersTheQueryAndNotThePage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Credit Search Co');
        $first = $this->creditMemo($I, $company, 'CNS-AAA', '10.00');
        $second = $this->creditMemo($I, $company, 'CNS-ZZZ', '20.00');

        // Positive control: one page holds one row, and it is the first one.
        $I->amOnPage('/admin/credit-memo/index?limit=1&sort=memo&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$first], $this->columnOf($I, 'Memo'), 'page one of a one-row page holds the first note');

        $I->amOnPage('/admin/credit-memo/index?q=CNS-ZZZ&limit=1&sort=memo&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$second], $this->columnOf($I, 'Memo'), 'the search reaches a note that is not on page one, so it narrowed the query');

        // A search that matches nothing empties the grid rather than ignoring itself.
        $I->amOnPage('/admin/credit-memo/index?q=CNS-NOTHING-MATCHES-THIS');
        $I->assertSame([], $this->columnOf($I, 'Memo'), 'a search nothing matches returns nothing, rather than everything');
        $I->see('No credit notes found.');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Sales returns — /admin/sales-return/index
     * ------------------------------------------------------------------------------------------
     */

    public function theSalesReturnStatusFilterExcludesTheOtherStatuses(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Return Filter Co');
        $requested = $this->salesReturn($I, $company, 'RMAF-AAA');
        $authorised = $this->salesReturn($I, $company, 'RMAF-BBB', authorise: true);

        $I->amOnPage('/admin/sales-return/index?sort=rma&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$requested, $authorised], $this->columnOf($I, 'RMA'), 'both returns are on the unfiltered grid');

        $I->amOnPage('/admin/sales-return/index?status=Authorised&sort=rma&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$authorised], $this->columnOf($I, 'RMA'), 'the Authorised tab shows only the authorised return');

        $I->amOnPage('/admin/sales-return/index?status=Requested&sort=rma&dir=asc');
        $I->assertSame([$requested], $this->columnOf($I, 'RMA'), 'and the Requested tab only the other one');
    }

    /** The same page-two proof as the credit note grid, on the box that searched the page. */
    public function theSalesReturnSearchBoxFiltersTheQueryAndNotThePage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Return Search Co');
        $first = $this->salesReturn($I, $company, 'RMAS-AAA');
        $second = $this->salesReturn($I, $company, 'RMAS-ZZZ');

        $I->amOnPage('/admin/sales-return/index?limit=1&sort=rma&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$first], $this->columnOf($I, 'RMA'), 'page one of a one-row page holds the first return');

        $I->amOnPage('/admin/sales-return/index?q=RMAS-ZZZ&limit=1&sort=rma&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$second], $this->columnOf($I, 'RMA'), 'the search reaches a return that is not on page one');

        $I->amOnPage('/admin/sales-return/index?q=RMAS-NOTHING-MATCHES-THIS');
        $I->assertSame([], $this->columnOf($I, 'RMA'), 'a search nothing matches returns nothing');
        $I->see('No sales returns found.');
    }

    /**
     * Arrived / Not yet arrived, which is not the same question as the status.
     *
     * The gap between "we agreed to take it back" and "the box arrived" is what #596 exists to
     * record, so it has to be askable. Both returns below are Received-or-Authorised deliberately:
     * the filter is on `received_at`, and a filter that silently answered the status question
     * instead would pass an easier test than this one.
     */
    public function theSalesReturnReceivedFilterSeparatesArrivedFromNotYetArrived(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Return Arrival Co');
        $warehouse = $this->warehouse($I);
        $waiting = $this->salesReturn($I, $company, 'RMAR-WAITING', authorise: true);
        $arrived = $this->salesReturn($I, $company, 'RMAR-ARRIVED', authorise: true, receiveAt: $warehouse);

        $I->amOnPage('/admin/sales-return/index?sort=rma&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$arrived, $waiting], $this->columnOf($I, 'RMA'), 'both returns are on the unfiltered grid');

        $I->amOnPage('/admin/sales-return/index?received=no&sort=rma&dir=asc');
        $I->assertSame([$waiting], $this->columnOf($I, 'RMA'), 'Not yet arrived excludes the one that turned up');

        $I->amOnPage('/admin/sales-return/index?received=yes&sort=rma&dir=asc');
        $I->assertSame([$arrived], $this->columnOf($I, 'RMA'), 'and Arrived excludes the one still in transit');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Debit memos — /admin/bundles/procurement/debit-memos
     * ------------------------------------------------------------------------------------------
     */

    public function theDebitMemoStatusFilterExcludesTheOtherStatuses(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->vendor($I, 'Debit Filter Supply');
        $draft = $this->debitMemo($I, $vendor, 'DMF-DRAFT', '15.00');
        $open = $this->debitMemo($I, $vendor, 'DMF-OPEN', '25.00', issue: true);

        $I->amOnPage('/admin/bundles/procurement/debit-memos?sort=number&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$draft, $open], $this->columnOf($I, 'Memo'), 'both memos are on the unfiltered grid');

        $I->amOnPage('/admin/bundles/procurement/debit-memos?filters[status]=Open&sort=number&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$open], $this->columnOf($I, 'Memo'), 'the Open tab shows only the issued memo');

        $I->amOnPage('/admin/bundles/procurement/debit-memos?filters[status]=Draft&sort=number&dir=asc');
        $I->assertSame([$draft], $this->columnOf($I, 'Memo'), 'and the Draft tab only the draft');
    }

    /**
     * The filter row's search box and vendor select, each proved to exclude.
     *
     * Two vendors, because the vendor filter is by id and a filter comparing names would pass with
     * one vendor on file and silently match the wrong rows the day two vendors share a name.
     */
    public function theDebitMemoSearchAndVendorFiltersExclude(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->vendor($I, 'Debit Search Supply');
        $theirs = $this->vendor($I, 'Debit Other Supply');
        $mine = $this->debitMemo($I, $ours, 'DMS-AAA', '11.00');
        $other = $this->debitMemo($I, $theirs, 'DMS-ZZZ', '22.00');

        $I->amOnPage('/admin/bundles/procurement/debit-memos?sort=number&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$mine, $other], $this->columnOf($I, 'Memo'), 'both memos are on the unfiltered grid');

        // The search box, asked for a row that a one-row page would not hold.
        $I->amOnPage('/admin/bundles/procurement/debit-memos?filters[q]=DMS-ZZZ&limit=1&sort=number&dir=asc');
        $I->assertSame([$other], $this->columnOf($I, 'Memo'), 'the search reaches a memo that is not on page one');

        $I->amOnPage('/admin/bundles/procurement/debit-memos?filters[vendor]=' . $ours->getId() . '&sort=number&dir=asc');
        $I->assertSame([$mine], $this->columnOf($I, 'Memo'), 'the vendor select excludes the other vendor\'s memo');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Purchase orders — /admin/bundles/procurement/purchase-orders
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The status bar, clicked as a browser would: the tab's own href, taken off the rendered page.
     *
     * Reading the link rather than typing the URL is what makes this a test of the BAR and not of
     * the controller behind it — a tab pointing at the wrong parameter renders a grid that is not
     * narrowed, and typing the URL by hand would never notice.
     */
    public function thePurchaseOrderStatusBarNarrowsToOneStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->vendor($I, 'PO Filter Supply');
        $warehouse = $this->warehouse($I);
        $draft = $this->purchaseOrder($I, $vendor, $warehouse, 'POF-DRAFT');
        $issued = $this->purchaseOrder($I, $vendor, $warehouse, 'POF-ISSUED', issue: true);

        $I->amOnPage('/admin/bundles/procurement/purchase-orders?sort=number&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$draft, $issued], $this->columnOf($I, 'PO'), 'both orders are on the unfiltered grid');

        $issuedTab = $I->grabAttributeFrom('.order-status-tabs a:nth-of-type(3)', 'href');
        $I->assertStringContainsString('status', $issuedTab, 'the third tab filters by a status');

        $I->amOnPage($issuedTab);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$issued], $this->columnOf($I, 'PO'), 'the Issued tab excludes the draft');

        $draftTab = $I->grabAttributeFrom('.order-status-tabs a:nth-of-type(2)', 'href');
        $I->amOnPage($draftTab);
        $I->assertSame([$draft], $this->columnOf($I, 'PO'), 'and the Draft tab excludes the issued order');

        // The All tab puts them back, so the bar is not simply narrowing to nothing each time.
        $allTab = $I->grabAttributeFrom('.order-status-tabs a:nth-of-type(1)', 'href');
        $I->amOnPage($allTab);
        $I->assertSame([$draft, $issued], $this->columnOf($I, 'PO'), 'and All shows both again');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The no-JS guarantee
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Every filter control on the four screens belongs to a GET form with a real submit button.
     *
     * This is what makes the URLs every test above requests reachable by a person with scripting
     * off: a `<select>` whose only way of applying is an `onchange` handler, or a search box with no
     * form at all, is the defect these screens were listed for. Read off the markup because that is
     * where the guarantee lives — the browser submits a form on Enter only when it has a submit
     * button or exactly one text field, and these have several boxes each.
     *
     * The status bar is checked the same way: plain `<a href>` links, which need no scripting to
     * follow, rather than buttons with handlers.
     */
    public function everyNewFilterIsAPlainGetFormThatWorksWithScriptingOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $screens = [
            '/admin/credit-memo/index' => 'credit-memo-filters',
            '/admin/sales-return/index' => 'sales-return-filters',
            '/admin/bundles/procurement/debit-memos' => 'debit-memo-filters',
            '/admin/bundles/procurement/purchase-orders' => 'po-filters',
        ];

        foreach ($screens as $path => $formId) {
            $I->amOnPage($path);
            $I->seeResponseCodeIsSuccessful();

            $I->seeElement('form#' . $formId . '[method="get"]');
            $I->seeElement('form#' . $formId . ' button[type="submit"]');

            // Every control in the filter row submits with that form, named or by form="" id.
            $I->seeElement('tr.filter-row [form="' . $formId . '"]');
            // The status bar is links, not scripted buttons.
            $I->seeElement('.order-status-tabs a[href]');
            $I->dontSeeElement('.order-status-tabs button');

            // And nothing on these screens is the inert search box that started this.
            $I->dontSeeElement('.table-search');
            $I->dontSeeElement('.js-memo-search');
        }
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Reading the grid
     * ------------------------------------------------------------------------------------------
     */

    /**
     * One column of the rendered grid, in row order.
     *
     * The whole column, compared whole — not `see()`, which is a substring match over the entire
     * page and would pass on a filter that narrowed nothing (#627).
     *
     * @return list<string>
     */
    private function columnOf(FunctionalTester $I, string $label): array
    {
        $cells = $I->grabMultiple('.table-card tbody td[data-label="' . $label . '"]');

        return array_values(array_map(trim(...), $cells));
    }

    private function idOf(FunctionalTester $I, string $class, string $documentNumber): int
    {
        $row = $I->grabService(EntityManagerInterface::class)
            ->getRepository($class)
            ->findOneBy(['documentNumber' => $documentNumber]);

        $I->assertNotNull($row, $documentNumber . ' was created');

        return (int) $row->getId();
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('list-filters-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $I->grabService(EntityManagerInterface::class);

        return $em;
    }

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('LF-' . strtoupper(substr(uniqid(), -8)))
            ->setPrimaryEmail('buyer@list-filters.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function warehouse(FunctionalTester $I): Warehouse
    {
        $em = $this->em($I);
        $region = (new FulfillmentRegion())->setName('List Filters Region ' . uniqid());
        $em->persist($region);
        $em->flush();

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    private function product(FunctionalTester $I): ProductCore
    {
        $em = $this->em($I);
        $product = (new ProductCore())
            ->setSku('LISTFIL-' . strtoupper(substr(uniqid(), -6)))
            ->setName('List Filters Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    /** A credit note with one line, so issuing it has something to credit. */
    private function creditMemo(FunctionalTester $I, Company $company, string $number, string $total, bool $issue = false): string
    {
        $em = $this->em($I);

        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-09-11')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $memo->addLine(
            (new CreditMemoLine())->setName('Returned widget')->setQuantity('1.00')->setPrice($total)->setSubtotal($total),
        );
        $em->persist($memo);
        $em->flush();

        if ($issue) {
            $memo->issue();
            $em->flush();
        }

        return $number;
    }

    /** An RMA with one line, moved through its own named actions rather than by writing a status. */
    private function salesReturn(
        FunctionalTester $I,
        Company $company,
        string $number,
        bool $authorise = false,
        ?Warehouse $receiveAt = null,
    ): string {
        $em = $this->em($I);
        $product = $this->product($I);

        $return = (new SalesReturn())
            ->setCompany($company)
            ->setDocumentNumber($number);
        $return->addLine(
            (new SalesReturnLine())->setProduct($product)->setName('Returned widget')->setQuantity('1.00'),
        );
        $em->persist($return);
        $em->flush();

        if ($authorise || $receiveAt instanceof Warehouse) {
            $return->authorise();
            $em->flush();
        }

        if ($receiveAt instanceof Warehouse) {
            $return->receive($receiveAt);
            $em->flush();
        }

        return $number;
    }

    private function vendor(FunctionalTester $I, string $name): Vendor
    {
        $em = $this->em($I);
        $vendor = (new Vendor())->setName($name . ' ' . uniqid())->setPaymentTerm('Net 30');
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    private function debitMemo(FunctionalTester $I, Vendor $vendor, string $number, string $total, bool $issue = false): string
    {
        $em = $this->em($I);

        $memo = (new DebitMemo())
            ->setVendor($vendor)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-09-11');
        $memo->addLine(
            (new DebitMemoLine())->setName('Returned part')->setQuantity('1.00')->setUnitCost($total)->setSubtotal($total),
        );
        $memo->recalculateTotals();
        $em->persist($memo);
        $em->flush();

        if ($issue) {
            $memo->issue();
            $em->flush();
        }

        return $number;
    }

    private function purchaseOrder(
        FunctionalTester $I,
        Vendor $vendor,
        Warehouse $warehouse,
        string $number,
        bool $issue = false,
    ): string {
        $em = $this->em($I);
        $product = $this->product($I);

        $order = (new PurchaseOrder())
            ->setPoNumber($number)
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse)
            ->setDocumentDate('2026-09-11')
            ->setExpectedDate('2026-12-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantityOrdered('5.00')
            ->setUnitCost('2.0000')
            ->setSubtotal('10.00');
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        if ($issue) {
            $order->setStatus('Issued', DocumentActor::system());
        }

        $order->recalculateTotals();
        $em->flush();

        return $number;
    }
}
