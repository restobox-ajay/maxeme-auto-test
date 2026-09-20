<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use ProcurementBundle\Entity\AbstractPurchaseDocument;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Enum\VendorBillStatus;
use ProcurementBundle\Match\MatchLine;
use ProcurementBundle\Match\ThreeWayMatchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The exception views (#555) — the whole reason a three-way match is worth building.
 *
 * A clean match needs nobody. These screens are the ones somebody actually works:
 *
 *  - **Billed but not received** — we are being asked to pay for goods we do not have. The most
 *    expensive exception on the list, and the reason quantity is matched against the receipts
 *    rather than against the order.
 *  - **Received but not billed** — an accrual. We owe money nobody has asked for yet, and a period
 *    close that only adds up the bills understates the liability.
 *  - **Price and quantity variance** — charged more than quoted, or over/under shipped.
 *
 * Received-but-not-billed is deliberately built from the open purchase order lines rather than from
 * the bills: goods that arrived and that no bill has ever mentioned are, by definition, not on any
 * bill to be found from one.
 *
 * ## Every row says what it is WORTH, and what to DO (item 49)
 *
 * Two things were missing, and they are the same omission twice.
 *
 * The biggest table had no money column at all, so a $5 discrepancy and a $5,000 one read
 * identically and "which of these matters" had no answer on the screen that exists to answer it.
 * Every row now carries what it puts at risk, in the document's own currency to two decimals, and
 * that column is the one the screen is sorted by — biggest first on arrival, because the question
 * this screen is opened with is "what do I do first". `MatchLine::amountAtRiskCents()` says how the
 * figure is arrived at and why a row with several findings reports one number rather than their sum.
 *
 * And every row stated the FINDING and stopped. "Billed but not received" means chase the receipt
 * or refuse to pay, and neither was reachable from the row — the verdict named a problem and left
 * the person to go and find the screen that fixes it. `movesForBillLine()` puts the one or two real
 * moves on the row itself, each gated on the state that would refuse it, so nothing here is offered
 * that cannot be completed.
 */
#[Route('/admin/bundles/procurement/exceptions')]
final class ExceptionController extends AbstractProcurementController
{
    /**
     * Over-billing across MORE THAN ONE bill, which no per-bill match can see (#658).
     *
     * Not a `MatchLine` exception kind, because the three-way match reports on one bill at a time
     * and this is a property of a purchase order LINE: two bills of 100 each against an order for
     * 100 are individually clean and jointly a double payment. It is the same shape as the accrual
     * list below — built from the purchase order lines, because a fact about several bills cannot
     * be found from any one of them.
     */
    public const KIND_OVER_BILLED = 'over_billed';

    /**
     * How many DOCUMENTS each of this screen's three feeds will read before it stops.
     *
     * Every row here costs a three-way match or a walk over a purchase order's lines, so the feeds
     * have always stopped somewhere — but the number was written three times, as a bare `200` in
     * three `setMaxResults()` calls, and the screen said nothing about it. A 201st bill was simply
     * absent, and the footer's "Showing 1 to 200 of 200" read as though 200 was all there was.
     *
     * It is a cap on documents SCANNED, not on rows shown: one bill can contribute several
     * exception lines, so the row count and this number are in different units and the screen has
     * to state them separately. See `scan()` for how the true figure is arrived at without a COUNT
     * query, and `_exception_footer.html.twig` for what the screen now says when it bites.
     */
    public const DEFAULT_SCAN_CAP = 200;

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly ThreeWayMatchService $matcher,
        /**
         * Injected rather than hard-coded so an installation with a bigger AP desk can raise it,
         * and so a test can prove what the screen says when the cap BITES without creating two
         * hundred bills to do it. Bound in the bundle's services.yaml to
         * `procurement.exception_scan_cap`.
         */
        private readonly int $scanCap = self::DEFAULT_SCAN_CAP,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    /**
     * The only ordering this worklist offers, and the reason it is the default.
     *
     * The screen exists to answer "what do I do first", and that question is answered by size: a
     * $5 discrepancy and a $5,000 one looked identical here until the amount column existed. So the
     * biggest exposure is at the top on arrival rather than after a click, and the header toggles
     * the direction. It is named in the URL rather than left implicit so that a link into this
     * screen — item 48's count-link, or one pasted into a message — states the order it meant.
     */
    public const SORT_AMOUNT = 'amount';

    #[Route('', name: 'admin_bundle_procurement_exceptions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['kind', 'vendor']);
        $kind = $filters['kind'] !== '' ? $filters['kind'] : 'all';

        // Whitelisted, like every other ordering in this bundle: an unknown `sort` reads as the
        // default rather than reaching a column name. Descending first — the point of the column.
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sort = self::SORT_AMOUNT;

        // Each feed reports what it READ alongside what it returned: how many documents its query
        // matched, and how many of those the cap let it look at. A screen that shows 200 rows out of
        // 205 documents and says nothing is not reporting a smaller number, it is reporting a
        // different question's answer as though it were this one's.
        $billScan = ['scanned' => 0, 'available' => 0];
        $accrualScan = ['scanned' => 0, 'available' => 0];
        $overBilledScan = ['scanned' => 0, 'available' => 0];

        $billExceptions = $this->sortByAmount($this->billExceptions($filters, $kind, $billScan), $dir);
        $accruals = \in_array($kind, ['all', MatchLine::EXCEPTION_RECEIVED_NOT_BILLED], true)
            ? $this->sortByAmount($this->receivedNotBilled($filters, $accrualScan), $dir)
            : [];
        $overBilled = \in_array($kind, ['all', self::KIND_OVER_BILLED], true)
            ? $this->sortByAmount($this->overBilled($filters, $overBilledScan), $dir)
            : [];

        return $this->render('@Procurement/exceptions.html.twig', [
            'kind' => $kind,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'vendors' => $this->activeVendors(),
            'billExceptions' => $billExceptions,
            'accruals' => $accruals,
            'overBilled' => $overBilled,
            // Counted in PHP from the rows already in hand, never with a COUNT query. This screen
            // caps two feeds at 200 rows and counts neither, and AdminListScreenConventionsCest
            // reads "ran a counting AND a limiting query rooted at table X" as "this screen is X's
            // list screen" — so a count(*) here would quietly claim to be both the purchase order
            // list and the vendor bill list and leave two documents with two screens each.
            'billExceptionCount' => \count($billExceptions),
            'accrualCount' => \count($accruals),
            'overBilledCount' => \count($overBilled),
            'billExceptionTotal' => $this->totalOf($billExceptions),
            'accrualTotal' => $this->totalOf($accruals),
            'overBilledTotal' => $this->totalOf($overBilled),
            // What each feed actually read, so the footer can say so when the cap bit. See `scan()`.
            'billScan' => $billScan,
            'accrualScan' => $accrualScan,
            'overBilledScan' => $overBilledScan,
            // The population this screen's biggest table is NOT looking at, and only computed when
            // its absence is about to be mis-stated.
            //
            // The empty state used to say "Every bill matches its purchase order and its receipts"
            // — a claim about EVERY bill, made by a query that excludes Draft and Void. A draft bill
            // with ten broken lines sat behind that sentence saying ten exceptions on its own page,
            // and a real user believed the screen that was wrong. So when this table comes back
            // empty the screen states its scope and says what is outside it.
            //
            // Only when it comes back empty: the figure costs a three-way match per draft bill, the
            // empty case is the one where that work is both affordable (this feed found nothing to
            // do) and load-bearing (it is the sentence that lied). A non-empty table makes no claim
            // about drafts, so it is not charged for one.
            'draftExceptionBills' => $billExceptions === [] ? $this->draftBillsCarryingExceptions($filters, $kind) : null,
            'kinds' => [
                'all' => 'Everything',
                MatchLine::EXCEPTION_BILLED_NOT_RECEIVED => 'Billed but not received',
                MatchLine::EXCEPTION_RECEIVED_NOT_BILLED => 'Received but not billed',
                MatchLine::EXCEPTION_PRICE_VARIANCE => 'Price variance',
                MatchLine::EXCEPTION_QUANTITY_VARIANCE => 'Quantity variance',
                MatchLine::EXCEPTION_UNMATCHED => 'Not on the purchase order',
                self::KIND_OVER_BILLED => 'Billed more than ordered or received',
            ],
        ]);
    }

    /**
     * Every live bill whose match has something to say — one row per LINE, not per bill.
     *
     * Draft and Void bills are skipped: a draft has authorised nothing and a void bill is not a
     * charge, so listing either would bury the ones where money is actually at stake. WHICH states
     * those are is not decided here — `VendorBillStatus::uncounted()` decides it, and the bill's own
     * detail page reads the same rule to say whether its exceptions reach this list. See
     * `billsInScope()`.
     *
     * Flat rather than grouped by bill, because the screen's ordering question is "which of these
     * is the biggest" and that is a property of a line. Grouping would force the amount column to
     * sort bills by their worst line and then shuffle lines inside them, which is two orderings
     * fighting over one column.
     *
     * @param array<string, string>               $filters
     * @param array{scanned: int, available: int} $scan    out: what the cap let this feed read
     *
     * @return list<array{bill: VendorBill, line: MatchLine, currency: string, amountCents: int, amount: string, moves: list<array{label: string, href: string}>}>
     */
    private function billExceptions(array $filters, string $kind, array &$scan = []): array
    {
        $qb = $this->billsInScope($filters);

        $out = [];

        /** @var list<VendorBill> $bills */
        $bills = $this->scan($qb, 'b', $scan);

        foreach ($bills as $bill) {
            $lines = $this->matcher->match($bill)->exceptions();

            if ($kind !== 'all') {
                $lines = array_values(array_filter($lines, static fn (MatchLine $line): bool => $line->has($kind)));
            }

            foreach ($lines as $line) {
                $out[] = [
                    'bill' => $bill,
                    'line' => $line,
                    'currency' => $bill->getCurrency(),
                    'amountCents' => $line->amountAtRiskCents(),
                    'amount' => $line->amountAtRisk(),
                    'moves' => $this->movesForBillLine($bill, $line),
                ];
            }
        }

        return $out;
    }

    /**
     * The bills this worklist covers, as a query — the ONE statement of that scope.
     *
     * The excluded states come from `VendorBillStatus::uncounted()`, which enumerates the cases
     * `VendorBillStatus::counts()` answers false for. Nothing here names Draft or Void, and neither
     * does `bill_detail.html.twig`: the bill's own page asks `counts()` about the bill in its hand
     * and says whether these findings reach this list. Two hand-written copies of `[Draft, Void]`
     * is how a screen ends up claiming a scope its query does not have, which is the defect this
     * method exists to make unrepeatable.
     *
     * @param array<string, string> $filters
     */
    private function billsInScope(array $filters): QueryBuilder
    {
        $qb = $this->em->getRepository(VendorBill::class)->createQueryBuilder('b')
            ->andWhere('b.status NOT IN (:ignored)')
            ->setParameter('ignored', VendorBillStatus::uncounted())
            ->orderBy('b.documentDate', 'DESC')
            ->addOrderBy('b.id', 'DESC');

        if ($filters['vendor'] !== '') {
            $qb->andWhere('IDENTITY(b.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }

        return $qb;
    }

    /**
     * Run a feed's query under the cap, and report how much of the population it got to see.
     *
     * `available` is the number of documents the query matches with no cap on it; `scanned` is how
     * many of them came back. The screen says so whenever they differ, because "showing 200" and
     * "there are 200" are different statements and the footer used to make the second one out of
     * the first.
     *
     * ## Why the total is counted in PHP over an id projection
     *
     * Not to save a query — a `COUNT(*)` would be cheaper. `AdminListScreenConventionsCest` maps a
     * document to its list screen by watching for a request that ran BOTH a counting query and a
     * limiting query rooted at the same table, and that reading is what keeps this screen from
     * being mistaken for the vendor bill list and the purchase order list on top of being itself.
     * A `COUNT(*)` here would hand `VendorBill` and `PurchaseOrder` two list screens each and the
     * rules read off those screens would quietly stop being asked. An id-only scan is neither
     * counted nor limited, so the mapping stays true and the number is still exact rather than a
     * "more than 200" from over-fetching by one.
     *
     * The same reasoning is already on `index()` for the ROW counts, which have always been
     * counted in PHP from the rows in hand for this reason.
     *
     * @param array{scanned: int, available: int} $scan out
     *
     * @return list<object>
     */
    private function scan(QueryBuilder $qb, string $alias, array &$scan): array
    {
        $available = \count(
            (clone $qb)
                ->select($alias . '.id')
                ->resetDQLPart('orderBy')
                ->getQuery()
                ->getSingleColumnResult(),
        );

        /** @var list<object> $rows */
        $rows = $qb->setMaxResults($this->scanCap)->getQuery()->getResult();

        $scan = ['scanned' => \count($rows), 'available' => $available];

        return $rows;
    }

    /**
     * How many DRAFT bills currently carry match exceptions — the population this screen excludes.
     *
     * Read only when the bill-exception table came back empty, because that is the only moment the
     * screen is about to make a statement about bills it never looked at. It is the same three-way
     * match the rest of the screen runs, over the bills `billsInScope()` leaves out, and it is
     * capped by the same `$scanCap`: a figure that is itself unbounded work would be a worse lie
     * than the one it replaces.
     *
     * A void bill is deliberately NOT counted. A draft is on its way to this list — approving it
     * puts it here, which is what the sentence tells the reader — while a void bill has been
     * withdrawn and is never coming, so counting it would invite somebody to go and look for work
     * that does not exist.
     *
     * It reads the screen's own `kind` filter for the same reason it reads its vendor filter: this
     * sentence is rendered under a table that has been narrowed, and a count of drafts carrying ANY
     * exception, printed beneath a table showing only price variances, is a second number about a
     * second population — which is the whole defect this was written to close.
     *
     * @param array<string, string> $filters
     */
    private function draftBillsCarryingExceptions(array $filters, string $kind): int
    {
        $qb = $this->em->getRepository(VendorBill::class)->createQueryBuilder('b')
            ->andWhere('b.status = :draft')
            ->setParameter('draft', VendorBillStatus::Draft)
            ->orderBy('b.id', 'DESC')
            ->setMaxResults($this->scanCap);

        if ($filters['vendor'] !== '') {
            $qb->andWhere('IDENTITY(b.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }

        $carrying = 0;

        /** @var list<VendorBill> $drafts */
        $drafts = $qb->getQuery()->getResult();

        foreach ($drafts as $draft) {
            $lines = $this->matcher->match($draft)->exceptions();

            if ($kind !== 'all') {
                $lines = array_filter($lines, static fn (MatchLine $line): bool => $line->has($kind));
            }

            if ($lines !== []) {
                ++$carrying;
            }
        }

        return $carrying;
    }

    /**
     * What a buyer actually DOES about this line, as links to the screen that completes it.
     *
     * The screen used to state the finding and stop there. "Billed but not received" means chase
     * the receipt or refuse to pay, and neither was reachable from the row — the row named the
     * problem and left the person to go and find the right screen for it.
     *
     * Each move below is a control that can actually succeed, which is the rule item 47 exists to
     * enforce: an action offered and then refused is worse than no action, because it costs a click
     * and a page load to learn what the row could have said. So each one is GATED on the state that
     * would refuse it:
     *
     *  - **Record the receipt** needs a purchase order that still takes receipts —
     *    `PurchaseOrderStatus::refusesReceipts()`, the same test ReceivingService applies, so this
     *    screen and the receiving service cannot disagree about whether the button would work.
     *  - **Dispute this bill** needs a bill in one of the three states `VendorBill::dispute()`
     *    accepts. A Paid or already-Disputed bill is not offered it; the move there is to claim the
     *    money back with a debit memo, which is the action that IS available.
     *  - **Enter the bill** and **Raise a debit memo** are creates. They are always available, and
     *    they arrive pre-filled — `?po=` transcribes the order, `?bill=` transcribes the bill.
     *
     * ## Why these moves for these findings
     *
     *  - **Billed but not received** — either the goods are here and nobody booked them in, or they
     *    are not. Record the receipt settles the first; disputing the bill parks it out of the
     *    payment run until the vendor explains the second. Those are the only two answers, and the
     *    controller docblock has named them since #555. The debit memo sits behind both as the
     *    third, for the case where neither is available any more: the bill is PAID, so there is
     *    nothing left to withhold and the money has to be claimed back instead.
     *  - **Price variance** — they charged more than they quoted. Refuse to pay it (dispute) or bill
     *    it back (debit memo, pre-filled from this bill's own lines).
     *  - **Quantity variance** — nothing to do with this bill: it is the gap between what was
     *    ordered and what turned up. Short means goods are still owed, so the move is to receive
     *    them when they land. Over means we are holding goods nobody ordered, so the move is to send
     *    them back.
     *  - **Not on the purchase order** — a charge with nothing behind it. Legitimate often enough
     *    (freight, a substitution) that it is not an error, so the two moves are the two verdicts:
     *    refuse it, or accept it and claim the part that is wrong. Approving the bill is deliberately
     *    NOT offered: `VendorBill::approve()` accepts Draft and Disputed only, and drafts never
     *    reach this screen, so on most of these rows the button could not succeed.
     *
     * ## "Drafts never reach this screen" is TRUE, and the dispute gate includes Draft anyway
     *
     * That reads like a contradiction and is not, so it is written down rather than left to be
     * rediscovered. `billsInScope()` excludes every state `VendorBillStatus::counts()` is false for,
     * which is Draft and Void, so no draft is ever passed to this method. The dispute gate below
     * still answers for one, because it is not a statement about this screen: it is
     * `VendorBill::canBeDisputed()`, the entity's own guard, and `VendorBill::dispute()` genuinely
     * does accept a Draft — from the bill's own page, where the dispute form lives and where a draft
     * very much can be reached.
     *
     * So the branch is dead HERE and load-bearing THERE, and that is the right way round: a gate
     * narrowed to this screen's population would be a fourth copy of the rule, and the day drafts
     * started appearing on a worklist it would silently hide a control that works.
     *
     * What was actually wrong was neither of them. It was that the bill's own page said "N
     * exception(s). Every one needs a human." about a draft whose exceptions no worklist would ever
     * show, and this screen's empty state said "Every bill matches" while excluding that same bill.
     * Both are fixed in the templates; see `VendorBillController::detail()` and
     * `draftBillsCarryingExceptions()`.
     *  - **Received but not billed** — the vendor has not billed us for goods we have. Enter the
     *    bill, against the order that is already short of one.
     *
     * The moves are collected in order of the findings' SIZE, so the first link on a row is the
     * move for the biggest thing wrong with it, and capped at two: a row offering five links is a
     * menu, and the point of this column is that there is something obvious to do.
     *
     * @return list<array{label: string, href: string}>
     */
    private function movesForBillLine(VendorBill $bill, MatchLine $line): array
    {
        $order = $line->orderLine?->getPurchaseOrder() ?? $bill->getPurchaseOrder();
        $canReceive = $order instanceof PurchaseOrder && !$order->getStatusEnum()->refusesReceipts();
        // Asked of the bill rather than re-derived here. `VendorBill::canBeDisputed()` is
        // `VendorBill::dispute()`'s own guard, so this row cannot offer a move that would throw.
        // It answers true for a Draft, which `billsInScope()` means never gets asked — see the
        // docblock above for why that dead branch is correct and not a contradiction.
        $canDispute = $bill->canBeDisputed();

        $receive = $canReceive
            ? ['label' => 'Record the receipt', 'href' => $this->generateUrl('admin_bundle_procurement_receive', ['po' => $order->getId()])]
            : null;
        $dispute = $canDispute ? $this->disputeMove($bill, $line) : null;
        $debitMemo = ['label' => 'Raise a debit memo', 'href' => $this->generateUrl('admin_bundle_procurement_debit_memo_new', ['bill' => $bill->getId()])];

        $moves = [];

        foreach ($this->exceptionsBySize($line) as $exception) {
            $candidates = match ($exception) {
                MatchLine::EXCEPTION_BILLED_NOT_RECEIVED => [$receive, $dispute, $debitMemo],
                MatchLine::EXCEPTION_PRICE_VARIANCE, MatchLine::EXCEPTION_UNMATCHED => [$dispute, $debitMemo],
                MatchLine::EXCEPTION_QUANTITY_VARIANCE => [$this->quantityVarianceMove($line, $order, $receive)],
                MatchLine::EXCEPTION_RECEIVED_NOT_BILLED => [
                    $order instanceof PurchaseOrder
                        ? ['label' => 'Enter the bill', 'href' => $this->generateUrl('admin_bundle_procurement_bill_new', ['po' => $order->getId()])]
                        : null,
                ],
                default => [],
            };

            foreach ($candidates as $move) {
                if ($move === null) {
                    continue;
                }

                $moves[$move['href']] = $move;
            }
        }

        return array_slice(array_values($moves), 0, 2);
    }

    /**
     * Short-shipped means goods are still owed; over-shipped means we are holding goods nobody
     * ordered. One finding, two opposite moves, decided by which way the gap runs.
     *
     * @param array{label: string, href: string}|null $receive
     *
     * @return array{label: string, href: string}|null
     */
    private function quantityVarianceMove(MatchLine $line, ?PurchaseOrder $order, ?array $receive): ?array
    {
        if (QuantityScale::compare($line->quantityReceived, $line->quantityOrdered) > 0 && $order instanceof PurchaseOrder) {
            return [
                'label' => 'Return to the vendor',
                'href' => $this->generateUrl('admin_bundle_procurement_vendor_return_new', ['vendor' => $order->getVendor()->getId()]),
            ];
        }

        return $receive;
    }

    /**
     * The dispute form on the bill, with the finding already typed into it.
     *
     * `VendorBill::dispute()` refuses an empty reason — what is wrong with it is the whole content
     * of a dispute — so this cannot be a one-click action from a list without the screen inventing
     * somebody's reason for them. It carries the match's own words instead and leaves the person to
     * confirm or rewrite them, which is the honest version of the same convenience.
     *
     * @return array{label: string, href: string}
     */
    private function disputeMove(VendorBill $bill, MatchLine $line): array
    {
        $reason = sprintf('%s: %s', $line->description, lcfirst($line->describeExceptions()));

        return [
            'label' => 'Dispute this bill',
            'href' => $this->generateUrl('admin_bundle_procurement_bill', [
                'id' => $bill->getId(),
                'dispute' => mb_substr($reason, 0, 255),
            ]) . '#dispute',
        ];
    }

    /**
     * This line's findings, biggest money first — so the first move offered is the move for the
     * biggest thing wrong.
     *
     * @return list<string>
     */
    private function exceptionsBySize(MatchLine $line): array
    {
        $exceptions = $line->exceptions;

        usort(
            $exceptions,
            static fn (string $a, string $b): int => $line->amountForCents($b) <=> $line->amountForCents($a),
        );

        return $exceptions;
    }

    /**
     * Order any of the three feeds by the money in it.
     *
     * Sorted on `amountCents`, an integer, and never on the formatted string beside it: `'1000'`
     * sorts before `'9'` as text, which is exactly the bug an amount column gets when somebody
     * sorts the thing being displayed rather than the thing being measured. PHP's sort has been
     * stable since 8.0, so rows of equal size keep the document order the query gave them.
     *
     * @template T of array{amountCents: int}
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    private function sortByAmount(array $rows, string $dir): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => $dir === 'asc'
                ? $a['amountCents'] <=> $b['amountCents']
                : $b['amountCents'] <=> $a['amountCents'],
        );

        return $rows;
    }

    /**
     * What a section adds up to — the "how much is at risk" item 49 says is unanswerable.
     *
     * Summed in cents and formatted once, so the total is the sum of the rows and not the sum of
     * their roundings.
     *
     * **Null when the rows are not all in one currency, and that is not a corner case being tidied
     * away.** `AbstractPurchaseDocument::$currency` is per document and never converted — a stored
     * rate nobody maintains is worse than no rate at all — and a vendor's currency is whatever the
     * vendor's currency is. Adding CAD to USD to produce one confident number would be the loudest
     * wrong answer on the screen. The footer says so in words instead; making it a single figure
     * needs a conversion layer this application deliberately does not have.
     *
     * @param list<array{currency: string, amountCents: int}> $rows
     *
     * @return array{currency: string, amount: string}|null
     */
    private function totalOf(array $rows): ?array
    {
        $currencies = array_unique(array_column($rows, 'currency'));

        if (\count($currencies) !== 1) {
            return null;
        }

        return [
            'currency' => (string) reset($currencies),
            'amount' => MatchLine::money(array_sum(array_column($rows, 'amountCents'))),
        ];
    }

    /**
     * Goods that arrived against a purchase order line that no bill mentions — the accrual list.
     *
     * @param array<string, string>               $filters
     * @param array{scanned: int, available: int} $scan    out: what the cap let this feed read
     *
     * @return list<array{order: PurchaseOrder, line: PurchaseOrderLine, currency: string, amountCents: int, amount: string, moves: list<array{label: string, href: string}>}>
     */
    private function receivedNotBilled(array $filters, array &$scan = []): array
    {
        $qb = $this->em->getRepository(PurchaseOrder::class)->createQueryBuilder('p')
            ->andWhere('p.status IN (:live)')
            ->setParameter('live', [
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Closed,
            ])
            ->orderBy('p.documentDate', 'DESC');

        if ($filters['vendor'] !== '') {
            $qb->andWhere('IDENTITY(p.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }

        /** @var list<PurchaseOrder> $orders */
        $orders = $this->scan($qb, 'p', $scan);

        $rows = [];

        foreach ($orders as $order) {
            foreach ($order->getLines() as $line) {
                if (!$line->hasReceipts()) {
                    continue;
                }

                $billed = $this->billedQuantityFor($line);
                $received = $line->getQuantityReceived();

                // Nothing left to accrue on this line. Note what this skip used to HIDE: a line
                // billed for MORE than arrived also satisfies it, so an over-billed line vanished
                // from the one screen a person would check to catch a double payment. It does not
                // any more — overBilled() below reports exactly that case, from the same figures.
                if (QuantityScale::compare($billed, $received) >= 0) {
                    continue;
                }

                $unbilled = QuantityScale::sub($received, $billed);

                // What the unbilled units are worth at the price we agreed, which is the figure
                // an accrual is booked at.
                $cents = AbstractPurchaseDocument::cents(QuantityScale::mul($unbilled, $line->getUnitCost(), 6));

                $rows[] = [
                    'order' => $order,
                    'line' => $line,
                    'currency' => $order->getCurrency(),
                    'amountCents' => $cents,
                    'amount' => MatchLine::money($cents),
                    // The vendor has not billed us for goods we are holding. The move is to enter
                    // the bill against the order that is already short of one — always available,
                    // and pre-filled from the order by `?po=`.
                    'moves' => [[
                        'label' => 'Enter the bill',
                        'href' => $this->generateUrl('admin_bundle_procurement_bill_new', ['po' => $order->getId()]),
                    ]],
                ];
            }
        }

        return $rows;
    }

    /**
     * Purchase order lines charged for more than any document supports — the over-billing that has
     * already happened (#658).
     *
     * The guard refuses new ones; this shows the ones already on file, because there is no reason to
     * assume there are none. Before it, such a line appeared NOWHERE: the accrual list skips any
     * line whose billed quantity has reached what arrived, so an over-billed line silently
     * disappeared from the screen a person would check to catch a double payment.
     *
     * The test is `PurchaseOrderLine::isOverBilled()` — charged against the greater of ordered and
     * received — so this screen and the entity cannot disagree about what counts as too much.
     *
     * @param array<string, string>               $filters
     * @param array{scanned: int, available: int} $scan    out: what the cap let this feed read
     *
     * @return list<array{order: PurchaseOrder, line: PurchaseOrderLine, charged: string, currency: string, amountCents: int, amount: string, moves: list<array{label: string, href: string}>}>
     */
    private function overBilled(array $filters, array &$scan = []): array
    {
        $qb = $this->em->getRepository(PurchaseOrder::class)->createQueryBuilder('p')
            // Every status but Draft: goods can be billed against an order at any point after it is
            // issued, including one closed short or cancelled, and an over-billing found on a closed
            // order is exactly the kind nobody is looking for.
            ->andWhere('p.status <> :draft')
            ->setParameter('draft', PurchaseOrderStatus::Draft)
            ->orderBy('p.documentDate', 'DESC');

        if ($filters['vendor'] !== '') {
            $qb->andWhere('IDENTITY(p.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }

        /** @var list<PurchaseOrder> $orders */
        $orders = $this->scan($qb, 'p', $scan);

        $rows = [];

        foreach ($orders as $order) {
            foreach ($order->getLines() as $line) {
                if (!$line->isOverBilled()) {
                    continue;
                }

                $charged = $line->getQuantityCharged();
                $supported = QuantityScale::compare($line->getQuantityOrdered(), $line->getQuantityReceived()) >= 0
                    ? $line->getQuantityOrdered()
                    : $line->getQuantityReceived();

                // What the unsupported units are worth at the price we agreed — the figure at
                // risk.
                $cents = AbstractPurchaseDocument::cents(QuantityScale::mul(QuantityScale::sub($charged, $supported), $line->getUnitCost(), 6));

                // Two ways out, and both can actually be completed. If the goods did arrive and
                // nobody booked them in, receiving them makes the charge supported and the row
                // goes away — offered only while the order still takes receipts, which is the test
                // ReceivingService itself applies. Otherwise the money has to be claimed back, and
                // a debit memo is the document that does it. Disputing is deliberately absent here:
                // this row is a fact about SEVERAL bills against one order line, so there is no one
                // bill for the button to park.
                $moves = [];
                if (!$order->getStatusEnum()->refusesReceipts()) {
                    $moves[] = [
                        'label' => 'Record the receipt',
                        'href' => $this->generateUrl('admin_bundle_procurement_receive', ['po' => $order->getId()]),
                    ];
                }
                $moves[] = [
                    'label' => 'Raise a debit memo',
                    'href' => $this->generateUrl('admin_bundle_procurement_debit_memo_new', ['vendor' => $order->getVendor()->getId()]),
                ];

                $rows[] = [
                    'order' => $order,
                    'line' => $line,
                    'charged' => $charged,
                    'currency' => $order->getCurrency(),
                    'amountCents' => $cents,
                    'amount' => MatchLine::money($cents),
                    'moves' => $moves,
                ];
            }
        }

        return $rows;
    }

    /**
     * How much of this PO line any live bill has charged for.
     *
     * Draft and void bills do not count: neither is a charge against the business, and counting a
     * draft would make an accrual disappear the moment somebody started typing. That rule now lives
     * on `VendorBillStatus::counts()` and is read through `PurchaseOrderLine::getQuantityCharged()`,
     * which is the same sum this screen used to write for itself in DQL — one primitive, so the
     * accrual list, the over-billing list and the entity cannot drift apart.
     */
    private function billedQuantityFor(PurchaseOrderLine $line): string
    {
        return $line->getQuantityCharged();
    }
}
