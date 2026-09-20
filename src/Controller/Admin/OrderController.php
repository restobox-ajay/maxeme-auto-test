<?php

namespace App\Controller\Admin;

use App\Twig\SandboxedTemplateRenderer;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\AbstractDocumentAddress;
use App\Entity\Company;
use FeeBundle\Fee\FeeCalculatorResolver;
use PaymentBundle\Payment\PaymentMethodResolver;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Estimate;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Entity\ProductPricing;
use App\Enum\SalesOrderStatus;
use App\Service\AdminUrlGenerator;
use App\Service\AppSettings;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\Inventory\StockOverrideReasons;
use App\Service\Inventory\StockOverrideRecorder;
use App\Service\CustomFieldRenderer;
use App\Service\CustomerUrlGenerator;
use App\Exception\DocumentLocked;
use App\Service\Document\DocumentLockService;
use App\Service\Document\SalesDocumentCloner;
use App\Service\DocumentActorResolver;
use App\Service\OrderNumberGenerator;
use App\Service\Document\SellSideLineReconciler;
use App\Service\OrderTaxBreakdownService;
use App\Service\SalesDocumentChargeLines;
use App\Service\SalesDocumentLineWarnings;
use App\Service\SalesDocumentMoney;
use App\Service\OrderPaymentRollup;
use App\Service\SalesDocumentNotifier;
use App\Service\TextInput;
use App\Service\Uom\LineDenomination;
use App\Service\WarehouseFulfillmentRegionService;
use App\Validation\Constraint\ValidFulfillmentRegionAvailability;
use App\Validation\Constraint\ValidOrderLines;
use App\Validation\Constraint\ValidOrderLinesValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use ShippingBundle\Shipping\ShippingResolver;
use TaxBundle\Tax\TaxCalculatorResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use App\Service\Email\EmailTemplateRenderer;

#[Route('/admin')]
final class OrderController extends AbstractAdminController
{
    private function normalizeMoneyFilter(string $raw): ?string
    {
        $normalized = preg_replace('/[^0-9.\\-]/', '', $raw) ?? '';
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    #[Route('/order', name: 'admin_order_index', methods: ['GET'])]
    public function orders(Request $request, EntityManagerInterface $entityManager, OrderPaymentRollup $paymentRollup): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 100;
        }
        $search = $request->query->get('q', '');
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }
        
        // Scoped to one customer when the query names one by id (`OrderSearch[company_id]`, or a
        // bare `company_id`), which is how the company record drills through to "every order of
        // theirs". Through CompanyListScope rather than a nullable Company, because the third state
        // is what this screen used to get wrong: an id nobody recognises is NOT "no scope", and
        // answering it with every customer's orders hands somebody a grid that looks exactly like
        // the one they asked for.
        $companyScope = $this->companyListScope($request, $entityManager, 'OrderSearch');
        $company = $companyScope->company();
        if ($companyScope->isUnresolved()) {
            $this->addFlash('error', 'Company could not be found for these orders.');
        }

        $repo = $entityManager->getRepository(SalesOrder::class);
        $qb = $repo->createQueryBuilder('o')
            ->join('o.company', 'c')
            // Billing/shipping names moved off the order onto its address snapshots, so the filters
            // and sorts below read them from there. addSelect keeps the list from N+1-ing when the
            // template renders each row's address.
            ->leftJoin('o.orderAddresses', 'ba', 'WITH', "ba.type = 'billing'")->addSelect('ba')
            ->leftJoin('o.orderAddresses', 'sa', 'WITH', "sa.type = 'shipping'")->addSelect('sa');

        if ($company instanceof Company) {
            $qb->andWhere('o.company = :company')
               ->setParameter('company', $company);
        } elseif ($companyScope->isUnresolved()) {
            // Asked for a customer that does not exist. The list is empty, NOT unscoped — the
            // ruling on queue item 30, and the same clause the invoice and quote grids use. Ids
            // are positive, so this matches nothing while leaving ONE code path for the count, the
            // sort and the paging: a second path is how a grid ends up listing nothing over a
            // footer that still counts the whole business.
            $qb->andWhere('o.id = :noSuchCompany')->setParameter('noSuchCompany', 0);
        }

        if ($search) {
            $qb->andWhere("o.orderNumber LIKE :q OR c.name LIKE :q OR CONCAT(COALESCE(ba.firstName, ''), ' ', COALESCE(ba.lastName, '')) LIKE :q")
               ->setParameter('q', '%' . $search . '%');
        }

        $filterOrderNumber = trim((string) ($filters['orderNumber'] ?? ''));
        if ($filterOrderNumber !== '') {
            $qb->andWhere('o.orderNumber LIKE :filterOrderNumber')->setParameter('filterOrderNumber', '%' . $filterOrderNumber . '%');
        }

        $filterCompany = trim((string) ($filters['company'] ?? ''));
        if ($filterCompany !== '') {
            $qb->andWhere('c.name LIKE :filterCompany')->setParameter('filterCompany', '%' . $filterCompany . '%');
        }

        $filterShippingCompany = trim((string) ($filters['shippingCompanyName'] ?? ''));
        if ($filterShippingCompany !== '') {
            $qb->andWhere('sa.companyName LIKE :filterShippingCompany')->setParameter('filterShippingCompany', '%' . $filterShippingCompany . '%');
        }

        $filterUser = trim((string) ($filters['userName'] ?? ''));
        if ($filterUser !== '') {
            $qb->andWhere('o.userName LIKE :filterUser')->setParameter('filterUser', '%' . $filterUser . '%');
        }

        $filterBillingName = trim((string) ($filters['billingName'] ?? ''));
        if ($filterBillingName !== '') {
            $qb->andWhere("CONCAT(COALESCE(ba.firstName, ''), ' ', COALESCE(ba.lastName, '')) LIKE :filterBillingName")->setParameter('filterBillingName', '%' . $filterBillingName . '%');
        }

        $filterShippingName = trim((string) ($filters['shippingName'] ?? ''));
        if ($filterShippingName !== '') {
            $qb->andWhere("CONCAT(COALESCE(sa.firstName, ''), ' ', COALESCE(sa.lastName, '')) LIKE :filterShippingName")->setParameter('filterShippingName', '%' . $filterShippingName . '%');
        }

        $filterPoNumber = trim((string) ($filters['poNumber'] ?? ''));
        if ($filterPoNumber !== '') {
            $qb->andWhere('o.poNumber LIKE :filterPoNumber')->setParameter('filterPoNumber', '%' . $filterPoNumber . '%');
        }

        $filterTotal = trim((string) ($filters['total'] ?? ''));
        if ($filterTotal !== '') {
            $money = $this->normalizeMoneyFilter($filterTotal);
            if ($money !== null) {
                $qb->andWhere('o.total = :filterTotal')->setParameter('filterTotal', $money);
            }
        }

        // document_date holds a 'Y-m-d' string (see AbstractSalesDocument::$documentDate), so these are plain
        // string comparisons — that format compares lexicographically in calendar order, which is
        // the reason it is the format. The half-open ">= from, < to+1day" shape these used before is
        // gone with it: it existed to be robust about whether the column stored DATE or DATETIME,
        // and a column that stores exactly ten characters can just be compared inclusively.
        // Still parsed through parseDateFilter() rather than TextInput::calendarDate(): what an
        // admin types into a filter box is not what gets stored, so this side stays as forgiving
        // about "3/14/2026" as it has always been. It is the comparison VALUE that has to be the
        // stored format, which is what the ->format('Y-m-d') is for.
        $from = $this->calendarDateFilter($filters['documentDateFrom'] ?? null);
        $to = $this->calendarDateFilter($filters['documentDateTo'] ?? null);

        // If both are present but reversed, swap so the filter still works as expected.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== null) {
            $qb->andWhere('o.documentDate >= :filterDocumentDateFrom')->setParameter('filterDocumentDateFrom', $from);
        }

        if ($to !== null) {
            $qb->andWhere('o.documentDate <= :filterDocumentDateTo')->setParameter('filterDocumentDateTo', $to);
        }

        // Header Order Date filter (single day). If a range is given via the From/To boxes, range wins.
        $documentDate = $this->calendarDateFilter($filters['documentDate'] ?? null);
        if ($documentDate !== null && $from === null && $to === null) {
            $qb->andWhere('o.documentDate = :filterDocumentDateExact')->setParameter('filterDocumentDateExact', $documentDate);
        }

        // No invoice-date filter here any more (#539 stage 6). sales_order.invoice_date is gone:
        // an order billed across three invoices has three invoice dates, and a single column on the
        // order could only ever have named one of them. The filter lives on the Invoices grid, over
        // invoice.invoice_date, where there is exactly one date per row to compare.

        $usesLineJoin = false;
        $lineJoined = false;
        $filterBatchNumber = trim((string) ($filters['batchNumber'] ?? ''));
        if ($filterBatchNumber !== '') {
            // Batch number is stored on order lines as a free-form string.
            // Filter orders that have at least one line whose batch contains the entered number.
            $qb->leftJoin('o.lines', 'l');
            $lineJoined = true;
            $usesLineJoin = true;
            $qb->andWhere('l.batch LIKE :filterBatchNumber')->setParameter('filterBatchNumber', '%' . $filterBatchNumber . '%');
        }

        $filterBatchDate = trim((string) ($filters['batchDate'] ?? ''));
        if ($filterBatchDate !== '') {
            // Batch date is also stored on order lines (as part of the batch string).
            // Match against normalized Y-m-d to align with the grid value format.
            if (!$lineJoined) {
                $qb->leftJoin('o.lines', 'l');
                $lineJoined = true;
            }
            $usesLineJoin = true;

            $dt = $this->parseDateFilter($filterBatchDate);
            $needle = ($dt instanceof \DateTimeImmutable) ? $dt->format('Y-m-d') : $filterBatchDate;
            $qb->andWhere('l.batch LIKE :filterBatchDate')->setParameter('filterBatchDate', '%' . $needle . '%');
        }

        // Product Inventory Hub's "Recent Activity" View more link (product-inventory-hub build
        // order step 1): an exact id match, not a SKU/name LIKE, so it can never widen to a
        // different product the way the Company screen's name-LIKE gap could widen to a different
        // customer — see docs/plans/2026-09-15-product-inventory-hub.md.
        $filterProduct = trim((string) ($filters['product'] ?? ''));
        if ($filterProduct !== '' && ctype_digit($filterProduct)) {
            if (!$lineJoined) {
                $qb->leftJoin('o.lines', 'l');
                $lineJoined = true;
            }
            $usesLineJoin = true;
            $qb->andWhere('IDENTITY(l.product) = :filterProduct')->setParameter('filterProduct', (int) $filterProduct);
        }

        $filterStatus = trim((string) ($filters['status'] ?? ''));
        if ($filterStatus !== '') {
            $qb->andWhere('o.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        // Rolled up from the order's invoices, not read off the order — payment is the invoice's
        // since #539 stage 4, and sales_order.payment_status no longer exists to be read. The filter
        // goes into the same query the list is paged by, so filtering, sorting and the rendered
        // badge cannot disagree with each other (see OrderPaymentRollup).
        $filterPaymentStatus = trim((string) ($filters['paymentStatus'] ?? ''));
        if ($filterPaymentStatus !== '') {
            $paymentRollup->applyFilter($qb, $filterPaymentStatus);
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery
            ->select($usesLineJoin ? 'COUNT(DISTINCT o.id)' : 'COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        if ($usesLineJoin) {
            $qb->distinct(true);
        }

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $orderExpr = match ($sort) {
            'id' => 'o.id',
            'orderNumber' => 'o.orderNumber',
            'company' => 'c.name',
            'shippingCompanyName' => 'sa.companyName',
            'userName' => 'o.userName',
            'billingName' => 'ba.lastName',
            'shippingName' => 'sa.lastName',
            'poNumber' => 'o.poNumber',
            'total' => 'o.total',
            'documentDate' => 'o.documentDate',
            'status' => 'o.status',
            // The rollup's own alias, selected below. Sorting on it orders the grid from "nothing
            // paid" to "fully paid", which is what someone clicking the column is asking for.
            'paymentStatus' => OrderPaymentRollup::SELECT_ALIAS,
            default => 'o.id',
        };

        $qb->select('o');
        // One grouped subquery, joined into the list query rather than looked up per row: the grid
        // pages orders, so a per-row accessor would be a query per row and a post-fetch computation
        // could not be sorted or filtered on.
        $paymentRollup->addSelect($qb);

        $rows = $qb
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = array_map(function (array $row) use ($paymentRollup): array {
            $order = $row[0];

            return [
                'id' => (string) $order->getId(),
                'number' => $order->getOrderNumber(),
                'company' => $order->getCompany()->getName(),
                'companyId' => (string) $order->getCompany()->getId(),
                'shippingCompany' => $order->getShippingCompanyName() ?? '-',
                'user' => $order->getUserName() ?? '-',
                'billingName' => $order->getBillingName() ?? '-',
                'shippingName' => $order->getShippingName() ?? '-',
                'poNumber' => $order->getPoNumber() ?? '-',
                'total' => '$' . number_format((float) $order->getTotal(), 2),
                'date' => $order->getDocumentDate(),
                'status' => $order->getStatus(),
                'paymentStatus' => $paymentRollup->labelFor($row[OrderPaymentRollup::SELECT_ALIAS] ?? null)->value,
            ];
        }, $rows);

        if ($request->isXmlHttpRequest()) {
            $payload = [
                'html' => $this->renderView('admin/order/_list_rows.html.twig', [
                    'orders' => $rows,
                    // The empty state names the customer, or says there is no such customer, so the
                    // partial needs the scope even when it is rendered on its own for the JS pager.
                    'company' => $company instanceof Company ? $this->companyToRow($company) : null,
                    'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
                ]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pageCount,
            ];

            // Opt-in debug helper for diagnosing filter mismatches.
            if ($request->query->getInt('debug_filters', 0) === 1) {
                $payload['_debug_query'] = $request->query->all();
                $payload['_debug_filters'] = $filters;
                $payload['_debug_companyScope'] = $company ? ['id' => $company->getId(), 'name' => $company->getName()] : null;
            }
            return $this->json($payload);
        }

        return $this->render('admin/order/index.html.twig', [
            // Both halves of the scope reach the template: the customer whose orders these are, so
            // the screen can say so, and the id that matched nothing, so it can say that instead of
            // rendering as an ordinary unfiltered grid.
            'company' => $company instanceof Company ? $this->companyToRow($company) : null,
            'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
            'orders' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pageCount,
        ]);
    }

    #[Route('/order/detail/{id}', name: 'admin_order_detail', methods: ['GET'])]
    public function detail(int $id, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService, \App\Repository\AuditLogRepository $auditLogRepository, OrderPaymentRollup $paymentRollup): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');
            return $this->redirectToRoute('admin_order_index');
        }

        $logs = $auditLogRepository->findNarrativeForEntity('SalesOrder', $order->getId());

        $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);
        $sourceEstimate = $entityManager->getRepository(Estimate::class)->findOneBy(['convertedOrder' => $order]);

        return $this->render('admin/order/detail.html.twig', [
            'order' => $order,
            // Rolled up from this order's invoices — the order holds no payment status of its own
            // since #539 stage 4, and one order's invoices are already loaded here, so the PHP-side
            // twin of the grid's subquery is what this page wants.
            'paymentStatus' => $paymentRollup->forOrder($order)->value,
            'logs'  => $logs,
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
            'perLineTax' => $taxBreakdown['perLineTax'],
            'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
            'perFeeLineTax' => $taxBreakdown['perFeeLineTax'],
            'auditHistory' => $auditLogRepository->findForEntity('SalesOrder', $order->getId()),
            'sourceEstimate' => $sourceEstimate,
        ]);
    }

    #[Route('/order/edit/{id}', name: 'admin_order_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager, ShippingResolver $shippingResolver, FeeCalculatorResolver $feeResolver, PaymentMethodResolver $paymentMethodResolver, OrderTaxBreakdownService $taxBreakdownService, CustomFieldRenderer $customFieldRenderer, CompanyFulfillmentRegionService $companyFulfillmentRegionService, TaxCalculatorResolver $taxResolver, AdminOrderStockValidator $stockValidator, StockOverrideRecorder $stockOverrides, DocumentActorResolver $actorResolver, OrderPaymentRollup $paymentRollup, WarehouseFulfillmentRegionService $warehouses, BackorderSplitResolver $backorderSplits, DocumentLockService $locks, \App\Repository\AuditLogRepository $auditLogRepository, SellSideLineReconciler $lineReconciler): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');
            return $this->redirectToRoute('admin_order_index');
        }

        // The EXPLICIT lock, asked before and separately from the status rule below. The two are not
        // the same thing and neither weakens the other: canEditOnStatus() is the application
        // saying "a Closed order is history", and this is a named person saying "leave this one
        // alone" about an order whose status would happily allow the edit. A locked DRAFT is refused
        // here and sails straight past the status rule, which is the whole reason a lock cannot be
        // expressed as a status.
        //
        // Refused on GET as well as POST, so the edit screen does not open on a document that cannot
        // be saved — the disputed-bill dead end merged tonight is what an un-actionable screen costs.
        // Caught locally rather than left to DocumentLockedSubscriber so the refusal lands on the
        // order's own detail page, where the Unlock control the sentence names actually is; the
        // wording still lives on the exception.
        try {
            $locks->assertWritable($order, 'edited');
        } catch (DocumentLocked $locked) {
            $this->addFlash('error', $locked->getMessage());

            return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
        }

        if (!$order->canEditOnStatus()) {
            $this->addFlash('error', sprintf('Order %s is %s and cannot be edited.', $order->getOrderNumber(), $order->getStatus()));
            return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
        }

        $company = $order->getCompany();
        $logs = $auditLogRepository->findNarrativeForEntity('SalesOrder', $order->getId());

        if ($request->isMethod('POST')) {
            // #417: the request-scoped write lock below (#396) only serializes concurrent SAVES
            // against each other on SQLite, which has no row-level locking of its own — it does
            // nothing to notice that one of those saves was computed from a page that was already
            // stale before this request even started. That is a different bug: admin B loaded
            // this form, admin A saved in the meantime, and B's browser is still carrying A's
            // now-overwritten field values in a hidden `version` input. $order was just loaded
            // fresh, above, for THIS request, so its version is whatever is in the database right
            // now; EntityManager::lock() with LockMode::OPTIMISTIC compares that against the
            // submitted one and throws OptimisticLockException on a mismatch, before a single
            // field below is touched. (LockMode::OPTIMISTIC is real, DB-agnostic Doctrine
            // behaviour — unlike LockMode::PESSIMISTIC_WRITE, which is a SQLite no-op and is not
            // used here for exactly that reason.)
            //
            // Only checked when the post actually carries a `version` — the current
            // admin/order/form.html.twig always sends one, but a request with no opinion on which
            // version it was edited against (any submit predating this field) has nothing to
            // compare and is left to the #396 lock alone, exactly as before this issue. Declared
            // outside the `if` (rather than only inside it) because the flush()-time catch further
            // down logs it too, on the rarer path where Doctrine's own automatic version check is
            // what catches the conflict instead of this one.
            $submittedVersion = $request->request->has('version') ? $request->request->getInt('version', -1) : null;
            if ($submittedVersion !== null) {
                try {
                    $entityManager->lock($order, LockMode::OPTIMISTIC, $submittedVersion);
                } catch (OptimisticLockException $e) {
                    return $this->rejectStaleOrderSubmit($order, $submittedVersion, $entityManager);
                }
            }

            $rawLines = $this->postedOrderLines($request);

            // The charge rows are checked here, before a single field of the order is written: a
            // fee calculator flushes partway through the save below (FeeRepository::ensureBySlug()
            // persists and flushes), so a row that cannot become a line has to be refused at the
            // door rather than discovered halfway through one.
            [$fulfillmentRegion, $regionError] = $this->resolveFulfillmentRegion(
                $request,
                $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company),
                $order->getFulfillmentRegion(),
            );

            $saveMode = $this->saveModeFromRequest($request);
            // Decided BEFORE the door check rather than after it, because the stock check below
            // needs to know which status this save is heading for: a draft reserves nothing and is
            // never refused over stock, while anything that reserves must fit (#326). The flash for
            // a refused demotion is returned rather than raised here, so it is not emitted on a save
            // that is about to be turned away anyway.
            [$approvesOrder, $demotionRefusal] = $this->editSaveApprovesOrder($order, $saveMode);
            // The status this save lands the order in, for the stock check below only. Approving is
            // an action, not an assignment (#539 stage 2), so this is a prediction of the outcome
            // rather than the value that gets written.
            $status = $approvesOrder ? SalesOrderStatus::Approved->value : $order->getStatus();

            $lineError = $this->validateOrderLines($rawLines)
                ?? SalesDocumentChargeLines::errorFor($this->postedChargeRows($request))
                ?? $regionError
                // Item 63. Checked HERE, before the transaction is opened, so the refusal comes
                // back as the form with a banner on it rather than as an exception mid-save. The
                // rule itself is not here: SalesOrder::removeLine() refuses too, and that is the
                // guard — a screen check is only the courtesy that makes the refusal readable.
                ?? $this->invoicedLineDeletionRefusal($order, $rawLines);

            // What this save asks for beyond what the shelf can cover, and what the operator said
            // about it (#326, warn-and-override).
            //
            // Selling past available stock is the operator's decision, not this form's: they know
            // about the delivery landing Friday, the drop-ship, the substitution agreed on the
            // phone. So the save is NOT turned away over a shortfall — it is turned away only while
            // a shortfall has no REASON against it, because an oversell nobody can explain a
            // fortnight later is indistinguishable from the #326 defect itself. This is what
            // receiving does with a short-dated pallet, on the same two steps: warn with the
            // numbers, then book it in the moment somebody says why, recorded with their name on it.
            //
            // Measured only once the cheaper checks above have passed, so a save already turned away
            // for a bad charge row does not pay for a round of inventory lookups. The shortfalls are
            // carried down to the recorder below rather than measured a second time there, so the
            // figures written are the figures the operator was shown.
            $stockReasons = StockOverrideReasons::fromPostedLines($rawLines);
            $stockShortfalls = [];
            if ($lineError === null) {
                $stockShortfalls = $stockValidator->shortfallsFor($rawLines, $status, $fulfillmentRegion, $order, $entityManager);
                $lineError = $stockOverrides->refusalFor($stockShortfalls, $stockReasons);
            }

            if ($lineError !== null) {
                $this->addFlash('error', $lineError);
                $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);
                $orderLineRows = $this->orderLinesToRows($order, $stockValidator, $entityManager, $warehouses);
                $productsRemote = $this->orderCatalogExceedsInlineLimit($entityManager);

                return $this->render('admin/order/form.html.twig', [
                    // A refused save repaints what was typed: the shared line row reads these instead of
                    // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
                    // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
                    // LineDenomination::boxUntouched(), so reformatting one here would let the next round
                    // trip rewrite a stored figure.
                    'submitted' => $this->submittedLinesForRerender($request),
                    'mode' => 'Edit',
                    'orderData' => $this->orderToEditRow($order, $taxBreakdownService, $paymentRollup),
                    'orderLines' => $orderLineRows,
                    'logs' => $logs,
                    'company' => $this->companyToRow($company),
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                    'billingAddress' => $this->addressToRow($order->getEffectiveBillingAddress(), $company),
                    'shippingAddress' => $this->addressToRow($order->getEffectiveShippingAddress(), $company),
                    'addressBook' => $this->addressBookRows($company),
                    'products' => $this->orderProductRows($entityManager, $companyFulfillmentRegionService, $company, $order->getFulfillmentRegion(), $productsRemote, $this->productIdsFromLineRows($orderLineRows)),
                    'productsRemote' => $productsRemote,
                    'locations' => $this->orderFulfillmentRegionRows($entityManager),
                    'fulfillmentRegions' => $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company),
                    'shippingOptions' => $this->buildShippingOptions($shippingResolver, $order, $company),
                    'taxLines' => $taxBreakdown['lines'],
                    'taxLinesTotal' => $taxBreakdown['total'],
                    'perLineTax' => $taxBreakdown['perLineTax'],
                    'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
                    'perFeeLineTax' => $taxBreakdown['perFeeLineTax'],
                    'orderError' => $lineError,
                    'customFieldFragment' => $customFieldRenderer->renderFields('order', $order, 'edit'),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ($demotionRefusal !== null) {
                $this->addFlash('error', $demotionRefusal);
            }

            // Explicit transaction plus a forced early write, the same idiom
            // CartController::add()/reorder() and CheckoutController::processCheckoutSubmission() use
            // (#301/#321): SQLite has no row-level locking, and beginTransaction() alone is deferred,
            // taking no lock until the first write — so two admins saving this order at once, or an
            // admin's save racing another mutating request against it, could otherwise interleave
            // their writes at the SQL level. The no-op UPDATE below forces SQLite's write lock
            // immediately, serializing concurrent submits to THIS action against each other (#396).
            // It only serializes the writes; it does not detect or surface a conflict when one save
            // overwrites another's — that is tracked separately, along with extending this same lock
            // to the order's other mutating endpoints, in #412.
            $conn = $entityManager->getConnection();
            $conn->beginTransaction();
            try {
                $conn->executeStatement('UPDATE sales_order SET id = id WHERE id = 0');
                if ($approvesOrder) {
                    // A Save Order on a Draft is the admin accepting it. Through the one gate, so
                    // the acceptance is validated and lands on the order's timeline; every later
                    // status this order holds is derived from its invoices.
                    $order->setStatus('Approved', $actorResolver->resolve(), 'Order approved.');
                }

                $order
                    ->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80))
                    ->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')))
                    // Resolved above, not read raw: an absent field means UNCHANGED, never cleared.
                    ->setFulfillmentRegion($fulfillmentRegion)
                    // No payment status: it is the invoice's, derived from its payment rows, and
                    // the order form shows the rollup read-only rather than posting one (#539
                    // stage 4). Method and term are terms of the sale and stay editable here.
                    ->setPaymentMethod($this->nullableString($request->request->get('payment_method')))
                    ->setPaymentTerm($this->nullableString($request->request->get('payment_term')))
                    // No setBillingName()/setShippingName() here: those join first+last (falling back to
                    // the COMPANY name) and the setter splits the result on the first space again, so
                    // "Mary Jane Watson" came back as first "Mary" / last "Jane Watson" and a nameless
                    // company got its own name shredded across the contact fields. applyAddressEditsFromRequest(),
                    // via applyOrderAddressFromRequest() below, writes first and last from their own
                    // posted fields losslessly — it always did, which is the only reason this was
                    // invisible (#278). Company name is written straight through and stays.
                    ->setShippingCompanyName($this->nullableString($request->request->get('shipping_company_name')));

                $this->applyOrderCompanyCard($order, $request);

                // Absent or unparseable leaves the order dated as it was. document_date is NOT NULL
                // and an order is always dated something, so a blank box is a field that wasn't
                // filled in, not an instruction to clear it.
                $documentDate = TextInput::calendarDate($request->request->get('document_date'));
                if ($documentDate !== null) {
                    $order->setDocumentDate($documentDate);
                }

                // Edits made on the order form now land on the ORDER's own address snapshot.
                //
                // Previously they were written straight onto the CompanyAddress the order pointed at, so
                // correcting a typo on one order silently rewrote the customer's address-book entry —
                // and, because every other order pointed at the same row, rewrote their delivery address
                // too. When no address was picked it instead created a brand new CompanyAddress per save,
                // quietly growing the customer's address book.
                //
                // The address book is now only ever READ here; source_address_id records which entry the
                // snapshot came from.
                $this->applyOrderAddressFromRequest($order, $entityManager, $company, $request, 'billing');
                $this->applyOrderAddressFromRequest($order, $entityManager, $company, $request, 'shipping');

                // The rows already on the order, by id, so a submitted row can be matched to the line
                // it came from and UPDATED — rather than the whole collection being deleted and rebuilt
                // from the post, which is what this did (#267).
                //
                // AuditLogSubscriber is a generic UnitOfWork listener and SalesOrderLine is not in its
                // EXCLUDED list, so the rebuild wrote a deleted+created pair per line into audit_log on
                // every save, whether or not anything on the line had changed: a quantity going 5 → 7
                // was unreadable as anything but one line vanishing and a different one appearing. The
                // line primary keys churned with it, so nothing could safely key off a line id across
                // an edit. Same diff-in-place shape EstimateController::applyLinesFromRequest() uses.
                /** @var array<int, SalesOrderLine> $existingLines */
                $existingLines = [];
                foreach ($order->getLines() as $existingLine) {
                    $existingLines[(int) $existingLine->getId()] = $existingLine;
                }

                $lineWarnings = new SalesDocumentLineWarnings();

                // Row-by-row decisions (id matching, boxUntouched semantics, catalog defaults, unit
                // conversion) live in SellSideLineReconciler now — the one place Order, Estimate and
                // Invoice all make them, so they cannot drift the way Invoice's save mechanics did.
                // This loop's only job is writing each resolved row onto SalesOrderLine specifically;
                // SalesOrderLine/InvoiceLine/EstimateLine share no common setter surface to do that
                // generically (see DocumentLine's own docblock).
                $reconciled = $lineReconciler->reconcile($existingLines, $rawLines, $fulfillmentRegion, $lineWarnings);
                $keptLineIds = $reconciled['keptIds'];
                $subtotal = (float) $reconciled['subtotal'];

                foreach ($reconciled['lines'] as $resolved) {
                    $orderLine = $resolved->existingId !== null ? $existingLines[$resolved->existingId] : new SalesOrderLine();

                    $lineReconciler->applyQuantity($orderLine, $resolved);

                    $orderLine
                        ->setProduct($resolved->product)
                        ->setName($resolved->name)
                        ->setLocation($resolved->location)
                        ->setSku($resolved->sku)
                        ->setWeight($resolved->weight)
                        ->setUnit($resolved->unit)
                        ->setTaxCode($resolved->taxCode)
                        ->setCost($resolved->cost)
                        ->setPrice($resolved->priceForColumn)
                        ->setSubtotal($resolved->lineSubtotal)
                        ->setBatch($resolved->batch)
                        ->setLotId($resolved->lotId)
                        ->setSerial($resolved->serial)
                        ->setRestockEta($resolved->restockEta)
                        ->setSortOrder($resolved->sortOrder);

                    $order->addLine($orderLine);
                }

                // Only the rows this save did not carry are deleted, and they are dropped BEFORE the
                // fee, tax and shipping figures below are read back off the order — leaving them in the
                // collection would have the document count every removed line one more time.
                // orphanRemoval on SalesOrder::$lines turns the detach into the DELETE, which is what
                // the explicit remove() the rebuild needed used to do.
                foreach ($existingLines as $existingLineId => $existingLine) {
                    if (!isset($keptLineIds[$existingLineId])) {
                        $order->removeLine($existingLine);
                    }
                }

                // Which units of each line ship and which are a promise (#548). After the removals,
                // so the pool a line draws on is not shared with a row this save just deleted, and
                // against the order's post-approval status, since a Draft promises nothing. For a
                // SKU nobody has opted in this sets every line to zero, which is where they already
                // were.
                $backorderSplits->applyToOrderLines($order, $order->getStatus(), $entityManager);

                // Stated before anything is calculated off it: fees priced against the order's value
                // read the header figure, not a re-sum of the rows. Stored to the cent because the
                // column is decimal(12,2); the grand total below is built from $subtotal itself, which
                // is still carried at SalesDocumentMoney::SCALE.
                $order->setSubtotal($this->decimal($subtotal));

                // Read here rather than at the top of the save: the guard inside restores the order's
                // stored charges off its own snapshot, so it has to run while fee_lines and tax_lines
                // still hold what the last save wrote — and after the lines are rebuilt, so a shipping
                // row is taxed at the class this save's goods actually carry.
                $normalizedCharges = $this->chargeRowsForSave($request, $order, $taxBreakdownService);
                $normalizedCharges = $this->recalculateShippingCharge($normalizedCharges, $shippingResolver, $order);
                $order->setShippingMethod($this->deriveShippingMethodFromCharges($normalizedCharges));

                // Shipping rows join the calculated fees as rows of the same list, which is what makes
                // them appear on the invoice, be taxed by tax class and count toward the total without
                // any of those three being told about shipping. The admin's one-off fee rows join them
                // for the same reason and on the same terms — the only difference is that they state
                // their own tax class and placement, having no Fee definition to inherit either from.
                $feeLines = array_merge(
                    $feeResolver->calculate(FeeContext::fromDocument($order)),
                    SalesDocumentChargeLines::toShippingLines($normalizedCharges, $order->getHighestTaxClass()),
                    SalesDocumentChargeLines::toFeeLines($normalizedCharges),
                );
                $feeTotal = $this->sumFeeLines($feeLines);
                // The admin's tax rows join the breakdown as lines rather than being added to the tax
                // figure behind its back, so the total is unchanged and the adjustment is now visible.
                $taxBreakdown = $taxBreakdownService->withManualTaxLines(
                    $taxBreakdownService->computeBreakdownFor($order, $feeLines),
                    $taxBreakdownService->manualTaxLinesFromCharges($normalizedCharges),
                );
                // Carried at SCALE rather than snapped to the cent here: tax is an intermediate on the
                // way to the grand total, and rounding it before adding it is a second rounding point
                // (#257). The tax COLUMN is still stored to the cent — that is a decimal(12,2) column
                // and a displayed figure, not the arithmetic.
                $tax = SalesDocumentMoney::intermediate($taxBreakdown['total']);
                $order
                    ->setTax($this->decimal($tax))
                    ->setTotal($this->decimal($subtotal + $tax + $feeTotal))
                    ->setFeeLines(FeeLineSnapshot::encode($feeLines))
                    ->setTaxLines($taxBreakdownService->toJson($taxBreakdown));

                $feeResolver->applyOrderSnapshots($order, $company->getId());
                $taxResolver->applyOrderSnapshots($order, $company->getId());
                $customFieldRenderer->saveFromRequest('order', $order, $request);

                // The decision to go beyond the shelf, recorded against the lines it was taken on
                // (#326). Inside this transaction and before the flush below, exactly where
                // ReceivingService writes its short-dated rows: the document and the record of why
                // it looks like that are written together or not at all.
                //
                // After the line diff and after applyToOrderLines(), so what it hangs off is the
                // line this save actually kept. Silent when nothing was short, which is almost every
                // save.
                $stockOverrides->recordForOrder(
                    $order,
                    $stockShortfalls,
                    $stockReasons,
                    // The same display name the order's own timeline entries carry, so "who
                    // approved this" and "who oversold it" are one person spelled one way.
                    $actorResolver->resolve()->displayName,
                    $entityManager,
                );

                // #417: the version this save is ABOUT TO BECOME, not the one $order still holds —
                // Doctrine only increments the in-memory value during the flush() below, which
                // hasn't run yet, but a plain integer version column always moves by exactly 1 per
                // successful UPDATE, so the post-save number is knowable here without waiting for
                // it. Recorded so "why did my PO number revert" is a lookup in this log rather
                // than a mystery — see rejectStaleOrderSubmit() for the entry a BLOCKED save
                // leaves instead.
                $resultingVersion = $order->getVersion() + 1;

                // No status-change entry here any more (#539 stage 2). setStatus() writes its own, and
                // approving is the only transition an edit save can make; a second entry restating it would
                // put the same event on the timeline twice — the #273 double-logging defect arriving
                // from the other direction. The version this save landed in is on the generic
                // "updated" entry below, which every save writes whether or not the status moved.

                $this->logOrderAction(
                    $order,
                    sprintf('Order %s updated by %s. (version %d)', $order->getOrderNumber(), $this->getUser()?->getUserIdentifier() ?? 'System', $resultingVersion),
                    'System',
                    false,
                    $entityManager
                );

                $entityManager->flush();
                $conn->commit();
            } catch (OptimisticLockException $e) {
                // Defense in depth, not the primary fix: the check at the top of this POST
                // handler already catches the case #417 is about (a page left open since before
                // someone else's save landed). This is the much narrower window between that
                // check and this flush() — another save serialized behind the #396 lock above
                // could still land in between. Doctrine's own version column catches it here
                // automatically because $order's in-memory version is never touched by this
                // method; the UPDATE's WHERE version = ... simply matches zero rows.
                //
                // $entityManager is unusable after this — UnitOfWork::commit() closes it on any
                // failure past this point — so the rejection is logged with a raw insert rather
                // than through logOrderAction()/flush().
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                $this->recordStaleOrderRejectionRaw($conn, (int) $order->getId(), $submittedVersion);
                $this->addFlash('error', $this->staleOrderSubmitMessage($order));

                return $this->redirectToRoute('admin_order_edit', ['id' => $order->getId()]);
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $this->addFlash('success', sprintf('Order %s updated successfully.', $order->getOrderNumber()));
            // The save succeeded; a coerced quantity is reported, not refused. Every route out of
            // here is a redirect, so the warnings ride the flash bag to whichever page that is —
            // and the edit form below paints the named rows red when it is this one.
            $lineWarnings->flashOnto($request);
            // Both recalculating modes land back on the form: the admin is still working on the
            // document. 'recalc' is the no-JS Add Line submit, whose whole point is to come back to
            // a page carrying the new row's inputs. A post that stated no mode ('' — the no-JS row
            // delete) lands there too, and for the same reason: it deleted a row and has said
            // nothing about being finished, so it gets the form back with the row gone rather than
            // being bounced to the read-only detail page.
            if ($saveMode === 'draft_recalc' || $this->saveModePreservesStatus($saveMode)) {
                return $this->redirectToRoute('admin_order_edit', ['id' => $order->getId()]);
            }

            return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
        }

        $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);
        $flashedLineWarnings = SalesDocumentLineWarnings::takeFromFlash($request);
        $orderLineRows = $this->orderLinesToRows($order, $stockValidator, $entityManager, $warehouses);
        $productsRemote = $this->orderCatalogExceedsInlineLimit($entityManager);

        return $this->render('admin/order/form.html.twig', [
            // A refused save repaints what was typed: the shared line row reads these instead of
            // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
            // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
            // LineDenomination::boxUntouched(), so reformatting one here would let the next round
            // trip rewrite a stored figure.
            'submitted' => $this->submittedLinesForRerender($request),
            'mode' => 'Edit',
            'lineWarnings' => $flashedLineWarnings['messages'],
            'lineWarningQtyRows' => $flashedLineWarnings['qtyRows'],
            'lineWarningPriceRows' => $flashedLineWarnings['priceRows'],
            'orderData' => $this->orderToEditRow($order, $taxBreakdownService, $paymentRollup),
            'orderLines' => $orderLineRows,
            'logs' => $logs,
            'company' => $this->companyToRow($company),
            'companies' => $this->companyRowsFromDatabase($entityManager),
            'billingAddress' => $this->addressToRow($order->getEffectiveBillingAddress(), $company),
            'shippingAddress' => $this->addressToRow($order->getEffectiveShippingAddress(), $company),
            'addressBook' => $this->addressBookRows($company),
            'products' => $this->orderProductRows($entityManager, $companyFulfillmentRegionService, $company, $order->getFulfillmentRegion(), $productsRemote, $this->productIdsFromLineRows($orderLineRows)),
            'productsRemote' => $productsRemote,
            'locations' => $this->orderFulfillmentRegionRows($entityManager),
            'fulfillmentRegions' => $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company),
            'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
            'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company),
            'shippingOptions' => $this->buildShippingOptions($shippingResolver, $order, $company),
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
            'perLineTax' => $taxBreakdown['perLineTax'],
            'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
            'perFeeLineTax' => $taxBreakdown['perFeeLineTax'],
            'orderError' => null,
            'customFieldFragment' => $customFieldRenderer->renderFields('order', $order, 'edit'),
        ]);
    }

    /**
     * #417's client-side staleness check: while the edit page is open, app.js pings this to learn
     * whether the order has moved since the page was rendered, without paying for the rest of the
     * order (lines, addresses, fee/tax breakdown, …) just to read one column. Deliberately not the
     * same route as detail()/edit() with a partial response — this is meant to be cheap enough to
     * call on a timer and on clicks (see the two-trigger polling in app.js) without that ever
     * being a concern.
     *
     * This is advisory only: it is what shows the "someone else changed this" banner before a
     * save is attempted. The actual, authoritative check is the version lock at the top of
     * edit()'s POST handling — a stale value here can never cause a bad save, only a late warning
     * (the request racing the check itself) or a momentarily-unnecessary one (the order changed
     * again back to this admin's known version in between, vanishingly unlikely and harmless
     * either way).
     */
    #[Route('/order/{id}/version', name: 'admin_order_version_check', methods: ['GET'])]
    public function versionCheck(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $version = $entityManager->createQueryBuilder()
            ->select('o.version')
            ->from(SalesOrder::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($version === null) {
            return $this->json(['found' => false], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['found' => true, 'version' => (int) $version['version']]);
    }

    #[Route('/order/update-status/{id}', name: 'admin_order_update_status', methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        CustomerUrlGenerator $customerUrlGenerator,
        SalesDocumentNotifier $salesDocumentNotifier,
        \App\Service\AppSettings $appSettings,
        DocumentActorResolver $actorResolver,
        BackorderSplitResolver $backorderSplits,
        DocumentLockService $locks,
    ): Response {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            return $this->json(['success' => false, 'message' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        // Approve and Void both arrive here. A frozen order performs neither. Answered as JSON 409
        // for the XHR the status dropdown sends, and as a flash for a plain form post — see
        // DocumentLockedSubscriber, which decides that from the request rather than from here.
        $locks->assertWritable($order, 'changed');

        if (!$order->canEditOnStatus()) {
            return $this->json(['success' => false, 'message' => 'This order is locked and cannot be edited.'], Response::HTTP_FORBIDDEN);
        }

        // Checked against the enum rather than written through, the way the quote endpoint already
        // does (EstimateController::updateStatus()). This took whatever string arrived: a typo like
        // 'Proccessing' persisted silently, getStatusEnum() then returned null for it and
        // OrderInventoryBucketResolver::bucketForStatus() fell to its `default => null` — so the
        // order reserved no inventory at all, and dropped out of every list filter and status-gated
        // action besides. A missing `status` was worse still: null into a string parameter, a
        // TypeError, a 500 (#266).
        //
        // SalesOrder::$status stays a plain string column so legacy pre-cutover values still
        // hydrate (see the SalesOrderStatus docblock) — it is what this endpoint may WRITE that is
        // narrowed here, not what the column may hold.
        $statusEnum = SalesOrderStatus::tryFrom(trim((string) $request->request->get('status', '')));
        if ($statusEnum === null) {
            return $this->json(['success' => false, 'message' => 'Invalid status.'], Response::HTTP_BAD_REQUEST);
        }

        $status = $statusEnum->value;
        $notify = $request->request->getBoolean('notify_client');
        $oldStatus = $order->getStatus();
        $actor = $actorResolver->resolve();

        // Approve and Void are the only two an admin performs; Partially Invoiced, Invoiced and
        // Closed are derived from the order's invoices by SalesOrderStatusDeriver and would be
        // recomputed away the moment anything touched the order (#539 stage 2). Refusing them here
        // is what stops the control offering a choice the system will silently overrule — and the
        // fulfilment statuses an admin actually wants are on the INVOICE now, which has its own
        // actions on its own page.
        try {
            match ($statusEnum) {
                SalesOrderStatus::Approved => $order->setStatus('Approved', $actor, 'Order approved.'),
                SalesOrderStatus::Void => $order->setStatus('Void', $actor),
                default => throw new \DomainException(sprintf(
                    '%s is derived from this order\'s invoices and cannot be set by hand. Approve or Void the order, or change the status of one of its invoices.',
                    $status,
                )),
            };
        } catch (\DomainException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Approving no longer stamps an invoice date on the order (#539 stage 6). It could not: the
        // column is gone, because an order billed across several invoices has several invoice dates
        // and one column on the order can only name one of them. Approving an order does not invoice
        // it either — raising an invoice does, and OrderInvoicingService dates that invoice.

        // setStatus() writes the timeline entry itself, so this no longer restates the
        // transition. What it still owns is the customer notification flag an admin ticked, which is
        // a property of THIS request and not of the transition — hence a second, notified entry
        // rather than a duplicate of the action's.
        if ($notify) {
            $this->logOrderAction(
                $order,
                sprintf('Customer notified of the change from %s to %s.', $oldStatus, $status),
                'System',
                true,
                $entityManager
            );
        }

        // The status control is the third way a Draft becomes Approved, after the two save buttons
        // on the form, so the lines are split here too (#548) — an order approved from this
        // endpoint would otherwise hold its whole quantity as stock it does not have, and never
        // reach the queue. Voiding runs it as well, which clears every promise: applyToOrderLines()
        // reads the status, and a status that holds nothing promises nothing.
        $backorderSplits->applyToOrderLines($order, $order->getStatus(), $entityManager);

        $entityManager->flush();

        if ($notify && $order->getCompany() && $order->getCompany()->getPrimaryEmail()) {
            try {
                $vars = array_merge(
                    [
                        'order' => $order,
                        'status' => $status,
                        'order_url' => $customerUrlGenerator->generate('customer_order_detail', ['id' => $order->getId()]),
                    ],
                    $salesDocumentNotifier->documentSummaryContext($order),
                );
                $rendered = $emailTemplates->render('order_status_update', $vars);
                $subject = $rendered?->subject ?? 'Order Update: ' . $order->getOrderNumber();
                $body    = $rendered?->body ?? $this->renderView('emails/order_status_update.html.twig', $vars);

                $email = $appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SALES)
                    ->to($order->getCompany()->getPrimaryEmail())
                    ->subject($subject)
                    ->html($body);

                $mailer->send($email);
            } catch (\Exception $e) {
                // Silent — do not block the status save on email failure
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'status' => $status
        ]);
    }

    // admin_order_update_time is gone. It was the write half of an inline pencil-edit on the order
    // DETAIL page — a view screen, editing one field through its own AJAX endpoint with its own
    // date parsing, separate from the edit form where every other field on the order is changed.
    // Order Date is now a field on that form like the rest of them, so this endpoint had no caller
    // left; an unused write route on a sales document is worth deleting rather than leaving.

    /**
     * The Sales Order document — on screen, and as a PDF with ?download=1.
     *
     * This replaces `admin_order_invoice`, which rendered the order AS an invoice because it was
     * one. Since #539 it is not: an order records what was ordered and accepted, and the money lives
     * on the invoices raised against it. So this renders a DIFFERENT template
     * (admin/order/sales_order.html.twig) with no amount due, no payment status, no remit-to note
     * and no invoice number — see that file's header for the full list of what is deliberately
     * absent, and why each one is.
     *
     * The invoice document moved with its entity, to InvoiceController::document().
     *
     * The addresses come off the ORDER's own snapshots rather than the company's current defaults,
     * which is a fix rather than a move: walking company.addresses for a default billing row meant a
     * document reprinted after the customer moved showed an address the goods never went to.
     * getEffectiveBillingAddress() answers from what this document recorded, and only falls back to
     * the company when it recorded nothing.
     */
    #[Route('/order/document/{id}', name: 'admin_order_document', methods: ['GET'])]
    public function document(int $id, Request $request, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');
            return $this->redirectToRoute('admin_order_index');
        }

        $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);
        $context = [
            'order' => $order,
            'billingAddress' => $order->getEffectiveBillingAddress(),
            'shippingAddress' => $order->getEffectiveShippingAddress(),
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
        ];

        if ($request->query->get('download') === '1') {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($this->renderView('admin/order/sales_order.html.twig', $context + ['is_pdf' => true]));
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="SalesOrder-' . $order->getOrderNumber() . '.pdf"',
            ]);
        }

        return $this->render('admin/order/sales_order.html.twig', $context);
    }

    /**
     * Copy this order into a new DRAFT order — "make another like this".
     *
     * ## This action already existed, and this changes what it does. Deliberately.
     *
     * The previous version built the copy inline, right here, and then approved it, so
     * a clone was born LIVE — reserving stock, owed to the customer, with "Order cloned from SO-x."
     * already on its timeline. That was defensible when clone was the order's own private action: it
     * preserved what clone had always done before #539 gave orders a status seam.
     *
     * It is not defensible now that the quote and the invoice have the same action. Three documents
     * whose Clone button means three different things is worse than any of the three behaviours, and
     * Draft is the right one of them: a copy is a *starting point*, it is reached for precisely when
     * something is about to be changed on it, and approving it is one click away on the screen it
     * opens. A clone that reserved stock the moment it was made would take inventory for an order
     * nobody has agreed to yet, which is the shape of defect #326 exists to stop.
     *
     * Two consequences are worth stating rather than discovering:
     *
     *  - the clone no longer reserves stock on creation. Approve it and it does, through exactly the
     *    ceiling `AdminOrderStockValidator` already applies to any other promotion out of Draft;
     *  - the clone's timeline is now EMPTY, where it used to carry one "Order cloned from SO-x."
     *    row. That row was real provenance and losing it is the cost of this change. It is recorded
     *    in the flash the admin sees, and in `audit_log`, which records the insert either way. If the
     *    owner wants it back on the timeline it is one `logOrderAction()` call here — but it cannot
     *    come back on the estimate and the invoice, which keep no such generic timeline writer, so
     *    restoring it would put the three documents back out of step.
     *
     * Available on EVERY status including Void, which is the main reason people clone at all — "this
     * one was wrong, make another". Cloning writes nothing to the original, so the original's status
     * is never consulted.
     *
     * A LOCKED order can be cloned. A lock freezes the document against being changed; the clone is
     * a different document.
     */
    #[Route('/order/clone/{id}', name: 'admin_order_clone', methods: ['POST'])]
    public function cloneOrder(int $id, EntityManagerInterface $entityManager, SalesDocumentCloner $cloner): Response
    {
        $original = $entityManager->find(SalesOrder::class, $id);
        if (!$original instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');
            return $this->redirectToRoute('admin_order_index');
        }

        $clone = $cloner->cloneOrder($original, $entityManager);
        $entityManager->flush();

        // No invoice, for the same reason the admin create path raises none (#539). A clone is an
        // admin-raised order, so it is invoiced deliberately through Convert to Invoice rather than
        // arriving pre-billed. Cloning never copies the original's invoices either — those belong
        // to the original and to the goods it actually billed.

        // The sentence about prices is the whole of "make it obvious which happened": a copy and a
        // re-price produce screens that look identical, and only this says which one ran.
        $this->addFlash('success', sprintf(
            'Order %s copied to %s as a new draft. Lines, quantities and prices came across exactly as they '
                . 'were — re-price it if the customer\'s list has moved since. Approve it when it is right.',
            $original->getOrderNumber(),
            $clone->getOrderNumber(),
        ));

        return $this->redirectToRoute('admin_order_detail', ['id' => $clone->getId()]);
    }

    /**
     * Freeze this order against editing. Any admin; unlocking asks for ROLE_SUPER_ADMIN — see
     * {@see \App\Service\Document\DocumentLockService} for the asymmetry and why it is not a role
     * this change invented.
     */
    #[Route('/order/lock/{id}', name: 'admin_order_lock', methods: ['POST'])]
    public function lockOrder(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentLockService $locks,
        DocumentActorResolver $actorResolver,
    ): Response {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');

            return $this->redirectToRoute('admin_order_index');
        }

        $locks->lock($order, $actorResolver->resolve(), (string) $request->request->get('reason', ''), $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'Order %s is locked. It can still be printed, emailed and cloned; nothing can edit, re-status or '
                . 'delete it until it is unlocked.',
            $order->getOrderNumber(),
        ));

        return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
    }

    /** Release the freeze. ROLE_SUPER_ADMIN, which ROLE_TECH_SUPPORT inherits — see DocumentLockService. */
    #[Route('/order/unlock/{id}', name: 'admin_order_unlock', methods: ['POST'])]
    public function unlockOrder(int $id, EntityManagerInterface $entityManager, DocumentLockService $locks): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');

            return $this->redirectToRoute('admin_order_index');
        }

        $locks->unlock($order, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Order %s is unlocked and can be edited again.', $order->getOrderNumber()));

        return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
    }

    // packingSlip() and sendInvoice() are gone from here, moved to InvoiceController (#539 stage 6).
    //
    // Both were about a document the order no longer produces. A packing slip lists the goods
    // actually going out, which is a per-invoice question once an order can be billed in parts — a
    // slip listing the whole order would be right at most once. And "Send Invoice" attached the
    // order printed as an invoice, which is precisely the conflation this stage removes.

    #[Route('/order/log/{id}', name: 'admin_order_log', methods: ['GET'])]
    public function log(int $id, EntityManagerInterface $entityManager, \App\Repository\AuditLogRepository $auditLogRepository): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');
            return $this->redirectToRoute('admin_order_index');
        }

        return $this->render('admin/order/log.html.twig', [
            'order' => $order,
            'logs' => $auditLogRepository->findNarrativeForEntity('SalesOrder', $order->getId()),
        ]);
    }

    #[Route('/order/log/add/{id}', name: 'admin_order_log_add', methods: ['POST'])]
    public function addLog(int $id, Request $request, EntityManagerInterface $entityManager, \Symfony\Component\Mailer\MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator, \App\Service\AppSettings $appSettings): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            return $this->json(['success' => false, 'message' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        $message = $request->request->get('message');
        $type = $request->request->get('type');
        $notify = $request->request->getBoolean('notify_client');

        if (!$message) {
            return $this->json(['success' => false, 'message' => 'Message is required.'], Response::HTTP_BAD_REQUEST);
        }

        $this->logOrderAction($order, $message, $type, $notify, $entityManager);
        $entityManager->flush();

        if ($notify && $order->getCompany() && $order->getCompany()->getPrimaryEmail()) {
            try {
                $vars = [
                    'order' => $order,
                    'message' => $message,
                    'order_url' => $customerUrlGenerator->generate('customer_order_detail', ['id' => $order->getId()]),
                ];
                $rendered = $emailTemplates->render('order_message', $vars);
                $subject = $rendered?->subject ?? 'Order Message: ' . $order->getOrderNumber();
                $body    = $rendered?->body ?? $this->renderView('emails/order_message.html.twig', $vars);

                $email = $appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SALES)
                    ->to($order->getCompany()->getPrimaryEmail())
                    ->subject($subject)
                    ->html($body);

                $mailer->send($email);
            } catch (\Exception $e) {
                // Silent — do not block the log save on email failure
            }
        }

        return $this->json(['success' => true, 'message' => 'Message added successfully.']);
    }

    #[Route('/order/log/delete/{id}/{logId}', name: 'admin_order_log_delete', methods: ['POST'])]
    public function deleteLog(int $id, int $logId, EntityManagerInterface $entityManager): Response
    {
        $log = $entityManager->find(\App\Entity\AuditLog::class, $logId);
        // actorType === 'document' is not just a read-side filter here — it is what stops this
        // route from becoming a way to delete an arbitrary audit_log row by guessing its id (see
        // EstimateController::deleteLog()'s identical guard).
        if ($log && $log->getEntityType() === 'SalesOrder' && $log->getEntityId() === $id && $log->getActorType() === 'document') {
            $entityManager->remove($log);
            $entityManager->flush();
            return $this->json(['success' => true, 'message' => 'Log entry deleted successfully.']);
        }

        return $this->json(['success' => false, 'message' => 'Log entry not found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/order/shipping-options', name: 'admin_order_shipping_options', methods: ['GET'])]
    public function shippingOptionsAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        ShippingResolver $shippingResolver,
    ): JsonResponse {
        $companyId = (int) $request->query->get('company_id', 0);
        $addressId = (int) $request->query->get('address_id', 0);
        $province  = (string) $request->query->get('province', '');
        $linesRaw  = (string) $request->query->get('lines', '[]');

        $company = $entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            return $this->json(['options' => []]);
        }

        $preview = $this->previewOrderFromRequest($entityManager, $company, $province, $addressId, $linesRaw);

        $options = array_map(
            fn ($o) => [
                'id'          => $o->id,
                'label'       => $o->label,
                'amount'      => $o->amount,
                'deliveryDays' => $o->deliveryDays,
            ],
            $shippingResolver->getAvailableOptions($preview)
        );

        return $this->json(['options' => $options]);
    }

    #[Route('/order/fee-lines', name: 'admin_order_fee_lines', methods: ['GET'])]
    public function feeLinesAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        FeeCalculatorResolver $feeResolver,
    ): JsonResponse {
        $companyId = (int) $request->query->get('company_id', 0);
        $addressId = (int) $request->query->get('address_id', 0);
        $province  = (string) $request->query->get('province', '');
        $linesRaw  = (string) $request->query->get('lines', '[]');

        $company = $entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            return $this->json(['lines' => [], 'total' => 0]);
        }

        $preview  = $this->previewOrderFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
        $feeLines = $feeResolver->calculate(FeeContext::fromDocument($preview));
        $total    = array_sum(array_map(fn(FeeLine $l) => $l->amount, $feeLines));

        return $this->json([
            'lines' => array_map(
                fn(FeeLine $l) => ['slug' => $l->slug, 'label' => $l->label, 'taxClass' => $l->taxClass, 'amount' => $l->amount, 'type' => $l->type, 'source' => $l->source],
                $feeLines
            ),
            'total' => round($total, 2),
        ]);
    }

    #[Route('/order/tax-breakdown', name: 'admin_order_tax_breakdown', methods: ['GET'])]
    public function taxBreakdownAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
    ): JsonResponse {
        $companyId = (int) $request->query->get('company_id', 0);
        $addressId = (int) $request->query->get('address_id', 0);
        $province = (string) $request->query->get('province', '');
        $linesRaw = (string) $request->query->get('lines', '[]');
        $shippingAmount = max(0.0, (float) $request->query->get('shipping', 0));

        $company = $entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            return $this->json(['lines' => [], 'total' => 0, 'perLineTax' => [], 'perLineTaxLabel' => []]);
        }

        $preview = $this->previewOrderFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
        // The browser sends the sum of the form's shipping rows rather than the rows themselves,
        // because every one of them is taxed at the same class — the document's highest — so one
        // preview row answers for all of them.
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
                fn(TaxLine $l) => ['label' => $l->label, 'rate' => $l->rate, 'amount' => $l->amount, 'slug' => $l->slug, 'source' => $l->source],
                $breakdown['lines']
            ),
            'total' => $breakdown['total'],
            'perLineTax' => $breakdown['perLineTax'],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'],
        ]);
    }

    #[Route('/order/create', name: 'admin_order_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, ShippingResolver $shippingResolver, FeeCalculatorResolver $feeResolver, PaymentMethodResolver $paymentMethodResolver, OrderTaxBreakdownService $taxBreakdownService, OrderNumberGenerator $orderNumberGenerator, CustomFieldRenderer $customFieldRenderer, CompanyFulfillmentRegionService $companyFulfillmentRegionService, TaxCalculatorResolver $taxResolver, AdminOrderStockValidator $stockValidator, StockOverrideRecorder $stockOverrides, DocumentActorResolver $actorResolver, BackorderSplitResolver $backorderSplits): Response
    {
        $company = $this->companyFromRequest($request, $entityManager, 'OrderSearch');

        if ($request->isMethod('POST')) {
            $postedCompany = $entityManager->find(Company::class, (int) $request->request->get('company_id'));
            // The no-JS "Add Line & Save" submit is a save like any other; it just names itself
            // rather than a save_mode, so it has to be recognised as one here too or it would be
            // mistaken for the company picker's post. The no-JS row delete is the same shape and
            // was not recognised (#236): it posts the whole form plus `remove_line`, so pressing ✕
            // on a new order was read as "the admin picked a company", and the entire form the
            // admin had filled in was thrown away for a redirect back to the picker.
            //
            // `lines` is here for the same reason: the company picker posts a company_id and
            // nothing else, so a post that carries order lines is a save whatever else it did or
            // did not state. Without it a save stating no save_mode — the case #236 is about — was
            // answered as a company pick and silently discarded.
            $isOrderSubmit = $request->request->has('save_mode')
                || $request->request->has('add_charge_line')
                || $request->request->has('remove_charge_line')
                || $request->request->has('remove_line')
                || $request->request->has('lines');

            if (!$isOrderSubmit && !($company instanceof Company) && $postedCompany instanceof Company) {
                return $this->redirectToRoute('admin_order_create', [
                    'OrderSearch' => ['company_id' => $postedCompany->getId()],
                ]);
            }

            if ($isOrderSubmit && $postedCompany instanceof Company) {
                $company = $postedCompany;
            }

            if (!$company instanceof Company) {
                $message = 'Please choose a company before saving the order.';
                $this->addFlash('error', $message);
                $productsRemote = $this->orderCatalogExceedsInlineLimit($entityManager);

                return $this->render('admin/order/form.html.twig', [
                    // A refused save repaints what was typed: the shared line row reads these instead of
                    // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
                    // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
                    // LineDenomination::boxUntouched(), so reformatting one here would let the next round
                    // trip rewrite a stored figure.
                    'submitted' => $this->submittedLinesForRerender($request),
                    'company' => null,
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                    'billingAddress' => null,
                    'shippingAddress' => null,
                    'addressBook' => [],
                    // No company chosen yet, so there's no company/region price list to resolve —
                    // product prices fall through to their default/original price.
                    'products' => $this->orderProductRows($entityManager, $companyFulfillmentRegionService, null, null, $productsRemote),
                    'productsRemote' => $productsRemote,
                    'locations' => $this->orderFulfillmentRegionRows($entityManager),
                    'fulfillmentRegions' => [],
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver),
                    'orderError' => $message,
                    'customFieldFragment' => $customFieldRenderer->renderFields('order', null, 'add'),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            // Same door check edit() runs, and for the same reason: nothing about the order is
            // built until the rows it will be built from are known to be lines.
            //
            // The region check joins them here and NOT in edit(). A company with no active
            // fulfillment region has no price list, so orderProductRows() falls through to the
            // product's default price — the order is priced off a list nobody negotiated, quietly
            // and without erroring. There is no correct price to give a new order, so it is refused.
            //
            // edit() deliberately does not refuse: an order already in flight must stay correctable
            // — a PO number, an invoice date, a note — even after the company's regions are
            // deactivated underneath it. Refusing there freezes shipped orders, which helps nobody.
            $activeRegions = $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company);

            // A new order has no stored region to fall back on, so the resolver is handed null and
            // its requirement bites here in full: the region has to be present, and has to be one
            // this company actually has.
            [$fulfillmentRegion, $regionError] = $this->resolveFulfillmentRegion($request, $activeRegions, null);

            $lineError = $this->errorForFulfillmentRegionAvailability($activeRegions, $company);
            // Validated against the rows that will actually be saved, not the rows that were
            // posted: a delete that empties the form has to be refused as an empty order rather
            // than pass validation on a row create() was about to drop.
            $lineError ??= $this->validateOrderLines($this->postedOrderLines($request))
                ?? SalesDocumentChargeLines::errorFor($this->postedChargeRows($request))
                ?? $regionError;

            // The shelf, and what the operator said about going past it (#326) — see edit(), which
            // states the reasoning in full. No existing order to add a hold back from: a new one
            // holds nothing yet, so the full availability applies. A create landing in Draft is not
            // measured at all, which is what lets an admin build an order before deciding what is
            // really in stock.
            $stockReasons = StockOverrideReasons::fromPostedLines($this->postedOrderLines($request));
            $stockShortfalls = [];
            if ($lineError === null) {
                $stockShortfalls = $stockValidator->shortfallsFor(
                    $this->postedOrderLines($request),
                    $this->newOrderStatusFor($this->saveModeFromRequest($request)),
                    $fulfillmentRegion,
                    null,
                    $entityManager,
                );
                $lineError = $stockOverrides->refusalFor($stockShortfalls, $stockReasons);
            }

            if ($lineError !== null) {
                $this->addFlash('error', $lineError);
                $productsRemote = $this->orderCatalogExceedsInlineLimit($entityManager);

                return $this->render('admin/order/form.html.twig', [
                    // A refused save repaints what was typed: the shared line row reads these instead of
                    // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
                    // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
                    // LineDenomination::boxUntouched(), so reformatting one here would let the next round
                    // trip rewrite a stored figure.
                    'submitted' => $this->submittedLinesForRerender($request),
                    'company' => $this->companyToRow($company),
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                    'billingAddress' => $this->addressToRow($this->defaultAddress($company, 'billing'), $company),
                    'shippingAddress' => $this->addressToRow($this->defaultAddress($company, 'shipping'), $company),
                    'addressBook' => $this->addressBookRows($company),
                    'products' => $this->orderProductRows($entityManager, $companyFulfillmentRegionService, $company, null, $productsRemote),
                    'productsRemote' => $productsRemote,
                    'locations' => $this->orderFulfillmentRegionRows($entityManager),
                    'fulfillmentRegions' => $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company),
                    'shippingOptions' => $this->buildShippingOptions($shippingResolver, null, $company),
                    'orderError' => $lineError,
                    'customFieldFragment' => $customFieldRenderer->renderFields('order', null, 'add'),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $lineWarnings = new SalesDocumentLineWarnings();
            ['order' => $order, 'subtotal' => $subtotal] = $this->createOrderFromRequest($request, $entityManager, $company, $shippingResolver, $orderNumberGenerator, $taxBreakdownService, $lineWarnings, $fulfillmentRegion);
            // Same guard edit() runs, on the same helper. It restores nothing here — the order was
            // minted a moment ago and has no earlier snapshot to protect — but this is the second of
            // the two save paths, and having them read the charge rows through one method is what
            // stops them diverging again the way create() and edit() just did.
            $normalizedCharges = $this->chargeRowsForSave($request, $order, $taxBreakdownService);

            // createOrderFromRequest() has already put the shipping rows on the order; the
            // calculated fees join them here, which is why this reads them back rather than
            // recomputing them from the post. The type=fee rows are not on the order yet — they are
            // built here so the calculators price against the same document edit() gives them.
            $feeLines = array_merge(
                $feeResolver->calculate(FeeContext::fromDocument($order)),
                $order->getShippingLines(),
                SalesDocumentChargeLines::toFeeLines($normalizedCharges),
            );
            $feeTotal = $this->sumFeeLines($feeLines);

            // Same as edit(): the type=tax rows posted with the form become manual tax lines on the
            // breakdown, so they reach the order's tax figure as lines instead of as a bare number.
            $taxBreakdown = $taxBreakdownService->withManualTaxLines(
                $taxBreakdownService->computeBreakdownFor($order, $feeLines),
                $taxBreakdownService->manualTaxLinesFromCharges($normalizedCharges),
            );
            // Same rounding policy edit() states: intermediates at SCALE, the cent applied once, at
            // the grand total. The total is built from the $subtotal the line loop accumulated
            // rather than from `(float) $order->getSubtotal()` — that read the decimal(12,2) column
            // back, so an order rounded its lines before summing them while the quote it converted
            // from did not, and the same basket landed a cent apart (#257).
            $tax = SalesDocumentMoney::intermediate($taxBreakdown['total']);

            $order
                ->setFeeLines(FeeLineSnapshot::encode($feeLines))
                ->setTaxLines($taxBreakdownService->toJson($taxBreakdown))
                ->setTax($this->decimal($tax))
                ->setTotal($this->decimal($subtotal + $tax + $feeTotal));

            $entityManager->persist($order);

            // A Save Order approves the order; a Save Draft leaves it a Draft. Through the one
            // gate, so the acceptance is validated and lands on the order's timeline, and so every
            // later status this order holds is derived from its invoices.
            if ($this->newOrderIsApproved($this->saveModeFromRequest($request))) {
                $order->setStatus('Approved', $actorResolver->resolve(), 'Order approved.');
            }

            // Which units ship and which are a promise (#548) — after the approval, because a
            // Save Draft has committed to none of them and its lines are re-split when it is
            // approved later. See edit(), which does the same thing at the same point in its save.
            $backorderSplits->applyToOrderLines($order, $order->getStatus(), $entityManager);

            // The decision to go beyond the shelf, recorded against the lines it was taken on
            // (#326). Same position edit() writes it in and the same position ReceivingService
            // writes its short-dated rows: after the lines exist, before the flush, so the document
            // and the record of why it looks like that are written together or not at all. Silent
            // when nothing was short, which is almost every save.
            $stockOverrides->recordForOrder(
                $order,
                $stockShortfalls,
                $stockReasons,
                $actorResolver->resolve()->displayName,
                $entityManager,
            );

            // No invoice is raised here, deliberately (#539). An admin-created order is the
            // manual-accounting case this feature exists for: it is invoiced later, possibly in
            // parts, through Convert to Invoice. Minting one here would leave the order Invoiced
            // the moment it was saved, with nothing left to convert — which is the whole feature.
            // Zoho Books behaves the same way: a sales order carries no invoice until you raise one.
            //
            // Stage 1 did raise one here, because the brief was written around the migration's
            // "every order has exactly one invoice" invariant rather than the workflow. That
            // invariant still holds for every order that existed at migration time and for every
            // ecom order, which is all anything actually depends on — and the deriver has always
            // handled an order with no invoices: that is Approved.

            $this->logOrderAction(
                $order,
                sprintf('Order %s created by %s.', $order->getOrderNumber(), $this->getUser()?->getUserIdentifier() ?? 'System'),
                'System',
                false,
                $entityManager
            );

            $entityManager->flush();

            $feeResolver->applyOrderSnapshots($order, $company->getId());
            $taxResolver->applyOrderSnapshots($order, $company->getId());
            $customFieldRenderer->saveFromRequest('order', $order, $request);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Order %s created successfully.', $order->getOrderNumber()));
            // Same as edit(): the order is saved either way, and a rewritten quantity travels with
            // the redirect so the page it lands on can say which line it happened to.
            $lineWarnings->flashOnto($request);

            // Same rule edit() applies: a mode that keeps working on the document — draft_recalc,
            // the no-JS charge submit, and a post that stated no mode at all (#236) — lands on the
            // new order's edit page rather than the list. The row the admin just deleted with the
            // no-JS ✕ is then visibly gone, on a form they can keep filling in.
            $saveMode = $this->saveModeFromRequest($request);
            if ($saveMode === 'draft_recalc' || $this->saveModePreservesStatus($saveMode)) {
                return $this->redirectToRoute('admin_order_edit', ['id' => $order->getId()]);
            }

            return $this->redirectToRoute('admin_order_index', [
                'OrderSearch' => ['company_id' => $company->getId()],
            ]);
        }

        $productsRemote = $this->orderCatalogExceedsInlineLimit($entityManager);

        return $this->render('admin/order/form.html.twig', [
            // A refused save repaints what was typed: the shared line row reads these instead of
            // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
            // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
            // LineDenomination::boxUntouched(), so reformatting one here would let the next round
            // trip rewrite a stored figure.
            'submitted' => $this->submittedLinesForRerender($request),
            'company' => $company instanceof Company ? $this->companyToRow($company) : null,
            'companies' => $this->companyRowsFromDatabase($entityManager),
            'billingAddress' => $company instanceof Company ? $this->addressToRow($this->defaultAddress($company, 'billing'), $company) : null,
            'shippingAddress' => $company instanceof Company ? $this->addressToRow($this->defaultAddress($company, 'shipping'), $company) : null,
            'addressBook' => $company instanceof Company ? $this->addressBookRows($company) : [],
            'products' => $this->orderProductRows($entityManager, $companyFulfillmentRegionService, $company instanceof Company ? $company : null, null, $productsRemote),
            'productsRemote' => $productsRemote,
            'locations' => $this->orderFulfillmentRegionRows($entityManager),
            'fulfillmentRegions' => $this->orderCompanyRegionRows($companyFulfillmentRegionService, $company instanceof Company ? $company : null),
            'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
            'paymentMethods' => $this->paymentMethodRowsFromDatabase($paymentMethodResolver, $company instanceof Company ? $company : null),
            'shippingOptions' => $company instanceof Company ? $this->buildShippingOptions($shippingResolver, null, $company) : [],
            'orderError' => null,
            'customFieldFragment' => $customFieldRenderer->renderFields('order', null, 'add'),
        ]);
    }

    /**
     * The customer a CREATE screen pre-fills from, when the query names one.
     *
     * Delegates to the shared scope rather than casting, which is what it used to do: `(int)` on
     * `12abc` answered 12, so a mangled link opened the new-order form against a customer nobody
     * named. A create screen genuinely has only two answers — there is a customer to raise the
     * order against, or the form asks for one — so this stays a nullable Company and the grid,
     * which has three, holds the CompanyListScope itself.
     */
    private function companyFromRequest(Request $request, EntityManagerInterface $entityManager, string $searchKey): ?Company
    {
        return $this->companyListScope($request, $entityManager, $searchKey)->company();
    }

    /**
     * The one thing a save is still refused for: a submission with no line in it at all. Nothing
     * about a line's NUMBERS is refused here any more.
     *
     * A quantity of 0 is legal — placeholder and soft-note rows are written that way, as they are
     * in Zoho — and so is a negative price, which the business uses deliberately. Both used to
     * come back as "Line %d: …" refusals that threw the admin's whole form away over a figure that
     * was never wrong. The one figure that IS wrong, a negative quantity, is not refused either:
     * it is saved as 0 and reported on the row it happened to (SalesDocumentLineWarnings), so the
     * document saves and the coercion is still impossible to miss.
     */
    /**
     * Whether a brand-new order is approved as it is created.
     *
     * A save mode that preserves status says "leave the status alone", and on a document that does
     * not exist yet the status to leave alone is the one nobody has finished writing. A post that
     * stated no save_mode at all lands in the same branch for the same reason (#236) — it used to
     * fall through to a live order, so a save nobody described minted one. Only an explicit Save
     * Order approves it.
     */
    private function newOrderIsApproved(string $saveMode): bool
    {
        return !str_starts_with($saveMode, 'draft') && !$this->saveModePreservesStatus($saveMode);
    }

    /**
     * The status a brand-new order will END UP in, for the stock check that runs before it exists.
     *
     * A prediction, not a value anything assigns: a new SalesOrder is a Draft and
     * `setStatus('Approved', ...)` is what moves it. Kept as a string because
     * AdminOrderStockValidator takes the status the save is heading for and asks
     * OrderInventoryBucketResolver whether it reserves.
     */
    private function newOrderStatusFor(string $saveMode): string
    {
        return $this->newOrderIsApproved($saveMode)
            ? SalesOrderStatus::Approved->value
            : SalesOrderStatus::Draft->value;
    }

    /**
     * Whether an edit save also approves the order, plus the flash owed to the admin when a demotion
     * was asked for and refused.
     *
     * Was targetStatusForEditSave() before #539 stage 2, when a save could name any status it liked.
     * It now answers the only status question an edit save is entitled to raise: a Save Order on a
     * Draft accepts it, and nothing else here moves an order at all — every later status is derived
     * from the invoice set, and Void is a deliberate act performed from the status control.
     *
     * Asked before the door checks run because the stock validation needs to know where the save is
     * heading: a draft reserves nothing and is never refused over stock, while anything that
     * reserves must fit (#326). Returning the flash instead of raising it keeps this free of side
     * effects, so a save that is subsequently refused for another reason does not also emit a
     * demotion notice about a save that never happened.
     *
     * @return array{0: bool, 1: string|null} [approves the order, refusal flash or null]
     */
    private function editSaveApprovesOrder(SalesOrder $order, string $saveMode): array
    {
        $status = $order->getStatus();

        if ($this->saveModePreservesStatus($saveMode)) {
            // Adding a charge row is not a statement about the document's status, and neither is a
            // post that named no save_mode at all (#236). Without this the no-JS "Add Line & Save"
            // would drag a live order back to Draft, and the no-JS row delete — the one control that
            // posts no mode — would approve the Draft it deleted a row from. Whatever the order was,
            // it stays.
            return [false, null];
        }

        if (str_starts_with($saveMode, 'draft')) {
            // A draft save can only ever confirm a draft. There is no longer any way to demote a
            // live order back to Draft at all — approving is one-way and the derived statuses are
            // computed, not assigned — so the refusal that used to protect against a silent
            // demotion (#264) is now what tells the admin why the mode was ignored.
            //
            // The template no longer renders the draft buttons on a non-draft order, but a stale
            // form or a scripted POST can still carry the mode, so the refusal lives here rather
            // than in the one place a browser is trusted to obey.
            if (strtolower($status) !== 'draft') {
                return [false, sprintf(
                    'Order %s is %s, so it was saved without being moved back to Draft. An order cannot be returned to Draft once it has been approved.',
                    $order->getOrderNumber(),
                    $order->getStatus(),
                )];
            }

            return [false, null];
        }

        return [strtolower($status) === 'draft', null];
    }

    /**
     * Ported to a symfony/validator constraint for #306, following ValidChargeRows' #317 pattern:
     * ValidOrderLinesValidator runs the same rule this method used to run by hand, so a future fix
     * is inherited by both create() and edit() without either changing anything.
     */
    private function validateOrderLines(array $lines): ?string
    {
        $violations = Validation::createValidator()->validate($lines, new ValidOrderLines());

        return count($violations) > 0 ? (string) $violations[0]->getMessage() : null;
    }

    /**
     * create()'s door check for a company with no active fulfillment region — see the comment
     * beside its call site for why edit() never runs this. Ported to a symfony/validator constraint
     * for #306, following ValidFulfillmentRegionActivation's #317 pattern.
     */
    private function errorForFulfillmentRegionAvailability(array $activeRegions, Company $company): ?string
    {
        $violations = Validation::createValidator()->validate(
            $activeRegions !== [],
            new ValidFulfillmentRegionAvailability($company->getName()),
        );

        return count($violations) > 0 ? (string) $violations[0]->getMessage() : null;
    }

    /**
     * $lineWarnings collects what the save had to rewrite on the way in — the create path's half of
     * the coercion edit() records inline. It is passed in rather than returned because this method
     * already returns more than one thing.
     *
     * The unrounded subtotal comes back beside the order because create() finishes the grand total
     * and must add the same figure this loop accumulated, not the cent-rounded one the decimal(12,2)
     * subtotal column stores. Reading it back off the order was the order half of #257 — it is what
     * put an order a cent away from the quote it converted from. Same {count/subtotal} shape
     * EstimateController::applyLinesFromRequest() returns, for the same reason.
     *
     * @return array{order: SalesOrder, subtotal: float}
     */
    private function createOrderFromRequest(Request $request, EntityManagerInterface $entityManager, Company $company, ShippingResolver $shippingResolver, OrderNumberGenerator $orderNumberGenerator, OrderTaxBreakdownService $taxBreakdownService, SalesDocumentLineWarnings $lineWarnings, ?string $fulfillmentRegion): array
    {
        $order = new SalesOrder();

        $order
            ->setCompany($company)
            ->setOrderNumber($orderNumberGenerator->next($entityManager))
            // No status assignment: every order is born a Draft, and create() approves it after
            // persisting when the save mode says so (#539 stage 2). A brand-new order built by the
            // no-JS Add Line submit therefore stays Draft — 'recalc' says "leave the status alone",
            // and on a document that does not exist yet the status to leave alone is the one nobody
            // has finished writing. A post that stated no save_mode at all lands in the same branch
            // for the same reason (#236) — it used to fall through to a live order, so a save nobody
            // described minted one. Only an explicit Save Order approves it.
            ->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80))
            ->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')))
            // Resolved, not read raw. create() used to take whatever string arrived — so the value
            // check and the requirement that edit() enforces were both absent on the path that
            // MINTS the order, which is the one place a wrong region has no earlier correct value
            // to fall back to. create()'s caller refuses the save before reaching here if the
            // resolver objected.
            ->setFulfillmentRegion($fulfillmentRegion)
            // See edit(): payment status is derived on the invoice and is not posted from here.
            ->setPaymentMethod($this->nullableString($request->request->get('payment_method')))
            ->setPaymentTerm($this->nullableString($request->request->get('payment_term')))
            // user_name is the CUSTOMER USER who initiated the document, and nobody initiated this
            // one — an admin typed it in. It used to be filled with the company's contact person,
            // who did not ask for the order and may never have heard of it, while the quote side
            // filled the same column with the staff member's name and email. Left null so the "User"
            // column means one thing on both document types; the admin who created it is recorded
            // in the order's own log and in audit_log, which is where it belongs (#269).
            ->setUserName(null)
            ->setBillingName($this->fullName(
                $request->request->get('billing_first_name'),
                $request->request->get('billing_last_name'),
                $request->request->get('billing_company_name')
            ))
            ->setShippingName($this->fullName(
                $request->request->get('shipping_first_name'),
                $request->request->get('shipping_last_name'),
                $request->request->get('shipping_company_name')
            ))
            ->setShippingCompanyName($this->nullableString($request->request->get('shipping_company_name')));

        // setCompany() above froze the company's identity onto the order; anything the admin typed
        // over it in the Order Info card belongs on that snapshot, not on the Company row. Same
        // call edit() makes, so a detail corrected while creating an order is kept rather than
        // silently discarded.
        $this->applyOrderCompanyCard($order, $request);

        // Left unset when the admin didn't pick one, so SalesDocumentDateStamp dates the order
        // today in the display timezone at persist time.
        $documentDate = TextInput::calendarDate($request->request->get('document_date'));
        if ($documentDate !== null) {
            $order->setDocumentDate($documentDate);
        }

        // The same two calls edit() makes, for the two reasons create() needed both (#282).
        //
        // create() used to copy the chosen address-book entry and stop, which threw away every
        // field the admin had typed into the address card — the create screen renders the SAME
        // editable card the edit screen does, so a suite number or a phone corrected while raising
        // the order simply did not stick, and copyFrom() overwrote the name fields with it. It also
        // checked only that the posted id resolved to SOME CompanyAddress: any id belonging to any
        // company would be copied onto the new order, so a posted number was enough to read another
        // customer's name, street, phone and both email addresses out through this form.
        //
        // applyOrderAddressFromRequest() carries the company-ownership check and then applies the
        // posted fields on top, so routing create() through it answers both.
        $this->applyOrderAddressFromRequest($order, $entityManager, $company, $request, 'billing');
        $this->applyOrderAddressFromRequest($order, $entityManager, $company, $request, 'shipping');

        $subtotal = 0.0;
        $rowIndex = 0;
        foreach ($this->postedOrderLines($request) as $line) {
            if (!is_array($line) || $this->isBlankOrderLine($line)) {
                continue;
            }

            $product = null;
            $productId = $this->lineProductId($line);
            if ($productId > 0) {
                $foundProduct = $entityManager->find(ProductCore::class, $productId);
                $product = $foundProduct instanceof ProductCore ? $foundProduct : null;
            }

            $defaults = $this->productLineDefaults($product, $entityManager);
            // The unit this row was said in (#659). Null — none picked, the base unit picked, or
            // one this product is not available in — means the product's base unit, which is what
            // every line said before this issue and what every no-JS post naming none still says.
            $lineUnit = $this->lineUnitFor($line['unit_id'] ?? null, $product, $entityManager);
            $baseUnit = LineDenomination::baseUnitOf($product);
            // Same three rules edit() applies, for the same reasons: a negative quantity becomes 0
            // and says so, a price is saved exactly as typed however negative it is, and a typed
            // price that is not a number becomes 0 and says so. There is no stored figure to fall
            // back on here — this order does not exist yet — so every box is read as typed, which
            // is also why create() needs no re-expression branch: nothing has been rendered to
            // re-express.
            $enteredQty = $lineWarnings->quantityForRow((float) ($line['qty'] ?? 0), $rowIndex);
            $qty = $enteredQty * LineDenomination::factorToBase($lineUnit, $baseUnit);
            $priceRaw = $this->rawLineAmount($line['price'] ?? null);
            // The box asks in the selected unit and the column stores per base unit, so the typed
            // figure is divided on the way in. A blank box still means "take the catalog's", and
            // that figure is ALREADY per base unit — the price grid has no unit column because the
            // product's base unit is what "per unit" means (#601) — so it is not divided.
            $price = $priceRaw !== ''
                ? LineDenomination::toBasePrice((string) $lineWarnings->priceForRow($priceRaw, $rowIndex), $lineUnit, $baseUnit)
                : (string) $defaults['price'];
            $lineSubtotal = SalesDocumentMoney::intermediate($qty * (float) $price);
            $subtotal = SalesDocumentMoney::intermediate($subtotal + $lineSubtotal);
            $taxCode = $this->nullableString($line['tax_code'] ?? null) ?? $defaults['taxCode'];

            $costRaw = $this->rawLineAmount($line['cost'] ?? null);
            $cost = $costRaw !== '' ? (float) $costRaw : $defaults['cost'];

            $orderLine = new SalesOrderLine();
            // See edit(): one call owns the entered figure, the unit and the base figure it
            // resolves to (#659).
            $this->applyLineQuantity($orderLine, $enteredQty, $qty, $lineUnit, $baseUnit);

            $orderLine
                ->setProduct($product)
                ->setName($this->lineName($line, $product))
                // See edit(): every line here is new by definition, so a blank box always falls
                // back to the document's own fulfillment region.
                ->setLocation($this->nullableString($line['location'] ?? null) ?? $this->nullableString($fulfillmentRegion))
                ->setSku($this->nullableString($line['sku'] ?? null) ?? $product?->getSku())
                ->setWeight($this->nullableString($line['weight'] ?? null) ?? $defaults['weight'])
                ->setUnit($this->nullableString($line['unit'] ?? null) ?? $defaults['unit'])
                ->setTaxCode($taxCode)
                ->setCost($this->decimal($cost))
                ->setPrice($this->lineRate($price, $lineUnit))
                ->setSubtotal($this->decimal($lineSubtotal))
                ->setBatch($this->nullableString($line['batch'] ?? null))
                // The lot/serial picker's posted choice (2026-09-14 lot/serial/expiry plan) — feeds
                // SalesOrderReservationSubject::heldLines() through the reconciler.
                ->setLotId($this->nullableLotId($line['lot_id'] ?? null))
                ->setSerial($this->nullableString($line['serial'] ?? null))
                // Same as edit()'s rebuild: the ETA is the admin's, the split is not (#548).
                ->setRestockEta($this->lineRestockEta($line))
                // Same as edit()'s rebuild: the row states where it sits.
                ->setSortOrder($rowIndex);

            $order->addLine($orderLine);
            $rowIndex++;
        }

        // Stated before the shipping recalculation reads the order, for the same reason edit() does.
        $order->setSubtotal($this->decimal($subtotal));

        $normalizedCharges = $this->chargeRowsForSave($request, $order, $taxBreakdownService);
        $normalizedCharges = $this->recalculateShippingCharge($normalizedCharges, $shippingResolver, $order);
        $order->setShippingMethod($this->deriveShippingMethodFromCharges($normalizedCharges));

        // The shipping rows go on now so the order is complete when create() asks it for fees and
        // tax; create() then rewrites fee_lines with the calculated fees merged in. Tax and total
        // are left at zero here for the same reason — create() states both a moment later.
        $order
            ->setFeeLines(FeeLineSnapshot::encode(
                SalesDocumentChargeLines::toShippingLines($normalizedCharges, $order->getHighestTaxClass()),
            ))
            ->setTax($this->decimal(0.0))
            ->setTotal($this->decimal($subtotal + ($order->getShippingTotal() ?? 0.0)));

        return ['order' => $order, 'subtotal' => $subtotal];
    }

    /** @return list<array<string, string>> */
    private function lineName(array $line, ?ProductCore $product): string
    {
        return $this->nullableString($line['name'] ?? null) ?? $product?->getName() ?? 'Custom line';
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** @return array{id:string,number:string,status:string,poNumber:?string,documentDate:?string,shipping:string,specialInstructions:?string,paymentStatus:string,paymentMethod:?string,paymentTerm:?string,chargeLines:?string} */
    private function orderToEditRow(SalesOrder $order, OrderTaxBreakdownService $taxBreakdownService, OrderPaymentRollup $paymentRollup): array
    {
        return [
            'id' => (string) $order->getId(),
            'number' => $order->getOrderNumber(),
            // Round-tripped through a hidden field on the edit form (#417) — see the version
            // check at the top of edit()'s POST handling for what this protects.
            'version' => $order->getVersion(),
            'status' => $order->getStatus(),
            'poNumber' => $order->getPoNumber(),
            'documentDate' => $order->getDocumentDate(),
            'subtotal' => $order->getSubtotal(),
            'shipping' => $this->decimal($order->getShippingTotal() ?? 0.0),
            'tax' => $order->getTax(),
            'total' => $order->getTotal(),
            'specialInstructions' => $order->getSpecialInstructions(),
            'fulfillmentRegion' => $order->getFulfillmentRegion(),
            // Rolled up from this order's invoices and rendered read-only: the form no longer posts
            // a payment status, because it is derived from the payment rows on each invoice.
            'paymentStatus' => $paymentRollup->forOrder($order)->value,
            'paymentMethod' => $order->getPaymentMethod(),
            'paymentTerm' => $order->getPaymentTerm(),
            // The order's OWN contact details, so the Order Info card shows what this order holds
            // rather than what the Company row holds today. The card writes back to this snapshot;
            // rendering the live record instead would let an admin overwrite the details the order
            // was placed under just by opening the page and saving.
            'companyIdentity' => [
                'email' => $order->getCompanyIdentity()->getEmail(),
                'phone' => $order->getCompanyIdentity()->getPhone(),
            ],
            'chargeLines' => $this->orderChargeRowsJson($order, $taxBreakdownService),
            'feeLines' => $order->getFeeLines(),
            'shippingMethod' => $order->getShippingMethod(),
        ];
    }

    /**
     * The form edits shipping, ad-hoc tax and ad-hoc fees as one list of charge rows, and a save
     * rebuilds the order from whatever it posts back. So a saved order's shipping, manual tax and
     * manual fee lines have to be handed to the form as rows — otherwise the first re-save of an
     * untouched order would quietly drop whatever the form was never shown.
     *
     * The manual fee rows are the sharpest case: fee_lines is rebuilt from the calculators on every
     * save, and no calculator has ever heard of them, so they exist afterwards only because they
     * made this round trip.
     */
    private function orderChargeRowsJson(SalesOrder $order, OrderTaxBreakdownService $taxBreakdownService): ?string
    {
        $charges = $this->storedChargeRows($order, $taxBreakdownService);

        return $charges === [] ? null : json_encode($charges, JSON_UNESCAPED_UNICODE);
    }

    private function deriveShippingMethodFromCharges(array $charges): ?string
    {
        return SalesDocumentChargeLines::deriveShippingMethod($charges);
    }

    /**
     * A named shipping method's amount is never trusted from the browser — it's recomputed
     * fresh from the final cart/province every time the order is saved, exactly like checkout.
     * "Custom Shipping" (or anything not matching the "Shipping (Method Name)" label format)
     * is a manual override and is left exactly as submitted.
     *
     * @param list<array{label:string,amount:float,type:string}> $charges
     * @return list<array{label:string,amount:float,type:string}>
     */
    private function recalculateShippingCharge(
        array $charges,
        ShippingResolver $shippingResolver,
        SalesOrder $order,
    ): array {
        foreach ($charges as $i => $charge) {
            if (($charge['type'] ?? '') !== FeeLine::TYPE_SHIPPING) {
                continue;
            }

            $label = (string) ($charge['label'] ?? '');
            if (!preg_match('/^Shipping \((.+)\)$/', $label, $m)) {
                continue;
            }

            foreach ($shippingResolver->getAvailableOptions($order) as $option) {
                if ($option->label === $m[1]) {
                    $charges[$i]['amount'] = $option->amount;
                    break;
                }
            }
        }

        return $charges;
    }

    /**
     * The order form's three preview endpoints (shipping options, fee lines, tax breakdown) ask
     * about lines that have not been saved and may never be, so there is no document to read —
     * which is what made them the last hand-built calculator inputs. This builds the document the
     * form is describing, so they stop being an exception.
     *
     * THIS ORDER MUST NEVER BE PERSISTED. FeeRepository::ensureBySlug() and
     * CustomFieldDefinitionRepository::ensureBySlug() both flush() partway through a calculation,
     * and SalesOrder's lines and addresses carry cascade: ['persist'], so a single persist() here
     * would write a whole order to the database from a GET request. What makes it safe is invisible
     * in the code below: Doctrine ignores objects it does not manage when it flushes, and nothing
     * ever hands this one to the entity manager.
     *
     * The header money is deliberately left unset. Two of the three payloads carry no prices at all,
     * and a fee priced off order value has always seen zero from these endpoints; inventing a
     * subtotal here would change what the preview shows.
     */
    private function previewOrderFromRequest(
        EntityManagerInterface $entityManager,
        Company $company,
        string $province,
        int $addressId,
        string $linesJson,
    ): SalesOrder {
        $order = (new SalesOrder())->setCompany($company);

        // The form's province wins over the book's: the admin may be quoting to somewhere the
        // selected address does not cover, and that is what the preview is for. The book row still
        // rides along as the source link, since that is the id a shipping rule matches on.
        $address = $order->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING);
        $bookAddress = $addressId > 0 ? $entityManager->find(CompanyAddress::class, $addressId) : null;
        // Ownership check, matching applyOrderAddressFromRequest() and
        // EstimateController::applyEstimateAddressCard(): stale form state (the admin switches
        // company while an old address_id is still in play) must not quote shipping/tax against a
        // different company's address. See #284.
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

            // Every posted row becomes a line, including the ones with no product: the tax
            // breakdown's per-line figures are returned by position and the browser maps them back
            // onto the form's rows, so dropping a row here would shift every figure after it.
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product instanceof ProductCore ? $product : null)
                    // Quantity is floored the same way a save floors it — a negative one is not a
                    // quantity. The subtotal is NOT: a line priced negative has a negative
                    // subtotal, and flooring it here previewed tax the save would never produce.
                    ->setQuantity($this->decimal(max(0.0, (float) ($row['qty'] ?? 0))))
                    ->setSubtotal($this->decimal((float) ($row['subtotal'] ?? 0)))
                    // #full-parity, 2026-09-13: a present-but-EMPTY tax_code (the ordinary case for
                    // a line that never overrode the product's own default) used to read as
                    // "explicitly no tax" here, silently zeroing the live preview's tax figure for
                    // any line relying on its product's default — nullableString() is the same
                    // empty-means-absent fallback the real save path already uses
                    // (EstimateController::applyLinesFromRequest()), just never applied here.
                    ->setTaxCode($this->nullableString($row['tax_code'] ?? null)
                        ?? ($product instanceof ProductCore ? $product->getSalesTaxCode() : null))
            );
        }

        return $order;
    }

    /**
     * Safe to call unchecked only because every save path has already run
     * SalesDocumentChargeLines::errorFor() at the door: normalize() throws on a type it does not
     * recognise rather than filing the row somewhere.
     *
     * @param mixed $chargesRaw
     * @return list<array{label:string,amount:float,type:string,slug?:string,taxClass?:string,placement?:string}>
     */
    private function normalizeChargeLines(mixed $chargesRaw): array
    {
        return SalesDocumentChargeLines::normalize($chargesRaw);
    }

    /**
     * The charge rows a save should rebuild the order's shipping, manual tax and manual fee lines
     * from.
     *
     * A post that never rendered the charge UI at all (a bare API or no-JS post of just the lines)
     * must not wipe the charges already stored on the order. The form rebuilds the order from what
     * it posts back, so "no charge_lines arrived" is ambiguous: it means either "the admin removed
     * every charge row" or "this post was never shown them". `charge_lines_present` is what tells
     * the two apart — the forms render it beside the Add Line control, so only a post that had the
     * charge UI in front of it carries the marker, and only that post is allowed to clear charges.
     *
     * Without this the very first save of an untouched order destroyed every shipping row, tax
     * adjustment and manual fee line on it and silently changed the total. Quotes have had the
     * protection since manual fee lines landed (EstimateController::applyChargeLinesFromRequest());
     * orders had nothing, and the two save paths are the same money path.
     *
     * The rows the guard restores are the same ones orderChargeRowsJson() hands the form for the
     * round trip, which is the reconstruction this controller already relies on for an untouched
     * save to be a no-op.
     *
     * @return list<array{label:string,amount:float,type:string,slug?:string,taxClass?:string,placement?:string}>
     */
    private function chargeRowsForSave(Request $request, SalesOrder $order, OrderTaxBreakdownService $taxBreakdownService): array
    {
        $charges = $this->normalizeChargeLines($this->postedChargeRows($request));

        if (!$request->request->has('charge_lines_present') && $charges === []) {
            return $this->storedChargeRows($order, $taxBreakdownService);
        }

        return $charges;
    }

    /**
     * Applies the Order Info card's Primary Email / Company Phone onto the order's OWN frozen
     * company identity — never onto the live Company row.
     *
     * This used to be `$company->setPrimaryEmail(...)->setPhoneNumber(...)`, which is exactly the
     * drift CompanyIdentity exists to prevent. Two failures, both silent:
     *
     * A POST that omitted the fields nulled the customer's email and phone COMPANY-WIDE, from an
     * order edit. Every other document reading through the live record lost them too, and so did
     * the customer's own account.
     *
     * And correcting a typo while editing one order rewrote the customer's record, so every other
     * order — including ones already invoiced under the old details — silently changed what it
     * printed. A document is a record of what was agreed; the address cards already resolved this
     * the same way, and quotes have done it since CompanyIdentity landed.
     *
     * The fields stay editable, because they are a textbox for typing into. What they write is this
     * order's snapshot.
     *
     * A no-op when neither field was submitted, so a bare or no-JS POST that never rendered the
     * card leaves the snapshot setCompany() froze alone. Same guard, same reason, as
     * EstimateController::applyEstimateCompanyCard().
     */
    private function applyOrderCompanyCard(SalesOrder $order, Request $request): void
    {
        $hasEmail = $request->request->has('primary_email');
        $hasPhone = $request->request->has('company_phone');
        if (!$hasEmail && !$hasPhone) {
            return;
        }

        $snapshot = $order->getCompanyIdentity()->toArray();
        if ($hasEmail) {
            $snapshot['email'] = $this->nullableString($request->request->get('primary_email'));
        }
        if ($hasPhone) {
            $snapshot['phone'] = $this->nullableString($request->request->get('company_phone'));
        }

        $order->setCompanySnapshot($snapshot);
    }

    /**
     * The raw charge rows this post carries, adjusted for whichever no-JS charge button was pressed.
     *
     * Both buttons are real saves — one appends a row, one drops a row, each saving and
     * recalculating in a single POST — so the adjustment has to happen before anything reads the
     * rows, including the errorFor() check at the door.
     *
     * @return array<int|string, mixed>
     */
    private function postedChargeRows(Request $request): array
    {
        $rows = $request->request->all('charge_lines');

        if ($request->request->has('remove_charge_line')) {
            return SalesDocumentChargeLines::withoutRemovedRow(
                $rows,
                (string) $request->request->get('remove_charge_line', ''),
            );
        }

        if (!$request->request->has('add_charge_line')) {
            return $rows;
        }

        $added = SalesDocumentChargeLines::rowFromAddLineChoice((string) $request->request->get('charge_line_type', ''));
        if ($added === null) {
            return $rows;
        }

        // Only one row can be "the" order's shipping, so a second named shipping choice replaces the
        // first rather than stacking beside it — what .js-order-bottom-add does in the browser.
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
     * The order's stored shipping, manual tax and manual fee lines read back as charge rows.
     *
     * @return list<array{label:string,amount:float,type:string,slug?:string,taxClass?:string,placement?:string}>
     */
    private function storedChargeRows(SalesOrder $order, OrderTaxBreakdownService $taxBreakdownService): array
    {
        return array_merge(
            SalesDocumentChargeLines::fromShippingLines($order->getShippingLines()),
            $taxBreakdownService->manualTaxChargeRows($order->getTaxLines()),
            SalesDocumentChargeLines::fromFeeLines($order->getFeeLineRows()),
        );
    }

    /**
     * The line rows this post wants saved: everything under `lines`, minus the row the no-JS delete
     * named in `remove_line`.
     *
     * One method because both save paths need the same answer. edit() has always dropped the named
     * row; create() read `lines` raw and never looked at `remove_line` at all (#236), so pressing
     * the ✕ on a not-yet-saved order saved the very row it was pressed to delete. Reading the rows
     * through here is what stops the two paths from drifting apart again.
     *
     * @return array<int|string, mixed>
     */
    private function postedOrderLines(Request $request): array
    {
        $lines = $request->request->all('lines');

        $removeLineIndex = $request->request->get('remove_line');
        if ($removeLineIndex !== null && $removeLineIndex !== '') {
            unset($lines[(int) $removeLineIndex]);
        }

        return $lines;
    }

    /**
     * The save mode this post asks for, or '' when it asked for nothing.
     *
     * The no-JS "Add Line & Save" submit carries no save_mode of its own. It must NOT be answered
     * for as `draft_recalc`: on an existing order that string would take the `str_starts_with(...,
     * 'draft')` branch in edit() and demote a Pending — or Confirmed, or Shipped — order to Draft
     * for the crime of adding a shipping row. Adding a charge line says nothing about what the
     * document's status should become.
     *
     * So it reports `recalc`: recalculate and land back on the edit page, leave the status alone.
     * On create() there is no status to leave alone and the order is minted Draft, which is what a
     * document nobody has finished writing should be.
     *
     * The no-JS row delete is the third such submit and is named here too (#265). It posts the whole
     * form plus `remove_line` and no mode of its own, so it used to fall through to the default —
     * which was 'order', meaning deleting a line from a Draft promoted it to a live Pending order
     * and bounced the admin to the read-only detail page. #236 made an absent mode preserve the
     * status, which fixed the symptom; saying so explicitly here is what stops the fix from resting
     * on a default. Removing a row is a manipulation of the form, exactly like adding a charge row.
     *
     * Anything else reports what the post stated — and '' when it stated nothing. That default used
     * to be 'order' (#236), which made a post carrying no save_mode indistinguishable from pressing
     * Save Order. An absent mode is not a statement of intent. It is now its own case and preserves
     * the status, exactly as `recalc` does. The explicit buttons still post draft_recalc /
     * draft_exit / recalc / order and still mean precisely what they always meant.
     */
    private function saveModeFromRequest(Request $request): string
    {
        if (
            $request->request->has('add_charge_line')
            || $request->request->has('remove_charge_line')
            || $request->request->has('remove_line')
        ) {
            return 'recalc';
        }

        return (string) $request->request->get('save_mode', '');
    }

    /**
     * Whether this post should leave the order's status exactly as it found it.
     *
     * Two modes qualify, for one reason: neither of them says anything about status. 'recalc' is
     * the no-JS charge-line submit, the no-JS row delete (#265), and the "Save & Recalc" button a
     * non-draft order shows in place of the draft buttons (#264) — all three save and recalculate
     * and none of them is a statement about the document's status. '' is a post that named no
     * save_mode at all (#236) — a script, a form fragment. Status is changed by the buttons that
     * name a status and by the status endpoint, never as a side effect of a save that never
     * mentioned it.
     */
    private function saveModePreservesStatus(string $saveMode): bool
    {
        return $saveMode === 'recalc' || $saveMode === '';
    }

    /**
     * The fee rows' contribution to the grand total. An intermediate like any other, so it is
     * carried at SalesDocumentMoney::SCALE and not snapped to the cent on the way past (#257).
     *
     * @param FeeLine[] $lines
     */
    private function sumFeeLines(array $lines): float
    {
        return SalesDocumentMoney::intermediate((float) array_sum(array_map(fn(FeeLine $l) => $l->amount, $lines)));
    }

    /** @return list<array<string, string|null>> */
    private function orderLinesToRows(SalesOrder $order, ?AdminOrderStockValidator $stockValidator = null, ?EntityManagerInterface $entityManager = null, ?WarehouseFulfillmentRegionService $warehouses = null): array
    {
        // Resolved once for the whole table rather than per line: every row asks the same question
        // of the same handful of regions. A line names a region and stock lives in a warehouse, so
        // what the map holds is the warehouse serving each region (#546).
        $warehousesByLowerRegionName = [];
        if ($stockValidator instanceof AdminOrderStockValidator && $entityManager instanceof EntityManagerInterface && $warehouses instanceof WarehouseFulfillmentRegionService) {
            $warehousesByLowerRegionName = $warehouses->warehousesByLowerRegionName();
        }

        // Every row's available units, in one query for the whole document rather than one per row
        // (#659). A twenty-line order asking per row is twenty queries to render a dropdown.
        $unitsByProductId = $this->unitChoicesForLines($order->getLines(), $entityManager);

        $rows = [];
        foreach ($order->getLines() as $line) {
            if (!$line instanceof SalesOrderLine) {
                continue;
            }

            // What this line could be raised to without overselling — the ceiling the save now
            // enforces (#326), shown beside the quantity box so an admin can see it while typing
            // rather than discovering it on a refused save. Null where there is nothing meaningful
            // to state: a blank line with no product, or a region that resolves to no warehouse.
            $available = null;
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $warehousesByLowerRegionName !== []) {
                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                    $line->getLocation(),
                    $order->getFulfillmentRegion(),
                    $warehousesByLowerRegionName,
                );
                if ($warehouse instanceof Warehouse) {
                    // The order's own hold is added back, so an order already Pending for 5 shows
                    // the 5 it is holding as available to itself rather than as spoken for.
                    $available = $stockValidator->availableFor($product, $warehouse, $order, $entityManager);
                }
            }

            $rows[] = [
                'available' => $available,
                // The backorder figures are read off the line, never recomputed for display (#548):
                // what the row shows is what the last save decided, which is also what the buckets
                // hold. Zero and 'Fulfilled' on every line of every SKU nobody has opted in.
                'backordered' => $line->getBackorderedUnits(),
                'fulfillmentStatus' => $line->getFulfillmentStatus(),
                'restockEta' => $line->getRestockEta()?->format('Y-m-d') ?? '',
                // Rendered as a hidden field on the row so the next save can match the row back to
                // this line and update it in place instead of replacing it (#267).
                'id' => $line->getId() !== null ? (string) $line->getId() : '',
                'type' => $line->getProduct() !== null ? 'product' : 'blank',
                'productId' => $line->getProduct()?->getId() !== null ? (string) $line->getProduct()?->getId() : '',
                'name' => $line->getName(),
                'location' => $line->getLocation(),
                'sku' => $line->getSku(),
                // The quantity box asks in the line's OWN unit, so it renders what the human said —
                // 40, not 480. A line entered in base units answers its base figure, which is what
                // this key has always held.
                'qty' => $line->getQuantityEntered(),
                // Posted straight back so the next save can tell an untouched box from a retyped
                // one. See LineDenomination::boxUntouched(): without it, switching Case to Pallet
                // and pressing save multiplies the order twenty-fold without anybody typing a digit.
                'qtyRendered' => $line->getQuantityEntered(),
                'weight' => $line->getWeight(),
                'unit' => $line->getUnit(),
                // The units this row may be denominated in, and which one it currently names. An
                // empty list is legitimate and common — blank is allowed (#659), and some items do
                // not participate — and the U/M cell then offers the base unit alone, which is the
                // whole of what this product can be counted in. It never offers a text box on a
                // product row: a PRODUCT declares its unit, so typing one over it invents a fact.
                'lineUnitId' => (string) ($line->getUnitOfMeasure()?->getId() ?? ''),
                'lineUnits' => $unitsByProductId[(int) ($product?->getId() ?? 0)] ?? [],
                'unitLabel' => $line->getDisplayUnitLabel(),
                'baseUnitLabel' => $line->getBaseUnitLabel(),
                // The two figures the conversion resolves to, shown beside the boxes that produced
                // them so the rounding happens in front of a person rather than on the invoice
                // later (#601). Both derived at render; neither is stored in this shape.
                'baseQuantity' => $line->getQuantity(),
                'baseUnitPrice' => $line->getBaseUnitRate(),
                'resolvedLineTotal' => $line->getLineTotal(),
                'taxCode' => $line->getTaxCode(),
                'cost' => $line->getCost(),
                'originalPrice' => $line->getProduct()?->getOriginalPrice() ?? '',
                // Per the unit the selector names — `$0.500000/EA` reads as `$6.00` per Case. A line
                // in base units answers the stored figure untouched, exactly as before.
                'price' => $line->getDisplayUnitPrice(),
                'priceRendered' => $line->getDisplayUnitPrice(),
                'subtotal' => $line->getSubtotal(),
                'batch' => $line->getBatch(),
                'tracksBatch' => $this->productTracksBatch($product),
                // 2026-09-14 lot/serial/expiry plan: the line's own picked lot/serial, and which
                // capture UI to render for it.
                'trackingMode' => $this->productTrackingMode($product),
                'lotId' => $line->getLotId(),
                'serial' => $line->getSerial(),
            ];
        }

        return $rows;
    }

    /**
     * The available units of every product on this order, keyed by product id (#659).
     *
     * The lookup itself is shared with the quote form; this only collects the product ids off the
     * order's own lines. Null entity manager is the create page, where there is no document to read
     * units for yet.
     *
     * @param iterable<mixed> $lines
     *
     * @return array<int, list<array{id: int, code: string}>>
     */
    private function unitChoicesForLines(iterable $lines, ?EntityManagerInterface $entityManager): array
    {
        if (!$entityManager instanceof EntityManagerInterface) {
            return [];
        }

        $productIds = [];
        foreach ($lines as $line) {
            $productId = $line instanceof SalesOrderLine ? $line->getProduct()?->getId() : null;
            if ($productId !== null) {
                $productIds[] = (int) $productId;
            }
        }

        return $this->lineUnitChoicesFor($productIds, $entityManager);
    }

    /**
     * True when a submitted line has neither a product selected nor a free-text name — a no-JS
     * fallback row left untouched. Delegates to ValidOrderLinesValidator, the single source of
     * truth since #306.
     */
    private function isBlankOrderLine(array $line): bool
    {
        return ValidOrderLinesValidator::isBlankLine($line);
    }

    /**
     * Why this save may not be applied, when it would delete a line an invoice bills (item 63).
     *
     * A posted row keeps its line by naming its id; a line no id in the post names is the delete —
     * the same matching the save loop does, and blank rows are skipped here for the same reason
     * they are skipped there, so the two cannot disagree about which lines this save keeps.
     *
     * The refusal SENTENCE comes from SalesOrder::lineDeletionRefusal(), not from here, so the
     * screen and the entity say the same thing in the same words.
     *
     * @param array<int|string, mixed> $rawLines
     */
    private function invoicedLineDeletionRefusal(SalesOrder $order, array $rawLines): ?string
    {
        /** @var array<int, true> $keptLineIds */
        $keptLineIds = [];
        foreach ($rawLines as $line) {
            if (!is_array($line) || $this->isBlankOrderLine($line)) {
                continue;
            }

            $lineId = (int) ($line['id'] ?? 0);
            if ($lineId > 0) {
                $keptLineIds[$lineId] = true;
            }
        }

        foreach ($order->getLines() as $existingLine) {
            if (isset($keptLineIds[(int) $existingLine->getId()])) {
                continue;
            }

            $refusal = $order->lineDeletionRefusal($existingLine);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * The product id a posted line carries, from either of the two inputs that can supply one.
     *
     * Past PRODUCT_SELECT_INLINE_LIMIT the <select> is seeded only with products already on this
     * document, so it holds no option for anything else and a no-JS admin has nothing to pick an
     * unlisted product with. The form gives them a plain id box inside <noscript> instead (see the
     * order_line_row macro), and for those saves it is the only field that arrives filled —
     * product_id itself comes through empty.
     *
     * product_id_manual is checked FIRST, which looks backwards until you follow both worlds through:
     *
     *  - Scripting on: a <noscript>'s contents are never parsed into the DOM, so product_id_manual
     *    does not exist, does not post, and this reduces to the plain product_id read it replaced.
     *  - Scripting off: the id box is the field the admin can actually edit — the select is hidden
     *    (see the noscript <style> in the form) and still posts whatever it was rendered with. Taking
     *    the select first meant retyping the id on an existing line changed nothing: the stale
     *    selected option won and the edit was silently discarded.
     *
     * So whichever field the admin can reach is the one that decides. Both reads are scalar-guarded
     * for the same reason rawLineAmount() is — a nested array here was an uncaught 500 (#395).
     *
     * @param array<string, mixed> $line
     */
    /**
     * The restock ETA a row states, or null (#548).
     *
     * Hand-entered and therefore hand-mistyped: anything that is not a plain Y-m-d date reads as
     * "not stated" rather than refusing the whole save, because an ETA is a note about the future
     * and no part of the order depends on it. The date input posts this shape; a no-JS browser
     * posting something else gets the same treatment as a blank.
     *
     * @param array<string, mixed> $line
     */
    private function lineRestockEta(array $line): ?\DateTimeImmutable
    {
        $raw = is_scalar($line['restock_eta'] ?? null) ? trim((string) $line['restock_eta']) : '';
        if ($raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $parsed === false ? null : $parsed;
    }

    private function lineProductId(array $line): int
    {
        $manual = is_scalar($line['product_id_manual'] ?? null) ? (int) $line['product_id_manual'] : 0;
        if ($manual > 0) {
            return $manual;
        }

        return is_scalar($line['product_id'] ?? null) ? (int) $line['product_id'] : 0;
    }

    /**
     * Catalog defaults for a selected product — used to backfill cost/price/tax code/weight/unit
     * when a submitted line left them blank. The JS-enabled form copies these in immediately on
     * product selection (via the option's data-* attributes); a no-JS browser has no way to do
     * that, so the server fills them in from the same source at save time instead.
     *
     * @return array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string}
     */
    private function productLineDefaults(?ProductCore $product, EntityManagerInterface $entityManager): array
    {
        if ($product === null) {
            return ['cost' => 0.0, 'price' => 0.0, 'taxCode' => null, 'weight' => null, 'unit' => null];
        }

        $pricing = $entityManager->getRepository(ProductPricing::class)->findOneBy(['product' => $product], ['id' => 'ASC']);

        return [
            'cost' => (float) ($product->getCostPrice() ?? 0),
            'price' => $pricing instanceof ProductPricing ? (float) $pricing->getPrice() : 0.0,
            'taxCode' => TaxContext::mapTaxCode($product->getSalesTaxCode()),
            'weight' => $product->getWeight(),
            'unit' => $product->getUnit(),
        ];
    }

    /**
     * Above this many active products, the order form stops pre-rendering the whole catalog into
     * the product <select> (#399: a 13-line order against a 3,454-product catalog rendered 48,705
     * <option> nodes / 23.6 MB of HTML, an intermittent OOM 500). Below it, the form behaves
     * exactly as before — every catalog this small is what the existing test suite's fixtures
     * assume, and nothing about the browsing experience needs to change until the catalog is
     * actually large enough to be the problem.
     */
    private const PRODUCT_SELECT_INLINE_LIMIT = 200;

    /**
     * How many matches one search request answers with. The picker asks for the next page when the
     * admin clicks "Show more", so this caps the response size without capping what is reachable —
     * the previous flat 50 silently hid every match past the fiftieth with nothing to say so.
     * EstimateController holds the same value for the same reason.
     */
    private const PRODUCT_SEARCH_PAGE_SIZE = 50;

    /** True once the catalog is too large to pre-render into every line's <select> (#399). */
    private function orderCatalogExceedsInlineLimit(EntityManagerInterface $entityManager): bool
    {
        return $entityManager->getRepository(ProductCore::class)->count(['deleted' => false]) > self::PRODUCT_SELECT_INLINE_LIMIT;
    }

    /**
     * Past PRODUCT_SELECT_INLINE_LIMIT, this only pre-renders options for products a line already
     * points at — everything else is fetched on demand by searchOrderProducts() as the admin
     * types. $includeProductIds is empty on every blank/new line, which is the common case once
     * the cap is in effect, so this returns no rows at all rather than touching the repository.
     *
     * @param ?Company   $company                    the order's company, when one has been chosen — its price
     *                                                 list for $regionName is the first tier of the price
     *                                                 precedence below
     * @param ?string    $regionName                 the order's fulfillment region, when one has been chosen
     * @param bool       $catalogExceedsInlineLimit  orderCatalogExceedsInlineLimit(), computed once per request
     *                                                 and passed in so every call against the same request agrees
     * @param list<int>  $includeProductIds          ids already selected on one of the order's lines — ignored
     *                                                 below the cap, where the whole catalog renders regardless
     *
     * @return list<array<string, string>>
     */
    private function orderProductRows(
        EntityManagerInterface $entityManager,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        ?Company $company = null,
        ?string $regionName = null,
        bool $catalogExceedsInlineLimit = false,
        array $includeProductIds = [],
    ): array {
        $criteria = ['deleted' => false];
        if ($catalogExceedsInlineLimit) {
            if ($includeProductIds === []) {
                return [];
            }

            $criteria['id'] = $includeProductIds;
        }

        $products = $entityManager->getRepository(ProductCore::class)->findBy($criteria, ['name' => 'ASC']);

        $priceList = $company instanceof Company
            ? $companyFulfillmentRegionService->priceListForCompanyRegion($company, $regionName)
            : null;

        // Batched, for the same reason the search endpoint is: this used to run a findOneBy() per
        // product, so below the inline limit a 200-product catalog cost 200 extra queries on every
        // single render of the order form.
        $pricingByProductId = $this->pricingForProducts($entityManager, $priceList, $products);

        $rows = [];
        foreach ($products as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $rows[] = $this->orderProductRow($product, $pricingByProductId[(int) $product->getId()] ?? null);
        }

        return $rows;
    }

    /** @return list<int> ids of every line's product, deduplicated — orderProductRows()'s seed set past the cap */
    private function productIdsFromLineRows(array $lineRows): array
    {
        $ids = [];
        foreach ($lineRows as $lineRow) {
            $id = (int) ($lineRow['productId'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The catalog search behind the order form's product <select> (#399): the full catalog no
     * longer renders into the page, so this is how the admin finds anything not already on one of
     * the order's lines. Same row shape and price precedence as orderProductRows(), same
     * deleted-product exclusion, just resolved for a search term instead of a fixed id list.
     */
    #[Route('/order/products/search', name: 'admin_order_product_search', methods: ['GET'])]
    public function searchOrderProducts(
        Request $request,
        EntityManagerInterface $entityManager,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
    ): JsonResponse {
        $term = trim((string) $request->query->get('q', ''));
        // Plain get() + cast, NOT getInt(): the picker sends company_id="" whenever no company is
        // chosen yet (the create form's company select starts empty), and getInt() throws a
        // BadRequestException on anything FILTER_VALIDATE_INT rejects — an empty string included —
        // turning "no company selected" into an HTTP 400. Same pattern this controller already uses
        // for company_id elsewhere. An absent/unparseable id means "price it without a price list",
        // which is exactly what a null $company gives us below.
        $companyId = (int) $request->query->get('company_id', 0);
        $company = $companyId > 0 ? $entityManager->find(Company::class, $companyId) : null;
        $regionName = trim((string) $request->query->get('region', ''));

        // #399: nothing is searched below two characters. Enforced here and not only in the browser,
        // because the alternative was worse than returning nothing: an empty term skipped the LIKE
        // entirely and handed back the first 50 products of the catalog — neither a search result
        // nor a set the caller asked for. Keep in step with MIN_SEARCH_CHARS in app.js.
        if (mb_strlen($term) < 2) {
            return $this->json(['products' => [], 'hasMore' => false]);
        }

        $offset = max(0, (int) $request->query->get('offset', 0));

        $qb = $entityManager->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->where('p.deleted = false')
            ->andWhere('p.name LIKE :term OR p.sku LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('p.name', 'ASC')
            // The tiebreaker is what makes paging safe, not decoration: product names in a real
            // catalog collide heavily ("ZMAX X-SPIDER A/S 225/55R18 102V XL" vs "… 98V"), and
            // LIMIT/OFFSET over a non-unique sort is free to return a row twice or skip it entirely
            // between pages. Any total order will do; id is the cheap one.
            //
            // Note the functional suite CANNOT prove this is needed: SQLite happens to return rowid
            // order deterministically, so the paging test passes with this line deleted. It is here
            // for the engines that make no such promise.
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult($offset)
            // One more than the page, purely to answer "is there a next page?" without a second
            // COUNT query over the same LIKE. The extra row is dropped below and never returned.
            ->setMaxResults(self::PRODUCT_SEARCH_PAGE_SIZE + 1);

        $priceList = $company instanceof Company
            ? $companyFulfillmentRegionService->priceListForCompanyRegion($company, $regionName !== '' ? $regionName : null)
            : null;

        $found = $qb->getQuery()->getResult();
        $hasMore = count($found) > self::PRODUCT_SEARCH_PAGE_SIZE;
        $page = array_slice($found, 0, self::PRODUCT_SEARCH_PAGE_SIZE);

        // One query for the whole page's pricing, resolved BEFORE the loop. The per-product
        // findOneBy() this replaces was a textbook N+1 — 50 matches meant 50 extra round trips per
        // search, which is what made a 1.2 kB response take seconds to build.
        $pricingByProductId = $this->pricingForProducts($entityManager, $priceList, $page);

        $rows = [];
        foreach ($page as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $rows[] = $this->orderProductRow($product, $pricingByProductId[(int) $product->getId()] ?? null);
        }

        return $this->json(['products' => $rows, 'hasMore' => $hasMore]);
    }

    /**
     * Every ProductPricing row for one price list across a set of products, keyed by product id — the
     * batched replacement for a findOneBy() per product.
     *
     * @param array<int, mixed> $products
     * @return array<int, ProductPricing>
     */
    private function pricingForProducts(EntityManagerInterface $entityManager, ?PriceList $priceList, array $products): array
    {
        if (!$priceList instanceof PriceList || $products === []) {
            return [];
        }

        $byProductId = [];
        $pricingRows = $entityManager->getRepository(ProductPricing::class)->createQueryBuilder('pr')
            // Selecting the product id alongside the row is what keeps this to ONE query: reading
            // pr.getProduct()->getId() instead would be an identity-map hit for a product already
            // loaded, but a lazy proxy load for any that is not — which is the N+1 coming back in
            // through a side door.
            ->select('pr AS pricing', 'IDENTITY(pr.product) AS productId')
            ->where('pr.priceList = :list')
            ->andWhere('pr.product IN (:products)')
            ->setParameter('list', $priceList)
            ->setParameter('products', $products)
            ->getQuery()
            ->getResult();

        foreach ($pricingRows as $pricingRow) {
            $pricing = $pricingRow['pricing'] ?? null;
            if ($pricing instanceof ProductPricing) {
                $byProductId[(int) $pricingRow['productId']] = $pricing;
            }
        }

        return $byProductId;
    }

    /** @return array<string, string> */
    private function orderProductRow(ProductCore $product, ?ProductPricing $pricing): array
    {
        return [
            'id' => (string) $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'weight' => $product->getWeight() ?? '',
            'unit' => $product->getUnit() ?? '',
            'taxCode' => TaxContext::mapTaxCode($product->getSalesTaxCode()),
            'cost' => $product->getCostPrice() ?? '',
            'originalPrice' => $product->getOriginalPrice() ?? '',
            // Same precedence the storefront and the product price grid already use: the price
            // the company's price list sets for its region, else the product's default price,
            // else its original price — so the form's Price field (and the subtotal
            // recalculated from it) is never blank.
            'price' => $this->orderProductPrice($product, $pricing),
            // Whether picking this product should offer the Batch cell's lot/serial capture UI
            // (#670) — read into the picker's data-tracks-batch so the row can re-decide when the
            // admin changes which product a line names, the same figure orderLinesToRows() reads
            // for the product a saved line already carries.
            'tracksBatch' => $this->productTracksBatch($product) ? '1' : '',
            // Which of the two capture UIs — a lot select or a serial box (2026-09-14 lot/serial/
            // expiry plan). Read into data-tracking-mode alongside data-tracks-batch.
            'trackingMode' => $this->productTrackingMode($product),
        ];
    }

    /** Batch (lot) or serial tracking is a per-product attribute, not a line-row default (#670). */
    private function productTracksBatch(?ProductCore $product): bool
    {
        $policy = $product?->getTrackingPolicy();

        return $policy instanceof TrackingPolicy && ($policy->tracksLotsOutbound() || $policy->tracksSerialsOutbound());
    }

    /**
     * 'lot', 'serial', or '' — which capture UI the Batch cell's lot/serial picker offers for this
     * product (2026-09-14 lot/serial/expiry plan). Read into the picker's data-tracking-mode
     * alongside data-tracks-batch, the same way and for the same reason: the row has to re-decide
     * when the admin changes which product a line names.
     */
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

    private function orderProductPrice(ProductCore $product, ?ProductPricing $pricing): string
    {
        if ($pricing instanceof ProductPricing) {
            return $pricing->getPrice();
        }

        $base = $product->getDefaultPrice();
        if ($base === null || $base === '') {
            $base = $product->getOriginalPrice();
        }

        return $base ?? '';
    }

    /** @return list<string> */
    private function orderFulfillmentRegionRows(EntityManagerInterface $entityManager): array
    {
        $regions = array_map(
            fn (FulfillmentRegion $region): string => $region->getName(),
            $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC'])
        );

        return $regions !== [] ? $regions : ['Main'];
    }

    /**
     * The company's own active regions — what actually determines its price list
     * (see CompanyFulfillmentRegionService::priceListForCompanyRegion()), unlike
     * orderFulfillmentRegionRows() above which lists every region system-wide for the
     * per-line location picker.
     *
     * @return list<string>
     */
    private function orderCompanyRegionRows(CompanyFulfillmentRegionService $companyFulfillmentRegionService, ?Company $company): array
    {
        if (!$company instanceof Company) {
            return [];
        }

        return array_map(
            fn (CompanyFulfillmentRegion $row): string => $row->getFulfillmentRegion()->getName(),
            $companyFulfillmentRegionService->activeRowsForCompany($company)
        );
    }

    /**
     * The region a save should store, or a refusal message.
     *
     * Three things at once, because they are one rule:
     *
     * ABSENT MEANS UNCHANGED. When the company has no active regions the form renders a nameless
     * disabled input in place of the select, so nothing is posted — and the unguarded setter this
     * replaces read that as "clear it", wiping the region of an order placed long before the
     * company's regions were deactivated. Silent, and not cosmetic: the region picks the price
     * list (orderProductRows()), so the next edit priced the order against a different one.
     *
     * PRESENT MEANS REQUIRED. Where the company has active regions the select renders and a region
     * is genuinely required — refused HERE, server-side. The form's `required` attribute is a
     * courtesy on top of this and nothing rests on it: it used to be pure decoration, because the
     * order form carried novalidate and switched every native constraint off, and #248 removed that
     * attribute so the browser now asserts the same rule this method enforces. A POST that skips
     * the browser entirely still lands on this check.
     *
     * AND THE VALUE IS CHECKED, not just its presence. A submitted region must be one the company
     * actually has, or the one this document already holds. Otherwise `required` only proves the
     * box was non-empty, and a stale or hand-edited POST could name a region the company has no
     * assignment for — which resolves to no price list and prices the order off the product default.
     *
     * A stale stored region — set, but no longer among the company's active ones — is accepted
     * unchanged. That is what keeps "required" from becoming the freeze this issue rejected: the
     * requirement is satisfied by the value the document already has, so an admin can still correct
     * a PO number on an order whose company has since dropped that region. The form renders it as a
     * selected option and warns; it never forces a change.
     *
     * @param list<string> $activeRegions
     * @return array{0: string|null, 1: string|null} [region to store, refusal]
     */
    private function resolveFulfillmentRegion(Request $request, array $activeRegions, ?string $storedRegion): array
    {
        $stored = $storedRegion !== null ? trim($storedRegion) : '';

        // Absent means unchanged, so an absent field resolves to what is already stored. The
        // requirement below is then enforced on the RESULT, not on the raw post — otherwise
        // omitting the field would be a way to slip past a rule that submitting it blank runs into,
        // and a brand-new order could be saved with no region at all for a company that has them.
        $region = $request->request->has('fulfillment_region')
            ? trim((string) $request->request->get('fulfillment_region', ''))
            : $stored;

        if ($region === '') {
            // Nothing to pick, so nothing to require: keep whatever the document already had.
            if ($activeRegions === []) {
                return [$storedRegion, null];
            }

            // Exactly one active region is an unambiguous answer, and it is the one the rendered
            // form would have posted — the template pre-selects it for the same reason. Answering it
            // here rather than refusing means a caller that never saw the select (an API post, a
            // partial save) lands on the same region a browser would have sent, instead of being
            // told to choose between one option.
            if (count($activeRegions) === 1) {
                return [$activeRegions[0], null];
            }

            return [null, 'Choose a fulfillment region before saving. It decides which price list this order is priced against.'];
        }

        // The value itself, not just its presence. A stale stored region is accepted so an order in
        // flight can be saved unchanged; anything else the company has no assignment for resolves
        // to no price list and would price the order off the product default.
        if (!in_array($region, $activeRegions, true) && $region !== $stored) {
            return [null, sprintf('"%s" is not a fulfillment region this company is set up for.', $region)];
        }

        return [$region, null];
    }

    /**
     * @return list<array{id:string,label:string,amount:float,deliveryDays:?int,taxClass:string}>
     */
    private function buildShippingOptions(ShippingResolver $resolver, ?SalesOrder $order, Company $company): array
    {
        // On the create page there is no order yet, so an empty one standing in for the form is what
        // the options are resolved against. It is transient in the same way the preview endpoints'
        // order is, and never persisted — see previewOrderFromRequest().
        $order ??= (new SalesOrder())
            ->setCompany($company)
            ->setShippingAddressFrom($this->defaultAddress($company, 'shipping'));

        return array_map(
            fn ($o) => [
                'id'          => $o->id,
                'label'       => $o->label,
                'amount'      => $o->amount,
                'deliveryDays' => $o->deliveryDays,
                'taxClass'    => $o->taxClass,
            ],
            $resolver->getAvailableOptions($order)
        );
    }

    private function logOrderAction(SalesOrder $order, string $comment, string $type, bool $customerNotified, EntityManagerInterface $entityManager): void
    {
        $order->queueActivityLogEntry()
            ->setUserName($this->resolveLogUserName())
            ->setComment($comment)
            ->setType($type)
            ->setRecipientNotified($customerNotified);
    }

    /**
     * Shared by logOrderAction() (the normal, ORM-backed path) and
     * recordStaleOrderRejectionRaw() (#417's raw-SQL fallback for the one path where the
     * EntityManager is already closed and cannot be used) — the "who did this" string on an
     * order log entry should read identically no matter which of the two wrote the row.
     */
    private function resolveLogUserName(): string
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\AdminUser) {
            return 'System';
        }

        $userName = trim($user->getFirstName() . ' ' . $user->getLastName());
        $userName = $userName === '' ? $user->getEmail() : $userName . ', ' . $user->getEmail();

        return $userName . ' (' . $user->getId() . ')';
    }

    /**
     * The primary #417 fix: a submit whose hidden `version` field no longer matches what is in
     * the database was rendered from a page that was already stale before this request began —
     * admin A's save committed after admin B loaded the form, and B's fields are edits made
     * against a document that no longer exists in the shape B is editing it as. Nothing from the
     * post is applied; this is called before a single setter runs.
     *
     * Sends the admin back to a fresh copy of the edit page (current data, current version, so
     * the next submit starts clean) rather than re-rendering their now-unusable post — the flash
     * message is the only place the "why" survives that redirect.
     */
    private function rejectStaleOrderSubmit(SalesOrder $order, int $submittedVersion, EntityManagerInterface $entityManager): Response
    {
        $this->logOrderAction(
            $order,
            sprintf(
                'Save rejected: submitted version %d does not match the order\'s current version %d. No changes were applied.',
                $submittedVersion,
                $order->getVersion()
            ),
            'System',
            false,
            $entityManager
        );
        $entityManager->flush();

        $this->addFlash('error', $this->staleOrderSubmitMessage($order));

        return $this->redirectToRoute('admin_order_edit', ['id' => $order->getId()]);
    }

    private function staleOrderSubmitMessage(SalesOrder $order): string
    {
        return sprintf(
            'Order %s was changed by someone else while you had this page open. Your edits were not saved — review the current version below and make your changes again.',
            $order->getOrderNumber()
        );
    }

    /**
     * The defense-in-depth counterpart to rejectStaleOrderSubmit(): reached only when
     * $entityManager->flush() itself throws OptimisticLockException (a race landing inside this
     * request's own load-then-flush span, rather than the cross-request staleness the check at
     * the top of edit()'s POST handling exists for). UnitOfWork::commit() closes the
     * EntityManager on any failure past that point, so persist()/flush() through it are no
     * longer available — this writes the same rejection record with a raw insert against the
     * still-usable underlying DBAL connection instead, so a blocked save is never silent purely
     * because of which of the two checks caught it.
     */
    private function recordStaleOrderRejectionRaw(\Doctrine\DBAL\Connection $conn, int $orderId, ?int $submittedVersion): void
    {
        // Writes audit_log directly rather than through AuditLogger/the entity's own
        // queueActivityLogEntry() — both need a live EntityManager, which UnitOfWork::commit()
        // has already closed by the time this runs (see this method's own docblock).
        $conn->insert('audit_log', [
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'actor_type' => 'document',
            'actor_name' => $this->resolveLogUserName(),
            'area' => 'System',
            'entity_type' => 'SalesOrder',
            'entity_id' => $orderId,
            'action' => 'System',
            'summary' => sprintf(
                'Save rejected: submitted version %s was stale by the time this save reached the database. No changes were applied.',
                $submittedVersion === null ? '(none)' : (string) $submittedVersion
            ),
            'recipient_notified' => 0,
        ]);
    }

    /**
     * Build (or update) one of an order's address snapshots from the posted form.
     *
     * If a book entry was chosen, its fields seed the snapshot and it is recorded as the source; the
     * posted values then overwrite on top, so an admin's corrections apply to this order only. The
     * book entry itself is never modified.
     *
     * A no-op when the card was never rendered — same guard applyEstimateAddressCard() has (#282).
     * Without it a post that carries no `*_address_id` at all (a bare or scripted submit, a form
     * fragment) reads as "no entry chosen" and severs the order's source_address_id, which is a
     * decision the submission never made.
     */
    private function applyOrderAddressFromRequest(
        SalesOrder $order,
        EntityManagerInterface $entityManager,
        Company $company,
        Request $request,
        string $type,
    ): void {
        if (!$request->request->has($type . '_address_id')) {
            return;
        }

        $snapshot = $order->addressForWriting($type);

        $addressId = (int) $request->request->get($type . '_address_id');
        if ($addressId > 0) {
            $source = $entityManager->find(CompanyAddress::class, $addressId);
            // Ownership check kept from the previous implementation: an id from another company must
            // not be readable through this form.
            if ($source instanceof CompanyAddress && $source->getCompany()->getId() === $company->getId()) {
                $snapshot->copyFrom($source);
            }
        } else {
            $snapshot->setSourceAddress(null);
        }

        $this->applyAddressEditsFromRequest($snapshot, $type, $request);
    }
}
