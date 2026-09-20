<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\Warehouse;
use App\Enum\SalesReturnStatus;
use App\Event\SalesReturnReceivedEvent;
use App\Service\QuantityScale;
use App\Service\SalesReturnNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The sales return's admin pages (#596) — the RMA an operator raises, authorises, receives, declines
 * or closes.
 *
 * ## What these screens are for
 *
 * Before #596 the only way goods could come back was `credit_memo.restock`: a credit note ticked
 * "the goods came back", and issuing it wrote the `returned` rows. That collapses the authorisation,
 * the receipt and the money into one act, so a customer could not ship anything back until somebody
 * had decided to credit them. These screens split the first two off, which is what makes the four
 * ordinary cases in SalesReturn's docblock expressible at all.
 *
 * ## Every transition goes through the entity
 *
 * authorise(), receive(), decline(), close() enforce their own from-state and throw a sentence an
 * admin can act on. This controller resolves the request, reports the refusal, and never writes a
 * status or a timestamp itself — the same division CreditMemoController keeps, and the property this
 * repo actually judges a transition by.
 *
 * The one thing that happens HERE and not in the entity is the stock, and it cannot be otherwise: an
 * entity has no entity manager and no movement service. receive() records the receipt, the flush
 * commits it, and only then is SalesReturnReceivedEvent dispatched — see that class for why after,
 * and why an event rather than a call into InventoryDepthBundle.
 *
 * ## Declining after receipt strands stock ON PURPOSE, and the screen says so
 *
 * A return that was received and then declined leaves its units in `returned`, counted in
 * `quarantine_quantity`, not sellable and not the customer's to have back for free. Nothing here
 * disposes of them, because what happens to refused goods is a commercial decision — ship them back
 * at the customer's cost, scrap them, sell them as B-stock — and a status change must not make it.
 *
 * What this controller DOES do is refuse to let that be invisible. detail() computes the stranded
 * quantity and the template puts it in a warning block with a link to the product's stock screen,
 * where the `returned` rows are listed. Being correct and unfindable is how stock quietly rots in a
 * bucket nobody reports on.
 */
#[Route('/admin')]
final class SalesReturnController extends AbstractAdminController
{
    /**
     * The un-nested spellings of "which customer", in precedence order.
     *
     * `company` first because it is this screen's own, and the one the New-return button and the
     * grid's Back link generate; `company_id` behind it so a drill-through written against the
     * shape every other document grid uses still lands. The nested `SalesReturnSearch[company_id]`
     * is read before either, by CompanyListScope itself.
     */
    private const COMPANY_QUERY_KEYS = ['company', 'company_id'];

    /**
     * The Sales Returns grid.
     *
     * Scoped to one company when the query names one, in the same `SalesReturnSearch[company_id]`
     * shape the credit note and invoice grids use, so a company page can cross-link here the way it
     * already cross-links to those. The flat `?company=` spelling this screen has always also
     * answered is passed to the scope as a bare key, so it keeps working — and now fails closed too
     * rather than widening back to every customer's returns.
     *
     * Through CompanyListScope since queue item 30, for the third state a nullable Company cannot
     * hold: an id that resolves to nobody is not "no scope".
     */
    #[Route('/sales-return/index', name: 'admin_sales_return_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $companyScope = $this->companyListScope($request, $entityManager, 'SalesReturnSearch', self::COMPANY_QUERY_KEYS);
        $company = $companyScope->company();
        if ($companyScope->isUnresolved()) {
            $this->addFlash('error', 'Customer could not be found for these returns.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $search = trim((string) $request->query->get('q', ''));
        // Flat GET parameters, beside `q`, `sort` and `limit`, rather than the `filters[...]` bag
        // /admin/order uses: this screen has always spelled its state flat, and one grid speaking
        // two shapes would be worse than either. What matters is that every control on the screen
        // is a parameter the SERVER reads — the .table-search box this replaces was not.
        $status = trim((string) $request->query->get('status', ''));
        $received = trim((string) $request->query->get('received', ''));

        $qb = $entityManager->getRepository(SalesReturn::class)->createQueryBuilder('r')
            ->join('r.company', 'c')
            ->leftJoin('r.invoice', 'i');

        if ($company instanceof Company) {
            $qb->andWhere('r.company = :company')->setParameter('company', $company);
        } elseif ($companyScope->isUnresolved()) {
            // Fail closed. Ids are positive, so this matches nothing — and it goes into the SAME
            // query builder the count below is cloned from, so the footer's total is scoped to the
            // same nothing the rows are.
            $qb->andWhere('r.id = :noSuchCompany')->setParameter('noSuchCompany', 0);
        }

        if ($search !== '') {
            $qb->andWhere('r.documentNumber LIKE :search OR c.name LIKE :search OR i.documentNumber LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Through tryFrom, the same way InvoiceController reads the Invoices grid's status: a value
        // the enum does not name is not a status anybody can pick from the bar or the column.
        $filterStatus = SalesReturnStatus::tryFrom($status);
        if ($filterStatus instanceof SalesReturnStatus) {
            $qb->andWhere('r.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        // The Received column's own filter, and it is not the same question as the status: the gap
        // between "we agreed to take it back" and "the box arrived" is what #596 exists to record,
        // so "which of these have not turned up yet" has to be askable in one click.
        if ($received === 'yes') {
            $qb->andWhere('r.receivedAt IS NOT NULL');
        } elseif ($received === 'no') {
            $qb->andWhere('r.receivedAt IS NULL');
        }

        $currentSort = (string) $request->query->get('sort', 'rma');
        $currentDir = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $qb->orderBy(match ($currentSort) {
            'company' => 'c.name',
            'status' => 'r.status',
            'requested' => 'r.requestedAt',
            default => 'r.documentNumber',
        }, $currentDir);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        /** @var list<SalesReturn> $returns */
        $returns = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/sales_return/_list_rows.html.twig', [
                    'returns' => $returns,
                    // The empty state names the customer, or says there is no such customer, so the
                    // partial needs the scope even when it is rendered on its own for the JS pager.
                    'company' => $company instanceof Company ? $this->companyToRow($company) : null,
                    'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
                ]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / max($limit, 1)),
            ]);
        }

        return $this->render('admin/sales_return/index.html.twig', [
            'company' => $company instanceof Company ? $this->companyToRow($company) : null,
            'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
            'returns' => $returns,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
            'search' => $search,
            // The status bar and the Status column's <select> both render from this list, so the
            // two cannot come to offer different statuses.
            'statuses' => SalesReturnStatus::cases(),
            'status' => $status,
            'received' => $received,
            'currentSort' => $currentSort,
            'currentDir' => $currentDir,
        ]);
    }

    /**
     * Raise a return. GET renders the draft editor, POST creates it.
     *
     * `?invoice=` pre-fills the lines from that invoice, which is the ordinary path — a customer
     * telephones about a specific delivery. `?company=` raises one against a customer with no
     * invoice named at all, which is the case the nullable columns exist for: goods turn up, or
     * somebody rings about a delivery nobody can find yet, and refusing to open the document until
     * the paperwork is located is how parcels arrive with no reference on them.
     *
     * The number is allocated at CREATE, not at authorisation. It has to be: the whole practical
     * purpose of the document is that the customer writes the number on the box, and a document
     * without one is a document nobody can be told about.
     */
    #[Route('/sales-return/new', name: 'admin_sales_return_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        SalesReturnNumberGenerator $numbers,
    ): Response {
        $invoice = $this->invoiceFromQuery($request, $entityManager);
        $company = $invoice instanceof Invoice ? $invoice->getCompany() : $this->searchCompany($request, $entityManager);

        if (!$company instanceof Company) {
            $this->addFlash('error', 'A return is raised against a customer. Open one from an invoice, or from the customer\'s page.');

            return $this->redirectToRoute('admin_sales_return_index');
        }

        if ($request->isMethod('POST')) {
            try {
                $return = (new SalesReturn())
                    ->setCompany($company)
                    ->setDocumentNumber($numbers->next($entityManager))
                    ->setInvoice($invoice)
                    ->setSalesOrder($invoice instanceof Invoice ? $invoice->getSalesOrder() : null)
                    ->setReason($this->nullable((string) $request->request->get('reason', '')))
                    ->setNotes($this->nullable((string) $request->request->get('notes', '')));

                $this->applyPostedLines($return, $request, $entityManager);

                if ($return->getLines()->isEmpty()) {
                    throw new \DomainException(
                        'A return needs at least one line with a product and a quantity on it. '
                        . 'That list is what the receiving clerk checks the parcel against.'
                    );
                }

                $entityManager->persist($return);
                $entityManager->flush();

                $this->addFlash('success', sprintf(
                    'Return %s raised. Authorise it to give the customer a number to put on the box.',
                    $return->getDocumentNumber(),
                ));

                return $this->redirectToRoute('admin_sales_return_detail', ['id' => $return->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/sales_return/edit.html.twig', [
            'return' => null,
            'company' => $company,
            'invoice' => $invoice,
            'invoiceLines' => $invoice instanceof Invoice ? $invoice->getLines() : [],
            'products' => $this->sellableProducts($entityManager),
        ]);
    }

    /**
     * Edit a draft's lines.
     *
     * Only while Requested. After authorisation the lines are a promise made to a customer about
     * what they may send, and after receipt they are a record of what arrived — editing either is
     * rewriting history rather than correcting a draft, which is why the refusal lives on
     * SalesReturnStatus::allowsLineEditing() and not on a permission.
     */
    #[Route('/sales-return/{id}/edit', name: 'admin_sales_return_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $return = $entityManager->find(SalesReturn::class, $id);
        if (!$return instanceof SalesReturn) {
            $this->addFlash('error', 'Return could not be found.');

            return $this->redirectToRoute('admin_sales_return_index');
        }

        if (!$return->isDraft()) {
            $this->addFlash('error', sprintf(
                'Return %s is %s. Only a requested return can be edited — its lines are what the customer was told they may send.',
                $return->getDocumentNumber(),
                $return->getStatus()->value,
            ));

            return $this->redirectToRoute('admin_sales_return_detail', ['id' => $id]);
        }

        if ($request->isMethod('POST')) {
            try {
                $return
                    ->setReason($this->nullable((string) $request->request->get('reason', '')))
                    ->setNotes($this->nullable((string) $request->request->get('notes', '')));

                $this->applyPostedLines($return, $request, $entityManager);

                if ($return->getLines()->isEmpty()) {
                    throw new \DomainException('A return needs at least one line with a product and a quantity on it.');
                }

                $entityManager->flush();
                $this->addFlash('success', sprintf('Return %s updated.', $return->getDocumentNumber()));

                return $this->redirectToRoute('admin_sales_return_detail', ['id' => $id]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/sales_return/edit.html.twig', [
            'return' => $return,
            'company' => $return->getCompany(),
            'invoice' => $return->getInvoice(),
            'invoiceLines' => $return->getInvoice() instanceof Invoice ? $return->getInvoice()->getLines() : [],
            'products' => $this->sellableProducts($entityManager),
        ]);
    }

    /**
     * The document, its lines, and — when it applies — the warning that units are stranded.
     *
     * `strandedUnits` is asked of the DOCUMENT (how much did we take in and then refuse) rather than
     * counted off `inventory_detail` (what is left in `returned` right now), because the two answer
     * different questions and only the first is stable. Somebody acting on the goods changes the
     * second; the fact that a refused parcel came in never changes.
     */
    #[Route('/sales-return/{id}', name: 'admin_sales_return_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, EntityManagerInterface $entityManager): Response
    {
        $return = $entityManager->find(SalesReturn::class, $id);
        if (!$return instanceof SalesReturn) {
            $this->addFlash('error', 'Return could not be found.');

            return $this->redirectToRoute('admin_sales_return_index');
        }

        return $this->render('admin/sales_return/detail.html.twig', [
            'return' => $return,
            'dispositions' => SalesReturnLine::dispositions(),
            'warehouses' => $entityManager->getRepository(Warehouse::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
            'creditNotes' => $entityManager->getRepository(CreditMemo::class)->findBy(['salesReturn' => $return], ['id' => 'ASC']),
        ]);
    }

    /**
     * Authorise, receive, decline, close — one endpoint, four named actions on the entity.
     *
     * One endpoint rather than four routes, matching CreditMemoController's `action/{action}` shape.
     * The `match` is exhaustive with a throwing default, so an unknown action is a reported refusal
     * rather than a silent no-op — the failure mode a `switch` with no default produces, and the one
     * hardest to notice from a screen that simply redirects back.
     *
     * Receiving is the only one that moves stock, and the ordering is load-bearing: transition,
     * flush, THEN dispatch. SalesReturnReceivedEvent's docblock has the argument in full — the
     * movement service opens its own transaction and flushes inside it, so dispatching before this
     * controller's flush returns would be re-entrant against the unit of work being committed.
     */
    #[Route('/sales-return/{id}/action/{action}', name: 'admin_sales_return_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function performAction(
        int $id,
        string $action,
        Request $request,
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        $return = $entityManager->find(SalesReturn::class, $id);
        if (!$return instanceof SalesReturn) {
            $this->addFlash('error', 'Return could not be found.');

            return $this->redirectToRoute('admin_sales_return_index');
        }

        try {
            match ($action) {
                'authorise' => $return->authorise(),
                'receive' => $this->receiveGoods($return, $request, $entityManager),
                'decline' => $return->decline($this->nullable((string) $request->request->get('reason', ''))),
                'close' => $return->close(),
                default => throw new \DomainException(sprintf('%s is not a sales return action.', $action)),
            };

            $entityManager->flush();

            if ($action === 'receive') {
                $eventDispatcher->dispatch(new SalesReturnReceivedEvent($return));
            }

            $this->addFlash('success', $this->outcomeMessage($return, $action));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_sales_return_detail', ['id' => $id]);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The parts of a request that are not one line of entity call
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Receiving: the warehouse, then the dispositions, then the transition.
     *
     * The warehouse is resolved and validated BEFORE receive() is called, so a POST naming a
     * warehouse that does not exist is refused with the document untouched. Passing a null through
     * to the transition and letting it complain would be the same outcome by accident rather than by
     * design, and it would stop being the same outcome the moment the parameter was made nullable.
     *
     * Dispositions are written after the transition and not before, because they are a fact about a
     * receipt: recording "damaged" on a return nobody has received is a claim about a box nobody has
     * opened. If receive() throws, nothing has been written.
     */
    private function receiveGoods(SalesReturn $return, Request $request, EntityManagerInterface $entityManager): void
    {
        $warehouse = $entityManager->find(Warehouse::class, $request->request->getInt('warehouse_id', 0));
        if (!$warehouse instanceof Warehouse) {
            throw new \DomainException(
                'Say which warehouse the goods arrived at. A receipt that does not record where the '
                . 'stock landed cannot be turned into inventory rows, and guessing a building is worse '
                . 'than refusing, because nothing afterwards can tell it was a guess.'
            );
        }

        $return->receive($warehouse);

        /** @var array<int|string, mixed> $dispositions */
        $dispositions = $request->request->all('dispositions');
        foreach ($return->getLines() as $line) {
            $key = (string) $line->getId();
            if (!array_key_exists($key, $dispositions)) {
                continue;
            }

            $value = $dispositions[$key];
            $line->setDisposition(is_scalar($value) ? (string) $value : null);
        }
    }

    /** What the admin is told happened, in the document's own words rather than the action's. */
    private function outcomeMessage(SalesReturn $return, string $action): string
    {
        return match ($action) {
            'authorise' => sprintf(
                'Return %s is authorised. Give the customer that number to write on the box.',
                $return->getDocumentNumber(),
            ),
            'receive' => sprintf(
                '%s unit(s) received against %s at %s. They are in `returned` — present, counted in quarantine, '
                . 'and NOT sellable until somebody rules on them.',
                QuantityScale::trim($return->totalUnits()),
                $return->getDocumentNumber(),
                $return->getWarehouse()?->getName() ?? '(unknown)',
            ),
            'decline' => QuantityScale::compare($return->strandedUnits(), 0) > 0
                ? sprintf(
                    'Return %s is declined. %s unit(s) already received are still in `returned` at %s: nothing here '
                    . 'disposes of them, because what happens to refused goods is a commercial decision. Find them '
                    . 'on the product\'s stock screen.',
                    $return->getDocumentNumber(),
                    QuantityScale::trim($return->strandedUnits()),
                    $return->getWarehouse()?->getName() ?? '(unknown)',
                )
                : sprintf('Return %s is declined. No goods had arrived, so no stock moved.', $return->getDocumentNumber()),
            default => sprintf('Return %s is now %s.', $return->getDocumentNumber(), $return->getStatus()->value),
        };
    }

    /**
     * Rebuilds the line set from the posted `lines[]` rows.
     *
     * Removes everything and rebuilds rather than diffing by id, which is exactly what
     * CreditMemoController::applyPostedFields() does and for the same reason: a diff has to decide
     * what an absent row means, and "the operator deleted it" and "the browser did not send it"
     * are indistinguishable in a form post. Rebuilding makes the posted set the whole truth. It is
     * only ever reachable while the document is Requested, so no receipt or disposition can be
     * destroyed by it.
     *
     * A row with no product or a non-positive quantity is DROPPED rather than refused. An empty
     * spare row at the bottom of the form is how every line editor in this app behaves, and
     * rejecting the whole submission over one is the behaviour that teaches operators to retype
     * everything.
     */
    private function applyPostedLines(SalesReturn $return, Request $request, EntityManagerInterface $entityManager): void
    {
        foreach ($return->getLines()->toArray() as $existing) {
            $return->removeLine($existing);
        }

        /** @var array<int, mixed> $rows */
        $rows = $request->request->all('lines');
        $sortOrder = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $product = $entityManager->find(ProductCore::class, (int) ($row['product_id'] ?? 0));
            if (!$product instanceof ProductCore) {
                continue;
            }

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $invoiceLineId = (int) ($row['invoice_line_id'] ?? 0);
            $invoiceLine = $invoiceLineId > 0 ? $entityManager->find(InvoiceLine::class, $invoiceLineId) : null;

            $return->addLine(
                (new SalesReturnLine())
                    ->setProduct($product)
                    ->setInvoiceLine($invoiceLine instanceof InvoiceLine ? $invoiceLine : null)
                    ->setName(trim((string) ($row['name'] ?? '')) !== '' ? trim((string) $row['name']) : $product->getName())
                    ->setSku($this->nullable((string) ($row['sku'] ?? '')) ?? $product->getSku())
                    ->setQuantity(number_format($quantity, 2, '.', ''))
                    ->setReason($this->nullable((string) ($row['reason'] ?? '')))
                    ->setSortOrder($sortOrder++),
            );
        }
    }

    /**
     * The products a line may name, for the rows an operator types by hand.
     *
     * Every non-deleted product, not only the dimensional ones. A return of a simple-inventory
     * product is a real document — the customer sent it back and somebody has to answer for the
     * money — and the subscriber's honest response is to record the receipt and move no stock,
     * because `quantity` on a simple product is a number an admin types and this layer must not
     * touch it. Filtering the dropdown to dimensional SKUs would make an ordinary return
     * unrecordable rather than merely unstocked.
     *
     * @return list<ProductCore>
     */
    private function sellableProducts(EntityManagerInterface $entityManager): array
    {
        /** @var list<ProductCore> $rows */
        $rows = $entityManager->getRepository(ProductCore::class)->findBy(['deleted' => false], ['sku' => 'ASC']);

        return $rows;
    }

    private function invoiceFromQuery(Request $request, EntityManagerInterface $entityManager): ?Invoice
    {
        $invoiceId = $request->query->getInt('invoice', 0);
        if ($invoiceId <= 0) {
            return null;
        }

        $invoice = $entityManager->find(Invoice::class, $invoiceId);

        return $invoice instanceof Invoice ? $invoice : null;
    }

    /**
     * The customer the CREATE screen raises a return against, when the query names one.
     *
     * Delegates to the shared scope rather than casting, which is what it used to do: `getInt()` on
     * `12abc` answered 12, so a mangled link opened the draft editor against a customer nobody
     * named — and a return is filed under whoever the form was opened for. A create screen has only
     * two answers, a customer or none, so this stays a nullable Company; the grid, which has three,
     * holds the CompanyListScope itself.
     */
    private function searchCompany(Request $request, EntityManagerInterface $entityManager): ?Company
    {
        return $this->companyListScope($request, $entityManager, 'SalesReturnSearch', self::COMPANY_QUERY_KEYS)->company();
    }

    /** '' means "the operator left it blank", which is a NULL column and not an empty string. */
    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
