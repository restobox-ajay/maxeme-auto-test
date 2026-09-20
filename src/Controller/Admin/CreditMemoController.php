<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\QuantityScale;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoApplication;
use App\Entity\CreditMemoLine;
use App\Entity\CreditMemoRefund;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\PaymentMethod;
use App\Entity\ProductCore;
use App\Entity\SalesReturn;
use App\Enum\CreditMemoStatus;
use App\Enum\CreditMemoVoidDisposition;
use App\Event\CreditMemoIssuedEvent;
use App\Service\CreditMemoNumberGenerator;
use App\Service\DocumentActorResolver;
use App\Service\Inventory\CreditMemoRestockResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The credit note's admin pages (#586).
 *
 * The grid at `admin_credit_memo_index` was a placeholder on InvoiceController — a screen with a
 * hard-coded empty list, waiting for the entity that #586 builds. It has moved here whole, route
 * name and path unchanged, so the company page's existing cross-link keeps working, and it now
 * lists rows.
 *
 * ## What a credit note does, restated because the placeholder said otherwise
 *
 * The docblock that used to sit on that placeholder said a credit memo "does not return anything to
 * sales_hold or any other inventory bucket". Three of its four claims survive #586 unchanged and one
 * does not, and it is worth being precise about which:
 *
 *  - it still does NOT return quantity to the order's uninvoiced remainder;
 *  - it still does NOT touch `sales_hold`;
 *  - it still does NOT change the order's derived status: an Invoiced or Closed order stays that way;
 *  - it still does NOT affect Invoice::countsTowardInvoicedQuantity().
 *
 * What has changed is `pending`/`approved`. The invoice holds stock for goods it billed, and goods
 * that came back are not goods it is still shipping — leaving that hold in place makes the invoice
 * reserve units the warehouse is standing on. So the invoice's reservation target is now computed
 * net of credits (InvoiceReservationSubject::stockedQuantityFor()), which is a RELEASE of a hold,
 * not a reversal of the sale. The client's rule is intact: the invoice is real, it is not
 * un-invoiced, and the sale is not undone.
 *
 * Every transition and every allocation goes through CreditMemo's named actions, which enforce their
 * own from-state and settle the balance. This controller resolves the request, reports the refusal,
 * and never writes a status or an amount itself.
 */
#[Route('/admin')]
final class CreditMemoController extends AbstractAdminController
{
    /**
     * The methods offered when `payment_method` has no rows.
     *
     * #586 says a refund's method "draws on the same `payment_method` table", and it does — the rows
     * are read first and win whenever there are any. But that table is populated lazily by whichever
     * payment bundles an instance has enabled, so a fresh install genuinely has none, and a refund
     * form with an empty dropdown is a screen an admin cannot use. These five are the same literals
     * admin/invoice/payments.html.twig has always offered, kept identical on purpose so a refund and
     * a payment record the same words for the same act.
     */
    private const FALLBACK_METHODS = ['Bank Transfer', 'Check', 'Credit Card', 'Cash', 'E-Transfer'];

    /**
     * The Credit Notes grid.
     *
     * Scoped to one company when the query names one, which is how the company detail page links
     * here — that parameter shape (`CreditMemoSearch[company_id]`) predates this issue and is kept.
     *
     * Through CompanyListScope since queue item 30, for the third state a nullable Company cannot
     * hold: a customer id that resolves to nobody is not "no scope". This grid used to answer one
     * with every customer's credit notes under a flash an admin can miss, which is a list of other
     * people's money in a screen that reads as if it were the customer's own.
     */
    #[Route('/credit-memo/index', name: 'admin_credit_memo_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $companyScope = $this->companyListScope($request, $entityManager, 'CreditMemoSearch');
        $company = $companyScope->company();
        if ($companyScope->isUnresolved()) {
            $this->addFlash('error', 'Company could not be found for these credit memos.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $search = trim((string) $request->query->get('q', ''));
        // Flat GET parameters, like `q`, `sort` and `limit` beside them, rather than the
        // `filters[...]` bag /admin/order uses. This screen has always spelled its state flat and
        // one grid speaking two shapes would be worse than either; what matters is that every
        // control is a URL parameter the SERVER reads, which is what the search box was not.
        $status = trim((string) $request->query->get('status', ''));
        $type = trim((string) $request->query->get('type', ''));

        $qb = $entityManager->getRepository(CreditMemo::class)->createQueryBuilder('m')
            ->join('m.company', 'c')
            ->leftJoin('m.invoice', 'i');

        if ($company instanceof Company) {
            $qb->andWhere('m.company = :company')->setParameter('company', $company);
        } elseif ($companyScope->isUnresolved()) {
            // Fail closed. Ids are positive, so this matches nothing — and it goes into the SAME
            // query builder the count below is cloned from, so the footer's total is scoped to the
            // same nothing the rows are.
            $qb->andWhere('m.id = :noSuchCompany')->setParameter('noSuchCompany', 0);
        }

        if ($search !== '') {
            $qb->andWhere('m.documentNumber LIKE :search OR c.name LIKE :search OR i.documentNumber LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Through tryFrom, the same way InvoiceController reads the Invoices grid's status: a value
        // the enum does not name is not a status anybody can pick from the bar or the column, so it
        // is not a filter either. The column is a plain string, so the slug is what goes to the
        // query — the enum is only the vocabulary that says which slugs exist.
        $filterStatus = CreditMemoStatus::tryFrom($status)?->value;
        if ($filterStatus !== null) {
            $qb->andWhere('m.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        if ($type !== '') {
            $qb->andWhere('m.creditMemoTypeId = :filterType')->setParameter('filterType', (int) $type);
        }

        // Product Inventory Hub's "Recent Activity" View more link (build order step 1) — this
        // screen had no product filter at all before this (checked directly: it never joins
        // CreditMemoLine), so this is a straight addition, exact id match only.
        $product = trim((string) $request->query->get('product', ''));
        $usesLineJoin = false;
        if ($product !== '' && ctype_digit($product)) {
            $qb->innerJoin('m.lines', 'ml');
            $usesLineJoin = true;
            $qb->andWhere('IDENTITY(ml.product) = :filterProduct')->setParameter('filterProduct', (int) $product);
        }

        $currentSort = (string) $request->query->get('sort', 'memo');
        $currentDir = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $qb->orderBy(match ($currentSort) {
            'company' => 'c.name',
            'amount' => 'm.total',
            'status' => 'm.status',
            default => 'm.documentNumber',
        }, $currentDir);

        $countQb = clone $qb;
        $total = (int) $countQb->select($usesLineJoin ? 'COUNT(DISTINCT m.id)' : 'COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        if ($usesLineJoin) {
            $qb->distinct(true);
        }

        /** @var list<CreditMemo> $memos */
        $memos = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        $typeNames = $this->creditMemoTypeNames($entityManager);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/credit_memo/_list_rows.html.twig', [
                    'memos' => $memos,
                    'typeNames' => $typeNames,
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

        return $this->render('admin/credit_memo/index.html.twig', [
            'company' => $company instanceof Company ? $this->companyToRow($company) : null,
            'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
            'memos' => $memos,
            'typeNames' => $typeNames,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
            'search' => $search,
            // The status bar and the Status column's <select> both render from this list, so the
            // two cannot come to offer different statuses.
            'statuses' => array_column(CreditMemoStatus::cases(), 'value'),
            'status' => $status,
            'type' => $type,
            'currentSort' => $currentSort,
            'currentDir' => $currentDir,
        ]);
    }

    /**
     * The draft editor, for a new note.
     *
     * `?invoice=` raises it FROM an invoice: the company, the addresses, the region and the lines are
     * copied off that invoice, and `credit_memo.invoice_id` records where they came from. `?company=`
     * raises a standalone one — goodwill, a pricing correction, a credit that has not been allocated
     * to anything yet — which #586 is explicit is a first-class case and not a degraded one.
     *
     * `?sales_return=` raises it against an RMA (#596), which is the link the return's detail screen
     * offers once the goods have arrived. It changes exactly one thing about the note: the note may
     * no longer restock, because that return's receipt already put the goods back. The editor stops
     * offering the tick box and setRestock() refuses it anyway if a POST supplies it — see
     * CreditMemo::assertRestockAndReturnAreExclusive(), which is the actual rule; the hidden control
     * is only a courtesy so that nobody is refused for something they were invited to do.
     *
     * ## And with NOTHING in the query: the customer is chosen here
     *
     * This used to bounce straight back to the grid with "open one from an invoice, or from a
     * company", which made the note's own Create page unreachable from anywhere that did not
     * already know the customer — the sidebar, and the grid when it is not scoped to one company.
     * #586 calls a standalone credit a first-class case, so the page asks the question instead of
     * refusing it, exactly as OrderController::create() has always done: a plain `<select>` of
     * companies, posted back to this same route, which redirects to `?company=` and re-enters the
     * editor knowing who the note is for.
     *
     * Two steps rather than one, and that is the order screen's shape rather than an accident: the
     * customer decides the addresses, the snapshot and the region the draft is built from, so it is
     * settled before the draft exists rather than read off the same POST that creates it.
     */
    #[Route('/credit-memo/new', name: 'admin_credit_memo_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        CreditMemoNumberGenerator $numbers,
    ): Response {
        $invoice = $this->invoiceFromQuery($request, $entityManager);
        $salesReturn = $this->salesReturnFromQuery($request, $entityManager);
        $invoice ??= $salesReturn?->getInvoice();
        $company = $invoice?->getCompany() ?? $salesReturn?->getCompany() ?? $this->companyFromQuery($request, $entityManager);

        if ($request->isMethod('POST')) {
            // Which POST is this? The picker's, or the draft's? Told apart by the draft's own
            // fields rather than by a marker the picker promises not to send, for the reason
            // OrderController::create() learned the hard way in #236: a save that happens not to
            // state the marker is then read as a company pick and the whole form is thrown away.
            // `document_date` is required on every draft submit and `lines` is on every one that
            // has a row, so a POST carrying either is a save whatever else it did or did not send.
            $isDraftSubmit = $request->request->has('lines') || $request->request->has('document_date');
            $postedCompany = $this->postedCompany($request, $entityManager);

            // Never allowed to override a customer the query already settled: with `?invoice=` or
            // `?sales_return=` in scope the company comes off that document, and a posted id that
            // disagreed would file the note against somebody who was never billed.
            if (!$company instanceof Company && $postedCompany instanceof Company) {
                if (!$isDraftSubmit) {
                    return $this->redirectToRoute('admin_credit_memo_new', ['company' => $postedCompany->getId()]);
                }

                $company = $postedCompany;
            }
        }

        if (!$company instanceof Company) {
            // A draft submit that named no customer is a refusal with the question still on screen,
            // not a redirect that loses what was typed — and 422 rather than 200, the same answer
            // the order form gives, so the response says the POST was not accepted.
            $refused = $request->isMethod('POST');
            $error = $refused ? 'Choose a customer before saving the credit note.' : null;
            if ($error !== null) {
                $this->addFlash('error', $error);
            }

            return $this->render('admin/credit_memo/choose_company.html.twig', [
                'companies' => $this->companyRowsFromDatabase($entityManager),
                // Printed on the page as well as flashed: the flash bag renders into a hidden
                // element that only JavaScript reveals, and this screen has to read without any.
                'creditMemoError' => $error,
            ], $refused ? new Response('', Response::HTTP_UNPROCESSABLE_ENTITY) : null);
        }

        if ($request->isMethod('POST')) {
            $memo = (new CreditMemo())
                ->setCompany($company)
                ->setInvoice($invoice)
                // Attached BEFORE applyPostedFields(), which is what makes the refusal reachable: a
                // POST that ticks restock on a note carrying a return has to meet the guard, and it
                // only can if the return is already on the object when setRestock() runs.
                ->setSalesReturn($salesReturn)
                ->setDocumentNumber($numbers->next($entityManager));

            if ($invoice instanceof Invoice) {
                // The invoice's frozen addresses, not the company's current ones: a credit note is a
                // record of part of that transaction being undone, and the customer may have moved
                // since. Same rule an invoice follows when it is raised from an order.
                foreach ($invoice->getAddresses() as $address) {
                    $memo->copyAddressFrom($address);
                }
                $memo->copyCompanySnapshotFrom($invoice);
                $memo->setFulfillmentRegion($invoice->getFulfillmentRegion());
            }

            try {
                $this->applyPostedFields($memo, $request, $entityManager);
                $entityManager->persist($memo);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Credit note %s created as a draft.', $memo->getDocumentNumber()));

                return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $memo->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/credit_memo/edit.html.twig', [
            'memo' => null,
            'company' => $company,
            'invoice' => $invoice,
            'salesReturn' => $salesReturn,
            'rows' => $this->rowsFromInvoice($invoice),
            'types' => $this->creditMemoTypeRows($entityManager),
        ]);
    }

    /**
     * The detail screen: the balance, where it went, and every action that is legal right now.
     *
     * The balance is asked of the document rather than added up here. It is the same sum that
     * decides Open versus Closed, and a template doing its own arithmetic beside it is a second
     * answer able to disagree with the badge next to it.
     */
    #[Route('/credit-memo/{id}', name: 'admin_credit_memo_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, EntityManagerInterface $entityManager, CreditMemoRestockResolver $restock): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        return $this->render('admin/credit_memo/detail.html.twig', [
            'memo' => $memo,
            'typeName' => $this->creditMemoTypeNames($entityManager)[$memo->getCreditMemoTypeId()] ?? null,
            // Only invoices this note could legally be applied to: the same customer's, not
            // cancelled. Rendering the ones applyTo() would refuse is a form that exists to fail.
            'applicableInvoices' => $this->applicableInvoices($memo, $entityManager),
            'refundMethods' => $this->refundMethods($entityManager),
            // Whether issuing this note actually moved stock, which decides whether Void is a
            // one-click post or has to pass through the confirmation screen first (item 38).
            'movedStock' => $restock->movedStock($memo),
            // And, once voided, what became of the goods — so a person looking at the note can see
            // it without going to find the product in the inventory ledger.
            'voidOutcome' => $restock->voidOutcome($memo),
        ]);
    }

    /**
     * The confirmation in front of voiding a note that recorded goods coming back (item 38).
     *
     * A separate GET screen rather than a dialog, because this app works with JavaScript off and
     * because the choice on it is the whole point: it states, in real terms, which product, how
     * many units, which warehouse and which bucket each answer lands them in. "An adjustment will
     * be created" is not something a person can consent to.
     *
     * A note that moved no stock never reaches this — it has nothing to confirm, and a confirmation
     * in front of that would be noise.
     */
    #[Route('/credit-memo/{id}/void', name: 'admin_credit_memo_void_confirm', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function confirmVoid(int $id, EntityManagerInterface $entityManager, CreditMemoRestockResolver $restock): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        $rows = $restock->restockedUnits($memo);
        if ($rows === []) {
            return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $memo->getId()]);
        }

        return $this->render('admin/credit_memo/void.html.twig', [
            'memo' => $memo,
            'rows' => $rows,
            'dispositions' => CreditMemoVoidDisposition::all(),
            'dispositionNotHere' => CreditMemoVoidDisposition::NotHere,
            'dispositionWrittenOff' => CreditMemoVoidDisposition::WrittenOff,
            'dispositionStillHere' => CreditMemoVoidDisposition::StillHere,
        ]);
    }

    /** The draft editor, for a note that already exists. Only a draft may be edited. */
    #[Route('/credit-memo/{id}/edit', name: 'admin_credit_memo_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        if (!$memo->isDraft()) {
            $this->addFlash('error', sprintf(
                'Credit note %s is %s. Only a draft can be edited — issue a further note, or void this one.',
                $memo->getDocumentNumber(),
                $memo->getStatus(),
            ));

            return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $memo->getId()]);
        }

        if ($request->isMethod('POST')) {
            try {
                $this->applyPostedFields($memo, $request, $entityManager);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Credit note %s saved.', $memo->getDocumentNumber()));

                return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $memo->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/credit_memo/edit.html.twig', [
            'memo' => $memo,
            'company' => $memo->getCompany(),
            'invoice' => $memo->getInvoice(),
            'salesReturn' => $memo->getSalesReturn(),
            'rows' => $this->rowsFromMemo($memo),
            'types' => $this->creditMemoTypeRows($entityManager),
        ]);
    }

    /**
     * Issue or void.
     *
     * Both are CreditMemo's own named actions, which enforce their from-state and throw a sentence
     * the admin can act on. Issuing additionally dispatches CreditMemoIssuedEvent, AFTER the flush,
     * which is what puts returned goods back when the note says they came back — see
     * CreditMemoIssuedEvent for why an event rather than a direct call, and why after.
     *
     * Voiding goes through `CreditMemoRestockResolver` rather than straight to the entity, because
     * withdrawing a note that recorded goods coming back has to say what became of those goods
     * (item 38). A note that moved nothing still voids on a bare post, exactly as it always did —
     * and the entity's own refusals still run first, inside that call. The two are asymmetric for
     * the reason the resolver's docblock gives: a receipt cannot fail, undoing one can, so the void
     * and its ledger entry are one transaction instead of a document plus an event after the flush.
     */
    #[Route('/credit-memo/{id}/action/{action}', name: 'admin_credit_memo_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function performAction(
        int $id,
        string $action,
        Request $request,
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher,
        CreditMemoRestockResolver $restock,
        DocumentActorResolver $actorResolver,
    ): Response {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        try {
            match ($action) {
                'issue' => $memo->issue(),
                'void' => $restock->void(
                    $memo,
                    trim((string) $request->request->get('disposition', '')) ?: null,
                    $actorResolver->resolve()->displayName,
                ),
                default => throw new \DomainException(sprintf('%s is not a credit note action.', $action)),
            };

            // void() has already flushed inside its own transaction; issue() has not.
            $entityManager->flush();

            if ($action === 'issue') {
                $eventDispatcher->dispatch(new CreditMemoIssuedEvent($memo));
            }

            $this->addFlash('success', sprintf('Credit note %s is now %s.', $memo->getDocumentNumber(), $memo->getStatus()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $id]);
    }

    /** Spend part of the balance against one invoice. */
    #[Route('/credit-memo/{id}/apply', name: 'admin_credit_memo_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        try {
            $invoice = $entityManager->find(Invoice::class, $request->request->getInt('invoice_id'));
            if (!$invoice instanceof Invoice) {
                throw new \DomainException('Invoice could not be found.');
            }

            $application = $memo->applyTo(
                $invoice,
                trim((string) $request->request->get('amount', '')),
                $this->postedDate($request, 'applied_at'),
            );
            $entityManager->persist($application);
            $entityManager->flush();

            $this->addFlash('success', sprintf(
                '$%s of credit note %s applied to invoice %s. $%s left.',
                $application->getAmount(),
                $memo->getDocumentNumber(),
                $invoice->getDocumentNumber(),
                $memo->getBalance(),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $id]);
    }

    /** Take an allocation back off the note — applied to the wrong invoice, or for the wrong amount. */
    #[Route('/credit-memo/{id}/application/{applicationId}/withdraw', name: 'admin_credit_memo_application_withdraw', methods: ['POST'], requirements: ['id' => '\d+', 'applicationId' => '\d+'])]
    public function withdrawApplication(int $id, int $applicationId, EntityManagerInterface $entityManager): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        try {
            $application = $entityManager->find(CreditMemoApplication::class, $applicationId);
            if (!$application instanceof CreditMemoApplication) {
                throw new \DomainException('Application could not be found.');
            }

            // withdrawApplication() refuses a row belonging to another note, so a forged id in the
            // URL cannot unpick somebody else's allocation through this route.
            $memo->withdrawApplication($application);
            $entityManager->flush();
            $this->addFlash('success', 'Application withdrawn.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $id]);
    }

    /**
     * Log a refund an admin has already made.
     *
     * The same act as recording a payment against an invoice, and deliberately the same shape of
     * form. There is no gateway here and nothing is charged: the money left by some means the admin
     * chose, and this records that it did.
     */
    #[Route('/credit-memo/{id}/refund', name: 'admin_credit_memo_refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refund(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        try {
            $method = trim((string) $request->request->get('method', ''));
            if ($method === '') {
                throw new \DomainException('A refund method is required.');
            }

            $recordedBy = $this->getUser();
            $comment = trim((string) $request->request->get('comment', ''));

            $refund = (new CreditMemoRefund())
                ->setUser($recordedBy instanceof AdminUser ? $recordedBy : null)
                ->setRefundedAt($this->postedDate($request, 'refunded_at') ?? new \DateTimeImmutable('today'))
                ->setMethod($method)
                ->setAmount(trim((string) $request->request->get('amount', '')))
                ->setComment($comment === '' ? null : $comment);

            $memo->recordRefund($refund);
            $entityManager->persist($refund);
            $entityManager->flush();

            $this->addFlash('success', sprintf(
                'Refund of $%s via %s recorded. $%s left.',
                $refund->getAmount(),
                $refund->getMethod(),
                $memo->getBalance(),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $id]);
    }

    /** A refund taken back off the note — recorded twice, or against the wrong document. */
    #[Route('/credit-memo/{id}/refund/{refundId}/delete', name: 'admin_credit_memo_refund_delete', methods: ['POST'], requirements: ['id' => '\d+', 'refundId' => '\d+'])]
    public function deleteRefund(int $id, int $refundId, EntityManagerInterface $entityManager): Response
    {
        $memo = $entityManager->find(CreditMemo::class, $id);
        if (!$memo instanceof CreditMemo) {
            $this->addFlash('error', 'Credit note could not be found.');

            return $this->redirectToRoute('admin_credit_memo_index');
        }

        try {
            $refund = $entityManager->find(CreditMemoRefund::class, $refundId);
            if (!$refund instanceof CreditMemoRefund) {
                throw new \DomainException('Refund could not be found.');
            }

            $memo->voidRefund($refund);
            $entityManager->flush();
            $this->addFlash('success', 'Refund deleted.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_credit_memo_detail', ['id' => $id]);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The draft form
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Everything the editor posts, applied to a draft.
     *
     * The lines are rebuilt from the POST rather than diffed against what is there, which is what
     * every other line editor in this app does: a row an admin deleted from the form is a row that
     * is gone, and matching by id to decide what survived is the part that goes wrong.
     *
     * Money is NOT recomputed from a price list or run through the fee and tax calculators. A credit
     * note credits back figures that were already agreed on the invoice it came from, so re-pricing
     * it against today's price list would credit a different amount from the one that was billed.
     * Tax is a figure the admin enters for exactly the same reason — this issue explicitly leaves
     * valuation, COGS and G/L posting to a later accounting bundle, and guessing a tax split here
     * would be the first piece of that guessed rather than decided.
     */
    private function applyPostedFields(CreditMemo $memo, Request $request, EntityManagerInterface $entityManager): void
    {
        $documentDate = trim((string) $request->request->get('document_date', ''));
        if ($documentDate !== '') {
            $memo->setDocumentDate($documentDate);
        }

        $typeId = $request->request->getInt('credit_memo_type_id');
        $memo->setCreditMemoTypeId($typeId > 0 ? $typeId : null);

        $reason = trim((string) $request->request->get('reason', ''));
        $memo->setReason($reason === '' ? null : $reason);

        $memo->setRestock($request->request->getBoolean('restock'));

        foreach ($memo->getLines()->toArray() as $existing) {
            $memo->removeLine($existing);
        }

        $posted = $request->request->all('lines');
        $subtotalCents = 0;
        $sortOrder = 0;

        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            if ($quantity < 0) {
                // Unreachable through the guard above, and stated anyway: a credit note is not a
                // negative invoice, and a negative quantity here would flow into the inventory
                // net-out as a negative release.
                throw new \DomainException('A credit note line cannot be for a negative quantity.');
            }

            $price = round((float) ($row['price'] ?? 0), 2);
            $lineSubtotal = round($quantity * $price, 2);
            $subtotalCents += (int) round($lineSubtotal * 100);

            $line = (new CreditMemoLine())
                ->setName(trim((string) ($row['name'] ?? '')))
                ->setSku($this->nullableText($row['sku'] ?? null))
                ->setLocation($this->nullableText($row['location'] ?? null))
                ->setUnit($this->nullableText($row['unit'] ?? null))
                ->setTaxCode($this->nullableText($row['tax_code'] ?? null))
                ->setQuantity(number_format($quantity, 2, '.', ''))
                ->setPrice(number_format($price, 2, '.', ''))
                ->setSubtotal(number_format($lineSubtotal, 2, '.', ''))
                ->setSortOrder($sortOrder++);

            $invoiceLineId = (int) ($row['invoice_line_id'] ?? 0);
            if ($invoiceLineId > 0) {
                $invoiceLine = $entityManager->find(InvoiceLine::class, $invoiceLineId);
                if ($invoiceLine instanceof InvoiceLine) {
                    $line->setInvoiceLine($invoiceLine);
                    $line->setProduct($invoiceLine->getProduct());
                }
            }

            if ($line->getProduct() === null) {
                $productId = (int) ($row['product_id'] ?? 0);
                if ($productId > 0) {
                    $line->setProduct($entityManager->find(ProductCore::class, $productId));
                }
            }

            $memo->addLine($line);
            $entityManager->persist($line);
        }

        $taxCents = (int) round((float) trim((string) $request->request->get('tax', '0')) * 100);
        $taxCents = max(0, $taxCents);

        $memo
            ->setSubtotal(number_format($subtotalCents / 100, 2, '.', ''))
            ->setTax(number_format($taxCents / 100, 2, '.', ''))
            ->setTotal(number_format(($subtotalCents + $taxCents) / 100, 2, '.', ''));
    }

    /**
     * The rows the editor starts from when a note is raised against an invoice: every billed line,
     * pre-filled at zero.
     *
     * Zero rather than the full billed quantity, deliberately. A credit note is nearly always for
     * part of an invoice, and a form that arrives pre-filled to credit everything is one where the
     * mistake is a single missed keystroke rather than a deliberate act.
     *
     * The maximum offered is the billed quantity LESS what other notes have already credited, so two
     * notes cannot between them credit more of a line than was ever sold — the same figure
     * InvoiceLine::getCreditedUnits() nets out of the invoice's inventory hold, asked once and used
     * for both.
     *
     * @return list<array{invoiceLine: ?InvoiceLine, name: string, sku: ?string, location: ?string, unit: ?string, taxCode: ?string, price: string, quantity: string, maximum: string}>
     */
    private function rowsFromInvoice(?Invoice $invoice): array
    {
        if (!$invoice instanceof Invoice) {
            return [];
        }

        $rows = [];
        foreach ($invoice->getLines() as $line) {
            $remaining = self::creditableRemainder($line);
            if (QuantityScale::compare($remaining, 0) <= 0) {
                continue;
            }

            $rows[] = [
                'invoiceLine' => $line,
                'name' => $line->getName(),
                'sku' => $line->getSku(),
                'location' => $line->getLocation(),
                'unit' => $line->getUnit(),
                'taxCode' => $line->getTaxCode(),
                'price' => (string) ($line->getPrice() ?? '0.00'),
                'quantity' => '0.00',
                'maximum' => $remaining,
            ];
        }

        return $rows;
    }

    /**
     * The rows an existing draft already carries, in the same shape the invoice-derived ones use, so
     * the editor template has one loop rather than two.
     *
     * @return list<array{invoiceLine: ?InvoiceLine, name: string, sku: ?string, location: ?string, unit: ?string, taxCode: ?string, price: string, quantity: string, maximum: string}>
     */
    private function rowsFromMemo(CreditMemo $memo): array
    {
        $rows = [];
        foreach ($memo->getLines() as $line) {
            $invoiceLine = $line->getInvoiceLine();

            $rows[] = [
                'invoiceLine' => $invoiceLine,
                'name' => $line->getName(),
                'sku' => $line->getSku(),
                'location' => $line->getLocation(),
                'unit' => $line->getUnit(),
                'taxCode' => $line->getTaxCode(),
                'price' => (string) ($line->getPrice() ?? '0.00'),
                'quantity' => $line->getQuantity(),
                // The draft's own units are already inside getCreditedUnits()? No — a draft counts
                // toward nothing, which is exactly why they are added back here: the ceiling this
                // row may be raised to is what was billed less what OTHER live notes took.
                'maximum' => $invoiceLine instanceof InvoiceLine
                    ? self::creditableRemainder($invoiceLine)
                    : QuantityScale::canonical(0),
            ];
        }

        return $rows;
    }

    /** What's left of an invoice line to credit: billed less already-credited, never negative. */
    private static function creditableRemainder(InvoiceLine $invoiceLine): string
    {
        $remaining = QuantityScale::sub($invoiceLine->getQuantity(), $invoiceLine->getCreditedUnits());

        return QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Lookups
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The configured classifications, read with raw SQL because `credit_memo_type` has no entity —
     * it is one of the three raw-SQL config tables, and CreditMemo::$creditMemoTypeId says at length
     * why it stays that way.
     *
     * Guarded with tablesExist() for the same reason AbstractAdminController guards its payment-term
     * dropdown: both test suites build their schema from entity metadata, so this table does not
     * exist in either of them, and an unguarded query would 500 every screen that renders the list.
     *
     * @return list<array{id: int, name: string}>
     */
    private function creditMemoTypeRows(EntityManagerInterface $entityManager): array
    {
        $connection = $entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['credit_memo_type'])) {
            return [];
        }

        $records = $connection->fetchAllAssociative(
            "SELECT id, name FROM credit_memo_type WHERE status = 'Active' ORDER BY name ASC, id ASC"
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $records);
    }

    /** @return array<int, string> id => name, for rendering a stored classification on a list or a document */
    private function creditMemoTypeNames(EntityManagerInterface $entityManager): array
    {
        $names = [];
        foreach ($this->creditMemoTypeRows($entityManager) as $row) {
            $names[$row['id']] = $row['name'];
        }

        return $names;
    }

    /**
     * The invoices this note may legally be applied to.
     *
     * The same customer's and not cancelled — exactly the two conditions CreditMemo::applyTo()
     * enforces, asked here so the form offers what will work rather than what will be refused. The
     * note's own header invoice is in the list when it qualifies and is not special: provenance does
     * not decide where a balance may land.
     *
     * @return list<Invoice>
     */
    private function applicableInvoices(CreditMemo $memo, EntityManagerInterface $entityManager): array
    {
        if (!$memo->isStatus('Open')) {
            return [];
        }

        return array_values($entityManager->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.status != :cancelled')
            ->setParameter('company', $memo->getCompany())
            ->setParameter('cancelled', \App\Enum\InvoiceStatus::Cancelled->value)
            ->orderBy('i.documentNumber', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The refund method dropdown: `payment_method` rows if there are any, the invoice screen's
     * literals otherwise. See FALLBACK_METHODS.
     *
     * @return list<string>
     */
    private function refundMethods(EntityManagerInterface $entityManager): array
    {
        $names = array_map(
            static fn (PaymentMethod $method): string => $method->getName(),
            $entityManager->getRepository(PaymentMethod::class)->findBy([], ['name' => 'ASC']),
        );

        return $names === [] ? self::FALLBACK_METHODS : array_values($names);
    }

    /**
     * The RMA a note is being raised against, when the return's screen sent the operator here (#596).
     *
     * Read from `?sales_return=`, and deliberately never inferred from the invoice. An invoice may
     * have several returns against it and most credit notes have none at all; guessing which one a
     * note belongs to would attach the wrong document and then refuse a restock the operator was
     * entitled to.
     */
    private function salesReturnFromQuery(Request $request, EntityManagerInterface $entityManager): ?SalesReturn
    {
        $id = $request->query->getInt('sales_return', 0);
        if ($id <= 0) {
            return null;
        }

        $salesReturn = $entityManager->find(SalesReturn::class, $id);

        return $salesReturn instanceof SalesReturn ? $salesReturn : null;
    }

    private function invoiceFromQuery(Request $request, EntityManagerInterface $entityManager): ?Invoice
    {
        $invoiceId = $request->query->getInt('invoice');

        return $invoiceId > 0 ? $entityManager->find(Invoice::class, $invoiceId) : null;
    }

    private function companyFromQuery(Request $request, EntityManagerInterface $entityManager): ?Company
    {
        $companyId = $request->query->getInt('company');

        return $companyId > 0 ? $entityManager->find(Company::class, $companyId) : null;
    }

    /** The customer the create page's picker named, when a POST carries one. */
    private function postedCompany(Request $request, EntityManagerInterface $entityManager): ?Company
    {
        $companyId = $request->request->getInt('company_id');

        return $companyId > 0 ? $entityManager->find(Company::class, $companyId) : null;
    }

    /**
     * A date field as typed, or null when it was left blank.
     *
     * Parsed defensively for the reason InvoiceController::receivedAt() is: an unparseable string
     * reaching DateTimeImmutable throws, and a 500 on a mistyped date is not an answer.
     */
    private function postedDate(Request $request, string $field): ?\DateTimeImmutable
    {
        $raw = trim((string) $request->request->get($field, ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \DomainException(sprintf('%s is not a date this can read.', $raw));
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
