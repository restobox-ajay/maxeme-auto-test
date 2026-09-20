<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomFieldDefinition;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\InvoicePaymentApplication;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\InvoicePaymentStatus;
use App\Enum\InvoiceShippingStatus;
use App\Enum\InvoiceStatus;
use App\Http\RequestedParent;
use App\Payment\InvoicePaymentMover;
use App\Repository\AuditLogRepository;
use App\Service\AdminUrlGenerator;
use App\Service\AppSettings;
use App\Service\BusinessDate;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\CustomFieldRenderer;
use App\Service\CustomerUrlGenerator;
use App\Service\Document\DocumentLockService;
use App\Service\Document\InvoiceLineReconciler;
use App\Service\Document\SalesDocumentCloner;
use App\Service\DocumentActorResolver;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\StockOverrideReasons;
use App\Service\Inventory\StockOverrideRecorder;
use App\Service\InvoiceNumberGenerator;
use App\Service\InvoiceOrderMatcher;
use App\Service\OrderInvoicingService;
use App\Service\OrderTaxBreakdownService;
use App\Service\RegionSeedData;
use App\Service\SalesDocumentChargeLines;
use App\Service\SalesDocumentLineColumns;
use App\Service\SalesDocumentLineWarnings;
use App\Service\SalesDocumentMoney;
use App\Service\TextInput;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use FeeBundle\Fee\FeeCalculatorResolver;
use PaymentBundle\Payment\PaymentMethodResolver;
use ShippingBundle\Shipping\ShippingResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The invoice's own admin pages (#539 stage 2, then 4 and 6).
 *
 * Stage 2 owed three things: somewhere for the order page's cross-links to point at, the Convert to
 * Invoice screen pre-filled with uninvoiced quantity, and the named actions reachable from a button.
 * Stage 4 added the payments screen and the credit memo screen, both of which moved here from
 * OrderController when money became the invoice's rather than the order's. Stage 5 added the link
 * screen — relating an existing invoice to an existing sales order — and the charge rows on the
 * Convert screen.
 *
 * Stage 6 brings the documents. `index()`, `document()`, `packingSlip()` and `send()` are the pages
 * that used to be OrderController's `orders()`-sibling invoice actions, moved rather than rewritten:
 * printing "the order as an invoice" was correct only while an order WAS one, and the whole of #539
 * is that it is not. The order keeps a document of its own — a different one — at
 * OrderController::document().
 *
 * Every status change and every payment goes through Invoice's named actions, which write their own
 * timeline entries. This controller resolves the actor and reports the refusal; it never writes a
 * status or attaches a payment itself.
 */
#[Route('/admin')]
final class InvoiceController extends AbstractAdminController
{
    /**
     * The Invoices grid.
     *
     * Carries both statuses side by side, which is the point of it: InvoiceStatus answers "have the
     * goods gone" and InvoicePaymentStatus answers "has the money arrived", and an invoice can be
     * Completed and Not Paid without either being wrong.
     *
     * Both are plain columns on `invoice` — derived at write time by their subscribers, not at read
     * time — so filtering and sorting are ordinary WHERE and ORDER BY. That is deliberately unlike
     * the Orders grid's payment column, which has no column of its own and is rolled up from these
     * rows by OrderPaymentRollup. One entity's answer needs no aggregate.
     */
    #[Route('/invoice', name: 'admin_invoice_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        // Scoped to one customer when the query names one by id (`InvoiceSearch[company_id]`, or a
        // bare `company_id`), which is how a customer record drills through to "every invoice of
        // theirs". The Company column's LIKE filter below is unchanged and works alongside it: a
        // name is fuzzy by design — someone typing "Acme" wants Acme Holdings too — and an id is
        // exact by design. Neither can do the other's job, so both are here.
        $companyScope = $this->companyListScope($request, $entityManager, 'InvoiceSearch');
        if ($companyScope->isUnresolved()) {
            $this->addFlash('error', 'Company could not be found for these invoices.');
        }

        // Left join on the order: an invoice need not have been raised against one (stage 5 links
        // existing invoices to orders), and one that has not must still be listed.
        $qb = $entityManager->getRepository(Invoice::class)->createQueryBuilder('i')
            ->join('i.company', 'c')
            ->leftJoin('i.salesOrder', 'o');

        if ($companyScope->company() !== null) {
            $qb->andWhere('i.company = :scopedCompany')->setParameter('scopedCompany', $companyScope->company());
        } elseif ($companyScope->isUnresolved()) {
            // Asked for a customer that does not exist. The list is empty, NOT unscoped: an id
            // nobody recognises must never widen into every customer's invoices, which is a grid
            // that looks exactly like the one that was asked for and whose totals are not theirs.
            // ids are positive, so this matches nothing while leaving one code path for counting,
            // sorting and paging.
            $qb->andWhere('i.id = :noSuchCompany')->setParameter('noSuchCompany', 0);
        }

        $filterDocumentNumber = trim((string) ($filters['documentNumber'] ?? ''));
        if ($filterDocumentNumber !== '') {
            $qb->andWhere('i.documentNumber LIKE :filterDocumentNumber')->setParameter('filterDocumentNumber', '%' . $filterDocumentNumber . '%');
        }

        $filterOrderNumber = trim((string) ($filters['orderNumber'] ?? ''));
        if ($filterOrderNumber !== '') {
            $qb->andWhere('o.orderNumber LIKE :filterOrderNumber')->setParameter('filterOrderNumber', '%' . $filterOrderNumber . '%');
        }

        $filterCompany = trim((string) ($filters['company'] ?? ''));
        if ($filterCompany !== '') {
            $qb->andWhere('c.name LIKE :filterCompany')->setParameter('filterCompany', '%' . $filterCompany . '%');
        }

        $filterTotal = trim((string) ($filters['total'] ?? ''));
        if ($filterTotal !== '') {
            // Partial match, like the Quotes grid: requiring the stored value to the cent is
            // unusable, because nobody types a total that precisely.
            $digits = preg_replace('/[^0-9.\-]/', '', $filterTotal) ?? '';
            if ($digits !== '') {
                $qb->andWhere('i.total LIKE :filterTotal')->setParameter('filterTotal', '%' . $digits . '%');
            }
        }

        // invoice_date holds a 'Y-m-d' string, which compares lexicographically in calendar order —
        // the reason it is that format. So the From/To range is two plain string comparisons, and
        // BOTH bounds are inclusive: an invoice dated exactly on `from` or exactly on `to` is
        // inside the range an admin asked for, and a half-open upper bound would silently drop the
        // last day of every month-end search. Same shape, and the same shared calendarDateFilter(),
        // as the Orders grid's document_date range.
        //
        // Named invoiceDateFrom/invoiceDateTo, not documentDateFrom/documentDateTo: this grid reads
        // and sorts i.invoiceDate, and Invoice carries a SEPARATE documentDate (stamped at persist
        // time) that these boxes deliberately do not touch. The convention shared with the Orders
        // grid is `<column filter name>From`/`To`; the literal string is not.
        $from = $this->calendarDateFilter($filters['invoiceDateFrom'] ?? null);
        $to = $this->calendarDateFilter($filters['invoiceDateTo'] ?? null);

        // If both are present but reversed, swap so the filter still works as expected.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== null) {
            $qb->andWhere('i.invoiceDate >= :filterInvoiceDateFrom')->setParameter('filterInvoiceDateFrom', $from);
        }

        if ($to !== null) {
            $qb->andWhere('i.invoiceDate <= :filterInvoiceDateTo')->setParameter('filterInvoiceDateTo', $to);
        }

        // Header Invoice Date filter (single day). If a range is given via the From/To boxes, range
        // wins — the two would otherwise intersect into a window nobody asked for. Left as a raw
        // string comparison rather than routed through calendarDateFilter(): an unparseable value
        // here has always matched nothing, and turning it into "no filter at all" would quietly
        // widen a search instead of narrowing it.
        $filterInvoiceDate = trim((string) ($filters['invoiceDate'] ?? ''));
        if ($filterInvoiceDate !== '' && $from === null && $to === null) {
            $qb->andWhere('i.invoiceDate = :filterInvoiceDate')->setParameter('filterInvoiceDate', $filterInvoiceDate);
        }

        $filterStatus = InvoiceStatus::tryFrom(trim((string) ($filters['status'] ?? '')))?->value;
        if ($filterStatus !== null) {
            $qb->andWhere('i.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        $filterPaymentStatus = InvoicePaymentStatus::tryFrom(trim((string) ($filters['paymentStatus'] ?? '')));
        if ($filterPaymentStatus !== null) {
            $qb->andWhere('i.paymentStatus = :filterPaymentStatus')->setParameter('filterPaymentStatus', $filterPaymentStatus);
        }

        // The third status axis, filtered exactly as the payment one above: through tryFrom(), so a
        // hand-edited query string naming a value the enum does not have widens nothing — it is
        // simply no filter, the same as an empty box. 'Partially Shipped' stays selectable with no
        // shipment bundle active and correctly matches nothing then, because core never derives it.
        $filterShippingStatus = InvoiceShippingStatus::tryFrom(trim((string) ($filters['shippingStatus'] ?? '')));
        if ($filterShippingStatus !== null) {
            $qb->andWhere('i.shippingStatus = :filterShippingStatus')->setParameter('filterShippingStatus', $filterShippingStatus);
        }

        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $orderExpr = match ($sort) {
            'documentNumber' => 'i.documentNumber',
            'orderNumber' => 'o.orderNumber',
            'company' => 'c.name',
            'invoiceDate' => 'i.invoiceDate',
            'total' => 'i.total',
            'status' => 'i.status',
            'paymentStatus' => 'i.paymentStatus',
            'shippingStatus' => 'i.shippingStatus',
            default => 'i.id',
        };

        $invoices = $qb->select('i')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('i.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/invoice/index.html.twig', [
            // Both halves of the scope reach the template: the customer whose invoices these are,
            // so the screen can say so, and the id that matched nothing, so it can say that instead
            // of rendering as an ordinary unfiltered grid.
            'company' => $companyScope->company() !== null ? $this->companyToRow($companyScope->company()) : null,
            'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
            'invoices' => $invoices,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pageCount,
        ]);
    }

    /**
     * The invoice's view screen, built to the same standard as OrderController::detail().
     *
     * It used to hand the template the invoice, its log and its available actions and nothing else,
     * which is why the page was a six-field strip: everything the order screen prints in its three
     * header panels was reachable on the entity and simply never asked for. Four things are added
     * here, each for a reason that is about an INVOICE rather than about matching the order:
     *
     *  - The two addresses. An invoice is the demand for payment, so where it was billed is part of
     *    the document, not a lookup — and the addresses on a sales document are a FROZEN SNAPSHOT,
     *    captured when the document is written, exactly as the order side already settled.
     *  - The tax breakdown. The page printed one Tax figure with nothing behind it. breakdownForOrder()
     *    returns the invoice's own frozen tax_lines snapshot when it has one and recomputes only for
     *    documents saved before that column existed, so the per-line Tax $ column and the itemised
     *    totals say what was actually charged rather than what today's rates would charge.
     *  - The audit history. Invoice is not in AuditLogSubscriber::EXCLUDED, so these rows have been
     *    written all along and had nowhere to be read. On a document that states money owed, who
     *    changed what is the question most worth being able to answer.
     */
    #[Route('/invoice/detail/{id}', name: 'admin_invoice_detail', methods: ['GET'])]
    public function detail(
        int $id,
        EntityManagerInterface $entityManager,
        OrderTaxBreakdownService $taxBreakdownService,
        AuditLogRepository $auditLogRepository,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $breakdown = $taxBreakdownService->breakdownForOrder($invoice);

        return $this->render('admin/invoice/detail.html.twig', [
            'invoice' => $invoice,
            // The invoice's OWN frozen snapshots (invoice_address), never the company's current
            // address book. An invoice raised from an order inherits the ORDER's snapshot at issue
            // time — OrderInvoicingService::raise() calls copyAddressFrom() per address for exactly
            // this reason — so a customer who moves house afterwards does not silently restate where
            // last quarter's goods were billed and sent, and the two documents can never disagree
            // about one shipment.
            //
            // getBillingAddress()/getShippingAddress(), not the getEffective*Address() pair the
            // invoice PDF calls. The two return the same object for an invoice, because the only
            // difference is that getEffective* resolves a LINK-ONLY row live through
            // source_address_id, and Cart::setAddressLink() is the sole producer of a link-only row
            // in the application — a cart is not yet a record of anything, an invoice always is. The
            // plain getters are used here so that "this screen never reads the address book live" is
            // a property of the call rather than of which subclass happens to be passing through it.
            'billingAddress' => $invoice->getBillingAddress(),
            'shippingAddress' => $invoice->getShippingAddress(),
            'taxLines' => $breakdown['lines'],
            'taxLinesTotal' => $breakdown['total'],
            'perLineTax' => $breakdown['perLineTax'],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'],
            'logs' => $auditLogRepository->findNarrativeForEntity('Invoice', $invoice->getId()),
            'auditHistory' => $auditLogRepository->findForEntity('Invoice', $invoice->getId()),
            // The actions this invoice can actually perform right now, so the page renders the two
            // or three buttons that will work rather than six of which four throw.
            'availableActions' => $this->availableActions($invoice),
        ]);
    }


    /**
     * Copy this invoice into a new DRAFT invoice — "make another like this".
     *
     * ## The copy is STANDALONE, and that is the whole of the interesting part
     *
     * `Invoice::$salesOrder` is NOT carried over. An invoice's order link says which order's goods
     * this document bills, and `SalesOrder::invoicedQuantityFor()` counts every linked invoice's
     * lines against the order's. Copying the link would therefore double the order's invoiced
     * quantity the moment the clone was saved, and the original order would read as over-invoiced by
     * exactly the amount of a document that has billed nobody. The per-line
     * `InvoiceLine::$salesOrderLine` attributions are dropped for the same arithmetic.
     *
     * So a cloned invoice is exactly what `admin_invoice_create` produces: a standalone invoice,
     * which `Invoice::$salesOrder` has been nullable for since #539 stage 5 and which
     * `unlinkFromOrder()` can already produce. If it should bill the same order, Link to Order does
     * that deliberately and runs `InvoiceOrderMatcher` over it first — which is the check that stops
     * the over-invoicing this would otherwise have caused silently.
     *
     * Available on EVERY status, including Cancelled and fully Paid. A paid invoice is the single
     * most-cloned document in a wholesale office: same customer, same twelve lines, next month.
     * Cloning writes nothing to the original, so its status is never consulted, and a LOCKED invoice
     * can be cloned too — a lock freezes the document, and the clone is a different document.
     */
    #[Route('/invoice/clone/{id}', name: 'admin_invoice_clone', methods: ['POST'])]
    public function cloneInvoice(
        int $id,
        EntityManagerInterface $entityManager,
        SalesDocumentCloner $cloner,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $copy = $cloner->cloneInvoice($invoice, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'Invoice %s copied to %s as a new draft. Lines, quantities and prices came across exactly as they '
                . 'were — re-price it if the customer\'s list has moved since. The copy bills no sales order: '
                . 'link it to one if it should.',
            $invoice->getDocumentNumber(),
            $copy->getDocumentNumber(),
        ));

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $copy->getId()]);
    }

    /**
     * Freeze this invoice against editing. Any admin; unlocking asks for ROLE_SUPER_ADMIN — see
     * {@see \App\Service\Document\DocumentLockService} for the asymmetry.
     */
    #[Route('/invoice/lock/{id}', name: 'admin_invoice_lock', methods: ['POST'])]
    public function lockInvoice(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentLockService $locks,
        DocumentActorResolver $actorResolver,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $locks->lock($invoice, $actorResolver->resolve(), (string) $request->request->get('reason', ''), $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'Invoice %s is locked. It can still be printed, emailed and cloned; nothing can edit it, take a '
                . 'payment against it, change its status or delete it until it is unlocked.',
            $invoice->getDocumentNumber(),
        ));

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /** Release the freeze. ROLE_SUPER_ADMIN, which ROLE_TECH_SUPPORT inherits — see DocumentLockService. */
    #[Route('/invoice/unlock/{id}', name: 'admin_invoice_unlock', methods: ['POST'])]
    public function unlockInvoice(int $id, EntityManagerInterface $entityManager, DocumentLockService $locks): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $locks->unlock($invoice, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Invoice %s is unlocked and can be edited again.', $invoice->getDocumentNumber()));

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /**
     * Raise an invoice with no order behind it — the create screen and its save.
     *
     * ## Why this exists
     *
     * Until now an invoice could only be a consequence of a sales order: `newFromOrder()` /
     * `createFromOrder()` bill part or all of one, and every other creation path mints its 1:1
     * shadow. `Invoice::$salesOrder` has been nullable since #539 stage 5 and `unlinkFromOrder()`
     * can already produce an order-less invoice, so the ENTITY was ready; what was missing was a
     * way to raise one in the first place. A distributor types up an invoice for goods no order
     * was ever raised for — a counter sale, a rebill, a paper order from a rep — and the only
     * previous answer was to invent a sales order to hang it on.
     *
     * ## Shape
     *
     * The same two-step every other admin create screen uses (order, quote): a bare company
     * picker posts `company_id` with no `save_mode` and is answered with a redirect carrying
     * `?company_id=`, which renders the workspace. That redirect-then-reload is what makes the
     * page work with scripting off, and it is copied rather than re-invented — see
     * OrderController::create() and EstimateController::create().
     *
     * ## Tax and fees come from the one place that computes them
     *
     * Nothing here knows what a tax code means. The breakdown is
     * OrderTaxBreakdownService::computeBreakdownFor(), typed to AbstractSalesDocument and handed
     * this invoice's own lines and charge rows — the same call the order form makes and the same
     * call OrderInvoicingService makes for a part-invoice. A second implementation of tax on this
     * path is the shape behind #589/#590/#591, so there is not one.
     *
     * ## Addresses
     *
     * An order-derived invoice copies the ORDER's frozen snapshots. A standalone invoice has no
     * order, so it takes its own snapshot of the customer's default billing and shipping rows AT
     * CREATION TIME, through setBillingAddressFrom()/setShippingAddressFrom() — the same freeze an
     * estimate takes. It is a snapshot and not a live read on purpose: an invoice is the record of
     * who was billed and where the goods went, and editing the customer afterwards must not
     * restate a document that has already gone out.
     *
     * ## Stock
     *
     * Invoicing moves no stock, but an ISSUED invoice HOLDS it: InvoiceReservationSubject writes
     * what each line bills into `pending`/`approved`. So #326's ceiling applies here exactly as it
     * does to an order save, asked through AdminOrderStockValidator::shortfallsForInvoice() — and
     * backorder capacity is NOT cover on this document, because an invoice line has no split to
     * fall back on and would reserve the whole amount anyway. A draft holds nothing and is never
     * refused.
     */
    #[Route('/invoice/create', name: 'admin_invoice_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        InvoiceNumberGenerator $invoiceNumbers,
        OrderTaxBreakdownService $taxBreakdownService,
        FeeCalculatorResolver $feeResolver,
        ShippingResolver $shippingResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        CustomFieldRenderer $customFieldRenderer,
        DocumentActorResolver $actorResolver,
        BusinessDate $businessDate,
        AdminOrderStockValidator $stockValidator,
        StockOverrideRecorder $stockOverrides,
        OrderInvoicingService $orderInvoicing,
        DocumentLockService $locks,
        InvoiceLineReconciler $lineReconciler,
    ): Response {
        // Whether this invoice bills an order is a FIELD, not a screen. `order_id` is that field's
        // one discriminator, read the same way on a GET (querystring, from the "Convert to Invoice"
        // link) and a POST (a hidden field the form carries), exactly the two-step "presence is the
        // switch" pattern this screen already uses for `company_id`.
        $orderId = (int) ($request->query->get('order_id') ?: $request->request->get('order_id'));
        $order = null;
        if ($orderId > 0) {
            $order = $entityManager->find(SalesOrder::class, $orderId);
            if (!$order instanceof SalesOrder) {
                $this->addFlash('error', 'Order could not be found.');

                return $this->redirectToRoute('admin_order_index');
            }

            $status = $order->getStatusEnum();
            if ($status === null || !$status->isApprovedOrLater()) {
                $this->addFlash('error', sprintf(
                    'Order %s is %s. Approve it before invoicing against it.',
                    $order->getOrderNumber(),
                    $order->getStatus(),
                ));

                return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
            }
        }

        $company = $order instanceof SalesOrder ? $order->getCompany() : $this->companyFromRequest($request, $entityManager);

        if (!$request->isMethod('POST')) {
            $lineRows = $order instanceof SalesOrder ? $this->linePrefillFromOrder($order) : $this->blankInvoiceLineRows();
            $chargeRows = $order instanceof SalesOrder ? $this->uninvoicedChargeRows($order) : [];
            if ($order instanceof SalesOrder && $lineRows === [] && $chargeRows === []) {
                $this->addFlash('info', sprintf('Order %s is fully invoiced. There is nothing left to invoice.', $order->getOrderNumber()));

                return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
            }

            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $chargeRows,
                null,
                Response::HTTP_OK,
                null,
                $order,
            );
        }

        $postedCompany = $order instanceof SalesOrder
            ? $order->getCompany()
            : $entityManager->find(Company::class, (int) $request->request->get('company_id', 0));

        // A post that states no save_mode and none of the no-JS row buttons is the company picker
        // answering step one — never true once an order is behind this invoice, since that path
        // never renders a company picker at all. Recognising the buttons here is what keeps the page
        // usable without JavaScript: "Add Line", "Add Line & Save" and the charge row's ✕ all post
        // the whole form, and reading one of them as a company pick would throw away everything
        // typed so far — exactly the defect #236 fixed on the order form.
        $isInvoiceSubmit = $order instanceof SalesOrder
            || $request->request->has('save_mode')
            || $request->request->has('add_line')
            || $request->request->has('remove_line')
            || $request->request->has('add_charge_line')
            || $request->request->has('remove_charge_line')
            || $request->request->has('lines');

        if (!$isInvoiceSubmit) {
            return $this->redirectToRoute(
                'admin_invoice_create',
                $postedCompany instanceof Company ? ['company_id' => $postedCompany->getId()] : [],
            );
        }

        $company = $postedCompany;
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Choose a customer before saving the invoice.');

            return $this->redirectToRoute('admin_invoice_create');
        }

        if ($order instanceof SalesOrder) {
            // Billing an order writes the ORDER: the invoice joins its invoice set and
            // SalesOrderDerivedStatusSubscriber re-derives its status from it. A frozen order is not
            // moved to Partially Invoiced by a write aimed at a different document.
            $locks->assertWritable($order, 'invoiced');
        }

        $lineRows = $this->postedInvoiceLineRows($request);

        // Two contracts, because they mean different things: an order-linked charge row DRAWS DOWN
        // one that already exists (`charges[N][slug|quantity|amount]`) and a standalone one MINTS a
        // new one (`charge_lines[N][...]`, through the shared charge row and add-line bar).
        if ($order instanceof SalesOrder) {
            $postedCharges = $request->request->all('charges');
            $requestedCharges = [];
            foreach (is_array($postedCharges) ? $postedCharges : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $amount = trim((string) ($row['amount'] ?? ''));
                $requestedCharges[] = [
                    'slug' => trim((string) ($row['slug'] ?? '')),
                    'quantity' => trim((string) ($row['quantity'] ?? '0')),
                    'amount' => $amount === '' ? null : $amount,
                ];
            }

            try {
                $charges = $orderInvoicing->keptCharges($order, $requestedCharges);
            } catch (\DomainException $e) {
                return $this->renderInvoiceForm(
                    $request,
                    $entityManager,
                    $shippingResolver,
                    $paymentMethodResolver,
                    $companyFulfillmentRegionService,
                    $customFieldRenderer,
                    $taxBreakdownService,
                    $company,
                    $lineRows,
                    [],
                    $e->getMessage(),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    null,
                    $order,
                );
            }
        } else {
            $rawCharges = $this->postedInvoiceChargeRows($request);

            // A charge row naming a type this app has no line for is refused at the door rather than
            // half way through the save, for the reason EstimateController states: the fee
            // calculators flush partway through, so a refusal discovered later leaves half a
            // document written.
            $chargeError = SalesDocumentChargeLines::errorFor($rawCharges);
            if ($chargeError !== null) {
                return $this->renderInvoiceForm(
                    $request,
                    $entityManager,
                    $shippingResolver,
                    $paymentMethodResolver,
                    $companyFulfillmentRegionService,
                    $customFieldRenderer,
                    $taxBreakdownService,
                    $company,
                    $lineRows,
                    [],
                    $chargeError,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    null,
                    $order,
                );
            }

            $charges = SalesDocumentChargeLines::normalize($rawCharges);
        }

        // No special "re-render only" branch for add_line/add_charge_line/remove_charge_line —
        // order's own saveModeFromRequest() treats these identically to its own no-JS submits: a
        // real save, staying in Draft (no save_mode named means not 'issue'), exactly as one of
        // these posts does here (#full-parity, 2026-09-13 — invoice must not special-case this).
        if ($request->request->has('add_line')) {
            $lineRows[] = self::EMPTY_INVOICE_LINE_ROW;
        }

        [$stockError, $stockShortfalls, $stockReasons] = $this->stockShortfallCheck(
            array_map(
                fn (array $row): array => [
                    'product_id' => $row['productId'] ?? '',
                    'qty' => $this->baseQuantityForRow($row, $entityManager),
                    'location' => $row['location'] ?? '',
                    'stock_override_reason' => $row['stockOverrideReason'] ?? '',
                ],
                $lineRows,
            ),
            $request->request->get('save_mode') === 'issue' ? 'Pending' : 'Draft',
            $this->nullableString($request->request->get('fulfillment_region')),
            $stockValidator,
            $stockOverrides,
            $entityManager,
        );
        if ($stockError !== null) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $charges,
                $stockError,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                null,
                $order,
            );
        }

        $invoice = new Invoice();
        $invoice->setCompany($company);
        // setCompany() seeds the identity from the live company; snapshotCompany() freezes it, so the
        // document keeps naming the party it was raised against even after the company row is
        // edited. Unconditional, order or not: the admin can see and edit the address/company card on
        // screen regardless, so save must take whatever was actually posted — the order's identity
        // only ever pre-fills what that card SHOWS at GET time, never overrides it at save.
        $invoice->snapshotCompany($company);
        if ($order instanceof SalesOrder) {
            $order->addInvoice($invoice);
        }
        $invoice->setSource('Admin');
        // user_name is the CUSTOMER USER who initiated the document. An admin-typed invoice has
        // none, so it stays null rather than carrying a staff name into a customer-facing column —
        // the same rule EstimateController::create() states (#269). Who typed it is on the
        // invoice's own timeline, written below, and in audit_log.
        $invoice->setUserName(null);
        $invoice->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80));
        $invoice->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')));
        $invoice->setPaymentMethod($this->nullableString($request->request->get('payment_method')));
        $invoice->setPaymentTerm($this->nullableString($request->request->get('payment_term')));
        $invoice->setFulfillmentRegion($this->nullableString($request->request->get('fulfillment_region')));

        // document_date is what the document was written on and invoice_date is what it is booked
        // under. An order-derived invoice takes both from the order; here the admin states one date
        // and both follow it, so the two never disagree on a document nobody typed two dates on.
        //
        // Resolved here rather than left to SalesDocumentDateStamp's prePersist default: that fills
        // document_date alone, which would leave a blank box producing an invoice dated today and
        // booked under nothing. BusinessDate::today() is the same clock the stamp uses.
        $invoiceDate = $this->calendarDate($request->request->get('invoice_date')) ?? $businessDate->today();
        $invoice->setDocumentDate($invoiceDate);
        $invoice->setInvoiceDate($invoiceDate);
        $invoice->setDueDate($this->calendarDate($request->request->get('due_date')));

        // The addresses, frozen now — see this method's docblock and the Billing/Shipping Detail
        // card's own (#full-parity, 2026-09-12). The company's plain defaults are seeded first so a
        // bare no-JS/API POST that skips straight to save_mode (the card never rendered) still gets
        // an address, exactly as EstimateController::create() does; applyInvoiceAddressCard() below
        // then overrides with whatever the card actually posted. No setBillingName()/
        // setShippingName() call: applyAddressEditsFromRequest() writes first and last from their
        // own posted fields losslessly, unlike that pair which joins and re-splits on the first
        // space — the same fix OrderController::create() made for #278.
        $billingAddress = $this->defaultAddress($company, 'billing');
        if ($billingAddress !== null) {
            $invoice->setBillingAddressFrom($billingAddress);
        }
        $shippingAddress = $this->defaultAddress($company, 'shipping');
        if ($shippingAddress !== null) {
            $invoice->setShippingAddressFrom($shippingAddress);
        }
        $this->applyInvoiceAddressCard($invoice, $company, $entityManager, $request, 'billing');
        $this->applyInvoiceAddressCard($invoice, $company, $entityManager, $request, 'shipping');

        // What the save had to rewrite on a line, carried back to the admin rather than done
        // silently: a negative quantity is stored as 0 and a typed price that cannot be read as a
        // number is stored as 0, and both say so, naming the line. The same collector the order and
        // quote saves use, so all three documents report a coercion the same way (#248/#253/#259).
        $lineWarnings = new SalesDocumentLineWarnings();
        $subtotal = $this->applyLineRows($invoice, $this->postedLineRows($request), $entityManager, $lineWarnings, $lineReconciler);

        // Taxable goods with nowhere to tax them are REFUSED, never silently zero-taxed.
        $provinceError = $this->taxProvinceRefusal($invoice, $company);
        if ($provinceError !== null) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $charges,
                $provinceError,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                null,
                $order,
            );
        }

        $actor = $actorResolver->resolve();

        if ($order instanceof SalesOrder) {
            // Drawn down from what the order already agreed to charge, not recalculated fresh — a
            // partial-order fee bill is "how much of this specific already-agreed dollar amount am I
            // billing now", not something a rate calculator derives from this one invoice's own
            // lines. Fully isolated in OrderInvoicingService::chargesAndTaxFor(), reused as-is — it
            // sets subtotal/fee/tax/total on $invoice itself, including tax, so the standalone tax
            // path below does not run for this branch.
            $requestedLines = [];
            foreach ($invoice->getLines() as $invoiceLine) {
                $orderLine = $invoiceLine->getSalesOrderLine();
                if ($orderLine instanceof SalesOrderLine) {
                    $requestedLines[] = ['line' => $orderLine, 'quantity' => $invoiceLine->getQuantity(), 'price' => $invoiceLine->getPrice()];
                }
            }
            $orderInvoicing->chargesAndTaxFor($invoice, $order, $requestedLines, $charges, $subtotal, $actor);
        } else {
            // Named shipping rows are re-priced from the resolver rather than trusted from the
            // browser, the same guard the order and quote forms apply to their own shipping rows.
            $charges = $this->repricedShippingCharges($charges, $shippingResolver, $invoice);
            $invoice->setShippingMethod(SalesDocumentChargeLines::deriveShippingMethod($charges));

            // Before the fee calculators: FeeContext::fromDocument() reads the document's subtotal
            // and its priced rows, so a document whose subtotal is still 0 is priced as an empty one.
            $invoice->setSubtotal($this->decimalAmount($subtotal));

            $feeLines = array_merge(
                $feeResolver->calculate(FeeContext::fromDocument($invoice)),
                SalesDocumentChargeLines::toShippingLines($charges, $invoice->getHighestTaxClass()),
                SalesDocumentChargeLines::toFeeLines($charges),
            );
            // Carried at scale, not snapped to the cent: a fee total is an intermediate on the way to
            // the grand total and rounding it here would be a second rounding point (#257).
            $feeTotal = SalesDocumentMoney::intermediate((float) array_sum(array_map(
                static fn (FeeLine $line): float => $line->amount,
                $feeLines,
            )));
            $invoice->setFeeLines(FeeLineSnapshot::encode($feeLines));

            // THE tax path. Manually typed tax rows join the computed breakdown as lines of their
            // own, so the stored tax figure stays the sum of the document's tax lines rather than a
            // total with an unexplained extra on top — identical to what the order form does.
            $breakdown = $taxBreakdownService->withManualTaxLines(
                $taxBreakdownService->computeBreakdownFor($invoice, $feeLines),
                $taxBreakdownService->manualTaxLinesFromCharges($charges),
            );
            $tax = SalesDocumentMoney::intermediate((float) $breakdown['total']);

            $invoice
                ->setTaxLines($taxBreakdownService->toJson($breakdown))
                ->setTax($this->decimalAmount($tax))
                ->setTotal($this->decimalAmount($subtotal + $feeTotal + $tax));
        }

        // The number is drawn LAST, once this invoice is certain to exist. DocumentNumberAllocator
        // increments a counter row and commits it there and then — so allocating before the
        // line check above would burn an invoice number every time somebody pressed Save on a form
        // with nothing on it, and an accounting sequence with holes in it is a question somebody
        // has to answer later.
        $invoice->setDocumentNumber($invoiceNumbers->next($entityManager));

        $invoice->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment($order instanceof SalesOrder
                ? sprintf('Invoice %s raised against order %s.', $invoice->getDocumentNumber(), $order->getOrderNumber())
                : sprintf('Invoice %s raised directly for %s. No sales order stands behind it.', $invoice->getDocumentNumber(), $company->getName()))
            ->setType('System');
        if ($order instanceof SalesOrder) {
            $order->queueActivityLogEntry()
                ->setUserName($actor->displayName)
                ->setComment(sprintf('Invoice %s raised against this order.', $invoice->getDocumentNumber()))
                ->setType('System');
        }

        // Through the named action, never by assigning a status: issuing is what starts the
        // inventory hold and writes the timeline entry. A draft is inert and holds nothing.
        //
        // Issued only when the post actually asked for it. A post stating no mode at all — a script,
        // or a form submitted some way this screen does not render — lands as a Draft, because the
        // two answers are not equally safe: a draft can be issued afterwards with one button, while
        // an invoice issued by accident has taken a stock hold and cannot be un-issued at all.
        // OverInvoicingGuard's refusal is a plain \DomainException, not the StatusTransitionRefused
        // subtype the app-wide exception listener catches (Invoice::assertStatusChangeAllowed()'s
        // own docblock states why) — uncaught here it was a raw 500 instead of the flash the old,
        // now-deleted saveOrderLinkedInvoice() gave this exact refusal. The invoice still saves as a
        // Draft either way: the write below is unconditional, only the flash and the redirect differ.
        $issueError = null;
        if ($request->request->get('save_mode') === 'issue') {
            try {
                $invoice->issue($actor);
            } catch (\DomainException $e) {
                $issueError = $e->getMessage();
            }
        }

        $entityManager->persist($invoice);

        // The decision to bill beyond the shelf, recorded against the lines it was taken on (#326).
        // After the lines are on the invoice and before the flush, exactly where the order save and
        // ReceivingService both write theirs: the document and the record of why it looks like that
        // are written together or not at all. Silent when nothing was short, which is almost every
        // invoice.
        //
        // Whether the invoice is issued or left a Draft does not change whether this is written: a
        // Draft holds nothing today, so shortfallsForInvoice() found none and there is nothing here
        // to write. Anything this reaches is a document that really is holding stock it does not
        // have, on purpose.
        $stockOverrides->recordForInvoice($invoice, $stockShortfalls, $stockReasons, $actor->displayName, $entityManager);

        $entityManager->flush();

        // The admin-defined fields, AFTER the flush that minted the invoice and before the redirect
        // that leaves this request. A value row is FK'd to invoice_id with ON DELETE CASCADE — see
        // CustomFieldValueInvoice — so there is no id for it to point at until the invoice has been
        // written, which is why this cannot join the save above. Second flush, same request: the
        // invoice and its custom fields are one save as far as the admin is concerned.
        $customFieldRenderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_INVOICE, $invoice, $request);
        $entityManager->flush();

        if ($issueError !== null) {
            $this->addFlash('error', $issueError);
        } else {
            $this->addFlash('success', $order instanceof SalesOrder
                ? sprintf('Invoice %s was raised against order %s.', $invoice->getDocumentNumber(), $order->getOrderNumber())
                : sprintf('Invoice %s was raised for %s.', $invoice->getDocumentNumber(), $company->getName()));
        }
        // The invoice IS saved; these say what had to be rewritten on the way in, and they ride the
        // redirect so the page it lands on states them.
        $lineWarnings->flashOnto($request);

        // Order's own rule (OrderController::saveModePreservesStatus()): a save whose whole point
        // was adding/removing a row or a charge — not a stated "I'm done" button — lands back on
        // the form, not the read-only detail page, because the admin is still working on the
        // document (#full-parity, 2026-09-13). A refused issue is the same case for the same
        // reason: the invoice is a Draft either way and the admin is still working on it.
        if ($issueError !== null || $this->isContinueEditingPost($request)) {
            return $this->redirectToRoute('admin_invoice_edit', ['id' => $invoice->getId()]);
        }

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /**
     * Whether this post's whole point was adding/removing a row or a charge rather than stating
     * "save as draft" or "raise invoice" — the same distinction order's saveModeFromRequest() makes
     * between 'recalc' and every named save_mode.
     */
    private function isContinueEditingPost(Request $request): bool
    {
        return $request->request->has('add_line')
            || $request->request->has('remove_line')
            || $request->request->has('add_charge_line')
            || $request->request->has('remove_charge_line');
    }

    /**
     * Edit an invoice, order-linked or not — `Invoice::canEditOnStatus()` states the only exceptions
     * (Completed, Cancelled). Company/address freezing and document numbering are skipped: those
     * never depended on status, only on "this invoice already exists." Charges keep using this
     * invoice's own stored rows regardless of `salesOrder` — the order-drawdown reconciliation
     * lives in create()'s own save only.
     */
    #[Route('/invoice/edit/{id}', name: 'admin_invoice_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        OrderTaxBreakdownService $taxBreakdownService,
        FeeCalculatorResolver $feeResolver,
        ShippingResolver $shippingResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        CustomFieldRenderer $customFieldRenderer,
        DocumentActorResolver $actorResolver,
        AdminOrderStockValidator $stockValidator,
        StockOverrideRecorder $stockOverrides,
        DocumentLockService $locks,
        InvoiceLineReconciler $lineReconciler,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        if (!$invoice->canEditOnStatus()) {
            $this->addFlash('error', sprintf(
                'Invoice %s is %s and can no longer be edited. Raise a credit memo to correct it.',
                $invoice->getDocumentNumber(),
                $invoice->getStatus(),
            ));

            return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
        }

        // A manual freeze (App Management's super-admin lock) refuses through the same global
        // exception listener every other locked write does — see DocumentLockedSubscriber. Nothing
        // to catch here; letting it throw is the point.
        $locks->assertWritable($invoice, 'edited');

        $order = $invoice->getSalesOrder();
        if ($order instanceof SalesOrder) {
            $locks->assertWritable($order, 'invoiced');
        }

        $company = $invoice->getCompany();

        if (!$request->isMethod('POST')) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $this->lineRowsFromInvoice($invoice),
                $this->chargeRowsFromInvoice($invoice),
                null,
                Response::HTTP_OK,
                $invoice,
                $order,
            );
        }

        $lineRows = $this->postedInvoiceLineRows($request);
        $rawCharges = $this->postedInvoiceChargeRows($request);

        $chargeError = SalesDocumentChargeLines::errorFor($rawCharges);
        if ($chargeError !== null) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                [],
                $chargeError,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invoice,
                $order,
            );
        }

        $charges = SalesDocumentChargeLines::normalize($rawCharges);
        // A post that never rendered the charge UI at all must not wipe what is already stored —
        // order's own chargeRowsForSave() guard, ported verbatim (#full-parity, 2026-09-13). Without
        // it a bare API/no-JS post of just the lines destroyed every shipping row, tax adjustment
        // and manual fee line on the invoice's first edit.
        if (!$request->request->has('charge_lines_present') && $charges === []) {
            $charges = $this->storedInvoiceChargeRows($invoice, $taxBreakdownService);
        }

        // No special "re-render only" branch — see create()'s identical fix. An existing invoice
        // already has its number; there was never a minting concern here to begin with.
        if ($request->request->has('add_line')) {
            $lineRows[] = self::EMPTY_INVOICE_LINE_ROW;
        }

        // Same rule as create() — see that method's docblock on stock (#326) — and it must be asked
        // again here rather than trusted from the invoice's own creation: an edit can raise a
        // quantity, move it to a bin with less on the shelf, or lift the invoice from Draft to
        // Issued in the same save that changes what it bills.
        $stockShortfalls = $stockValidator->shortfallsForInvoice(
            array_map(
                fn (array $row): array => [
                    'product_id' => $row['productId'] ?? '',
                    'qty' => $this->baseQuantityForRow($row, $entityManager),
                    'location' => $row['location'] ?? '',
                ],
                $lineRows,
            ),
            $request->request->get('save_mode') === 'issue' ? 'Pending' : 'Draft',
            $this->nullableString($request->request->get('fulfillment_region')),
            $entityManager,
        );
        $stockReasons = StockOverrideReasons::fromPostedLines($lineRows, 'stockOverrideReason');
        $stockError = $stockOverrides->refusalFor($stockShortfalls, $stockReasons);
        if ($stockError !== null) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $charges,
                $stockError,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invoice,
                $order,
            );
        }

        // Company, its snapshot and the frozen addresses are NOT touched — see this method's
        // docblock. What an edit may still change: the document's own dates, references and terms,
        // exactly as create() sets them.
        $invoice->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80));
        $invoice->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')));
        $invoice->setPaymentMethod($this->nullableString($request->request->get('payment_method')));
        $invoice->setPaymentTerm($this->nullableString($request->request->get('payment_term')));
        $invoice->setFulfillmentRegion($this->nullableString($request->request->get('fulfillment_region')));

        $invoiceDate = $this->calendarDate($request->request->get('invoice_date')) ?? $invoice->getDocumentDate();
        $invoice->setDocumentDate($invoiceDate);
        $invoice->setInvoiceDate($invoiceDate);
        $invoice->setDueDate($this->calendarDate($request->request->get('due_date')));

        // Zero lines is a valid invoice (#full-parity, 2026-09-12) — but a request that omits the
        // `lines` key ENTIRELY is not the admin choosing that; it is a malformed/incomplete submit,
        // and the real screen always posts at least the two no-JS spare rows even when every real
        // line has been removed. Refusing only this case, never "posted but empty," is what stops a
        // broken request from silently wiping an invoice that already has real lines on it.
        if (!$request->request->has('lines')) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $charges,
                'Lines were not submitted — nothing was changed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invoice,
                $order,
            );
        }

        // The lines are UPSERTED by id, through the same SellSideLineReconciler Order and Estimate
        // use (#full-parity — this was the one save-mechanics gap the earlier effort of that name
        // never closed). An untouched line now survives an edit with its own identity intact:
        // InvoiceLineStockOverride still cascades on delete, but only a REMOVED line's override
        // is deleted with it, not every line's; a fresh one is written by recordForInvoice() below
        // for whatever THIS save's own stock check finds short, matched by product+warehouse
        // rather than by array position (see StockOverrideRecorder), so upserting changes nothing
        // about how it attaches. CreditMemoLine::$invoiceLine — which used to go stale on every
        // single edit, since a delete-and-rebuild always minted a new id — now stays valid across
        // an edit that doesn't touch the credited line, a real fix rather than a side effect to
        // ignore. orphanRemoval on Invoice::$lines still does the actual deletion for a REMOVED row.
        $lineWarnings = new SalesDocumentLineWarnings();
        $subtotal = $this->applyLineRows($invoice, $this->postedLineRows($request), $entityManager, $lineWarnings, $lineReconciler);

        $provinceError = $this->taxProvinceRefusal($invoice, $company);
        if ($provinceError !== null) {
            return $this->renderInvoiceForm(
                $request,
                $entityManager,
                $shippingResolver,
                $paymentMethodResolver,
                $companyFulfillmentRegionService,
                $customFieldRenderer,
                $taxBreakdownService,
                $company,
                $lineRows,
                $charges,
                $provinceError,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invoice,
                $order,
            );
        }

        $charges = $this->repricedShippingCharges($charges, $shippingResolver, $invoice);
        $invoice->setShippingMethod(SalesDocumentChargeLines::deriveShippingMethod($charges));

        $invoice->setSubtotal($this->decimalAmount($subtotal));

        $feeLines = array_merge(
            $feeResolver->calculate(FeeContext::fromDocument($invoice)),
            SalesDocumentChargeLines::toShippingLines($charges, $invoice->getHighestTaxClass()),
            SalesDocumentChargeLines::toFeeLines($charges),
        );
        $feeTotal = SalesDocumentMoney::intermediate((float) array_sum(array_map(
            static fn (FeeLine $line): float => $line->amount,
            $feeLines,
        )));
        $invoice->setFeeLines(FeeLineSnapshot::encode($feeLines));

        $breakdown = $taxBreakdownService->withManualTaxLines(
            $taxBreakdownService->computeBreakdownFor($invoice, $feeLines),
            $taxBreakdownService->manualTaxLinesFromCharges($charges),
        );
        $tax = SalesDocumentMoney::intermediate((float) $breakdown['total']);

        $invoice
            ->setTaxLines($taxBreakdownService->toJson($breakdown))
            ->setTax($this->decimalAmount($tax))
            ->setTotal($this->decimalAmount($subtotal + $feeTotal + $tax));

        // No setDocumentNumber() — an edit never draws a new one, whatever it changes.
        $actor = $actorResolver->resolve();
        $invoice->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Invoice %s updated.', $invoice->getDocumentNumber()))
            ->setType('System');

        // Same named action create() uses — see its docblock and its OverInvoicingGuard catch,
        // which applies here for the same reason: an order-linked Draft is now editable and its
        // edit screen can attempt exactly this same issue.
        $issueError = null;
        if ($request->request->get('save_mode') === 'issue') {
            try {
                $invoice->issue($actor);
            } catch (\DomainException $e) {
                $issueError = $e->getMessage();
            }
        }

        // No persist(): $invoice came from the EntityManager and is already managed.
        $stockOverrides->recordForInvoice($invoice, $stockShortfalls, $stockReasons, $actor->displayName, $entityManager);

        $entityManager->flush();

        $customFieldRenderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_INVOICE, $invoice, $request);
        $entityManager->flush();

        if ($issueError !== null) {
            $this->addFlash('error', $issueError);
        } else {
            $this->addFlash('success', sprintf('Invoice %s was updated.', $invoice->getDocumentNumber()));
        }
        $lineWarnings->flashOnto($request);

        // See create()'s identical rule and isContinueEditingPost() — a row/charge add or remove
        // stays on the form; a stated save leaves for the detail page. A refused issue is the same
        // case: the invoice is still a Draft and the admin is still working on it.
        if ($issueError !== null || $this->isContinueEditingPost($request)) {
            return $this->redirectToRoute('admin_invoice_edit', ['id' => $invoice->getId()]);
        }

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /**
     * The edit screen's starting rows: this invoice's own lines, in the same shape
     * postedInvoiceLineRows() reads a post back into, so a GET and a re-render after a refused
     * POST paint identically.
     *
     * @return list<array<string, string>>
     */
    /**
     * Applies a submitted Billing/Shipping Detail card onto the invoice's own address snapshot —
     * same pattern OrderController::applyOrderAddressFromRequest() and
     * EstimateController::applyEstimateAddressCard() use. The address book is only ever READ here;
     * source_address_id records which entry the snapshot came from. A no-op when the card was never
     * rendered (a bare/no-JS submit made before any company was chosen), so a plain save leaves
     * whatever address create()'s company-default fallback already set untouched.
     */
    /**
     * The invoice's own live fee/tax preview, ported from OrderController's
     * feeLinesAjax()/taxBreakdownAjax()/previewOrderFromRequest() verbatim
     * (#full-parity, 2026-09-13 — the owner's ruling: Order's implementation is the source of
     * truth, and there was no reason found for Invoice to compute this differently, or not at
     * all — it never had). Same params, same shape of response, so the one app.js function that
     * calls either endpoint does not need to know which document it is talking to.
     */
    #[Route('/invoice/fee-lines', name: 'admin_invoice_fee_lines', methods: ['GET'])]
    public function feeLinesAjax(Request $request, EntityManagerInterface $entityManager, FeeCalculatorResolver $feeResolver): JsonResponse
    {
        $companyId = (int) $request->query->get('company_id', 0);
        $addressId = (int) $request->query->get('address_id', 0);
        $province = (string) $request->query->get('province', '');
        $linesRaw = (string) $request->query->get('lines', '[]');

        $company = $entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            return $this->json(['lines' => [], 'total' => 0]);
        }

        $preview = $this->previewInvoiceFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
        $feeLines = $feeResolver->calculate(FeeContext::fromDocument($preview));
        $total = array_sum(array_map(fn (FeeLine $l): float => $l->amount, $feeLines));

        return $this->json([
            'lines' => array_map(
                fn (FeeLine $l): array => ['slug' => $l->slug, 'label' => $l->label, 'taxClass' => $l->taxClass, 'amount' => $l->amount, 'type' => $l->type, 'source' => $l->source],
                $feeLines,
            ),
            'total' => round($total, 2),
        ]);
    }

    #[Route('/invoice/tax-breakdown', name: 'admin_invoice_tax_breakdown', methods: ['GET'])]
    public function taxBreakdownAjax(Request $request, EntityManagerInterface $entityManager, FeeCalculatorResolver $feeResolver, OrderTaxBreakdownService $taxBreakdownService): JsonResponse
    {
        $companyId = (int) $request->query->get('company_id', 0);
        $addressId = (int) $request->query->get('address_id', 0);
        $province = (string) $request->query->get('province', '');
        $linesRaw = (string) $request->query->get('lines', '[]');
        $shippingAmount = max(0.0, (float) $request->query->get('shipping', 0));

        $company = $entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            return $this->json(['lines' => [], 'total' => 0, 'perLineTax' => [], 'perLineTaxLabel' => []]);
        }

        $preview = $this->previewInvoiceFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
        $feeLines = array_merge(
            $feeResolver->calculate(FeeContext::fromDocument($preview)),
            SalesDocumentChargeLines::toShippingLines(
                [['label' => 'Shipping', 'amount' => $shippingAmount, 'type' => FeeLine::TYPE_SHIPPING]],
                $preview->getHighestTaxClass(),
            ),
        );

        $breakdown = $taxBreakdownService->computeBreakdownFor($preview, $feeLines);

        return $this->json([
            'lines' => array_map(
                fn (TaxLine $l): array => ['label' => $l->label, 'rate' => $l->rate, 'amount' => $l->amount, 'slug' => $l->slug, 'source' => $l->source],
                $breakdown['lines'],
            ),
            'total' => $breakdown['total'],
            'perLineTax' => $breakdown['perLineTax'],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'],
        ]);
    }

    /**
     * The invoice-typed twin of OrderController::previewOrderFromRequest() — same body, an
     * Invoice/InvoiceLine in place of a SalesOrder/SalesOrderLine, because the calculators this
     * feeds (FeeCalculatorResolver, OrderTaxBreakdownService) already take AbstractSalesDocument
     * and do not care which concrete class this is. The per-document address/line-building glue
     * is the one part PHP's type system does not let this be one shared method for all three
     * documents — the same reason applyInvoiceAddressCard() exists beside
     * OrderController::applyOrderAddressFromRequest() and EstimateController::applyEstimateAddressCard()
     * rather than one shared function.
     */
    private function previewInvoiceFromRequest(
        EntityManagerInterface $entityManager,
        Company $company,
        string $province,
        int $addressId,
        string $linesJson,
    ): Invoice {
        $invoice = (new Invoice())->setCompany($company);

        $address = $invoice->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING);
        $bookAddress = $addressId > 0 ? $entityManager->find(CompanyAddress::class, $addressId) : null;
        if ($bookAddress instanceof CompanyAddress && $bookAddress->getCompany()->getId() === $company->getId()) {
            $address->copyFrom($bookAddress);
        }
        $address->setProvince($province);

        $decoded = json_decode($linesJson, true);
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row['product_id'] ?? 0);
            $product = $productId > 0 ? $entityManager->find(ProductCore::class, $productId) : null;

            $invoice->addLine(
                (new InvoiceLine())
                    ->setProduct($product instanceof ProductCore ? $product : null)
                    ->setQuantity($this->decimalAmount(max(0.0, (float) ($row['qty'] ?? 0))))
                    ->setSubtotal($this->decimalAmount((float) ($row['subtotal'] ?? 0)))
                    // #full-parity, 2026-09-13 — see OrderController::previewOrderFromRequest()'s
                    // own comment on this same fix: a present-but-empty tax_code must fall back to
                    // the product's default, the same as the real save path already does.
                    ->setTaxCode($this->nullableString($row['tax_code'] ?? null)
                        ?? ($product instanceof ProductCore ? $product->getSalesTaxCode() : null)),
            );
        }

        return $invoice;
    }

    private function applyInvoiceAddressCard(Invoice $invoice, Company $company, EntityManagerInterface $entityManager, Request $request, string $type): void
    {
        $idKey = $type . '_address_id';
        if (!$request->request->has($idKey)) {
            return;
        }

        $snapshot = $invoice->addressForWriting($type);

        $addressId = (int) $request->request->get($idKey);
        if ($addressId > 0) {
            $source = $entityManager->find(CompanyAddress::class, $addressId);
            // Ownership check: an id from another company must not be readable through this form.
            if ($source instanceof CompanyAddress && $source->getCompany()->getId() === $company->getId()) {
                $snapshot->copyFrom($source);
            }
        } else {
            $snapshot->setSourceAddress(null);
        }

        $this->applyAddressEditsFromRequest($snapshot, $type, $request);
    }

    private function lineRowsFromInvoice(Invoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->getLines() as $line) {
            $product = $line->getProduct();
            $unitId = (string) ($line->getUnitOfMeasure()?->getId() ?? '');
            $row = [
                // The row's identity, so a save updates THIS line rather than deleting it and
                // creating a replacement, exactly the reason order's own row carries it (#267).
                'id' => $line->getId(),
                'productId' => $product instanceof ProductCore ? (string) $product->getId() : '',
                'name' => $line->getName(),
                'sku' => (string) ($line->getSku() ?? ''),
                'location' => (string) ($line->getLocation() ?? ''),
                'qty' => $line->getQuantityEntered(),
                // Posted straight back so the next save can tell an untouched box from a retyped
                // one — LineDenomination::boxUntouched(), the same protection order's row has.
                'qtyRendered' => $line->getQuantityEntered(),
                'price' => (string) ($line->getPrice() ?? ''),
                'priceRendered' => $line->getDisplayUnitPrice(),
                'unit' => (string) ($line->getUnit() ?? ''),
                'unitId' => $unitId,
                'taxCode' => (string) ($line->getTaxCode() ?? ''),
                'weight' => (string) ($line->getWeight() ?? ''),
                'cost' => $line->getCost(),
                'originalPrice' => $product?->getOriginalPrice() ?? '',
                'subtotal' => $line->getSubtotal(),
                'batch' => $line->getBatch(),
                'tracksBatch' => $this->productTracksBatch($product),
                // 2026-09-14 lot/serial/expiry plan: the line's own picked lot/serial (typically
                // copied verbatim from its sales order line by OrderInvoicingService), and which
                // capture UI to render for it.
                'trackingMode' => $this->productTrackingMode($product),
                'lotId' => $line->getLotId(),
                'serial' => $line->getSerial(),
                'stockOverrideReason' => $line->getStockOverride()?->getReason() ?? '',
                // Carried back so a re-save doesn't silently drop the link OverInvoicingGuard reads.
                'salesOrderLineId' => (string) ($line->getSalesOrderLine()?->getId() ?? ''),
            ];
            // The two resolved figures, on a line denominated in something other than its base unit
            // — a base-unit line would just be restating the boxes (#601), exactly the guard
            // order_line_row() applies.
            if ($unitId !== '') {
                $row['baseQuantity'] = $line->getQuantity();
                $row['unitLabel'] = $line->getDisplayUnitLabel();
                $row['baseUnitPrice'] = $line->getBaseUnitRate();
                $row['resolvedLineTotal'] = $line->getLineTotal();
            }
            $orderLine = $line->getSalesOrderLine();
            if ($orderLine instanceof SalesOrderLine) {
                $row['ordered'] = $orderLine->getQuantityEntered();
                // In the line's OWN unit, not invoicedQuantityFor()'s base-unit figure — a line
                // ordered in boxes showing "40" beside an "Invoiced" of "240" (the base-EA count)
                // reads as invoiced more than was ordered, when 20 of 40 boxes is the true figure.
                // Trimmed to match `ordered`: invoicedQuantityInLineUnitFor() answers in the
                // NUMERIC(14,4) shape ("20.0000"), never what a box count is actually written as.
                $row['invoiced'] = LineDenomination::trimZeros($orderLine->getOrder()->invoicedQuantityInLineUnitFor($orderLine));
            }
            $rows[] = $row;
        }

        return $rows !== [] ? $rows : $this->blankInvoiceLineRows();
    }

    private function productTracksBatch(?ProductCore $product): bool
    {
        $policy = $product?->getTrackingPolicy();

        return $policy instanceof TrackingPolicy && ($policy->tracksLotsOutbound() || $policy->tracksSerialsOutbound());
    }

    /** 'lot', 'serial', or '' — see OrderController::productTrackingMode(), same reasoning. */
    private function productTrackingMode(?ProductCore $product): string
    {
        $policy = $product?->getTrackingPolicy();
        if (!$policy instanceof TrackingPolicy) {
            return '';
        }

        return match (true) {
            $policy->tracksLotsOutbound() => 'lot',
            $policy->tracksSerialsOutbound() => 'serial',
            default => '',
        };
    }

    /**
     * The edit screen's starting charge rows: only the ones an admin TYPED, never one a fee
     * calculator produced.
     *
     * `FeeLine::$source` is exactly this distinction (FeeLine.php's own docblock: "Separate from
     * $type because ... what differs is who decided the amount"), stored on every line since
     * before charges could be re-edited at all. An automatic line is excluded because it is not
     * INPUT — feeResolver->calculate() rebuilds it fresh on every save regardless of what this
     * screen shows, exactly as it does on create(). Showing it here as an editable row would let it
     * be typed over once and never recomputed again.
     *
     * @return list<array<string, string>>
     */
    private function chargeRowsFromInvoice(Invoice $invoice): array
    {
        $rows = [];
        foreach (FeeLineSnapshot::decode($invoice->getFeeLines()) as $line) {
            if ($line->source !== FeeLine::SOURCE_MANUAL) {
                continue;
            }

            $rows[] = [
                'label' => $line->label,
                'amount' => (string) $line->amount,
                'type' => $line->type,
                'slug' => $line->slug,
                'taxClass' => $line->taxClass,
                'placement' => $line->placement,
            ];
        }

        return $rows;
    }

    /**
     * This invoice's own stored shipping/manual-tax/manual-fee lines, reconstructed as charge_lines
     * rows — the same reconstruction OrderController::storedChargeRows() does, for the same guard
     * (#full-parity, 2026-09-13).
     */
    private function storedInvoiceChargeRows(Invoice $invoice, OrderTaxBreakdownService $taxBreakdownService): array
    {
        return array_merge(
            SalesDocumentChargeLines::fromShippingLines($invoice->getShippingLines()),
            $taxBreakdownService->manualTaxChargeRows($invoice->getTaxLines()),
            SalesDocumentChargeLines::fromFeeLines($invoice->getFeeLineRows()),
        );
    }

    /** One empty row for the no-JS "Add Line" submit — a product row, matching order's/quote's own
     * spare rows: shown the picker, not the free-text name box. */
    private const EMPTY_INVOICE_LINE_ROW = [
        'type' => 'product',
        'productId' => '',
        'name' => '',
        'sku' => '',
        'location' => '',
        'qty' => '1',
        'price' => '',
        'unit' => '',
        'unitId' => '',
        'taxCode' => '',
        'weight' => '',
        'cost' => '',
        // Blank, like every other box on a fresh row. A reason is only ever typed in answer to a
        // figure the screen has already stated (#326).
        'stockOverrideReason' => '',
    ];

    /**
     * The line table's columns, in order, on the create screen raising an invoice with NO order
     * behind it.
     *
     * ONE list per entry point, and the header, the colgroup, the rows and the empty row's colspan
     * are all built from it — a header and a row that disagree about columns is a table one cell out
     * of alignment, which reads as a wrong figure rather than as an error. Here rather than in the
     * template because the two entry points need two lists and the template must not be the thing
     * that decides which: it renders what it is handed. See admin/invoice/create.html.twig.
     */
    // Order's own column list, verbatim (#full-parity, 2026-09-13: "use SO template partials and
    // JS", "no reconcile"): a standalone invoice is built the same way an order is, and there is no
    // named reason left for its line table to say anything different. `nameInProductCell` (set on
    // lineRowOptions below) folds Product/Description back into one column, the same choice order
    // and quote already make, so there is no separate Description column any more. Batch is here
    // because InvoiceLine genuinely has the column (owner ruling: "then use the same code") — it was
    // only ever unexposed by this screen never offering the UI, not by the data model lacking it.
    /** @return list<array<string, string>> */
    /** Ordered/Invoiced sit next to Qty (blank on a row with no salesOrderLineId), Batch/Actions append. */
    private static function invoiceLineColumns(): array
    {
        $columns = SalesDocumentLineColumns::BASE;
        $qtyIndex = array_search('qty', array_column($columns, 'key'), true);
        array_splice($columns, $qtyIndex, 0, [
            ['key' => 'ordered', 'label' => 'Ordered'],
            ['key' => 'invoiced', 'label' => 'Invoiced'],
        ]);

        return array_merge($columns, [
            ['key' => 'batch', 'label' => 'Batch'],
            ['key' => 'actions', 'label' => 'Actions'],
        ]);
    }


    /**
     * Above this many active products the picker stops pre-rendering the catalogue and searches it
     * instead — the same cap and the same reason as the order form's (#399): a 3,454-product
     * catalogue rendered into every row's <select> is tens of megabytes of HTML.
     */
    private const INVOICE_PRODUCT_SELECT_INLINE_LIMIT = 200;

    private function blankInvoiceLineRows(): array
    {
        return [];
    }

    /** The company named by the query string, once step one is past (no order behind this invoice). */
    private function companyFromRequest(Request $request, EntityManagerInterface $entityManager): ?Company
    {
        $companyId = (int) $request->query->get('company_id', 0);
        if ($companyId <= 0) {
            return null;
        }

        $company = $entityManager->find(Company::class, $companyId);

        return $company instanceof Company ? $company : null;
    }

    /**
     * The posted line rows, in the order they were submitted, as plain strings.
     *
     * Read back as rows rather than applied straight onto lines because this screen re-renders
     * itself on Add Line and on a refusal, and it must come back with what the admin typed still in
     * the boxes. `lines[N][field]` is the order and quote forms' own vocabulary, so the shared
     * searchable-select markup posts here unchanged — and grouping a row's fields under its own
     * index is what removed the filler hidden inputs this screen used to need; see
     * AbstractAdminController::postedLineRows(), which also owns the no-JS Remove handling.
     *
     * The row keys stay camelCase because they are this screen's own re-render contract, read back
     * by `admin/invoice/create.html.twig` as `row.productId`, `row.unitId` and the rest. What changed
     * is only which posted field each is filled from.
     *
     * @return list<array<string, string>>
     */
    private function postedInvoiceLineRows(Request $request): array
    {
        $rows = [];
        foreach ($this->postedLineRows($request) as $row) {
            // `product_id_manual` is the no-JS id box the form renders inside <noscript> once the
            // catalogue outgrows the inline <select>. It keeps its own key rather than writing into
            // `product_id`, because a row may render both and the save decides between them here:
            // the picker wins when it named something, and the typed id is the way in when it did
            // not.
            $productId = $this->rawLineAmount($row['product_id'] ?? '');

            $rows[] = [
                'productId' => $productId !== '' ? $productId : $this->rawLineAmount($row['product_id_manual'] ?? ''),
                'name' => $this->rawLineAmount($row['name'] ?? ''),
                'sku' => $this->rawLineAmount($row['sku'] ?? ''),
                'location' => $this->rawLineAmount($row['location'] ?? ''),
                'qty' => $this->rawLineAmount($row['qty'] ?? ''),
                'price' => $this->rawLineAmount($row['price'] ?? ''),
                'unit' => $this->rawLineAmount($row['unit'] ?? ''),
                // The denomination this row was SAID in (#659). Distinct from `unit`, which is the
                // legacy free-text snapshot printed on the document: this one names a real
                // UnitOfMeasure row and is what the quantity and the price are converted from.
                'unitId' => $this->rawLineAmount($row['unit_id'] ?? ''),
                'taxCode' => $this->rawLineAmount($row['tax_code'] ?? ''),
                'weight' => $this->rawLineAmount($row['weight'] ?? ''),
                'cost' => $this->rawLineAmount($row['cost'] ?? ''),
                // Why this row is being billed beyond what the shelf can cover (#326). Carried on
                // the ROW rather than read separately at the point of decision, so it survives this
                // screen's re-index: a removed row is dropped above and the rest are renumbered, and
                // a reason read out of the raw post afterwards would sit one place out from the
                // quantity it belongs to. It also comes back in the box on a re-render, which is
                // what the receiving form does with its short-dated reason and for the same reason —
                // being told the figure and then handed an empty form is a worse answer than the
                // figure.
                'stockOverrideReason' => $this->rawLineAmount($row['stock_override_reason'] ?? ''),
                'salesOrderLineId' => $this->rawLineAmount($row['sales_order_line_id'] ?? ''),
                // The lot/serial picker's posted choice (2026-09-14 lot/serial/expiry plan) — the
                // same two fields OrderController::save() already reads under these exact names.
                // Dropped here before this fix: sales_line_row.html.twig has rendered
                // lines[N][lot_id]/lines[N][serial] since that plan landed, but this method never
                // forwarded either into the row applyLineRows() builds an InvoiceLine from, so
                // MandatoryCaptureGuard refused every tracked-outbound invoice line leaving Draft
                // regardless of what an admin picked on the form.
                'lotId' => $this->rawLineAmount($row['lot_id'] ?? ''),
                'serial' => $this->rawLineAmount($row['serial'] ?? ''),
            ];
        }

        return $rows !== [] ? $rows : $this->blankInvoiceLineRows();
    }

    /**
     * The raw charge rows this post carries, adjusted for whichever no-JS charge button was
     * pressed. The rules themselves — what the Add Line select's vocabulary means, how a row is
     * dropped, what counts as "the" document's shipping — are SalesDocumentChargeLines', shared
     * with the order and quote forms so the three cannot answer the same button differently.
     *
     * @return array<int|string, mixed>
     */
    private function postedInvoiceChargeRows(Request $request): array
    {
        $rows = $request->request->all('charge_lines');

        if ($request->request->has('remove_charge_line')) {
            return SalesDocumentChargeLines::withoutRemovedRow($rows, (string) $request->request->get('remove_charge_line', ''));
        }

        if (!$request->request->has('add_charge_line')) {
            return $rows;
        }

        $added = SalesDocumentChargeLines::rowFromAddLineChoice((string) $request->request->get('charge_line_type', ''));
        if ($added === null) {
            return $rows;
        }

        // Only one row can be "the" invoice's shipping, so a second named shipping choice replaces
        // the first rather than stacking beside it.
        if (SalesDocumentChargeLines::isNamedShippingRow($added)) {
            $rows = array_filter(
                $rows,
                static fn (mixed $row): bool => !is_array($row) || !SalesDocumentChargeLines::isNamedShippingRow($row),
            );
        }

        $rows[] = $added;

        return array_values($rows);
    }

    /**
     * Refuse a standalone invoice that bills TAXABLE goods with no province to tax them in.
     *
     * The sell-side twin of PurchaseOrder::assertTaxProvinceKnownIfTaxable() and the one on
     * VendorBill, and it exists for the same reason: with no province the shared calculators are
     * handed a blank, no calculator claims it, and the tax is $0 — and a $0 derived from nothing is
     * indistinguishable on the document from a genuine zero. The customer is then invoiced for less
     * than was owed and nothing anywhere says so.
     *
     * ## Why an invoice needs its own guard rather than inheriting the order's
     *
     * An order-rooted invoice inherits a province that was already settled when the order was
     * raised — OrderInvoicingService copies the order's frozen address snapshots wholesale. A
     * STANDALONE one has no order to inherit from, so it is the first sell-side document that can
     * reach the tax calculators with nothing behind it. That is precisely the case #539 left open
     * and this screen opens.
     *
     * ## Why at CREATE rather than at issue
     *
     * The purchase order guards at issue() because its province is re-derivable while it is a draft
     * — moving the warehouse re-derives it, so the admin can still fix it in place. A standalone
     * invoice is the opposite: its province comes from an address snapshot FROZEN at creation (see
     * create()'s docblock), and this screen is the only one that writes it. Correcting the
     * customer's address book afterwards does not restate a document that has already taken its
     * copy, and there is no invoice edit screen to re-take it from. So creation is the last moment
     * the refusal can still be acted on, and a guard at issue would refuse a draft whose only
     * remaining remedy was to delete it.
     *
     * Placed before the fee calculators and before the number is drawn, for the reason the stock
     * ceiling above states: the calculators flush partway through, so a refusal discovered later
     * leaves half a document written and a hole in the accounting sequence.
     *
     * ## Exempt goods are let through
     *
     * A decision, not an oversight, and the same one the purchase order took. With a highest tax
     * class of 'E' there is no rate to get wrong and the answer is $0 whatever the province says, so
     * refusing would block a legitimate document to guard a calculation that was never going to
     * run. The guard fires exactly where something is at stake.
     *
     * ## Why knownProvinceAnyCountry() and not the lenient resolver
     *
     * RegionSeedData::resolveProvinceAnyCountry() hands back the raw value uppercased when nothing
     * matches, so `'XX'` comes back as `'XX'` and a caller testing it against `''` is testing for an
     * EMPTY INPUT and nothing else — queue item 61's finding, which found two such callers that read
     * as validation while refusing nothing. knownProvinceAnyCountry() answers null for anything
     * neither country recognises, which is the question actually being asked here.
     */
    private function taxProvinceRefusal(Invoice $invoice, Company $company): ?string
    {
        if ($invoice->getHighestTaxClass() === 'E') {
            return null;
        }

        // Read off the document, not the company: what is at stake is the province this invoice was
        // TAXED on, which is its own frozen snapshot's. Asking the address book instead would pass a
        // document whose snapshot is blank because the customer had no address when it was taken.
        if (RegionSeedData::knownProvinceAnyCountry($invoice->getProvince()) !== null) {
            return null;
        }

        // WHICH remedy is asked rather than assumed — the correction VendorBill took in 3d08acd3.
        // "Record an address" sends somebody who already has one back to a screen they have fixed;
        // "it is not a province" is nonsense to somebody who has recorded none.
        $recorded = trim((string) $invoice->getShippingAddress()?->getProvince());

        return sprintf(
            'This invoice bills taxable goods but has no tax province. %s — the province is taken from the '
                . 'customer\'s shipping address and frozen onto the invoice when it is created, and saving '
                . 'without one would bill a tax figure derived from nothing.',
            $recorded !== ''
                ? sprintf(
                    '%s records its shipping province as "%s", which is not a province or state this app knows',
                    $company->getName(),
                    $recorded,
                )
                : sprintf(
                    '%s has no shipping address on file. Record one under Customers, then raise this invoice again',
                    $company->getName(),
                ),
        );
    }

    /**
     * The product ids the posted rows name, deduplicated — what the U/M cell's two lookups are
     * keyed by.
     *
     * @param list<array<string, string>> $rows
     * @return list<int>
     */
    private function productIdsFromRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['productId'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * What each named product's base unit is CALLED, keyed by product id (#659).
     *
     * The U/M select's empty option says it — "EA (base)" — because that is what NULL means in the
     * column, and an option reading "The product's base unit" where the product has actually
     * declared one tells the admin less than the code does. Read through
     * LineDenomination::baseLabel() with a null legacy string so the product's DECLARED base unit
     * answers, never a free-text snapshot: preferring the typed string over the declaration is the
     * precise defect the order and quote forms removed when they dropped their free-text U/M boxes.
     *
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function baseUnitLabelsFor(array $productIds, EntityManagerInterface $entityManager): array
    {
        $labels = [];
        foreach ($productIds as $id) {
            $product = $entityManager->find(ProductCore::class, $id);
            if ($product instanceof ProductCore) {
                $labels[$id] = LineDenomination::baseLabel(null, $product);
            }
        }

        return $labels;
    }

    /**
     * One posted row's quantity in BASE units (#659).
     *
     * The same two-step applyLineRows() performs, factored out because the stock ceiling is
     * asked BEFORE the invoice exists — see create() — and a second reading of "how much does this
     * row bill" is how the figure that was validated and the figure that was stored come to differ.
     *
     * Answers the raw string for a row naming no product: there is no ladder to convert against,
     * lineUnitFor() returns null for it, and the validator ignores productless rows anyway.
     *
     * @param array<string, string> $row
     */
    /** @param list<array<string, mixed>> $stockCheckRows product_id/qty/location/stock_override_reason */
    private function stockShortfallCheck(
        array $stockCheckRows,
        string $targetStatus,
        ?string $regionName,
        AdminOrderStockValidator $stockValidator,
        StockOverrideRecorder $stockOverrides,
        EntityManagerInterface $entityManager,
    ): array {
        $shortfalls = $stockValidator->shortfallsForInvoice($stockCheckRows, $targetStatus, $regionName, $entityManager);
        $reasons = StockOverrideReasons::fromPostedLines($stockCheckRows, 'stock_override_reason');

        return [$stockOverrides->refusalFor($shortfalls, $reasons), $shortfalls, $reasons];
    }

    private function baseQuantityForRow(array $row, EntityManagerInterface $entityManager): string
    {
        $productId = (int) ($row['productId'] ?? 0);
        $product = $productId > 0 ? $entityManager->find(ProductCore::class, $productId) : null;
        if (!$product instanceof ProductCore) {
            return (string) ($row['qty'] ?? '');
        }

        $raw = $this->rawLineAmount($row['qty'] ?? '');
        if (!is_numeric($raw)) {
            return (string) ($row['qty'] ?? '');
        }

        return LineDenomination::toBaseQuantity(
            (string) $raw,
            $this->lineUnitFor($row['unitId'] ?? null, $product, $entityManager),
            LineDenomination::baseUnitOf($product),
        );
    }

    /**
     * Turns the posted rows into invoice lines, upserted by id through
     * {@see InvoiceLineReconciler} exactly as Order and Estimate already are, and answers with the
     * subtotal they add up to.
     *
     * @param list<array<string, mixed>> $rows raw `lines[]` rows, in `postedLineRows()`'s shape —
     *     NOT `postedInvoiceLineRows()`'s camelCase remapping, which exists only for re-rendering
     *     the form and the stock-shortfall check, both untouched by this change
     */
    private function applyLineRows(
        Invoice $invoice,
        array $rows,
        EntityManagerInterface $entityManager,
        SalesDocumentLineWarnings $lineWarnings,
        InvoiceLineReconciler $lineReconciler,
    ): float {
        /** @var array<int, InvoiceLine> $existingLines */
        $existingLines = [];
        foreach ($invoice->getLines() as $existingLine) {
            $existingLines[(int) $existingLine->getId()] = $existingLine;
        }

        $reconciled = $lineReconciler->reconcile($existingLines, $rows, $invoice->getFulfillmentRegion(), $lineWarnings);

        foreach ($existingLines as $existingId => $existingLine) {
            if (!isset($reconciled['keptIds'][$existingId])) {
                $invoice->removeLine($existingLine);
            }
        }

        // Which SalesOrderLine a row was pre-filled from, if any — OverInvoicingGuard reads this at
        // issue time. Not modeled by the shared reconciler at all (Order/Estimate lines have no
        // such concept), so resolved directly here, matched the same way the reconciler itself
        // matches rows to lines: by id for an existing line, by posted order for a new one.
        [$salesOrderLineIdByExistingId, $salesOrderLineIdForNewRowsInOrder] = $this->invoiceSalesOrderLineIdsByRow($rows, $existingLines);

        $subtotal = 0.0;
        foreach ($reconciled['lines'] as $resolved) {
            $line = $resolved->existingId !== null ? $existingLines[$resolved->existingId] : new InvoiceLine();
            $lineReconciler->applyQuantity($line, $resolved);
            $line
                ->setProduct($resolved->product)
                ->setName($resolved->name)
                ->setLocation($resolved->location)
                ->setSku($resolved->sku)
                ->setWeight($resolved->weight)
                ->setUnit($resolved->unit)
                ->setTaxCode($resolved->taxCode)
                ->setCost($resolved->cost)
                // At the scale the denomination needs: a converted price gets the column's full six
                // places, because two cannot hold "$10.00 per box of 12" at all (#645/#659).
                ->setPrice($resolved->priceForColumn)
                ->setSubtotal($resolved->lineSubtotal)
                ->setSortOrder($resolved->sortOrder)
                ->setLotId($resolved->lotId)
                ->setSerial($resolved->serial);
            // No setBatch()/setRestockEta(): applyLineRows() never wrote either before this
            // conversion (InvoiceLine has no restock ETA at all, and batch is set only by
            // OrderInvoicingService at invoice creation) — omitting them here means an existing
            // line's batch now survives an edit rather than being silently wiped by the old
            // delete-and-rebuild, another fix that falls out of upserting rather than rebuilding.

            $salesOrderLineId = $resolved->existingId !== null
                ? (int) ($salesOrderLineIdByExistingId[$resolved->existingId] ?? 0)
                : (int) (array_shift($salesOrderLineIdForNewRowsInOrder) ?? 0);
            if ($salesOrderLineId > 0) {
                $orderLine = $entityManager->find(SalesOrderLine::class, $salesOrderLineId);
                if ($orderLine instanceof SalesOrderLine) {
                    $line->setSalesOrderLine($orderLine);
                }
            }

            $invoice->addLine($line);
            $subtotal = SalesDocumentMoney::intermediate($subtotal + (float) $resolved->lineSubtotal);
        }

        return $subtotal;
    }

    /**
     * `sales_order_line_id` per row, matched the same way {@see InvoiceLineReconciler} itself
     * matches rows to lines — a blank row (no product, no name) is skipped identically, so the
     * "new rows" queue stays in the same relative order {@see SellSideLineReconciler::reconcile()}
     * produces its own new resolved lines in.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, InvoiceLine> $existingLines keyed by id — an id this map does not
     *     recognize (a tampered or foreign one) counts as a NEW row, exactly as
     *     {@see SellSideLineReconciler::reconcile()} treats it
     *
     * @return array{0: array<int, string>, 1: list<string>}
     */
    private function invoiceSalesOrderLineIdsByRow(array $rows, array $existingLines): array
    {
        $byExistingId = [];
        $forNewRowsInOrder = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $blank = $this->rawLineAmount($row['product_id'] ?? null) === ''
                && $this->rawLineAmount($row['product_id_manual'] ?? null) === ''
                && $this->rawLineAmount($row['name'] ?? null) === '';
            if ($blank) {
                continue;
            }

            $solId = $this->rawLineAmount($row['sales_order_line_id'] ?? null);
            $existingId = (int) ($row['id'] ?? 0);
            if ($existingId > 0 && isset($existingLines[$existingId])) {
                $byExistingId[$existingId] = $solId;
            } else {
                $forNewRowsInOrder[] = $solId;
            }
        }

        return [$byExistingId, $forNewRowsInOrder];
    }

    /**
     * A named shipping row's amount is recomputed from the resolver on every save rather than
     * trusted from the browser — the same rule EstimateController::recalculateShippingCharges() and
     * OrderController::recalculateShippingCharge() apply. "Custom Shipping" and empty shipping rows
     * do not match the "Shipping (Method Name)" format and are left exactly as typed.
     *
     * @param list<array<string, mixed>> $charges
     * @return list<array<string, mixed>>
     */
    private function repricedShippingCharges(array $charges, ShippingResolver $shippingResolver, Invoice $invoice): array
    {
        $options = null;
        foreach ($charges as $i => $charge) {
            if (($charge['type'] ?? '') !== FeeLine::TYPE_SHIPPING
                || !preg_match('/^Shipping \((.+)\)$/', (string) ($charge['label'] ?? ''), $matches)
            ) {
                continue;
            }

            $options ??= $this->invoiceShippingOptions($shippingResolver, $invoice);
            foreach ($options as $option) {
                if ($option['label'] === $matches[1]) {
                    $charges[$i]['amount'] = (float) $option['amount'];
                    break;
                }
            }
        }

        return $charges;
    }

    /**
     * The carrier options the charge bar offers, resolved against this invoice's own lines and
     * shipping address. On the empty create page a transient invoice carrying only the company's
     * default shipping address stands in, the way the order and quote create pages do it.
     *
     * @return list<array{id: mixed, label: string, amount: float, deliveryDays: mixed, taxClass: mixed}>
     */
    private function invoiceShippingOptions(ShippingResolver $resolver, Invoice $invoice): array
    {
        return array_map(
            static fn ($option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'amount' => $option->amount,
                'deliveryDays' => $option->deliveryDays,
                'taxClass' => $option->taxClass,
            ],
            $resolver->getAvailableOptions($invoice),
        );
    }

    /**
     * A calendar date as typed, or null. Parsed defensively for the reason receivedAt() is: an
     * unparseable string is a mistyped box, and a 500 is not an answer to one.
     */
    private function calendarDate(mixed $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /** Money as the columns store it: two decimals, point separator, no thousands mark. */
    private function decimalAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Everything the create screen renders, in one place, so the six ways it can be re-rendered
     * (first paint, company chosen, Add Line, a charge button, a refusal, an empty line set) cannot
     * drift apart in what they hand the template.
     *
     * ## Why $request is required here, and optional everywhere else in the feature
     *
     * This is the ONLY re-render funnel in the custom fields feature that can lose what an admin
     * typed. Every other screen carrying these fields answers a refusal with a REDIRECT, and a
     * redirect re-reads the stored values — but this is an ADD screen, so there is nothing stored to
     * fall back on. Hand CustomFieldRenderer::renderFields() no request and a refusal over an
     * unrelated field (a charge type this app has no line for, a shortfall with no reason against
     * it, an empty line set, a taxable line with nowhere to tax it) repaints every custom field box
     * EMPTY, and everything typed into them is gone with no error naming them. That is the defect
     * this parameter exists to close, which is why it is a required parameter rather than an
     * optional one: a seventh re-render path added later cannot forget it and still compile.
     *
     * Passing it on the first paint costs nothing. A GET carries no `custom_field` in its request
     * bag, so renderFields() falls straight through to its stored-or-empty default — which on an add
     * screen is empty, exactly as before.
     *
     * @param list<array<string, string>> $lineRows
     * @param list<array<string, mixed>>  $chargeRows
     */
    private function renderInvoiceForm(
        Request $request,
        EntityManagerInterface $entityManager,
        ShippingResolver $shippingResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        CustomFieldRenderer $customFieldRenderer,
        OrderTaxBreakdownService $taxBreakdownService,
        ?Company $company,
        array $lineRows,
        array $chargeRows,
        ?string $error,
        int $status = Response::HTTP_OK,
        // Set only by edit(), for an invoice that already exists — same fields, same lines, same
        // save, just already numbered.
        ?Invoice $existing = null,
        // Set only on a fresh create with an order behind it. NOT set by edit(), even for an
        // order-linked invoice — see this method's chargeTemplate selection.
        ?SalesOrder $order = null,
    ): Response {
        // Computed once, up front, rather than at each of chargeContext's two reads below — the
        // breakdown is read-only against whatever the invoice's OWN stored lines/fee-lines already
        // are, so it is stable across both reads within one render.
        $existingTaxBreakdown = $existing instanceof Invoice ? $taxBreakdownService->breakdownForOrder($existing) : ['lines' => [], 'total' => 0.0];

        $productsRemote = $entityManager->getRepository(ProductCore::class)->count(['deleted' => false])
            > self::INVOICE_PRODUCT_SELECT_INLINE_LIMIT;

        $regions = $company instanceof Company
            ? array_map(
                static fn (CompanyFulfillmentRegion $row): string => (string) $row->getFulfillmentRegion()?->getName(),
                $companyFulfillmentRegionService->activeRowsForCompany($company),
            )
            : [];

        // The shipping options need a document to price against; nothing persists this one.
        $draft = null;
        if ($company instanceof Company) {
            $draft = new Invoice();
            $draft->setCompany($company);
            $draft->setShippingAddressFrom($this->defaultAddress($company, 'shipping'));
        }

        if ($error !== null) {
            $this->addFlash('error', $error);
        }

        // The header's buttons. Only reachable once a customer is chosen: "Change customer" reopens
        // step one and "List invoices" filters the list by the name on screen, and neither means
        // anything before there is a name. Editing an existing invoice has a company already, but
        // not a CHANGEABLE one — see this method's docblock on `$existing` — so its buttons point
        // at the invoice rather than at re-choosing who it bills.
        if ($existing instanceof Invoice) {
            $pageActions = [
                ['label' => 'Back to invoice', 'href' => $this->generateUrl('admin_invoice_detail', ['id' => $existing->getId()]), 'class' => 'button back-button'],
                // The stopgap this screen was always meant to replace — see admin_invoice_link's
                // own docblock, which names this page by the issue that asked for it.
                ['label' => 'Relate to a sales order', 'href' => $this->generateUrl('admin_invoice_link', ['id' => $existing->getId()]), 'class' => 'button outline'],
            ];
        } elseif ($order instanceof SalesOrder) {
            $pageActions = [['label' => 'Back to order', 'href' => $this->generateUrl('admin_order_detail', ['id' => $order->getId()]), 'class' => 'button back-button']];
        } else {
            $pageActions = [['label' => 'Back to invoices', 'href' => $this->generateUrl('admin_invoice_index'), 'class' => 'button back-button']];
            if ($company instanceof Company) {
                $pageActions[] = ['label' => 'Change customer', 'href' => $this->generateUrl('admin_invoice_create'), 'class' => 'button outline'];
                $pageActions[] = ['label' => 'List invoices', 'href' => $this->generateUrl('admin_invoice_index', ['filters' => ['company' => $company->getName()]]), 'class' => 'button outline'];
            }
        }

        return $this->render('admin/invoice/create.html.twig', [
            // A refused save repaints what was typed: the shared line row reads these instead of
            // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
            // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
            // LineDenomination::boxUntouched(), so reformatting one here would let the next round
            // trip rewrite a stored figure.
            'submitted' => $this->submittedLinesForRerender($request),
            'pageTitle' => match (true) {
                $existing instanceof Invoice => sprintf('Edit Invoice %s | Admin', $existing->getDocumentNumber()),
                $order instanceof SalesOrder => sprintf('Convert to Invoice: %s | Admin', $order->getOrderNumber()),
                default => 'Create Invoice | Admin',
            },
            'eyebrow' => match (true) {
                $existing instanceof Invoice => 'Edit invoice',
                $order instanceof SalesOrder => 'Convert to Invoice',
                default => 'New invoice',
            },
            'heading' => match (true) {
                $existing instanceof Invoice => (string) $existing->getDocumentNumber(),
                $order instanceof SalesOrder => (string) $order->getOrderNumber(),
                default => 'Create Invoice',
            },
            // A Draft can still be issued from here — the same button a fresh invoice uses. Anything
            // past Draft is already issued, so there is nothing left to issue; canIssue below is
            // what actually gates the button, this is only the words beside it.
            'canIssue' => !$existing instanceof Invoice || $existing->isDraft(),
            'lead' => match (true) {
                $existing instanceof Invoice && $existing->isDraft() => 'Still a Draft, so every line, charge and field here can change until it is issued. '
                    . 'Issuing takes the same button a fresh invoice uses.',
                $existing instanceof Invoice => sprintf('%s. Every line, charge and field here can still change.', $existing->getStatus()),
                $company instanceof Company => sprintf('Bill %s for this invoice.', $company->getName()),
                default => 'Choose a customer first, so the invoice is billed to the right party and priced from their price list.',
            },
            'pageActions' => $pageActions,
            'formAction' => $existing instanceof Invoice
                ? $this->generateUrl('admin_invoice_edit', ['id' => $existing->getId()])
                : $this->generateUrl('admin_invoice_create'),
            'hiddenFields' => match (true) {
                $existing instanceof Invoice => [],
                $order instanceof SalesOrder => ['order_id' => (string) $order->getId()],
                $company instanceof Company => ['company_id' => (string) $company->getId()],
                default => [],
            },
            'workspaceBack' => match (true) {
                $existing instanceof Invoice => ['label' => 'Back to invoice', 'href' => $this->generateUrl('admin_invoice_detail', ['id' => $existing->getId()])],
                $order instanceof SalesOrder => ['label' => 'Back to order', 'href' => $this->generateUrl('admin_order_detail', ['id' => $order->getId()])],
                default => ['label' => 'Back', 'href' => $this->generateUrl('admin_invoice_index')],
            },
            // A separate template PATH for edit, not an `{% if %}` in the create one: a screen
            // showing the invoice's OWN stored values, from its OWN frozen addresses, is a different
            // set of fields to display, not a flag on the same ones. The create template serves both
            // the blank and order-linked cases — `order` pre-fills what it shows, nothing more.
            'contextTemplate' => $existing instanceof Invoice
                ? 'admin/invoice/create/_standalone_context_edit.html.twig'
                : 'admin/invoice/create/_standalone_context.html.twig',
            'contextContext' => $existing instanceof Invoice
                ? [
                    'invoice' => $existing,
                    'company' => $company,
                    // edit() no longer refuses an order-linked invoice (#full-parity, 2026-09-13),
                    // so the edit screen needs the same "which order does this bill" link the
                    // create screen shows — read off the invoice's OWN link, not $order's presence
                    // on the request, since a GET here carries no order_id at all.
                    'order' => $existing->getSalesOrder(),
                    'fulfillmentRegions' => array_values(array_filter($regions, static fn (string $name): bool => $name !== '')),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company),
                ]
                : [
                    'company' => $company,
                    'order' => $order,
                    'billingAddress' => $company instanceof Company ? $this->addressToRow($order?->getBillingAddress() ?? $this->defaultAddress($company, 'billing'), $company) : null,
                    'shippingAddress' => $company instanceof Company ? $this->addressToRow($order?->getShippingAddress() ?? $this->defaultAddress($company, 'shipping'), $company) : null,
                    'addressBook' => $company instanceof Company ? $this->addressBookRows($company) : [],
                    'fulfillmentRegions' => array_values(array_filter($regions, static fn (string $name): bool => $name !== '')),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company),
                    'invoiceDate' => $order?->getDocumentDate(),
                    'poNumber' => $order?->getPoNumber(),
                    'paymentMethod' => $order?->getPaymentMethod(),
                    'paymentTerm' => $order?->getPaymentTerm(),
                    'fulfillmentRegion' => $order?->getFulfillmentRegion(),
                    'specialInstructions' => $order?->getSpecialInstructions(),
                ],
            'linesHeading' => 'Line Items',
            'linesNote' => null,
            'lineColumns' => self::invoiceLineColumns(),
            // The rest of sales_line_row.html.twig's parameter set. `kind` is not here: it is per
            // ROW and the template infers it from whether a product is picked.
            //
            // `hook: 'invoice'` (#full-parity, 2026-09-13: "use SO template partials and JS"): this
            // screen now has the same add/remove-line, add-charge-row and live-recalc JavaScript
            // order's and quote's do, off app.js's shared SellDoc* functions — see
            // admin/invoice/create.html.twig's own page_end/lines_toolbar blocks. `nameInProductCell`
            // is true for the same reason order's is: the picker and the free-text name are now two
            // states of ONE column (Add Product vs Add Blank Line), not a picker beside its own
            // No `qtyStep`/`costType`/`priceType`/`pricePlaceholder`/`taxCodeBlankLabel` overrides:
            // those were this screen's own leftover wording/typing from before #full-parity
            // (2026-09-13) with no matching reason in order — order's qty steps by whole units, its
            // cost/price boxes are real number inputs, and a blank tax code already falls back to
            // the product's own silently, the same way it does here, without a label saying so.
            'lineRowOptions' => [
                'lineClass' => 'invoice-line-row',
                'actionsTemplate' => 'admin/invoice/_line_actions.html.twig',
                'controlClass' => 'form-control',
                'labelled' => true,
                'hook' => 'invoice',
                'nameInProductCell' => true,
                'namePlaceholder' => 'Custom line name',
                'productSelectClass' => 'invoice-line-product-select',
                'productsRemote' => $productsRemote,
                'productSearchPath' => $this->generateUrl('admin_order_product_search'),
                // The save measures what it bills against the shelf and refuses only while a
                // shortfall has no reason against it — the box posts `lines[N][stock_override_reason]`.
                'showsStockOverride' => true,
                // Same value the context panel's own "Fulfillment Region" field shows above
                // ($existing->getFulfillmentRegion() once it exists, $order's region while it does
                // not yet) — a blank line's location box defaults to it, same as applyLineRows()
                // does server-side.
                'documentFulfillmentRegion' => $existing instanceof Invoice
                    ? $existing->getFulfillmentRegion()
                    : $order?->getFulfillmentRegion(),
            ],
            'addLineLabel' => 'Add another line',
            'chargeTemplate' => $order instanceof SalesOrder && !$existing instanceof Invoice
                ? 'admin/invoice/create/_order_charges.html.twig'
                : 'admin/invoice/create/_standalone_charges.html.twig',
            // Also top-level, for the shared sales_lines_toolbar.html.twig include (#full-parity,
            // 2026-09-13), which reads it the same way order's and quote's own templates do.
            'shippingOptions' => $draft instanceof Invoice ? $this->invoiceShippingOptions($shippingResolver, $draft) : [],
            // Real figures once the invoice actually exists — the same OrderTaxBreakdownService
            // call documentContext() already uses for the printed document, so the totals box and
            // the PDF cannot disagree. A brand-new invoice (no $existing yet) has nothing to break
            // down, so the totals footer falls back to its own $0.00 starting state instead.
            'chargeContext' => [
                'chargeRows' => $chargeRows,
                'shippingOptions' => $draft instanceof Invoice ? $this->invoiceShippingOptions($shippingResolver, $draft) : [],
                'existing' => $existing,
                'taxLines' => $existing instanceof Invoice ? $existingTaxBreakdown['lines'] : [],
                'taxLinesTotal' => $existing instanceof Invoice ? $existingTaxBreakdown['total'] : 0.0,
            ],
            'footerNote' => $existing instanceof Invoice
                ? null
                : 'Tax is computed when the invoice is saved, from this invoice\'s own lines and charge rows — the same calculators that tax an order-derived invoice.',
            'company' => $company,
            'companies' => $company instanceof Company
                ? []
                : $entityManager->getRepository(Company::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
            'products' => $this->invoiceProductRows($entityManager, $companyFulfillmentRegionService, $company, $productsRemote, $lineRows),
            'locations' => $this->invoiceLocationRows($entityManager),
            'lineRows' => $lineRows,
            // The U/M cell's vocabulary (#659), keyed by product id: what each named product may be
            // denominated in, and what its base unit is called. Resolved server-side in one query
            // for the whole document rather than one per row, and empty for a row naming no product
            // — nothing declares the unit of a line with no product behind it.
            'lineUnits' => $this->lineUnitChoicesFor($this->productIdsFromRows($lineRows), $entityManager),
            'baseUnitLabels' => $this->baseUnitLabelsFor($this->productIdsFromRows($lineRows), $entityManager),
            // The admin-defined fields. Handed the REQUEST, which is the whole point of this call —
            // see this method's docblock. `null` for the entity on a create, because no invoice
            // exists yet; `$existing` on an edit, so a field already answered shows its answer
            // rather than its blank default. CONTEXT_ADD/CONTEXT_EDIT is the same distinction a
            // field definition already makes for every other document.
            'customFieldFragment' => $customFieldRenderer->renderFields(
                CustomFieldDefinition::OBJECT_TYPE_INVOICE,
                $existing,
                $existing instanceof Invoice ? CustomFieldRenderer::CONTEXT_EDIT : CustomFieldRenderer::CONTEXT_ADD,
                $request,
            ),
            'invoiceError' => $error,
        ], new Response('', $status));
    }

    /**
     * The products the picker offers, priced the way every other sales screen prices them: the
     * company's price list for its region first, then the product's default, then its original.
     *
     * Past the inline limit only the products already named on a row are rendered, and the picker
     * fetches the rest as the admin types — through the ORDER form's search endpoint, deliberately.
     * That endpoint takes a term, a company and a region and answers with catalogue rows; nothing
     * about it is order-shaped, and a third copy of the same search is three places for the price
     * precedence to drift.
     *
     * @param list<array<string, string>> $lineRows
     * @return list<array<string, string>>
     */
    private function invoiceProductRows(
        EntityManagerInterface $entityManager,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        ?Company $company,
        bool $productsRemote,
        array $lineRows,
    ): array {
        $criteria = ['deleted' => false];
        if ($productsRemote) {
            $ids = [];
            foreach ($lineRows as $row) {
                $id = (int) ($row['productId'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }

            if ($ids === []) {
                return [];
            }

            $criteria['id'] = array_values($ids);
        }

        $products = $entityManager->getRepository(ProductCore::class)->findBy($criteria, ['name' => 'ASC']);
        $priceList = $company instanceof Company
            ? $companyFulfillmentRegionService->priceListForCompanyRegion($company, null)
            : null;

        $pricingByProductId = [];
        if ($priceList instanceof PriceList && $products !== []) {
            // One query, not one per product — the same batching the order form's rows use.
            foreach ($entityManager->getRepository(ProductPricing::class)->createQueryBuilder('pr')
                ->select('pr AS pricing', 'IDENTITY(pr.product) AS productId')
                ->where('pr.priceList = :list')
                ->andWhere('pr.product IN (:products)')
                ->setParameter('list', $priceList)
                ->setParameter('products', $products)
                ->getQuery()
                ->getResult() as $pricingRow
            ) {
                $pricing = $pricingRow['pricing'] ?? null;
                if ($pricing instanceof ProductPricing) {
                    $pricingByProductId[(int) $pricingRow['productId']] = $pricing;
                }
            }
        }

        $rows = [];
        foreach ($products as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $pricing = $pricingByProductId[(int) $product->getId()] ?? null;
            $price = $pricing instanceof ProductPricing ? $pricing->getPrice() : null;
            if ($price === null || $price === '') {
                $price = $product->getDefaultPrice();
            }
            if ($price === null || $price === '') {
                $price = $product->getOriginalPrice();
            }

            $rows[] = [
                'id' => (string) $product->getId(),
                'name' => $product->getName(),
                'sku' => (string) $product->getSku(),
                'weight' => (string) ($product->getWeight() ?? ''),
                'unit' => (string) ($product->getUnit() ?? ''),
                'taxCode' => TaxContext::mapTaxCode($product->getSalesTaxCode()),
                'cost' => (string) ($product->getCostPrice() ?? ''),
                'originalPrice' => (string) ($product->getOriginalPrice() ?? ''),
                'price' => (string) ($price ?? ''),
            ];
        }

        return $rows;
    }

    /** Every active fulfillment region, for the per-line Location picker. @return list<string> */
    private function invoiceLocationRows(EntityManagerInterface $entityManager): array
    {
        $regions = array_map(
            static fn (FulfillmentRegion $region): string => $region->getName(),
            $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        );

        return $regions !== [] ? $regions : ['Main'];
    }

    /**
     * Relate an existing invoice to an existing sales order — the issue's "In Edit Invoice page,
     * there should be a way to relate an invoice to an existing Sales Order" (#539 stage 5).
     *
     * A GET renders the picker, and re-renders it with the match report as soon as an order is
     * chosen, so the mismatches are on screen BEFORE anything is pressed. That is what "show clearly
     * what is not matching so user can correct" asks for: a refusal after the fact tells the admin
     * they were wrong, a report tells them what to fix.
     *
     * The rule itself is InvoiceOrderMatcher's, not this controller's, so a scripted POST that skips
     * this screen is refused by exactly the rule the screen rendered.
     */
    #[Route('/invoice/{id}/link', name: 'admin_invoice_link', methods: ['GET'])]
    public function linkToOrder(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        InvoiceOrderMatcher $matcher,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_order_index');
        }

        $candidate = $this->candidateOrder($request, $entityManager);

        return $this->render('admin/invoice/link.html.twig', [
            'invoice' => $invoice,
            'orders' => $this->linkableOrders($invoice, $entityManager),
            'candidate' => $candidate,
            'report' => $candidate instanceof SalesOrder ? $matcher->match($invoice, $candidate) : null,
        ]);
    }

    #[Route('/invoice/{id}/link', name: 'admin_invoice_link_save', methods: ['POST'])]
    public function saveLinkToOrder(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        InvoiceOrderMatcher $matcher,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_order_index');
        }

        $order = $entityManager->find(SalesOrder::class, (int) $request->request->get('order_id', 0));
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Choose the sales order this invoice bills.');

            return $this->redirectToRoute('admin_invoice_link', ['id' => $id]);
        }

        $match = $matcher->match($invoice, $order);
        if (!$match->isLinkable()) {
            // Each blocker is its own flash rather than one joined paragraph: they are independent
            // problems and each one names a different SKU to go and fix.
            foreach ($match->blockerMessages() as $message) {
                $this->addFlash('error', $message);
            }

            return $this->redirectToRoute('admin_invoice_link', ['id' => $id, 'order_id' => $order->getId()]);
        }

        try {
            // BOTH documents. Linking writes sales_order_id onto the invoice and attributions onto
            // its lines, and it re-derives the ORDER's status from its new invoice set — so a frozen
            // order cannot be quietly moved to Partially Invoiced by a write to something else.
            $locks->assertWritable($invoice, 'changed');
            $locks->assertWritable($order, 'changed');

            $invoice->linkToOrder($actorResolver->resolve(), $order, $match->attributions);
            $entityManager->flush();
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_invoice_link', ['id' => $id, 'order_id' => $order->getId()]);
        }

        foreach ($match->notices() as $notice) {
            $this->addFlash('info', $notice->message);
        }

        $this->addFlash('success', sprintf(
            'Invoice %s is now linked to order %s.',
            $invoice->getDocumentNumber(),
            $order->getOrderNumber(),
        ));

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    #[Route('/invoice/{id}/unlink', name: 'admin_invoice_unlink', methods: ['POST'])]
    public function unlinkFromOrder(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_order_index');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        try {
            // Same pair as the link above, in reverse: unlinking clears the invoice's order column
            // and takes this invoice back out of the order's invoiced totals.
            $locks->assertWritable($invoice, 'changed');
            $linkedOrder = $invoice->getSalesOrder();
            if ($linkedOrder instanceof SalesOrder) {
                $locks->assertWritable($linkedOrder, 'changed');
            }

            $invoice->unlinkFromOrder($actorResolver->resolve(), $reason !== '' ? $reason : null);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Invoice %s is no longer linked to an order.', $invoice->getDocumentNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /**
     * Every invoice transition, behind one endpoint.
     *
     * One route rather than six because the difference between them is entirely which named action
     * is called — there is no per-transition validation to write here, since each action validates
     * its own from-state and throws, and each writes its own timeline entry.
     */
    #[Route('/invoice/{id}/action/{action}', name: 'admin_invoice_action', methods: ['POST'])]
    public function performAction(
        int $id,
        string $action,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $actor = $actorResolver->resolve();
        $reason = trim((string) $request->request->get('reason', ''));

        try {
            // Every one of the six transitions writes the invoice's status column and its timeline,
            // so every one of them is a write to a document a lock freezes. Inside the existing try
            // on purpose: DocumentLocked IS a DomainException, so the catch below already flashes
            // the exact sentence and returns to this invoice's own page — which is where the Unlock
            // control the sentence names lives. Nothing new had to be built for that.
            $locks->assertWritable($invoice, $action === 'cancel' ? 'cancelled' : 'changed');

            match ($action) {
                'issue' => $invoice->issue($actor),
                'issue-awaiting-payment' => $invoice->issueAwaitingPayment($actor),
                'payment-received' => $invoice->paymentReceived($actor),
                'start-processing' => $invoice->startProcessing($actor),
                'complete' => $invoice->setStatus('Completed', $actor),
                'cancel' => $invoice->setStatus(
                    'Cancelled',
                    $actor,
                    $reason !== '' ? sprintf('Invoice cancelled: %s', $reason) : null,
                ),
                default => throw new \DomainException(sprintf('%s is not an invoice action.', $action)),
            };

            $entityManager->flush();
            $this->addFlash('success', sprintf('Invoice %s is now %s.', $invoice->getDocumentNumber(), $invoice->getStatus()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_detail', ['id' => $invoice->getId()]);
    }

    /**
     * The invoice document — on screen, and as a PDF with ?download=1.
     *
     * This is OrderController::invoice() moved. It renders admin/invoice/invoice.html.twig, which is
     * the same template that shipped at admin/order/invoice.html.twig, pointed at the entity the
     * document was always describing.
     *
     * The addresses come off the INVOICE's own snapshots, not off the company's current defaults.
     * The order side used to walk `company.addresses` looking for a default billing/shipping row,
     * which meant a document reprinted after the customer moved showed an address the goods never
     * went to. AbstractSalesDocument::getEffectiveBillingAddress() answers from what this document
     * recorded, falling back to the company only when it recorded nothing.
     */
    #[Route('/invoice/print/{id}', name: 'admin_invoice_print', methods: ['GET'])]
    public function document(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        OrderTaxBreakdownService $taxBreakdownService,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $context = $this->documentContext($invoice, $taxBreakdownService);

        if ($request->query->get('download') === '1') {
            return $this->pdf(
                $this->renderView('admin/invoice/invoice.html.twig', $context + ['is_pdf' => true]),
                sprintf('Invoice-%s.pdf', $invoice->getDocumentNumber()),
            );
        }

        return $this->render('admin/invoice/invoice.html.twig', $context);
    }

    /**
     * The packing slip, which is the invoice's and not the order's (#539 stage 6).
     *
     * An order billed across three invoices ships across three deliveries, so a slip listing the
     * whole order would be right at most once. It mirrors the invoice's product list without the
     * money, which is the whole difference between the two documents.
     */
    #[Route('/invoice/packing-slip/{id}', name: 'admin_invoice_packing_slip', methods: ['GET'])]
    public function packingSlip(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $isPdf = $request->query->get('pdf') === '1';
        $html = $this->renderView('admin/invoice/packing_slip.html.twig', [
            'invoice' => $invoice,
            'is_pdf' => $isPdf,
        ]);

        return $isPdf
            ? $this->pdf($html, sprintf('PackingSlip-%s.pdf', $invoice->getDocumentNumber()))
            : new Response($html);
    }

    /**
     * Email the invoice, with its PDF attached — OrderController::sendInvoice() moved.
     *
     * The attachment is the INVOICE document rather than the order printed as one, which was the
     * whole point of the move. The `invoice_customer`/`invoice_self` bodies used to be written
     * against an `order` variable only — fine while every invoice had one, broken the moment
     * /admin/invoice/create started raising invoices with no order on purpose. Sending one of those
     * used to be refused outright ("Download the invoice and attach it") rather than let Twig crash
     * on a null `order`.
     *
     * Both bodies (the shipped .twig files under templates/emails/shipped/, and any admin-edited
     * `email_template` row — see EmailTemplateResolver) now read the invoice's own fields first,
     * with `order` supplied as optional enhancement (Version20260919180000 carried the same edit
     * into the migration-seeded row; ShippedEmailTemplatesMatchTheChainTest is what proves the two
     * agree). So `$order` below is simply whatever the invoice has, never a reason to refuse —
     * AdminStandaloneInvoiceCest proves a standalone invoice actually sends, not that it is turned
     * away.
     */
    #[Route('/invoice/send/{id}', name: 'admin_invoice_send', methods: ['POST'])]
    public function send(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        CustomerUrlGenerator $customerUrlGenerator,
        AdminUrlGenerator $adminUrlGenerator,
        OrderTaxBreakdownService $taxBreakdownService,
        AppSettings $appSettings,
        DocumentActorResolver $actorResolver,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        // Optional, not required: an invoice exists and can be sent whether or not it bills an
        // order. $order rides into $emailVars below as optional data, the same as every other
        // nullable field the shipped bodies already read defensively.
        $order = $invoice->getSalesOrder();

        $type = $request->request->get('type', 'customer');
        if ($type === 'self') {
            $user = $this->getUser();
            $recipient = $user instanceof AdminUser ? $user->getEmail() : null;
        } else {
            $recipient = $invoice->getCompany()->getPrimaryEmail();
        }

        if (!$recipient) {
            $this->addFlash('error', sprintf('Recipient email address not found for type: %s.', $type));

            return $this->redirectToRoute('admin_invoice_print', ['id' => $id]);
        }

        // Both of these ship, so they resolve whether or not the database holds a row for them —
        // which is what #507 changed. The guard stays because a resolver miss would still mean a
        // typo here, but on a fresh install this used to be a hard stop with a flashed error.
        $templateCode = $type === 'self' ? 'invoice_self' : 'invoice_customer';

        try {
            $pdf = $this->dompdf($this->renderView(
                'admin/invoice/invoice.html.twig',
                $this->documentContext($invoice, $taxBreakdownService) + ['is_pdf' => true],
            ));

            $sendingUser = $this->getUser();
            $emailVars = [
                'invoice' => $invoice,
                'order' => $order,
                'generatedBy' => $sendingUser instanceof AdminUser
                    ? $this->fullName($sendingUser->getFirstName(), $sendingUser->getLastName(), $sendingUser->getEmail())
                    : null,
            ];

            // The two copies get different link variables, not one shared pair (#351). Both bodies
            // are admin-editable, and while both variables were in scope for both the customer copy
            // could resolve admin_url — a /admin/... link in a buyer's inbox, on a host they have no
            // account on.
            //
            // Both are absolute and anchored to their own host: generateUrl()'s default is a bare
            // path, and a path in an email body is a link that goes nowhere once it leaves the app
            // (#223); CustomerUrlGenerator/AdminUrlGenerator pin CUSTOMER_HOST/ADMIN_HOST rather
            // than inheriting the admin host this request happens to be on.
            if ($type === 'self') {
                $emailVars['admin_url'] = $adminUrlGenerator->generate('admin_invoice_print', ['id' => $invoice->getId()]);
            } elseif ($order !== null) {
                // The buyer's own view of this document is the order page in the portal, which since
                // stage 6 lists every invoice on the order with its own download link beside the
                // sales order's. A standalone invoice has no such page — there is no customer-facing
                // invoice detail screen — so order_url is simply absent, and the template's own
                // `order_url is defined` check drops the button rather than link to nothing.
                $emailVars['order_url'] = $customerUrlGenerator->generate('customer_order_detail', ['id' => $order->getId()]);
            }

            $rendered = $emailTemplates->render($templateCode, $emailVars);

            if ($rendered === null) {
                $this->addFlash('error', sprintf('Email template "%s" not found.', $templateCode));

                return $this->redirectToRoute('admin_invoice_print', ['id' => $id]);
            }

            $email = $appSettings->applyFromAddress(new Email(), AppSettings::FROM_SALES)
                ->to($recipient)
                ->subject($rendered->subject)
                ->html($rendered->body)
                ->attach($pdf, sprintf('Invoice-%s.pdf', $invoice->getDocumentNumber()), 'application/pdf');

            $mailer->send($email);

            // On the invoice's own timeline, written by the invoice, so "we sent this to the
            // customer on the 3rd" is recorded where the document is read (#539 stage 2's rule).
            $invoice->recordSent($actorResolver->resolve(), $recipient, $rendered->subject, $type === 'customer');
            $entityManager->flush();

            $this->addFlash('success', sprintf('Invoice sent successfully to %s.', $recipient));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send email: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_print', ['id' => $id]);
    }

    /**
     * The invoice's payments: what has been received, what is still owed, and the form that records
     * more.
     *
     * This screen was the order's until #539 stage 4. It moved with the money: an order billed
     * across three invoices is paid across three invoices, and a payment recorded against the order
     * could not say which of them it cleared.
     *
     * Nothing here writes a payment status. Recording and amending go through Invoice's named
     * actions, which attach the row and write the timeline entry, and the status is derived from the
     * rows at flush by InvoicePaymentStatusSubscriber.
     *
     * A DRAFT invoice does not get this screen at all (#31). It renders, because a person who
     * followed a bookmark needs to be told the invoice has not been issued yet rather than shown a
     * 404 or an empty grid — but it offers no form, and the POST below is refused whether or not the
     * form was ever rendered. See InvoiceStatus::acceptsPayment().
     */
    #[Route('/invoice/{id}/payments', name: 'admin_invoice_payments', methods: ['GET', 'POST'])]
    public function payments(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
        InvoicePaymentMover $paymentMover,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        if ($request->isMethod('POST')) {
            // A payment is money recorded ON the invoice: it writes an invoice_payment row and moves
            // payment_status on the header, and both are what the document IS rather than what
            // happened to it. So a frozen invoice takes none, and neither does it amend one already
            // recorded — this endpoint does both. The GET is deliberately NOT refused: reading the
            // payment history of a locked invoice is exactly the kind of look somebody locks it for.
            $locks->assertWritable($invoice, 'paid');

            // Refused here as well as in Invoice::recordPayment(), and not as belt-and-braces: this
            // endpoint also AMENDS an existing row, which goes through a different named action, and
            // "a draft takes no payments" has to hold for every door into the screen.
            if (!$invoice->acceptsPayment()) {
                $this->addFlash('error', $this->noPaymentsReason($invoice));

                return $this->redirectToRoute('admin_invoice_payments', ['id' => $invoice->getId()]);
            }

            return $this->savePayment($invoice, $request, $entityManager, $actorResolver);
        }

        // Which row the form is editing, named in the URL rather than by a script — the same
        // `?payment=` the bill payments screen has carried since queue item 51's second pass, and
        // the same reason: this screen's old inline-JS edit-in-place left no way to correct a
        // payment with scripting off.
        $requestedPayment = $this->requestedPayment($request, $invoice);
        $editing = $requestedPayment->entity();

        return $this->render('admin/invoice/payments.html.twig', [
            'invoice' => $invoice,
            'editing' => $editing,
            'badParents' => $this->unresolvedParents($requestedPayment),
            'methods' => ['Bank Transfer', 'Check', 'Credit Card', 'Cash', 'E-Transfer'],
            // The dropdown queue item 34 is, from the user's side. Empty is a real answer and the
            // screen says so in words rather than rendering a select with nothing in it.
            'moveTargets' => $paymentMover->candidatesFor($invoice),
        ]);
    }

    /**
     * The payment claim `?payment=` names, looked for among THIS invoice's rows — the sell-side
     * mirror of `VendorBillController::requestedPayment()`.
     *
     * @return RequestedParent<InvoicePaymentApplication>
     */
    private function requestedPayment(Request $request, Invoice $invoice): RequestedParent
    {
        $requestedId = RequestedParent::requestedIdIn($request->query->all(), 'payment');

        if ($requestedId === null) {
            return RequestedParent::none('payment');
        }

        foreach ($invoice->getApplications() as $application) {
            if ((string) $application->getId() === $requestedId) {
                return RequestedParent::of($application, $requestedId, 'payment');
            }
        }

        return RequestedParent::notOneOf($requestedId, 'payment', "one of this invoice's payments");
    }

    /**
     * Move a payment claim onto a different invoice — queue item 34, the sell-side mirror of
     * `VendorBillController::movePayment()`.
     *
     * The refusal path is the loud one. Every illegal move is answered with the sentence saying which
     * rule it broke and what to do instead — see {@see \App\Payment\PaymentApplicationGuard} — and
     * nothing is written when one is refused.
     */
    #[Route('/invoice/{id}/payments/move/{paymentId}', name: 'admin_invoice_payment_move', methods: ['POST'])]
    public function movePayment(
        int $id,
        int $paymentId,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
        InvoicePaymentMover $paymentMover,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $application = $entityManager->find(InvoicePaymentApplication::class, $paymentId);
        $targetId = $request->request->getInt('target_id', 0);

        try {
            $locks->assertWritable($invoice, 'paid');

            if (!$application instanceof InvoicePaymentApplication) {
                throw new \DomainException('That payment could not be found.');
            }

            if ($targetId <= 0) {
                throw new \DomainException('Choose the invoice to move this payment to.');
            }

            $target = $entityManager->find(Invoice::class, $targetId);
            if (!$target instanceof Invoice) {
                throw new \DomainException(sprintf('No invoice with id %d — it may have been removed since this page loaded.', $targetId));
            }

            // moveApplication() asserts the claim is on THIS invoice before reading anything else, so
            // a forged payment id cannot move somebody else's row through this route — the same
            // protection deletePayment() gets from withdrawApplication().
            $this->addFlash('success', $paymentMover->move(
                $actorResolver->resolve(),
                $invoice,
                $application,
                $target,
                $this->nullableString($request->request->get('reason')),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_payments', ['id' => $id]);
    }

    /**
     * Why this invoice takes no payment, in the words the screen and the refusals both use.
     *
     * One sentence per case rather than one generic line, because the two cases need different
     * things done about them: a draft is issued, and a cancelled invoice is not un-cancelled at all.
     */
    private function noPaymentsReason(Invoice $invoice): string
    {
        return $invoice->isStatus('Cancelled')
            ? sprintf(
                'Invoice %s is cancelled. It is owed nothing, so no payment can be recorded against it.',
                $invoice->getDocumentNumber(),
            )
            : sprintf(
                'Invoice %s is still a draft. It has not been issued to anybody, so no payment can be'
                . ' recorded against it. Issue the invoice first.',
                $invoice->getDocumentNumber(),
            );
    }

    #[Route('/invoice/{id}/payments/delete/{paymentId}', name: 'admin_invoice_payment_delete', methods: ['POST'])]
    public function deletePayment(
        int $id,
        int $paymentId,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
        DocumentLockService $locks,
    ): Response {
        $invoice = $entityManager->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice could not be found.');

            return $this->redirectToRoute('admin_invoice_index');
        }

        $application = $entityManager->find(InvoicePaymentApplication::class, $paymentId);

        try {
            // Inside the try because DocumentLocked is a DomainException and the catch below already
            // flashes it and returns to the payments screen.
            $locks->assertWritable($invoice, 'paid');

            // A draft holds no payments, so there is nothing here to delete — and deleting one
            // through this route would imply the row could legitimately have existed (#31). Refused
            // rather than silently succeeding on an empty collection.
            if (!$invoice->acceptsPayment()) {
                throw new \DomainException($this->noPaymentsReason($invoice));
            }

            if (!$application instanceof InvoicePaymentApplication) {
                throw new \DomainException('Payment could not be found.');
            }

            // withdrawApplication() refuses a claim belonging to another invoice, so a forged id
            // cannot delete somebody else's row through this route.
            $invoice->withdrawApplication($actorResolver->resolve(), $application);
            $entityManager->flush();
            $this->addFlash('success', 'Payment deleted successfully.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_payments', ['id' => $id]);
    }

    /*
     * The credit memo grid used to live here as a placeholder with a hard-coded empty list, waiting
     * for the entity it described. #586 built it, so the screen moved to CreditMemoController — same
     * route name, same path, so the company page's cross-link is unchanged — along with the rule
     * that placeholder stated about what a credit note does and does not do. That rule is restated
     * there, with the one part of it #586 changed called out explicitly.
     */

    /**
     * Record a new payment, or correct one already recorded.
     *
     * The distinction is the posted payment_id and nothing else, which is why both live here: the
     * screen is one form that either adds a row or edits the row an admin clicked Edit on.
     */
    private function savePayment(
        Invoice $invoice,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentActorResolver $actorResolver,
    ): Response {
        $amount = trim((string) $request->request->get('amount', ''));
        $method = trim((string) $request->request->get('method', ''));
        $comment = trim((string) $request->request->get('comment', ''));
        $comment = $comment === '' ? null : $comment;

        try {
            if ($method === '') {
                throw new \DomainException('A payment method is required.');
            }

            $receivedAt = $this->receivedAt($request);
            $actor = $actorResolver->resolve();
            $paymentId = (int) $request->request->get('payment_id', 0);

            if ($paymentId > 0) {
                $application = $entityManager->find(InvoicePaymentApplication::class, $paymentId);
                if (!$application instanceof InvoicePaymentApplication) {
                    throw new \DomainException('Payment could not be found.');
                }

                $invoice->amendApplication($actor, $application, $receivedAt, $method, $amount, $comment);
                $entityManager->flush();
                $this->addFlash('success', 'Payment updated successfully.');
            } else {
                $recordedBy = $this->getUser();
                $payment = (new InvoicePayment())
                    ->setUser($recordedBy instanceof AdminUser ? $recordedBy : null)
                    ->setReceivedAt($receivedAt)
                    ->setMethod($method)
                    ->setAmount($amount)
                    ->setComment($comment);

                $invoice->recordPayment($actor, $payment);
                $entityManager->persist($payment);
                $entityManager->flush();
                $this->addFlash('success', 'Payment added successfully.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_invoice_payments', ['id' => $invoice->getId()]);
    }

    /**
     * The date the money arrived, as typed.
     *
     * Parsed defensively: an unparseable string reaching DateTimeImmutable throws, and a 500 on a
     * mistyped date is not an answer. Today is the same default the form pre-fills.
     */
    private function receivedAt(Request $request): \DateTimeImmutable
    {
        $raw = trim((string) $request->request->get('received_at', ''));

        try {
            return $raw === '' ? new \DateTimeImmutable('today') : new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \DomainException(sprintf('%s is not a date this can read.', $raw));
        }
    }

    /**
     * Everything admin/invoice/invoice.html.twig needs, in one place.
     *
     * Shared by the screen, the download and the email attachment so that the copy a customer
     * receives is byte-for-byte the one an admin looked at before sending it — three call sites
     * assembling their own context is how they drift.
     *
     * @return array{invoice: Invoice, billingAddress: ?\App\Entity\AbstractDocumentAddress, shippingAddress: ?\App\Entity\AbstractDocumentAddress, taxLines: array<mixed>, taxLinesTotal: float}
     */
    private function documentContext(Invoice $invoice, OrderTaxBreakdownService $taxBreakdownService): array
    {
        $breakdown = $taxBreakdownService->breakdownForOrder($invoice);

        return [
            'invoice' => $invoice,
            'billingAddress' => $invoice->getEffectiveBillingAddress(),
            'shippingAddress' => $invoice->getEffectiveShippingAddress(),
            'taxLines' => $breakdown['lines'],
            'taxLinesTotal' => $breakdown['total'],
        ];
    }

    /** Rendered HTML as a PDF the browser offers to save. */
    private function pdf(string $html, string $filename): Response
    {
        return new Response($this->dompdf($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function dompdf(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The order lines with anything left to invoice, as the create screen's FLAT line rows — the
     * exact same shape a standalone row has, freely editable, plus `salesOrderLineId` so a save can
     * tag the resulting InvoiceLine for OverInvoicingGuard. A line already invoiced in full is left
     * out: the screen is a list of what can still be billed.
     *
     * @return list<array<string, string>>
     */
    private function linePrefillFromOrder(SalesOrder $order): array
    {
        $rows = [];
        foreach ($order->getLines() as $line) {
            $remaining = $order->uninvoicedQuantityFor($line);
            if ((float) $remaining <= 0.0) {
                continue;
            }

            $lineUnit = $line->getUnitOfMeasure();
            $lineBaseUnit = LineDenomination::baseUnitOf($line->getProduct());
            $remainingEntered = $lineUnit === null
                ? LineDenomination::trimZeros($remaining)
                : LineDenomination::trimZeros(LineDenomination::toEnteredQuantity($remaining, $lineUnit, $lineBaseUnit));
            $price = $line->getDisplayUnitPrice();

            $row = [
                'productId' => (string) ($line->getProduct()?->getId() ?? ''),
                'name' => (string) $line->getName(),
                'sku' => (string) $line->getSku(),
                'location' => (string) ($line->getLocation() ?? ''),
                'qty' => $remainingEntered,
                'qtyRendered' => $remainingEntered,
                'price' => $price,
                'priceRendered' => $price,
                'unit' => (string) ($line->getUnit() ?? ''),
                'unitId' => (string) ($lineUnit?->getId() ?? ''),
                'taxCode' => (string) ($line->getTaxCode() ?? ''),
                'weight' => (string) ($line->getWeight() ?? ''),
                'cost' => $line->getCost(),
                'batch' => $line->getBatch(),
                'tracksBatch' => $this->productTracksBatch($line->getProduct()),
                'trackingMode' => $this->productTrackingMode($line->getProduct()),
                'lotId' => $line->getLotId(),
                'serial' => $line->getSerial(),
                'salesOrderLineId' => (string) $line->getId(),
                'ordered' => $line->getQuantityEntered(),
                // In the line's OWN unit, trimmed — see lineRowsFromInvoice()'s identical fix and comment.
                'invoiced' => LineDenomination::trimZeros($order->invoicedQuantityInLineUnitFor($line)),
            ];
            if ($lineUnit !== null) {
                $row['baseQuantity'] = $remaining;
                $row['unitLabel'] = $line->getDisplayUnitLabel();
                $row['baseUnitLabel'] = $line->getBaseUnitLabel();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The order's charge rows with anything left to invoice, with the remainder and its unit price
     * pre-filled (#539 stage 5).
     *
     * A charge is a quantified row like any other, so this is the same shape linePrefillFromOrder()
     * has and is built on the same derivation: a row already billed in full is left off, because the
     * screen is a list of what can be billed.
     *
     * @return list<array{slug: string, label: string, type: string, ordered: string, invoiced: string, remaining: string, unitAmount: float, amount: float}>
     */
    private function uninvoicedChargeRows(SalesOrder $order): array
    {
        $rows = [];
        foreach ($order->getChargeSlugs() as $slug) {
            $remaining = $order->uninvoicedChargeQuantityFor($slug);
            if ((float) $remaining <= 0.0) {
                continue;
            }

            $ordered = $order->chargeQuantityFor($slug);
            $amount = $order->chargeAmountFor($slug);
            $unit = (float) $ordered === 0.0 ? $amount : $amount / (float) $ordered;
            $first = $order->chargeRowsFor($slug)[0];

            $rows[] = [
                'slug' => $slug,
                'label' => $first->label,
                'type' => $first->type,
                'ordered' => $ordered,
                'invoiced' => round((float) $ordered - (float) $remaining, 2),
                'remaining' => $remaining,
                'unitAmount' => round($unit, 2),
                'amount' => round($unit * (float) $remaining, 2),
            ];
        }

        return $rows;
    }

    /**
     * The orders this invoice could plausibly be attached to: the same customer's, still live, and
     * not already fully invoiced.
     *
     * A shortlist, not the rule. InvoiceOrderMatcher decides what may actually be linked; this only
     * decides what is worth putting in front of somebody, so a draft or a voided order is left out
     * because neither can be invoiced against at all.
     *
     * @return list<SalesOrder>
     */
    private function linkableOrders(Invoice $invoice, EntityManagerInterface $entityManager): array
    {
        $orders = $entityManager->getRepository(SalesOrder::class)->findBy(
            ['company' => $invoice->getCompany()],
            ['id' => 'DESC'],
            50,
        );

        // isApprovedOrLater() already excludes Void, deliberately — see the enum.
        return array_values(array_filter($orders, static function (SalesOrder $order): bool {
            return $order->getStatusEnum()?->isApprovedOrLater() === true;
        }));
    }

    /** The order the link screen is currently reporting on, when the request names one. */
    private function candidateOrder(Request $request, EntityManagerInterface $entityManager): ?SalesOrder
    {
        $orderId = $request->query->getInt('order_id');
        if ($orderId <= 0) {
            return null;
        }

        $order = $entityManager->find(SalesOrder::class, $orderId);

        return $order instanceof SalesOrder ? $order : null;
    }

    /**
     * Which named actions this invoice's current status permits.
     *
     * Kept in step with Invoice's own from-state checks by being a statement of the same table — the
     * actions still throw if this is wrong, so the worst a drift here can do is hide a button or show
     * one that refuses, never write an illegal transition.
     *
     * @return list<array{action: string, label: string}>
     */
    private function availableActions(Invoice $invoice): array
    {
        $actions = match ($invoice->getStatus()) {
            'Draft' => [
                ['action' => 'issue', 'label' => 'Issue'],
                ['action' => 'issue-awaiting-payment', 'label' => 'Issue awaiting payment'],
            ],
            'On Hold' => [['action' => 'payment-received', 'label' => 'Payment received']],
            'Pending' => [['action' => 'start-processing', 'label' => 'Start processing']],
            'Processing' => [['action' => 'complete', 'label' => 'Complete']],
            default => [],
        };

        if (!$invoice->isStatus('Cancelled')) {
            $actions[] = ['action' => 'cancel', 'label' => 'Cancel invoice'];
        }

        return $actions;
    }
}
