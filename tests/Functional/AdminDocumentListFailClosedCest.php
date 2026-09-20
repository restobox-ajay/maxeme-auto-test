<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\SalesOrder;
use App\Entity\SalesReturn;
use Tests\Support\FunctionalTester;

/**
 * The order, credit note and sales return grids FAIL CLOSED on a customer id they cannot resolve
 * (queue item 30).
 *
 * ## The defect
 *
 * All three screens read `(int) ($search['company_id'] ?? 0)` and answered null on a miss, and the
 * callers then added NO where clause. So `/admin/order?OrderSearch[company_id]=99999` flashed a
 * warning that a toast can swallow and listed every customer's orders underneath it. The worse
 * half was silent: `12abc` casts to `12`, so a mangled link showed customer 12's documents under a
 * heading that read as if it were the right customer, with totals that were not theirs.
 *
 * `CompanyListScope` already models the three states this needs — not requested, resolved, and
 * requested-but-unresolved — and the invoice and quote grids already fail closed on the third.
 * These three screens now use the same helper, and this file is the proof, screen by screen.
 *
 * ## Why every test creates a SECOND customer
 *
 * An empty page is not evidence of a fail-closed filter: a fixture that never saved produces one
 * too, and so does a 500 rendered as an error page. So each case creates two customers with a
 * document each, and the bad-id cases assert:
 *
 *   1. HTTP 200,
 *   2. the named "This customer does not exist." empty state — the positive control proving the
 *      grid rendered at all,
 *   3. the scoped-to-nothing count, "of 0 <noun>", so the total and the rows agree,
 *   4. that the OTHER customer's document is specifically ABSENT — the failure that matters,
 *   5. and both documents re-read from the database afterwards, so the empty grid is a refusal to
 *      answer rather than a fixture that never existed (#624).
 *
 * ## Numbers
 *
 * No assertion here is a bare number (#627). Document numbers carry a screen-specific prefix, the
 * count is asserted as the whole "of 0 <noun>" phrase inside `.table-count` — the noun being that
 * screen's own footer label — and the customer names are words.
 */
final class AdminDocumentListFailClosedCest
{
    /** The shapes a `company_id` can arrive in that are NOT an id, plus one id nobody has. */
    private const ALPHA = 'Zed Closed Alpha Supplies';
    private const BETA = 'Zed Closed Beta Trading';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-document-list-fail-closed@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('CLOSED-' . uniqid())
            ->setPrimaryEmail('buyer-' . uniqid() . '@closed.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function makeOrder(FunctionalTester $I, Company $company, string $number): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($number)
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($order);

        return $order;
    }

    private function makeMemo(FunctionalTester $I, Company $company, string $number): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($memo);

        return $memo;
    }

    private function makeReturn(FunctionalTester $I, Company $company, string $number): SalesReturn
    {
        $return = (new SalesReturn())
            ->setCompany($company)
            ->setDocumentNumber($number);
        $I->haveInRepository($return);

        return $return;
    }

    /**
     * The id shapes that must never scope a list to somebody.
     *
     * `$anId` is a real customer's id, so `12abc` and `-12` are built from a number that genuinely
     * resolves — the point of those two is that a cast would find the customer inside them.
     *
     * @return list<string>
     */
    private function badIdsAround(int $anId, int $missingId): array
    {
        return [
            (string) $missingId,
            'abc',
            $anId . 'abc',
            '-' . $anId,
            '0',
        ];
    }

    /**
     * Re-read a document from the database and prove it is still filed under its customer.
     *
     * Absence from a fail-closed page is only evidence of a refusal if the row is still there.
     * Read back through the repository rather than through the entity the test already holds.
     */
    private function seeStillFiledUnder(FunctionalTester $I, string $entityClass, string $documentNumber, Company $company): void
    {
        // SalesOrder's getDocumentNumber() is an alias for its MAPPED orderNumber, so the criteria
        // key differs from the getter. Named here rather than guessed, because a wrong criteria key
        // is a repository exception that reads like a missing row.
        $field = $entityClass === SalesOrder::class ? 'orderNumber' : 'documentNumber';

        /** @var SalesOrder|CreditMemo|SalesReturn $stored */
        $stored = $I->grabEntityFromRepository($entityClass, [$field => $documentNumber]);
        $I->assertSame(
            $company->getId(),
            $stored->getCompany()?->getId(),
            $documentNumber . ' should still be filed under ' . $company->getName() . ' in the database.',
        );
    }

    /**
     * The fail-closed screen, asserted the same way on every grid.
     *
     * `$countNoun` is the footer's own word for what it is counting. `admin/_table_count.html.twig`
     * takes a `label` that defaults to 'entries', and the conformance work that gave the credit
     * note and sales return grids their status tabs gave them their own nouns too. The phrase is
     * still asserted WHOLE — "of 0 credit notes", never a bare 0 (#627) — so the count is pinned to
     * this screen's footer rather than to any number that happens to appear on the page.
     */
    private function seeNothingListedAndNobodyElsesDocuments(FunctionalTester $I, string $url, string $mine, string $theirs, string $countNoun = 'entries'): void
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        // Positive control: the page rendered, and it says why it is empty rather than looking
        // like an ordinary grid that happens to have no rows today.
        $I->see('This customer does not exist.', 'tbody');
        // The count is scoped too. A grid listing nothing over a footer reading "of 214 entries"
        // would be the same lie one line down.
        $I->see('of 0 ' . $countNoun, '.table-count');
        $I->dontSee($mine, 'tbody');
        // The one that matters: never somebody else's documents.
        $I->dontSee($theirs, 'tbody');
        $I->dontSee(self::BETA, 'tbody');
    }

    // ─────────────────────────────────────────────────────────────────────── orders

    public function theOrderGridScopedByCompanyIdShowsThatCustomerAndNoOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeOrder($I, $alpha, 'SO-CLOSED-ALPHA-' . uniqid());
        $theirs = $this->makeOrder($I, $beta, 'SO-CLOSED-BETA-' . uniqid());

        // Positive control: unfiltered, the grid carries both. Without it a filter that returns
        // nothing at all would pass every assertion below.
        $I->amOnPage('/admin/order');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getOrderNumber(), 'tbody');
        $I->see($theirs->getOrderNumber(), 'tbody');

        $I->amOnPage('/admin/order?OrderSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('List orders for ' . self::ALPHA, 'h1');
        $I->see($mine->getOrderNumber(), 'tbody');
        $I->dontSee($theirs->getOrderNumber(), 'tbody');
        $I->dontSee(self::BETA, 'tbody');

        $this->seeStillFiledUnder($I, SalesOrder::class, $theirs->getOrderNumber(), $beta);
    }

    /**
     * Every unresolvable shape lists NOTHING — never every customer's orders.
     *
     * This is the defect in one test. `99999` used to flash and then list the whole business;
     * `12abc` did not even flash, because the cast found customer 12 inside it.
     */
    public function anUnresolvableOrderCompanyIdListsNothingRatherThanEveryCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeOrder($I, $alpha, 'SO-GHOST-ALPHA-' . uniqid());
        $theirs = $this->makeOrder($I, $beta, 'SO-GHOST-BETA-' . uniqid());

        $missingId = max((int) $alpha->getId(), (int) $beta->getId()) + 50000;

        foreach ($this->badIdsAround((int) $alpha->getId(), $missingId) as $bad) {
            $this->seeNothingListedAndNobodyElsesDocuments(
                $I,
                '/admin/order?OrderSearch[company_id]=' . urlencode($bad),
                $mine->getOrderNumber(),
                $theirs->getOrderNumber(),
            );
        }

        // Not a scalar at all. This must not throw either — `query->all('OrderSearch')` on a
        // scalar, and `(string)` on an array, are both ways to turn a mangled link into a 500.
        $this->seeNothingListedAndNobodyElsesDocuments(
            $I,
            '/admin/order?company_id[]=' . $alpha->getId(),
            $mine->getOrderNumber(),
            $theirs->getOrderNumber(),
        );

        // Both rows are still there; the empty grid is a refusal to answer, not a wipe.
        $this->seeStillFiledUnder($I, SalesOrder::class, $mine->getOrderNumber(), $alpha);
        $this->seeStillFiledUnder($I, SalesOrder::class, $theirs->getOrderNumber(), $beta);
    }

    /**
     * An EMPTY value is not a bad id — it is no id, and the list stays unscoped.
     *
     * The company picker posts `company_id=""` before anybody has chosen one. Failing closed on
     * that would empty the grid every time somebody opened it.
     */
    public function anEmptyOrderCompanyIdLeavesTheGridUnscoped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeOrder($I, $alpha, 'SO-EMPTY-ALPHA-' . uniqid());
        $theirs = $this->makeOrder($I, $beta, 'SO-EMPTY-BETA-' . uniqid());

        foreach (['/admin/order?OrderSearch[company_id]=', '/admin/order?company_id='] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('This customer does not exist.', 'tbody');
            $I->see($mine->getOrderNumber(), 'tbody');
            $I->see($theirs->getOrderNumber(), 'tbody');
        }
    }

    /**
     * The scope survives the second click, followed rather than retyped.
     *
     * A scope dropped by the first status tab is worse than no scope: the grid widens back to every
     * customer mid-session and nothing on it says so. The tab href is read OFF THE RENDERED PAGE,
     * because a hand-built URL would only re-prove that the controller filters.
     */
    public function theOrderScopeSurvivesTheFilterFormAndTheStatusTabs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeOrder($I, $alpha, 'SO-STICKY-ALPHA-' . uniqid());
        $theirs = $this->makeOrder($I, $beta, 'SO-STICKY-BETA-' . uniqid());

        $I->amOnPage('/admin/order?OrderSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();

        // The column filters submit as a plain GET form, so the scope has to be a field inside it
        // or the first Search drops it.
        $I->assertSame(
            (string) $alpha->getId(),
            $I->grabAttributeFrom('//form[@id="order-grid-filters-form"]//input[@name="OrderSearch[company_id]"]', 'value'),
            'The order filter form must carry the scope, or the first search widens the grid.',
        );

        // Both orders are Draft, so Beta's absence is the scope holding rather than the status
        // filter doing the work.
        $draftTab = $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[normalize-space()="Draft"]', 'href');
        $I->amOnPage($draftTab);
        $I->seeResponseCodeIsSuccessful();
        $I->see('List orders for ' . self::ALPHA, 'h1');
        $I->see($mine->getOrderNumber(), 'tbody');
        $I->dontSee($theirs->getOrderNumber(), 'tbody');

        // And the way back out is a link on the page, not a URL to remember.
        $allOrders = $I->grabAttributeFrom('//div[contains(@class, "panel-actions")]//a[normalize-space()="All Orders"]', 'href');
        $I->amOnPage($allOrders);
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getOrderNumber(), 'tbody');
        $I->see($theirs->getOrderNumber(), 'tbody');
    }

    /** An unresolvable scope keeps its own state through a status tab, rather than widening on click two. */
    public function theOrderFailClosedStateSurvivesAStatusTab(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeOrder($I, $alpha, 'SO-GHOSTTAB-ALPHA-' . uniqid());
        $theirs = $this->makeOrder($I, $beta, 'SO-GHOSTTAB-BETA-' . uniqid());

        $missingId = max((int) $alpha->getId(), (int) $beta->getId()) + 50000;
        $I->amOnPage('/admin/order?OrderSearch[company_id]=' . $missingId);
        $I->seeResponseCodeIsSuccessful();

        $draftTab = $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[normalize-space()="Draft"]', 'href');
        $this->seeNothingListedAndNobodyElsesDocuments($I, $draftTab, $mine->getOrderNumber(), $theirs->getOrderNumber());
    }

    // ─────────────────────────────────────────────────────────────── credit notes

    public function theCreditNoteGridScopedByCompanyIdShowsThatCustomerAndNoOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeMemo($I, $alpha, 'CN-CLOSED-ALPHA-' . uniqid());
        $theirs = $this->makeMemo($I, $beta, 'CN-CLOSED-BETA-' . uniqid());

        $I->amOnPage('/admin/credit-memo/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/credit-memo/index?CreditMemoSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Credit notes for ' . self::ALPHA, 'h1');
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        $I->dontSee(self::BETA, 'tbody');

        $this->seeStillFiledUnder($I, CreditMemo::class, $theirs->getDocumentNumber(), $beta);
    }

    public function anUnresolvableCreditNoteCompanyIdListsNothingRatherThanEveryCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeMemo($I, $alpha, 'CN-GHOST-ALPHA-' . uniqid());
        $theirs = $this->makeMemo($I, $beta, 'CN-GHOST-BETA-' . uniqid());

        $missingId = max((int) $alpha->getId(), (int) $beta->getId()) + 50000;

        foreach ($this->badIdsAround((int) $alpha->getId(), $missingId) as $bad) {
            $this->seeNothingListedAndNobodyElsesDocuments(
                $I,
                '/admin/credit-memo/index?CreditMemoSearch[company_id]=' . urlencode($bad),
                $mine->getDocumentNumber(),
                $theirs->getDocumentNumber(),
                'credit notes',
            );
        }

        $this->seeNothingListedAndNobodyElsesDocuments(
            $I,
            '/admin/credit-memo/index?company_id[]=' . $alpha->getId(),
            $mine->getDocumentNumber(),
            $theirs->getDocumentNumber(),
            'credit notes',
        );

        $this->seeStillFiledUnder($I, CreditMemo::class, $mine->getDocumentNumber(), $alpha);
        $this->seeStillFiledUnder($I, CreditMemo::class, $theirs->getDocumentNumber(), $beta);
    }

    public function anEmptyCreditNoteCompanyIdLeavesTheGridUnscoped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeMemo($I, $alpha, 'CN-EMPTY-ALPHA-' . uniqid());
        $theirs = $this->makeMemo($I, $beta, 'CN-EMPTY-BETA-' . uniqid());

        foreach (['/admin/credit-memo/index?CreditMemoSearch[company_id]=', '/admin/credit-memo/index?company_id='] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('This customer does not exist.', 'tbody');
            $I->see($mine->getDocumentNumber(), 'tbody');
            $I->see($theirs->getDocumentNumber(), 'tbody');
        }
    }

    /**
     * The scope survives the second click.
     *
     * Two notes for Alpha at one per page means page 2 exists; the link to it is read off page 1
     * and followed. The status tabs are checked alongside it — the conformance work that added
     * them also re-derived this screen's scope variable from `company` alone, which would have
     * dropped an unresolved id and widened a failed-closed grid on the first tab click.
     */
    public function theCreditNoteScopeSurvivesThePager(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        // Deliberately NOT uniqid()-suffixed: the default sort is by document number descending,
        // so fixed names make "which note is on page 2" a fact rather than a coin toss. Every test
        // in this suite runs inside a transaction that is rolled back, so the numbers are free.
        $first = $this->makeMemo($I, $alpha, 'CN-PAGER-ALPHA-B');
        $second = $this->makeMemo($I, $alpha, 'CN-PAGER-ALPHA-A');
        $theirs = $this->makeMemo($I, $beta, 'CN-PAGER-BETA-Z');

        $I->amOnPage('/admin/credit-memo/index?CreditMemoSearch[company_id]=' . $alpha->getId() . '&limit=1');
        $I->seeResponseCodeIsSuccessful();
        $I->see($first->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        // Every rendered control that takes a second click carries the scope, or it widens the
        // grid. The JS pager's data-ajax-url used to be one of them; this screen is server-paged
        // now and its status tabs are the control that replaced it.
        $I->assertStringContainsString(
            'company_id',
            (string) $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[1]', 'href'),
            'The credit note status tabs must carry the scope.',
        );

        $nextPage = $I->grabAttributeFrom('//div[contains(@class, "table-pagination")]/a[normalize-space()="2"]', 'href');
        $I->amOnPage($nextPage);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Credit notes for ' . self::ALPHA, 'h1');
        $I->see($second->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        $I->dontSee(self::BETA, 'tbody');

        $this->seeStillFiledUnder($I, CreditMemo::class, $theirs->getDocumentNumber(), $beta);
    }

    // ────────────────────────────────────────────────────────────── sales returns

    public function theSalesReturnGridScopedByCompanyIdShowsThatCustomerAndNoOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeReturn($I, $alpha, 'RMA-CLOSED-ALPHA-' . uniqid());
        $theirs = $this->makeReturn($I, $beta, 'RMA-CLOSED-BETA-' . uniqid());

        $I->amOnPage('/admin/sales-return/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->see($theirs->getDocumentNumber(), 'tbody');

        $I->amOnPage('/admin/sales-return/index?SalesReturnSearch[company_id]=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Returns for ' . self::ALPHA, 'h1');
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        $I->dontSee(self::BETA, 'tbody');

        // The flat `?company=` spelling this screen has always also accepted resolves the same way.
        $I->amOnPage('/admin/sales-return/index?company=' . $alpha->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        $this->seeStillFiledUnder($I, SalesReturn::class, $theirs->getDocumentNumber(), $beta);
    }

    public function anUnresolvableSalesReturnCompanyIdListsNothingRatherThanEveryCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeReturn($I, $alpha, 'RMA-GHOST-ALPHA-' . uniqid());
        $theirs = $this->makeReturn($I, $beta, 'RMA-GHOST-BETA-' . uniqid());

        $missingId = max((int) $alpha->getId(), (int) $beta->getId()) + 50000;

        foreach ($this->badIdsAround((int) $alpha->getId(), $missingId) as $bad) {
            $this->seeNothingListedAndNobodyElsesDocuments(
                $I,
                '/admin/sales-return/index?SalesReturnSearch[company_id]=' . urlencode($bad),
                $mine->getDocumentNumber(),
                $theirs->getDocumentNumber(),
                'returns',
            );

            // And through the flat spelling, which used to go through getInt() — a cast one level
            // further from the id than the nested one, and just as wrong on `12abc`.
            $this->seeNothingListedAndNobodyElsesDocuments(
                $I,
                '/admin/sales-return/index?company=' . urlencode($bad),
                $mine->getDocumentNumber(),
                $theirs->getDocumentNumber(),
                'returns',
            );
        }

        $this->seeNothingListedAndNobodyElsesDocuments(
            $I,
            '/admin/sales-return/index?company_id[]=' . $alpha->getId(),
            $mine->getDocumentNumber(),
            $theirs->getDocumentNumber(),
            'returns',
        );

        $this->seeStillFiledUnder($I, SalesReturn::class, $mine->getDocumentNumber(), $alpha);
        $this->seeStillFiledUnder($I, SalesReturn::class, $theirs->getDocumentNumber(), $beta);
    }

    public function anEmptySalesReturnCompanyIdLeavesTheGridUnscoped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $mine = $this->makeReturn($I, $alpha, 'RMA-EMPTY-ALPHA-' . uniqid());
        $theirs = $this->makeReturn($I, $beta, 'RMA-EMPTY-BETA-' . uniqid());

        $urls = [
            '/admin/sales-return/index?SalesReturnSearch[company_id]=',
            '/admin/sales-return/index?company_id=',
            '/admin/sales-return/index?company=',
        ];
        foreach ($urls as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('This customer does not exist.', 'tbody');
            $I->see($mine->getDocumentNumber(), 'tbody');
            $I->see($theirs->getDocumentNumber(), 'tbody');
        }
    }

    /** Same pager reasoning as the credit note grid: no status tabs, so the pager is click two. */
    public function theSalesReturnScopeSurvivesThePager(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $alpha = $this->makeCompany($I, self::ALPHA);
        $beta = $this->makeCompany($I, self::BETA);
        $first = $this->makeReturn($I, $alpha, 'RMA-PAGER-ALPHA-B');
        $second = $this->makeReturn($I, $alpha, 'RMA-PAGER-ALPHA-A');
        $theirs = $this->makeReturn($I, $beta, 'RMA-PAGER-BETA-Z');

        $I->amOnPage('/admin/sales-return/index?SalesReturnSearch[company_id]=' . $alpha->getId() . '&limit=1');
        $I->seeResponseCodeIsSuccessful();
        $I->see($first->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');

        // As on the credit note grid: server-paged now, so the status tabs are the second-click
        // control the scope has to survive, not the JS pager's data-ajax-url.
        $I->assertStringContainsString(
            'company_id',
            (string) $I->grabAttributeFrom('//nav[contains(@class, "order-status-tabs")]/a[1]', 'href'),
            'The sales return status tabs must carry the scope.',
        );

        $nextPage = $I->grabAttributeFrom('//div[contains(@class, "table-pagination")]/a[normalize-space()="2"]', 'href');
        $I->amOnPage($nextPage);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Returns for ' . self::ALPHA, 'h1');
        $I->see($second->getDocumentNumber(), 'tbody');
        $I->dontSee($theirs->getDocumentNumber(), 'tbody');
        $I->dontSee(self::BETA, 'tbody');

        $this->seeStillFiledUnder($I, SalesReturn::class, $theirs->getDocumentNumber(), $beta);
    }
}
