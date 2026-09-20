<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Contract\Fee\FeeContext;
use App\Entity\AuditLog;
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
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Enum\QuoteConversionInvoicing;
use App\Exception\DocumentLocked;
use App\Service\DocumentActor;
use App\Service\EstimateAlreadyConvertedException;
use App\Service\EstimateConversionService;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\CustomFieldRenderer;
use App\Service\Document\DocumentLockService;
use App\Service\Document\EstimateLineReconciler;
use App\Service\Document\SalesDocumentCloner;
use App\Service\DocumentActorResolver;
use App\Service\EstimateNumberGenerator;
use App\Service\BusinessDate;
use App\Service\OrderTaxBreakdownService;
use App\Service\SalesDocumentChargeLines;
use App\Service\SalesDocumentLineWarnings;
use App\Service\SalesDocumentMoney;
use App\Service\Uom\LineDenomination;
use App\Service\SalesDocumentNotifier;
use App\Service\TextInput;
use App\Service\WarehouseFulfillmentRegionService;
use App\Validation\Constraint\ValidEstimateFulfillmentRegionAvailability;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use FeeBundle\Fee\FeeCalculatorResolver;
use Psr\Log\LoggerInterface;
use ShippingBundle\Shipping\ShippingResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use TaxBundle\Tax\TaxCalculatorResolver;

#[Route('/admin')]
final class EstimateController extends AbstractAdminController
{
    private function parseEstimateDateFilter(?string $raw): ?\DateTimeImmutable
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }

        $candidates = ['Y-m-d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y'];
        foreach ($candidates as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $v);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception $e) {
            return null;
        }
    }

    #[Route('/estimate', name: 'admin_estimate_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, BusinessDate $businessDate): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        // Scoped to one customer when the query names one by id (`EstimateSearch[company_id]`, or a
        // bare `company_id`), which is how a customer record drills through to "every quote of
        // theirs". The Company column's LIKE filter below is unchanged and works alongside it: a
        // name is fuzzy by design — someone typing "Acme" wants Acme Holdings too — and an id is
        // exact by design. Neither can do the other's job, so both are here.
        $companyScope = $this->companyListScope($request, $entityManager, 'EstimateSearch');
        if ($companyScope->isUnresolved()) {
            $this->addFlash('error', 'Company could not be found for these quotes.');
        }

        // Left join: a quote that has not been converted has no order, and must still be listed.
        $qb = $entityManager->getRepository(Estimate::class)->createQueryBuilder('e')
            ->join('e.company', 'c')
            ->leftJoin('e.convertedOrder', 'o');

        if ($companyScope->company() !== null) {
            $qb->andWhere('e.company = :scopedCompany')->setParameter('scopedCompany', $companyScope->company());
        } elseif ($companyScope->isUnresolved()) {
            // Asked for a customer that does not exist. The list is empty, NOT unscoped: an id
            // nobody recognises must never widen into every customer's quotes, which is a grid that
            // looks exactly like the one that was asked for and whose totals are not theirs. ids
            // are positive, so this matches nothing while leaving one code path for counting,
            // sorting and paging.
            $qb->andWhere('e.id = :noSuchCompany')->setParameter('noSuchCompany', 0);
        }

        $filterDocumentNumber = trim((string) ($filters['documentNumber'] ?? ''));
        if ($filterDocumentNumber !== '') {
            $qb->andWhere('e.documentNumber LIKE :filterDocumentNumber')->setParameter('filterDocumentNumber', '%' . $filterDocumentNumber . '%');
        }

        $filterOrderNumber = trim((string) ($filters['orderNumber'] ?? ''));
        if ($filterOrderNumber !== '') {
            $qb->andWhere('o.orderNumber LIKE :filterOrderNumber')->setParameter('filterOrderNumber', '%' . $filterOrderNumber . '%');
        }

        $filterUserName = trim((string) ($filters['userName'] ?? ''));
        if ($filterUserName !== '') {
            $qb->andWhere('e.userName LIKE :filterUserName')->setParameter('filterUserName', '%' . $filterUserName . '%');
        }

        $filterCompany = trim((string) ($filters['company'] ?? ''));
        if ($filterCompany !== '') {
            $qb->andWhere('c.name LIKE :filterCompany')->setParameter('filterCompany', '%' . $filterCompany . '%');
        }

        $filterTotal = trim((string) ($filters['total'] ?? ''));
        if ($filterTotal !== '') {
            // Partial match, like the other text filters: requiring the exact stored value
            // to the cent (e.g. "551.05") is unusable — nobody types a total that precisely.
            $digits = preg_replace('/[^0-9.\\-]/', '', $filterTotal) ?? '';
            if ($digits !== '') {
                $qb->andWhere('e.total LIKE :filterTotal')->setParameter('filterTotal', '%' . $digits . '%');
            }
        }

        $filterSource = trim((string) ($filters['source'] ?? ''));
        if ($filterSource !== '') {
            $qb->andWhere('e.source = :filterSource')->setParameter('filterSource', $filterSource);
        }

        $filterCreatedAt = trim((string) ($filters['createdAt'] ?? ''));
        if ($filterCreatedAt !== '') {
            $createdAt = $this->parseEstimateDateFilter($filterCreatedAt);
            if ($createdAt instanceof \DateTimeImmutable) {
                // The admin sees this date in the display timezone, but e.createdAt is stored in
                // UTC — the typed day has to be converted to its UTC window before it can be
                // compared, or rows near the display timezone's midnight get missed/misfired.
                [$from, $to] = $businessDate->localDayRangeUtc($createdAt);
                $qb->andWhere('e.createdAt >= :filterCreatedAtFrom AND e.createdAt < :filterCreatedAtTo')
                    ->setParameter('filterCreatedAtFrom', $from, Types::DATETIME_IMMUTABLE)
                    ->setParameter('filterCreatedAtTo', $to, Types::DATETIME_IMMUTABLE);
            }
        }

        $filterStatus = trim((string) ($filters['status'] ?? ''));
        if ($filterStatus !== '') {
            // Checked against the vocabulary rather than the enum: under section 5 no production
            // code reads an enum, and the set a filter bar offers has to be the set the app ships.
            if (isset(Estimate::listStatuses()[$filterStatus])) {
                $qb->andWhere('e.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $orderExpr = match ($sort) {
            'id' => 'e.id',
            'documentNumber' => 'e.documentNumber',
            'orderNumber' => 'o.orderNumber',
            'company' => 'c.name',
            'userName' => 'e.userName',
            'status' => 'e.status',
            'total' => 'e.total',
            'source' => 'e.source',
            'createdAt' => 'e.createdAt',
            default => 'e.id',
        };

        $estimates = $qb->select('e')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/estimate/index.html.twig', [
            // Both halves of the scope reach the template: the customer whose quotes these are, so
            // the screen can say so, and the id that matched nothing, so it can say that instead of
            // rendering as an ordinary unfiltered grid.
            'company' => $companyScope->company() !== null ? $this->companyToRow($companyScope->company()) : null,
            'companyScopeMissingId' => $companyScope->isUnresolved() ? $companyScope->requestedId() : null,
            'estimates' => $estimates,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pageCount,
        ]);
    }

    #[Route('/estimate/detail/{id}', name: 'admin_estimate_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(
        int $id,
        EntityManagerInterface $entityManager,
        OrderTaxBreakdownService $taxBreakdownService,
        \App\Repository\AuditLogRepository $auditLogRepository,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $taxBreakdown = $taxBreakdownService->tryDecodeTaxLinesJson($estimate->getTaxLines()) ?? [
            'lines' => [],
            'total' => 0.0,
            'perLineTax' => [],
            'perLineTaxLabel' => [],
        ];

        return $this->render('admin/estimate/detail.html.twig', [
            'estimate' => $estimate,
            // Newest first, exactly as OrderController::detail() reads the order's timeline. The
            // template used to walk estimate.logs instead, which is the relation's insertion order,
            // so the newest message on a quote sank to the bottom of a growing list while the same
            // message on an order sat at the top of it.
            //
            // Ordered HERE rather than by an #[ORM\OrderBy] on the relation, and for the same
            // reason the order orders it here: the relation has other readers that want it as
            // written — EstimateConversionService copies a quote's timeline onto the order it
            // raises — and reversing it for all of them to suit one screen would restate a history
            // that is not this screen's to restate.
            'logs' => $auditLogRepository->findNarrativeForEntity('Estimate', $estimate->getId()),
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
            'perLineTax' => $taxBreakdown['perLineTax'],
            'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
            'auditHistory' => $auditLogRepository->findForEntity('Estimate', $estimate->getId()),
        ]);
    }

    /**
     * Copy this quote into a new draft quote — "make another like this".
     *
     * Available on EVERY status, including Rejected and Accepted. That is the point of the action
     * rather than an oversight: "this one was wrong, make another" is the main reason anybody
     * clones, and a rejected quote is exactly the one somebody wants to re-issue with two lines
     * changed. Cloning writes nothing to the original, so no status rule of the original's is
     * involved — {@see \App\Service\Document\SalesDocumentCloner} reads it and never touches it.
     *
     * A LOCKED quote can be cloned too. A lock freezes the document against being changed; the
     * clone is a different document.
     *
     * The flash says the prices came across as they were. That sentence is the whole of "make it
     * obvious to the user which happened" — a copy and a re-price look identical on the screen that
     * opens next, and the difference is worth more than the two seconds it costs to read.
     */
    #[Route('/estimate/clone/{id}', name: 'admin_estimate_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneEstimate(
        int $id,
        EntityManagerInterface $entityManager,
        SalesDocumentCloner $cloner,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate could not be found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        $copy = $cloner->cloneEstimate($estimate, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'Quote %s copied to %s as a new draft. Lines, quantities and prices came across exactly as they '
                . 'were — re-price it if the customer\'s list has moved since.',
            $estimate->getDocumentNumber(),
            $copy->getDocumentNumber(),
        ));

        return $this->redirectToRoute('admin_estimate_edit', ['id' => $copy->getId()]);
    }

    /**
     * Freeze this quote against editing. Any admin may do this; see
     * {@see \App\Service\Document\DocumentLockService} for why unlocking asks for more.
     */
    #[Route('/estimate/lock/{id}', name: 'admin_estimate_lock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lockEstimate(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DocumentLockService $locks,
        DocumentActorResolver $actorResolver,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate could not be found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        $locks->lock($estimate, $actorResolver->resolve(), (string) $request->request->get('reason', ''), $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'Quote %s is locked. It can still be printed, emailed and cloned; nothing can edit or delete it '
                . 'until it is unlocked.',
            $estimate->getDocumentNumber(),
        ));

        return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
    }

    /** Release the freeze. ROLE_SUPER_ADMIN, which ROLE_TECH_SUPPORT inherits — see DocumentLockService. */
    #[Route('/estimate/unlock/{id}', name: 'admin_estimate_unlock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unlockEstimate(
        int $id,
        EntityManagerInterface $entityManager,
        DocumentLockService $locks,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate could not be found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        $locks->unlock($estimate, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Quote %s is unlocked and can be edited again.', $estimate->getDocumentNumber()));

        return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
    }

    #[Route('/estimate/quote/{id}', name: 'admin_estimate_quote', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function quotePdf(int $id, Request $request, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate could not be found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        $taxBreakdown = $taxBreakdownService->tryDecodeTaxLinesJson($estimate->getTaxLines()) ?? [
            'lines' => [],
            'total' => 0.0,
        ];

        if ($request->query->get('download') === '1') {
            $html = $this->renderView('admin/estimate/quote.html.twig', [
                'estimate' => $estimate,
                'taxLines' => $taxBreakdown['lines'],
                'is_pdf' => true,
            ]);
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="Quote-' . $estimate->getDocumentNumber() . '.pdf"',
            ]);
        }

        return $this->render('admin/estimate/quote.html.twig', [
            'estimate' => $estimate,
            'taxLines' => $taxBreakdown['lines'],
        ]);
    }

    #[Route('/estimate/log/add/{id}', name: 'admin_estimate_log_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addLog(int $id, Request $request, EntityManagerInterface $entityManager, SalesDocumentNotifier $notifier): Response
    {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            return $this->json(['success' => false, 'message' => 'Estimate not found.'], Response::HTTP_NOT_FOUND);
        }

        $message = trim((string) $request->request->get('message', ''));
        if ($message === '') {
            return $this->json(['success' => false, 'message' => 'Message is required.'], Response::HTTP_BAD_REQUEST);
        }

        $notify = $request->request->getBoolean('notify_client');

        $estimate->queueActivityLogEntry()
            ->setUserName($this->adminDisplayName())
            ->setComment($message)
            ->setType((string) $request->request->get('type', 'System'))
            ->setRecipientNotified($notify);
        $entityManager->flush();

        // The flag used to be written and nothing sent, so customer_notified was a standing false
        // positive on quotes — the one record staff read to decide whether a follow-up is still
        // owed (#270). The order's twin (OrderController::addLog()) has always mailed the message;
        // this is the same send, routed through the notifier the rest of the quote emails use so it
        // picks up the DB template override and the company-recipient fallbacks for free.
        if ($notify) {
            $notifier->quoteMessage($estimate, $message);
        }

        return $this->json(['success' => true, 'message' => 'Message added successfully.']);
    }

    #[Route('/estimate/log/delete/{id}/{logId}', name: 'admin_estimate_log_delete', methods: ['POST'], requirements: ['id' => '\d+', 'logId' => '\d+'])]
    public function deleteLog(int $id, int $logId, EntityManagerInterface $entityManager): Response
    {
        $log = $entityManager->find(AuditLog::class, $logId);
        // actor_type === 'document' is not just a read-side filter here — it is what stops this
        // route from becoming a way to delete an arbitrary audit_log row by guessing its id. A
        // generic field-diff row is still checked entityType/entityId first, same as before, but
        // it is refused outright rather than deleted, regardless of whose id it names.
        if ($log && $log->getEntityType() === 'Estimate' && $log->getEntityId() === $id && $log->getActorType() === 'document') {
            $entityManager->remove($log);
            $entityManager->flush();

            return $this->json(['success' => true, 'message' => 'Log entry deleted successfully.']);
        }

        return $this->json(['success' => false, 'message' => 'Log entry not found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/estimate/create', name: 'admin_estimate_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        EstimateNumberGenerator $numberGenerator,
        ShippingResolver $shippingResolver,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        CustomFieldRenderer $customFieldRenderer,
        EstimateLineReconciler $lineReconciler,
    ): Response {
        $queryCompanyId = $request->query->getInt('company_id', 0);
        $company = $queryCompanyId > 0 ? $entityManager->find(Company::class, $queryCompanyId) : null;
        $company = $company instanceof Company ? $company : null;

        if ($request->isMethod('POST')) {
            $postedCompanyId = $request->request->getInt('company_id', 0);
            $postedCompany = $postedCompanyId > 0 ? $entityManager->find(Company::class, $postedCompanyId) : null;
            // The no-JS charge buttons are saves like any other; they just name themselves rather
            // than a save_mode, so they have to be recognised as one here or they would be mistaken
            // for the company picker's post and redirect away, binning everything typed so far.
            $isEstimateSubmit = $request->request->has('save_mode') || $this->isChargeLineSubmit($request);

            // The "Select/Change Customer" button posts only company_id (no save_mode) — reload the
            // page with that company in the query string so its Billing/Shipping panel loads, the
            // same redirect-then-reload trick OrderController::create() uses.
            if (!$isEstimateSubmit) {
                return $this->redirectToRoute('admin_estimate_create', $postedCompany instanceof Company ? ['company_id' => $postedCompany->getId()] : []);
            }

            $company = $postedCompany;
            if (!$company instanceof Company) {
                $this->addFlash('error', 'Please select a customer.');

                return $this->redirectToRoute('admin_estimate_create');
            }

            // A company with no active fulfillment region has no price list, so there is no correct
            // price to put on a brand-new quote — every line would be priced off the product
            // default, silently, on a document that goes out to the customer for approval. CREATE
            // is refused here; EDIT deliberately is not (see edit()), because an in-flight quote
            // has to stay correctable after its company's regions are deactivated underneath it.
            $activeRegions = $this->estimateCompanyRegionRows($companyFulfillmentRegionService, $company);
            $regionAvailabilityError = $this->errorForFulfillmentRegionAvailability($activeRegions, $company);
            if ($regionAvailabilityError !== null) {
                $this->addFlash('error', $regionAvailabilityError);

                return $this->redirectToRoute('admin_estimate_create', ['company_id' => $company->getId()]);
            }

            // Asked before an Estimate even exists, the same way edit() asks before touching one:
            // a fee calculator flushes partway through recomputeFeesAndTax()
            // (FeeRepository::ensureBySlug()), so a row that cannot become a line has to be refused
            // at the door rather than halfway through the save. Asked of the button-adjusted rows,
            // not the raw post — see the note in edit().
            $chargeError = SalesDocumentChargeLines::errorFor($this->postedChargeRows($request));
            if ($chargeError !== null) {
                $this->addFlash('error', $chargeError);

                return $this->redirectToRoute('admin_estimate_create', ['company_id' => $company->getId()]);
            }

            $estimate = new Estimate();
            $estimate->setCompany($company);
            $estimate->setDocumentNumber($numberGenerator->next($entityManager));
            $estimate->setSource('Admin');
            // user_name is the CUSTOMER USER who initiated the document. An admin-created quote has
            // no such user, so it stays null rather than carrying adminDisplayName() — a staff
            // member's name AND email address — into a customer-facing column that every other row
            // fills with a customer, and then on into the converted order via
            // EstimateConversionService. The creating admin is recorded in the quote's log below and
            // in audit_log instead (#269).
            $estimate->setUserName(null);
            $estimate->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80));
            $estimate->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')));
            $this->applyEstimateQuoteDate($estimate, $request);
            $this->applyEstimateCompanyCard($estimate, $request);

            // Before applyLinesFromRequest() below, for the same reason the charge rows are checked
            // above: recomputeFeesAndTax() flushes partway through, so a refusal discovered later
            // would leave half a quote written. The region also decides which price list the
            // suggested prices came from, so a quote must not be minted without one.
            $regionError = $this->applyEstimateFulfillmentRegion($estimate, $request, $activeRegions);
            if ($regionError !== null) {
                $this->addFlash('error', $regionError);

                return $this->redirectToRoute('admin_estimate_create', ['company_id' => $company->getId()]);
            }

            $estimate->setBillingName($company->getName());
            $estimate->setShippingName($company->getName());

            $billingAddress = $company->getDefaultBillingAddress();
            if ($billingAddress !== null) {
                $estimate->setBillingAddressFrom($billingAddress);
            }
            $shippingAddress = $company->getDefaultShippingAddress();
            if ($shippingAddress !== null) {
                $estimate->setShippingAddressFrom($shippingAddress);
            }

            // Only present once the Billing/Shipping Detail cards have actually rendered (i.e. a
            // company was already chosen before this submit) — a bare no-JS/API POST that skips
            // straight to save_mode still gets the company's plain defaults set above.
            $this->applyEstimateAddressCard($estimate, $company, 'billing', $request, $entityManager);
            $this->applyEstimateAddressCard($estimate, $company, 'shipping', $request, $entityManager);

            $lineWarnings = new SalesDocumentLineWarnings();
            ['count' => $lineCount, 'allPriced' => $allPriced, 'subtotal' => $subtotal] = $this->applyLinesFromRequest($request, $entityManager, $estimate, $lineWarnings, $lineReconciler);

            // The form's bottom "[line type] + Add Line" bar renders on create too, so charge rows
            // can be added before the first save — exactly like order, whose create action takes
            // charge_lines[] on the initial POST (OrderController::createOrderFromRequest()).
            [$manualTaxLines, $shippingLines, $manualFeeLines] = $this->applyChargeLinesFromRequest($estimate, $request, $shippingResolver, $taxBreakdownService);

            // Same unconditional recompute edit() does — the fee and tax snapshots are rebuilt from
            // whatever is priced so far, and holdBackUnstatableTotals() then decides whether the
            // header figures those produce may be stated at all.
            $estimate->setSubtotal(number_format($subtotal, 2, '.', ''));
            $this->recomputeFeesAndTax($estimate, $subtotal, $feeResolver, $taxBreakdownService, $manualTaxLines, $shippingLines, $manualFeeLines);
            $this->holdBackUnstatableTotals($estimate, $allPriced);

            // A new quote is born Draft, so "save as draft" is a no-op through the seam's one door
            // — it writes nothing and logs nothing — and "submit" is a real Draft -> Submitted move
            // that the vocabulary checks and the timeline records. Unless the admin priced every
            // line plus shipping/tax/total right here on the create form: then it is really a
            // Draft -> Priced move, the same one edit()'s Submitted -> Priced promotion makes for an
            // existing quote, just landed in a single hop instead of two because a brand-new quote
            // can never have been 'Priced' before this point (there is no demotion side to mirror).
            // isFullyPriced() is read from the entity rather than the $allPriced local because it is
            // the same test edit() promotes on, and it also covers shipping/tax/total, which
            // holdBackUnstatableTotals() just finished settling above and $allPriced never knew about.
            $saveMode = (string) $request->request->get('save_mode', 'draft');
            $submittedStatus = $estimate->isFullyPriced() ? 'Priced' : 'Submitted';
            $estimate->setStatus($saveMode === 'draft' ? 'Draft' : $submittedStatus, $this->documentActor());

            $entityManager->persist($estimate);
            // Opens the quote's log with who made it and when, the way order creation does
            // (OrderController::create()). Without it a quote's activity log stayed empty until
            // somebody happened to change its status (#271).
            $this->logEstimateAction(
                $estimate,
                sprintf('Estimate %s created by %s.', $estimate->getDocumentNumber(), $this->getUser()?->getUserIdentifier() ?? 'System'),
                $entityManager,
            );
            $entityManager->flush();

            // After the flush that mints the id, because a value row is FK'd to the quote and there
            // is nothing to point at until the quote exists. Same position and same reason as
            // OrderController::create()'s call. It writes ONLY custom_field_value_estimate — no
            // stored figure on the quote is touched.
            $customFieldRenderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, $estimate, $request);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Estimate %s created.', $estimate->getDocumentNumber()));
            // The quote is saved; a quantity that had to be rewritten rides the redirect to the
            // edit form, which paints the row it happened to (see SalesDocumentLineWarnings).
            $lineWarnings->flashOnto($request);

            return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
        }

        $companies = $entityManager->getRepository(Company::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        $productsRemote = $this->estimateCatalogExceedsInlineLimit($entityManager);

        return $this->render('admin/estimate/form.html.twig', [
            // A refused save repaints what was typed: the shared line row reads these instead of
            // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
            // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
            // LineDenomination::boxUntouched(), so reformatting one here would let the next round
            // trip rewrite a stored figure.
            'submitted' => $this->submittedLinesForRerender($request),
            'estimate' => null,
            'company' => $company instanceof Company ? $this->companyToRow($company) : null,
            'companies' => $companies,
            'billingAddress' => $company instanceof Company ? $this->addressToRow($this->defaultAddress($company, 'billing'), $company) : null,
            'shippingAddress' => $company instanceof Company ? $this->addressToRow($this->defaultAddress($company, 'shipping'), $company) : null,
            'addressBook' => $company instanceof Company ? $this->addressBookRows($company) : [],
            // No document yet, so no region has been picked — priceListForCompanyRegion() answers
            // for a company with exactly one active region and returns null otherwise, exactly as
            // OrderController::create() leaves it (OrderController.php:1388).
            'products' => $this->estimateProductRows($entityManager, $companyFulfillmentRegionService, $company, null, $productsRemote),
            'productsRemote' => $productsRemote,
            'locations' => $this->estimateLocationRows($entityManager),
            'fulfillmentRegions' => $this->estimateCompanyRegionRows($companyFulfillmentRegionService, $company),
            'fulfillmentRegion' => '',
            'staleFulfillmentRegion' => null,
            'perLineTax' => [],
            // No lines yet, so no units to offer — a row gets its U/M selector once it names a
            // product, which on this page is after the first save (#644).
            'lineUnits' => [],
            // Order's create page resolves its shipping options off the company's default shipping
            // address alone (there's no document yet) — same here.
            'shippingOptions' => $company instanceof Company ? $this->buildShippingOptions($shippingResolver, null, $company) : [],
            // The admin-defined fields, in the 'add' context: no quote exists yet, so every box is
            // blank and only definitions marked visible-on-add render. This is the ONLY render in
            // create() — every refusal above redirects back to this same GET rather than
            // re-rendering, so there is no second call site here to keep in step (unlike
            // OrderController::create(), whose refusals re-render inline and therefore need three).
            'customFieldFragment' => $customFieldRenderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, null, CustomFieldRenderer::CONTEXT_ADD),
        ]);
    }

    #[Route('/estimate/edit/{id}', name: 'admin_estimate_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
        SalesDocumentNotifier $notifier,
        ShippingResolver $shippingResolver,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        AdminOrderStockValidator $stockValidator,
        WarehouseFulfillmentRegionService $warehouses,
        DocumentLockService $locks,
        CustomFieldRenderer $customFieldRenderer,
        EstimateLineReconciler $lineReconciler,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        // The explicit lock, asked BEFORE the status rule below and separately from it. They are two
        // different refusals: the status rule says "an accepted quote is history", and this says
        // "somebody froze this one on purpose". A locked DRAFT is refused here and would sail
        // straight past the status rule, which is exactly why the lock is not expressed as a status.
        //
        // Refused on GET as well as POST, so the edit SCREEN does not open on a document that cannot
        // be saved — the disputed-bill dead end merged tonight is what an un-actionable screen costs.
        // Caught locally rather than left to DocumentLockedSubscriber, which is the OPTIMISATION that
        // subscriber's docblock describes: the refusal lands on the quote's own detail page, where
        // the Unlock control the sentence names actually is, instead of on whatever the Referer was.
        // The wording still lives on the exception — this only chooses where it is read.
        try {
            $locks->assertWritable($estimate, 'edited');
        } catch (DocumentLocked $locked) {
            $this->addFlash('error', $locked->getMessage());

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
        }

        if (!$estimate->canEditOnStatus()) {
            $this->addFlash('error', 'This estimate is locked and can no longer be edited.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
        }

        $activeRegions = $this->estimateCompanyRegionRows($companyFulfillmentRegionService, $estimate->getCompany());

        if ($request->isMethod('POST')) {
            // Before every other door check below, because all of them either mutate the estimate
            // (applyEstimateFulfillmentRegion() writes the region onto it) or answer a question
            // about a post that should never have been accepted in the first place. A submit
            // computed from a page that was already stale is not a save to validate; it is a save
            // to refuse.
            //
            // $estimate was loaded fresh, above, for THIS request, so its version is whatever is in
            // the database right now. EntityManager::lock() with LockMode::OPTIMISTIC compares that
            // against the SUBMITTED one and throws OptimisticLockException on a mismatch, before a
            // single posted field is touched. The column's own automatic flush()-time check backs
            // this up for the much narrower window between here and this request's own commit.
            //
            // Only checked when the post actually carries a `version` — the quote form always sends
            // one now, but a request with no opinion on which version it was edited against has
            // nothing to compare, and is left exactly as it was before this field existed. Same
            // allowance OrderController::edit() makes, and the reason every quote Cest that
            // predates this column still posts and saves unchanged.
            if ($request->request->has('version')) {
                // -1 for an unreadable value rather than 0: a version column starts at 1 and only
                // ever climbs, so -1 can never collide with a real one and a junk `version` is
                // refused rather than waved through.
                $submittedVersion = $request->request->getInt('version', -1);
                try {
                    $entityManager->lock($estimate, LockMode::OPTIMISTIC, $submittedVersion);
                } catch (OptimisticLockException) {
                    return $this->rejectStaleEstimateSubmit($estimate, $submittedVersion, $entityManager);
                }
            }

            // First thing in the POST, before applyLinesFromRequest() rewrites a single line: a fee
            // calculator flushes partway through recomputeFeesAndTax()
            // (FeeRepository::ensureBySlug() persists and flushes), so a charge row that cannot
            // become a line has to be refused at the door rather than discovered mid-save with part
            // of the estimate already written.
            //
            // The no-JS charge button is applied FIRST, exactly as OrderController does it: without
            // that, pressing a bad row's ✕ re-posts the row, the check at this door sees it before
            // the removal happens, and the save is refused — leaving the row permanently
            // undeletable. It also means a row the Add Line button just created is checked here
            // rather than discovered by toFeeLines() mid-save.
            $chargeError = SalesDocumentChargeLines::errorFor($this->postedChargeRows($request));
            if ($chargeError !== null) {
                $this->addFlash('error', $chargeError);

                return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
            }

            // Refused at the same door, and for the same reason. Note there is no "company has no
            // active region" refusal here, unlike create(): a quote already in flight stays
            // editable whatever happened to its company's regions afterwards, and simply keeps the
            // region it holds.
            $regionError = $this->applyEstimateFulfillmentRegion($estimate, $request, $activeRegions);
            if ($regionError !== null) {
                $this->addFlash('error', $regionError);

                return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
            }

            // Zero lines is a valid quote (#full-parity, 2026-09-12) — but a request that omits the
            // `lines` key ENTIRELY is not the admin choosing that; it is a malformed/incomplete
            // submit, and the real screen always posts at least the two no-JS spare rows even once
            // every real line has been removed. Refusing only this case, never "posted but empty,"
            // is what stops a broken request from silently wiping a quote that already has lines.
            if (!$request->request->has('lines')) {
                $this->addFlash('error', 'Lines were not submitted — nothing was changed.');

                return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
            }

            $lineWarnings = new SalesDocumentLineWarnings();
            ['count' => $lineCount, 'allPriced' => $allPriced, 'subtotal' => $subtotal] = $this->applyLinesFromRequest($request, $entityManager, $estimate, $lineWarnings, $lineReconciler);

            // The Estimate Info card is editable here the same way order/edit's Order Info card is.
            if ($request->request->has('po_number')) {
                $estimate->setPoNumber(TextInput::oneLineStringMax($request->request->get('po_number'), 80));
            }
            if ($request->request->has('special_instructions')) {
                $estimate->setSpecialInstructions($this->nullableString($request->request->get('special_instructions')));
            }
            $this->applyEstimateQuoteDate($estimate, $request);
            $this->applyEstimateCompanyCard($estimate, $request);

            $this->applyEstimateAddressCard($estimate, $estimate->getCompany(), 'billing', $request, $entityManager);
            $this->applyEstimateAddressCard($estimate, $estimate->getCompany(), 'shipping', $request, $entityManager);

            [$manualTaxLines, $shippingLines, $manualFeeLines] = $this->applyChargeLinesFromRequest($estimate, $request, $shippingResolver, $taxBreakdownService);

            // Recompute on every save, unconditionally, the way OrderController::edit() does: the
            // per-line and per-fee snapshots are rebuilt from whatever is priced so far, so a
            // priced line's own figures are visible on the row even while a sibling is TBD.
            // holdBackUnstatableTotals() then decides whether the document-level figures those
            // produce may be stated.
            $estimate->setSubtotal(number_format($subtotal, 2, '.', ''));
            $this->recomputeFeesAndTax($estimate, $subtotal, $feeResolver, $taxBreakdownService, $manualTaxLines, $shippingLines, $manualFeeLines);
            $this->holdBackUnstatableTotals($estimate, $allPriced);

            $action = (string) $request->request->get('action', 'save');
            $oldStatus = $estimate->getStatus();
            // Send Pricing and Make Visible to Customer are meant to differ only in the email
            // (below, gated on $action === 'send_pricing' and the final status), so both make a
            // Draft visible the same unconditional way — Send Pricing does not skip this just
            // because the quote isn't fully priced yet.
            if ($action === 'save_draft' && ($oldStatus === 'Submitted' || $oldStatus === 'Priced')) {
                // Deliberately no notification: pulling a quote back to Draft is exactly the "the
                // customer should not see this right now" case, and Draft is admin-only/not
                // customer-visible (EstimateStatus's own doc comment) — the point is to make it
                // disappear from the customer's view quietly, not announce that it did.
                $estimate->setStatus('Draft', $this->documentActor(), sprintf('Estimate status changed from %s to Draft.', $oldStatus));
            } elseif ($oldStatus === 'Draft' && ($action === 'send_pricing' || (string) $request->request->get('save_mode', '') === 'submit')) {
                $estimate->setStatus('Submitted', $this->documentActor(), 'Estimate status changed from Draft to Submitted.');
            }

            if ($action === 'send_pricing' && !$estimate->isFullyPriced()) {
                $this->addFlash('error', 'Fill in every line price and shipping before sending pricing to the customer.');
            }

            // Priced is a claim that the grand total is known, and the customer's Accept button
            // keys off the status alone. So an edit that takes the total away again — a blanked
            // line price, a removed shipping row, the setTotal(null) a few lines up — has to take
            // the status down with it, back to Submitted. Hung off isFullyPriced(), the same test
            // send_pricing is gated on, so the status and the total cannot end up disagreeing.
            if ($estimate->isStatus('Priced') && !$estimate->isFullyPriced()) {
                $estimate->setStatus('Submitted', $this->documentActor(), 'Estimate status changed from Priced to Submitted.');
            }

            // The mirror image of the downgrade above, and the fix for a quote getting stuck on
            // Submitted forever: an admin who fills in every remaining line/shipping/tax figure
            // through an ordinary Save (not the explicit "Send Pricing" button above) left the
            // quote fully priced with nothing to ever move it off Submitted. Deliberately does NOT
            // call quoteProvided() — the customer notification stays tied to the explicit Send
            // Pricing action, so finishing the last of several prices across separate saves
            // doesn't silently email the customer before the admin meant to.
            if ($estimate->isStatus('Submitted') && $estimate->isFullyPriced()) {
                $estimate->setStatus('Priced', $this->documentActor(), 'Estimate status changed from Submitted to Priced.');
            }

            // No status row is written here any more. setStatus() writes it, once per REAL move,
            // which is section 8's rule and the reason the deriver stopped writing its own. The
            // sentence is unchanged and is passed at each call above; what changed is that a save
            // which moves a Draft quote through Submitted to Priced now leaves BOTH moves on the
            // timeline instead of one row claiming a jump that never happened.

            // Before the flush, like every other write in this block: the quote already has an id,
            // so a value row has something to point at, and the whole save lands in one commit.
            // Writes ONLY custom_field_value_estimate — no stored figure on the quote is touched.
            $customFieldRenderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, $estimate, $request);

            $entityManager->flush();

            // Every route out of here is a redirect, so the coerced rows travel by flash — the
            // edit form below reads them back and paints those quantity cells red.
            $lineWarnings->flashOnto($request);

            if ($action === 'send_pricing' && $estimate->isStatus('Priced')) {
                $notifier->quoteProvided($estimate);

                $this->addFlash('success', 'Pricing sent to the customer.');

                return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
            }

            if ($action === 'save_draft' && $estimate->isStatus('Draft')) {
                $this->addFlash('success', 'Estimate reverted to Draft — the customer can no longer see it.');

                return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
            }

            $this->addFlash('success', 'Estimate saved.');

            // "Save & Exit" leaves for the detail page like order's "Save Draft & Exit"; every other
            // save ("Save & Recalculate" and the status buttons) stays here so the freshly recomputed
            // fee/tax totals are visible.
            if ($action === 'save_exit') {
                return $this->redirectToRoute('admin_estimate_detail', ['id' => $estimate->getId()]);
            }

            return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
        }

        $taxBreakdown = $taxBreakdownService->tryDecodeTaxLinesJson($estimate->getTaxLines()) ?? [
            'lines' => [],
            'total' => 0.0,
        ];

        $company = $estimate->getCompany();
        $flashedLineWarnings = SalesDocumentLineWarnings::takeFromFlash($request);
        $productsRemote = $this->estimateCatalogExceedsInlineLimit($entityManager);

        return $this->render('admin/estimate/form.html.twig', [
            // A refused save repaints what was typed: the shared line row reads these instead of
            // the stored values. Empty on a GET, so an ordinary render is unchanged. The BYTES are
            // echoed back untouched — qty_rendered and price_rendered are compared byte for byte by
            // LineDenomination::boxUntouched(), so reformatting one here would let the next round
            // trip rewrite a stored figure.
            'submitted' => $this->submittedLinesForRerender($request),
            'estimate' => $estimate,
            'lineWarnings' => $flashedLineWarnings['messages'],
            'lineWarningQtyRows' => $flashedLineWarnings['qtyRows'],
            'lineWarningPriceRows' => $flashedLineWarnings['priceRows'],
            'company' => $this->companyToRow($company),
            'companies' => [],
            'billingAddress' => $this->addressToRow($estimate->getEffectiveBillingAddress(), $company),
            'shippingAddress' => $this->addressToRow($estimate->getEffectiveShippingAddress(), $company),
            'addressBook' => $this->addressBookRows($company),
            // The quote's own region picks the price list its suggested prices come from, the same
            // way OrderController::edit() resolves the order's (OrderController.php:659).
            'products' => $this->estimateProductRows($entityManager, $companyFulfillmentRegionService, $company, $estimate->getFulfillmentRegion(), $productsRemote, $this->productIdsFromEstimateLines($estimate)),
            'productsRemote' => $productsRemote,
            'locations' => $this->estimateLocationRows($entityManager),
            'fulfillmentRegions' => $activeRegions,
            'fulfillmentRegion' => (string) $estimate->getFulfillmentRegion(),
            'staleFulfillmentRegion' => $this->staleFulfillmentRegion($estimate, $activeRegions),
            'perLineTax' => $this->estimatePerLineTax($estimate, $taxBreakdownService),
            'perLineAvailable' => $this->estimatePerLineAvailable($estimate, $stockValidator, $warehouses, $entityManager),
            // The U/M selector's options, per row's product (#659). Empty for a product that lists
            // no units — blank is allowed — and the U/M cell then offers the base unit alone; never
            // a text box, because a PRODUCT declares its unit and typing one over it invents a fact.
            'lineUnits' => $this->lineUnitChoicesFor($this->productIdsFromEstimateLines($estimate), $entityManager),
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
            'shippingOptions' => $this->buildShippingOptions($shippingResolver, $estimate, $company),
            // The stored shipping, manual tax and manual fee lines come back as charge rows: the
            // form rebuilds the estimate from what it posts, so anything it isn't shown is dropped
            // on the next save. The fee rows are the sharpest case — fee_lines is rebuilt from the
            // calculators every save and no calculator has heard of them.
            'chargeLines' => array_merge(
                SalesDocumentChargeLines::fromShippingLines($estimate->getShippingLines()),
                $taxBreakdownService->manualTaxChargeRows($estimate->getTaxLines()),
                SalesDocumentChargeLines::fromFeeLines($estimate->getFeeLineRows()),
            ),
            // The admin-defined fields, in the 'edit' context: the quote exists, so each box holds
            // what is stored against it and only definitions marked visible-on-edit render. Again
            // the only render in this method — every refusal above redirects back to this GET.
            'customFieldFragment' => $customFieldRenderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, $estimate, CustomFieldRenderer::CONTEXT_EDIT),
        ]);
    }

    /**
     * Admin-side accept — #392: a customer who called or emailed in to say "go ahead" has no way to
     * click their own Accept button, so a staff member records that acceptance here.
     *
     * **This marks the quote Accepted and creates nothing.** No sales order, no invoice. Acceptance,
     * raising the order and billing it are three separate deliberate steps on the admin side: the
     * order comes from convertToOrder() below, and its invoice from admin_invoice_create (with order_id) on the
     * order itself. The CUSTOMER's own accept (Customer\EstimateController::accept()) is unchanged
     * and still does all three in one transaction — a buyer clicking Accept has committed to the
     * purchase, so there is nothing left for anyone to decide.
     *
     * Deliberately NOT routed through updateStatus(): that endpoint sets whatever status a dropdown
     * posted and answers JSON, it applies no Priced precondition to Accepted (so a Draft quote could
     * be marked Accepted through it), and it records nothing about staff having accepted on the
     * customer's behalf — which is the one thing #392 asks to be able to see afterwards.
     */
    #[Route('/estimate/accept/{id}', name: 'admin_estimate_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(
        int $id,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        DocumentLockService $locks,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate not found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        // Acceptance writes the quote's status and its timeline, so it is a write to the document.
        $locks->assertWritable($estimate, 'accepted');

        // A quote that already has its order is forwarded to it, exactly as before: a retried click
        // that lands after someone converted should show the order, not argue about the quote.
        $alreadyConverted = $estimate->getConvertedOrder();
        if ($alreadyConverted instanceof SalesOrder) {
            $this->addFlash('success', sprintf('Estimate %s was already accepted — order %s.', $estimate->getDocumentNumber(), $alreadyConverted->getOrderNumber()));

            return $this->redirectToRoute('admin_order_detail', ['id' => $alreadyConverted->getId()]);
        }

        // Already accepted, with no order behind it yet — the state that did not exist while accept()
        // WAS the conversion, because back then Accepted always had an order and the branch above
        // caught it. The ordinary resubmit (the extra click, the stale tab, the retried request)
        // lands here, and must be told the quote is accepted rather than that it "is not ready to
        // accept yet", which is both confusing and wrong: it is accepted, and the next step is the
        // conversion. Same reasoning as the customer side's own already-accepted branch.
        if ($estimate->isStatus('Accepted')) {
            $this->addFlash('error', sprintf(
                'Estimate %s has already been accepted. Use Convert to Sales Order to raise the order.',
                $estimate->getDocumentNumber(),
            ));

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        if (!$estimate->isStatus('Priced')) {
            $this->addFlash('error', 'This estimate is not ready to accept yet — it must be Priced first.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        $documentNumber = $estimate->getDocumentNumber();
        // Who clicked the button, in the same "Name, email (id)" shape logEstimateAction() already
        // writes for every other admin action on a quote.
        $adminIdentity = $this->adminDisplayName();

        try {
            $claimed = $entityManager->wrapInTransaction(
                function (EntityManagerInterface $em) use ($estimate, $adminIdentity): bool {
                    // The status check above is a read-then-write and cannot settle two admins
                    // pressing Accept at the same moment: both read Priced before either writes.
                    // It used to not matter, because the conversion behind it had
                    // claimForConversion() to settle it. Acceptance no longer converts, so it needs
                    // its own claim — otherwise the loser also writes the log entry, and the quote's
                    // timeline says two different people accepted it on the customer's behalf.
                    if (!$this->claimAcceptance($estimate, $em)) {
                        return false;
                    }

                    // Keep the managed entity in step with the row the claim just wrote, so the
                    // UnitOfWork's own UPDATE writes the same value rather than disagreeing with it.
                    //
                    // Through the seam's one door, which also writes the timeline row — so the
                    // sentence that used to go through logEstimateAction() is passed as the comment
                    // rather than written beside it. One move, one row: writing both would be the
                    // double-row defect section 8 exists to prevent.
                    $estimate->setStatus(
                        'Accepted',
                        $this->documentActor(),
                        sprintf(
                            "Quote accepted on the customer's behalf by %s (customer approved by phone/email, not a self-service accept). No sales order was created — use Convert to Sales Order on this quote.",
                            $adminIdentity,
                        ),
                    );

                    $em->flush();

                    return true;
                },
            );
        } catch (\Throwable $e) {
            // The transaction rolled back, so nothing was written — the quote is still Priced and
            // still acceptable. Report that rather than a 500 on a page whose action this is.
            $logger->error('Admin quote acceptance failed.', [
                'estimateId' => $id,
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not accept this quote right now. Please try again.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        if ($claimed !== true) {
            // Lost the race to a simultaneous accept. Nothing was written by this request.
            $this->addFlash('error', sprintf('Estimate %s has already been accepted.', $documentNumber));

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        $this->addFlash('success', sprintf(
            "Estimate %s accepted on the customer's behalf. No sales order or invoice was created — use Convert to Sales Order on this quote to raise the order.",
            $documentNumber,
        ));

        return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
    }

    /**
     * Convert to Sales Order — the second of the admin side's three steps, and where the conversion
     * that #392's accept() used to perform now lives.
     *
     * Everything the old accept() did around convert() is here unchanged: the same single
     * transaction, the same flush-then-snapshot ordering, the same three exception branches, the
     * same notifications. Two things differ.
     *
     * 1. It runs against an ACCEPTED quote rather than a Priced one — acceptance already happened,
     *    possibly days ago and possibly by somebody else.
     * 2. It raises NO invoice. The order is billed afterwards, deliberately, through
     *    admin_invoice_create (with order_id) on the order screen ("Convert to Invoice"), which is the admin
     *    invoicing path that already existed and applies its own approved-only rule. The customer's
     *    own accept still raises the invoice inside the conversion; see QuoteConversionInvoicing.
     */
    #[Route('/estimate/convert/{id}', name: 'admin_estimate_convert', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function convertToOrder(
        int $id,
        EntityManagerInterface $entityManager,
        EstimateConversionService $conversionService,
        SalesDocumentNotifier $notifier,
        FeeCalculatorResolver $feeResolver,
        TaxCalculatorResolver $taxResolver,
        LoggerInterface $logger,
        AdminOrderStockValidator $stockValidator,
        DocumentLockService $locks,
    ): Response {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Estimate not found.');

            return $this->redirectToRoute('admin_estimate_index');
        }

        // Conversion writes converted_order_id and the status onto THIS quote, as well as raising an
        // order. Both halves are refused: a frozen quote does not quietly become an order.
        $locks->assertWritable($estimate, 'converted');

        // Same idempotent-resubmit handling accept() has always had: a retried click that lands
        // after the quote already converted is forwarded to the order it made rather than told to
        // try again. The genuinely simultaneous case is caught inside convert() instead, where the
        // database can settle it; this only spares the common case a pointless transaction.
        $alreadyConverted = $estimate->getConvertedOrder();
        if ($alreadyConverted instanceof SalesOrder) {
            $this->addFlash('success', sprintf('Estimate %s was already converted — order %s.', $estimate->getDocumentNumber(), $alreadyConverted->getOrderNumber()));

            return $this->redirectToRoute('admin_order_detail', ['id' => $alreadyConverted->getId()]);
        }

        // Accepted and nothing else. A Priced quote is one the customer has not agreed to yet, and
        // minting an order for it would be the admin deciding on their behalf without recording
        // that they did — which is exactly what accept() exists to record.
        if (!$estimate->isStatus('Accepted')) {
            $this->addFlash('error', 'Only an accepted quote can be converted to a sales order. Accept it first.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        $companyId = (int) $estimate->getCompany()->getId();
        $documentNumber = $estimate->getDocumentNumber();
        $adminIdentity = $this->adminDisplayName();

        try {
            // One transaction around everything the conversion produces, same as the customer path:
            // without it, the single flush committed the order while the reservation it triggers ran
            // afterwards, so a reservation failure left a committed order holding no stock.
            $order = $entityManager->wrapInTransaction(
                function (EntityManagerInterface $em) use ($estimate, $conversionService, $feeResolver, $taxResolver, $companyId, $adminIdentity): SalesOrder {
                    $order = $conversionService->convert(
                        $estimate,
                        $em,
                        $adminIdentity,
                        // The owner's decision, stated at the call site: an order raised by staff is
                        // not billed by the act of raising it. Named rather than a bare `false`,
                        // because what is being switched off is a document the customer is expected
                        // to pay.
                        invoicing: QuoteConversionInvoicing::NoInvoice,
                    );

                    // logConversion() (inside convert()) already wrote "Quote accepted; converted to
                    // order X." authored as $adminIdentity. This second entry says the one thing
                    // that log alone does not: that no invoice came with it, so an admin reading the
                    // timeline later does not go looking for one that was never raised.
                    $this->logEstimateAction(
                        $estimate,
                        sprintf(
                            'Sales order %s raised from this quote by %s. No invoice was raised with it — bill the order through Convert to Invoice.',
                            $order->getOrderNumber(),
                            $adminIdentity,
                        ),
                        $em,
                    );

                    // Must be flushed before the snapshots below: both writers return early on an
                    // order with no id yet.
                    $em->flush();

                    $feeResolver->applyOrderSnapshots($order, $companyId);
                    $taxResolver->applyOrderSnapshots($order, $companyId);

                    return $order;
                },
            );
        } catch (EstimateAlreadyConvertedException) {
            // Lost the race to a simultaneous conversion (another admin's, or the customer's own
            // accept). Nothing was written by this request.
            $this->addFlash('error', sprintf('Estimate %s has already been converted to an order.', $documentNumber));

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        } catch (UniqueConstraintViolationException $e) {
            $logger->warning('Admin quote conversion order number collided with a concurrent order.', [
                'estimateId' => $id,
                'companyId' => $companyId,
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not create the sales order right now. Please try again.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        } catch (\Throwable $e) {
            // The transaction rolled back, so there is no half-created order to explain — the quote
            // is still Accepted and still convertible.
            $logger->error('Admin quote conversion failed.', [
                'estimateId' => $id,
                'companyId' => $companyId,
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not create the sales order right now. Please try again.');

            return $this->redirectToRoute('admin_estimate_detail', ['id' => $id]);
        }

        // The order is committed by the time these run, so nothing here may turn a successful
        // conversion into an error the admin sees.
        $heldAsDraft = $stockValidator->shortfallsForOrder($order, $entityManager);
        try {
            $notifier->quoteApprovedAdmin($estimate, $order);

            if ($heldAsDraft !== []) {
                $notifier->quoteAcceptedShortOfStockAdmin($estimate, $order, $heldAsDraft);
            }

            // No customer actor to fall back a recipient email onto here — unlike the customer's own
            // accept(), which falls back to the accepting CustomerUser's email address.
            $notifier->orderReceived($order);
        } catch (\Throwable $e) {
            $logger->error('Admin quote conversion notifications failed after the order was created.', [
                'estimateId' => $id,
                'orderId' => $order->getId(),
                'exception' => $e,
            ]);
        }

        // Both halves of what happened, because the half that did NOT happen is the surprising one.
        // The held-as-Draft wording differs because Convert to Invoice refuses an unapproved order
        // (and the order screen does not even offer the button on a Draft), so "go and invoice it"
        // would be an instruction that fails.
        $this->addFlash('success', $heldAsDraft === []
            ? sprintf(
                'Estimate %s converted — sales order %s has been created. No invoice was raised: use Convert to Invoice on this order when you are ready to bill it.',
                $documentNumber,
                $order->getOrderNumber(),
            )
            : sprintf(
                'Estimate %s converted — sales order %s has been created and held as a Draft because stock is short. No invoice was raised: approve the order first, then use Convert to Invoice to bill it.',
                $documentNumber,
                $order->getOrderNumber(),
            ));

        return $this->redirectToRoute('admin_order_detail', ['id' => $order->getId()]);
    }

    /**
     * Atomically claims a Priced quote for THIS acceptance: one UPDATE that moves it Priced →
     * Accepted, which whichever request the database serves first wins and every later one loses.
     *
     * The same mechanism, and the same reasoning, as
     * EstimateConversionService::claimForConversion() — see its docblock for why a conditional
     * UPDATE rather than a locking read. It is a separate copy rather than a shared helper because
     * the two claim different things: that one claims the right to create the ORDER and keys on
     * converted_order_id, this one claims the right to record the ACCEPTANCE and keys on the status
     * it is moving off.
     *
     * Depends on the driver reporting rows MATCHED by an UPDATE. SQLite does, which is what this app
     * runs on everywhere; MySQL's affected-rows would report 0 for a row already at the new value,
     * which is not reachable here because the WHERE demands the old one.
     */
    private function claimAcceptance(Estimate $estimate, EntityManagerInterface $entityManager): bool
    {
        $metadata = $entityManager->getClassMetadata(Estimate::class);

        $claimed = (int) $entityManager->getConnection()->executeStatement(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ? AND %s = ?',
                $metadata->getTableName(),
                $metadata->getColumnName('status'),
                $metadata->getSingleIdentifierColumnName(),
                $metadata->getColumnName('status'),
            ),
            ['Accepted', $estimate->getId(), 'Priced'],
        );

        return $claimed === 1;
    }

    #[Route('/estimate/update-status/{id}', name: 'admin_estimate_update_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(int $id, Request $request, EntityManagerInterface $entityManager, SalesDocumentNotifier $notifier, DocumentLockService $locks): Response
    {
        $estimate = $entityManager->find(Estimate::class, $id);
        if (!$estimate instanceof Estimate) {
            return $this->json(['success' => false, 'message' => 'Estimate not found.'], Response::HTTP_NOT_FOUND);
        }

        // This endpoint answers JSON, and so does the refusal: DocumentLockedSubscriber returns 409
        // Conflict with the same sentence for an XHR caller and flashes it for a plain form post.
        $locks->assertWritable($estimate, 'changed');

        // Accepted is terminal for this endpoint whether or not an order exists behind it yet: the
        // customer has agreed to the quote, and taking that back from a status dropdown is not a
        // thing anyone should be able to do. (It no longer implies an order — since acceptance and
        // conversion split, a quote can sit Accepted with nothing raised against it.)
        if ($estimate->isStatus('Accepted')) {
            return $this->json(['success' => false, 'message' => 'This estimate has already been accepted and can no longer change status.'], Response::HTTP_FORBIDDEN);
        }

        // Untrusted input, so it is checked against the vocabulary and answered with a 400 rather
        // than handed to setStatus(), whose typo guard throws — that guard is for a status a
        // PROGRAMMER typed, and a 500 is the wrong answer to a posted field.
        $status = (string) $request->request->get('status', '');
        if (!isset(Estimate::listStatuses()[$status])) {
            return $this->json(['success' => false, 'message' => 'Invalid status.'], Response::HTTP_BAD_REQUEST);
        }

        // The same guard edit()'s send_pricing button carries. This endpoint is the other way into
        // Priced, and it had no pricing check at all — so a quote with a TBD line price could be
        // marked Priced from the status dropdown and offered to the customer for acceptance with
        // no grand total behind it.
        if ($status === 'Priced' && !$estimate->isFullyPriced()) {
            return $this->json([
                'success' => false,
                'message' => 'Fill in every line price and shipping before marking this estimate Priced.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $oldStatus = $estimate->getStatus();
        // setStatus() writes the timeline row, so the logEstimateAction() that used to sit beside
        // it is gone. The sentence is unchanged; what changed is that re-posting the status a quote
        // already holds is now a silent no-op rather than a row saying it changed from X to X.
        $estimate->setStatus($status, $this->documentActor(), sprintf('Estimate status changed from %s to %s.', $oldStatus, $status));
        $entityManager->flush();

        // Admin-side rejection is customer-facing — the customer submitted this quote request
        // and needs to know it was turned down. See #206 item 3 (this path sent no email at all).
        if ($status === 'Rejected' && $oldStatus !== 'Rejected') {
            $notifier->quoteRejectedCustomer($estimate);
        }

        return $this->json(['success' => true, 'status' => $status]);
    }

    /**
     * The estimate's shipping and tax extras are the charge rows the form's bottom
     * `[line type] + Add Line` control renders into the totals box — the exact mechanism order
     * uses. The estimate has no shipping fields of its own any more: its shipping IS its
     * type=shipping rows, and having none means shipping is still TBD, the one thing an estimate
     * can express and an order can't.
     *
     * Nothing is stored back as charge_lines. A type=shipping row becomes a shipping line, a
     * type=fee row an admin-entered fee line, and a type=tax row an admin-entered tax line — all
     * three returned for recomputeFeesAndTax() to fold into the fee_lines and tax_lines snapshots,
     * since it is the one place that states both.
     *
     * @return array{0: TaxLine[], 1: FeeLine[], 2: FeeLine[]} the manual tax lines, shipping lines
     *                                                         and manual fee lines this post asks for
     */
    private function applyChargeLinesFromRequest(
        Estimate $estimate,
        Request $request,
        ShippingResolver $shippingResolver,
        OrderTaxBreakdownService $taxBreakdownService,
    ): array {
        $charges = SalesDocumentChargeLines::normalize($this->postedChargeRows($request));
        $charges = $this->foldLegacyShippingPostIntoCharges($charges, $request);

        // A post that never rendered the charge UI at all (a bare API/no-JS post of just the
        // lines) must not wipe the charges already stored on the estimate. The manual fee lines
        // are read straight back off the snapshot rather than rebuilt from rows, so a save that
        // was never shown them re-freezes exactly what was already there.
        if (!$request->request->has('charge_lines_present') && $charges === []) {
            return [
                $taxBreakdownService->manualTaxLinesFromCharges(
                    $taxBreakdownService->manualTaxChargeRows($estimate->getTaxLines()),
                ),
                $estimate->getShippingLines(),
                SalesDocumentChargeLines::manualFeeLines($estimate->getFeeLineRows()),
            ];
        }

        $charges = $this->recalculateShippingCharges($charges, $shippingResolver, $estimate);
        $estimate->setShippingMethod(SalesDocumentChargeLines::deriveShippingMethod($charges));

        return [
            $taxBreakdownService->manualTaxLinesFromCharges($charges),
            SalesDocumentChargeLines::toShippingLines($charges, $estimate->getHighestTaxClass()),
            SalesDocumentChargeLines::toFeeLines($charges),
        ];
    }

    /**
     * The raw charge rows this post carries, adjusted for whichever no-JS charge button was pressed.
     *
     * The order form's twin of this is OrderController::postedChargeRows(), and the shared parts —
     * reading the Add Line select's vocabulary, dropping a row by index, deciding what counts as
     * "the" document's shipping — all live in SalesDocumentChargeLines so the two forms cannot
     * answer the same button differently. What is left here is only the wiring.
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

        // Only one row can be "the" quote's shipping, so a second named shipping choice replaces
        // the first rather than stacking beside it — what .js-estimate-bottom-add does in the
        // browser, and what the order form's submit does server-side.
        if (SalesDocumentChargeLines::isNamedShippingRow($added)) {
            $rows = array_filter(
                $rows,
                static fn (mixed $row): bool => !is_array($row) || !SalesDocumentChargeLines::isNamedShippingRow($row),
            );
        }

        $rows[] = $added;

        return array_values($rows);
    }

    /** Whether this post is one of the no-JS charge buttons, which are saves like any other. */
    private function isChargeLineSubmit(Request $request): bool
    {
        return $request->request->has('add_charge_line') || $request->request->has('remove_charge_line');
    }

    /**
     * create()'s door check for a company with no active fulfillment region — see the comment
     * beside its call site for why edit() never runs this. Ported to a symfony/validator
     * constraint for #307, following OrderController::errorForFulfillmentRegionAvailability()'s
     * #306 pattern.
     */
    private function errorForFulfillmentRegionAvailability(array $activeRegions, Company $company): ?string
    {
        $violations = Validation::createValidator()->validate(
            $activeRegions !== [],
            new ValidEstimateFulfillmentRegionAvailability($company->getName()),
        );

        return count($violations) > 0 ? (string) $violations[0]->getMessage() : null;
    }

    /**
     * The retired shipping/shipping_method field pair is still accepted from older callers, but
     * only as another way of writing a type=shipping charge row — never as a second shipping
     * mechanism living alongside them.
     *
     * @param list<array{label: string, amount: float, type: string}> $charges
     * @return list<array{label: string, amount: float, type: string}>
     */
    private function foldLegacyShippingPostIntoCharges(array $charges, Request $request): array
    {
        $shippingRaw = trim((string) $request->request->get('shipping', ''));
        if ($shippingRaw === '' || !is_numeric($shippingRaw) || SalesDocumentChargeLines::shippingRows($charges) !== []) {
            return $charges;
        }

        $method = $this->nullableString($request->request->get('shipping_method'));
        array_unshift($charges, [
            'label' => $method !== null ? sprintf('Shipping (%s)', $method) : 'Custom Shipping',
            'amount' => (float) $shippingRaw,
            'type' => FeeLine::TYPE_SHIPPING,
        ]);

        return $charges;
    }

    /**
     * A named shipping method's amount is never trusted from the browser — it's recomputed from
     * the estimate's final lines/address on every save, exactly like
     * OrderController::recalculateShippingCharge(). "Custom Shipping" and manual Empty Shipping
     * Lines don't match the "Shipping (Method Name)" label format and are left as submitted.
     *
     * @param list<array{label: string, amount: float, type: string}> $charges
     * @return list<array{label: string, amount: float, type: string}>
     */
    private function recalculateShippingCharges(array $charges, ShippingResolver $shippingResolver, Estimate $estimate): array
    {
        $options = null;
        foreach ($charges as $i => $charge) {
            if (($charge['type'] ?? '') !== FeeLine::TYPE_SHIPPING || !preg_match('/^Shipping \((.+)\)$/', (string) ($charge['label'] ?? ''), $m)) {
                continue;
            }

            $options ??= $this->buildShippingOptions($shippingResolver, $estimate, $estimate->getCompany());
            foreach ($options as $option) {
                if ($option['label'] === $m[1]) {
                    $charges[$i]['amount'] = (float) $option['amount'];
                    break;
                }
            }
        }

        return $charges;
    }

    /**
     * @param TaxLine[] $manualTaxLines
     * @param FeeLine[] $shippingLines
     * @param FeeLine[] $manualFeeLines
     */
    private function recomputeFeesAndTax(Estimate $estimate, float $subtotal, FeeCalculatorResolver $feeResolver, OrderTaxBreakdownService $taxBreakdownService, array $manualTaxLines = [], array $shippingLines = [], array $manualFeeLines = []): void
    {
        // A product's fees are only real once its price is — an unpriced line still carries a
        // product, but nothing about it is settled yet. That rule now lives on the document rather
        // than in this loop; see AbstractSalesDocument::getCartItems().
        //
        // Shipping rows are appended to the calculated fees because they are rows of the same list:
        // one snapshot holds everything charged beside the products, and setFeeLines() restates the
        // estimate's cached shipping figure from it. The admin's one-off fee rows are appended for
        // the same reason and on the same terms, differing only in stating their own tax class and
        // placement — see OrderController::edit(), which is the code path an accepted estimate's
        // snapshot is handed to verbatim.
        $feeLines = array_merge(
            $feeResolver->calculate(FeeContext::fromDocument($estimate)),
            $shippingLines,
            $manualFeeLines,
        );
        // An intermediate on the way to the grand total, so it is carried at
        // SalesDocumentMoney::SCALE — the same rule OrderController::sumFeeLines() follows, which is
        // half of what makes the two documents agree (#257).
        $feeTotal = SalesDocumentMoney::intermediate((float) array_sum(array_map(static fn (FeeLine $l) => $l->amount, $feeLines)));
        $estimate->setFeeLines(FeeLineSnapshot::encode($feeLines));

        // Manually added tax rows join the computed breakdown as lines of their own, the way
        // OrderController::edit() folds them into the order's, so the estimate's tax figure stays
        // the sum of its tax lines rather than a total with an unexplained extra on top.
        $taxBreakdown = $taxBreakdownService->withManualTaxLines(
            $taxBreakdownService->computeBreakdownFor($estimate, $feeLines),
            $manualTaxLines,
        );
        // Carried at SCALE for the same reason the fee total is, and for the same reason
        // OrderController does it: rounding tax before adding it is a rounding point before the
        // grand total, and the cent belongs at the grand total only. The tax COLUMN is still stored
        // to the cent — it is a decimal(12,2) figure the admin reads, not the arithmetic.
        $tax = SalesDocumentMoney::intermediate((float) $taxBreakdown['total']);
        $estimate->setTax(number_format($tax, 2, '.', ''));
        $estimate->setTaxLines($taxBreakdownService->toJson($taxBreakdown));
        $estimate->setTotal(number_format($subtotal + $feeTotal + $tax, 2, '.', ''));
    }

    /**
     * Nulls whichever header money figures the quote cannot honestly state yet.
     *
     * `total` used to be the only one held back, so `subtotal` and `tax` kept stating the priced
     * lines' figures as though they were the whole document's — a definite, understated number on
     * a document that had not finished being priced, which the admin quote PDF then printed as a
     * flat `$0.00` through `|default(0)` while the customer PDF of the same quote said "Pricing
     * pending" (#254). Each figure is now held back exactly when its own inputs are incomplete:
     *
     * - `subtotal` is the goods, so it needs every LINE priced; shipping has no part in it.
     * - `tax` is charged on the shipping row too, so it needs the lines AND shipping.
     * - `total` is everything, so it needs both — unchanged from before.
     *
     * That is also the split CheckoutController's quote path applies, so a customer-raised quote
     * and an admin-saved one populate the same fields, and `Estimate::isFullyPriced()` — which
     * wants all three columns plus shippingTotal — answers exactly as it did before.
     *
     * `fee_lines` and `tax_lines` are deliberately NOT cleared: those are per-row snapshots, and a
     * priced row's own fee/tax is real whatever its siblings are doing. They are what lets a
     * half-priced quote still show real per-line tax beside the rows reading TBD (#255).
     */
    private function holdBackUnstatableTotals(Estimate $estimate, bool $allPriced): void
    {
        if (!$allPriced) {
            $estimate->setSubtotal(null);
        }

        // Read shipping back off the estimate rather than from the caller's charge rows:
        // setFeeLines() restates the cached shipping figure from the snapshot just written, so this
        // is the same answer every reader downstream will get.
        if (!$allPriced || $estimate->getShippingTotal() === null) {
            $estimate->setTax(null);
            $estimate->setTotal(null);
        }
    }

    /**
     * Same computed carrier/method/cost options OrderController::buildShippingOptions() resolves
     * for the order form's shipping select, adapted for an estimate's lines/address — so the
     * estimate form's shipping control stops being raw manual guesswork.
     *
     * `$estimate` is null on the create page, where there's no document to read lines/address off
     * yet: the options then come from the company's default shipping address alone, the same
     * fallback order's nullable-order version uses.
     *
     * @return array<int, array{id: mixed, label: string, amount: float, deliveryDays: mixed, taxClass: mixed}>
     */
    private function buildShippingOptions(ShippingResolver $resolver, ?Estimate $estimate, Company $company): array
    {
        // An empty estimate stands in on the create page, the same way OrderController's does — a
        // transient document nothing ever persists.
        $estimate ??= (new Estimate())
            ->setCompany($company)
            ->setShippingAddressFrom($this->defaultAddress($company, 'shipping'));

        return array_map(
            fn ($o) => [
                'id'           => $o->id,
                'label'        => $o->label,
                'amount'       => $o->amount,
                'deliveryDays' => $o->deliveryDays,
                'taxClass'     => $o->taxClass,
            ],
            $resolver->getAvailableOptions($estimate)
        );
    }

    /**
     * Re-applies the whole submitted line set to the estimate: rows carrying a known
     * `lines[N][id]` update that line in place (re-snapshotting the product when it changed), rows
     * without one become new lines, and any existing line the submission left out is removed. Used
     * by both create() and edit() so an existing estimate's lines stay as editable as a brand new
     * one's.
     *
     * The rows arrive from AbstractAdminController::postedLineRows(), which is where the
     * `lines[N][field]` convention and the no-JS Remove handling live — shared with the order and
     * both invoice create paths so the four forms cannot drift apart again. Each row's fields are
     * grouped under the row's own index, so there is no longer any alignment for a row to lose;
     * see that method for what the parallel `line_*[]` arrays used to cost.
     *
     * $lineWarnings collects the quantities this rewrote, the way OrderController's save paths do —
     * see the note on the quantity below.
     *
     * @return array{count: int, allPriced: bool, subtotal: float}
     */
    private function applyLinesFromRequest(
        Request $request,
        EntityManagerInterface $entityManager,
        Estimate $estimate,
        SalesDocumentLineWarnings $lineWarnings,
        EstimateLineReconciler $lineReconciler,
    ): array {
        $rows = $this->postedLineRows($request);

        /** @var array<int, EstimateLine> $existingLines */
        $existingLines = [];
        foreach ($estimate->getLines() as $existingLine) {
            $existingLines[(int) $existingLine->getId()] = $existingLine;
        }

        // Row-by-row decisions (id matching, boxUntouched semantics, catalog defaults, the "TBD"
        // price state only a quote reaches) live in EstimateLineReconciler now — the same process
        // SellSideLineReconciler runs for Order, with the two seams (cost/price defaulting) that
        // genuinely differ overridden there. This loop's only job is writing each resolved row onto
        // EstimateLine specifically; the three sell-side line entities share no common setter
        // surface to do that generically.
        $reconciled = $lineReconciler->reconcile($existingLines, $rows, $estimate->getFulfillmentRegion(), $lineWarnings);

        if ($reconciled['lines'] === []) {
            // An empty submission is a mistake, never "delete every line" — leave the estimate
            // alone and let the caller report it.
            return ['count' => 0, 'allPriced' => false, 'subtotal' => 0.0];
        }

        foreach ($existingLines as $existingId => $existingLine) {
            if (!isset($reconciled['keptIds'][$existingId])) {
                $estimate->removeLine($existingLine);
            }
        }

        foreach ($reconciled['lines'] as $resolved) {
            $line = $resolved->existingId !== null ? $existingLines[$resolved->existingId] : new EstimateLine();

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
                ->setPrice($resolved->price)
                ->setSubtotal($resolved->lineSubtotal)
                ->setSortOrder($resolved->sortOrder);

            $estimate->addLine($line);
        }

        return ['count' => count($reconciled['lines']), 'allPriced' => $reconciled['allPriced'], 'subtotal' => (float) $reconciled['subtotal']];
    }

    /**
     * The estimate line table renders the same columns the order line table does, so each option
     * has to carry the same `data-*` payload the shared change handler copies into the row's
     * sibling fields — not just name/sku/price.
     *
     * The Price it suggests is resolved exactly the way OrderController::orderProductRows() resolves
     * an order's (OrderController.php:2137): through the company's fulfillment region to the price
     * list that region is on. This used to take whichever ProductPricing row had the lowest id,
     * ignoring the company entirely — so a company on a premium list was quoted off a wholesale one,
     * and the order the quote converted into then priced correctly. The customer was quoted one
     * figure and invoiced another, with nothing in between flagging the change (#238).
     *
     * Above this many active products, the quote form stops pre-rendering the whole catalog into
     * the product <select> (#399: same fix, same threshold, as OrderController's twin —
     * OrderController::PRODUCT_SELECT_INLINE_LIMIT / orderCatalogExceedsInlineLimit()). Below it,
     * the form behaves exactly as before.
     */
    private const PRODUCT_SELECT_INLINE_LIMIT = 200;

    /** Matches per search response; the picker pages with "Show more". Order's twin holds the same value. */
    private const PRODUCT_SEARCH_PAGE_SIZE = 50;

    /** True once the catalog is too large to pre-render into every line's <select> (#399). */
    private function estimateCatalogExceedsInlineLimit(EntityManagerInterface $entityManager): bool
    {
        return $entityManager->getRepository(ProductCore::class)->count(['deleted' => false]) > self::PRODUCT_SELECT_INLINE_LIMIT;
    }

    /**
     * Past PRODUCT_SELECT_INLINE_LIMIT, this only pre-renders options for products a line already
     * points at — everything else is fetched on demand by searchEstimateProducts() as the admin
     * types. $includeProductIds is empty on every blank/new line, which is the common case once
     * the cap is in effect, so this returns no rows at all rather than touching the repository.
     *
     * @param ?Company  $company                   the quote's company, when one has been chosen
     * @param ?string   $regionName                the quote's fulfillment region, when one has been chosen
     * @param bool      $catalogExceedsInlineLimit estimateCatalogExceedsInlineLimit(), computed once per
     *                                              request and passed in so every call against the same
     *                                              request agrees
     * @param list<int> $includeProductIds         ids already selected on one of the quote's lines — ignored
     *                                              below the cap, where the whole catalog renders regardless
     *
     * @return array<int, array{id: string, name: string, sku: string, weight: string, unit: string, taxCode: string, cost: string, originalPrice: string, price: string}>
     */
    private function estimateProductRows(
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

        // Batched: a findOneBy() per product meant the whole catalog's worth of extra queries on
        // every render of the quote form below the inline limit.
        $pricingByProductId = $this->pricingForProducts($entityManager, $priceList, $products);

        $rows = [];
        foreach ($products as $product) {
            $rows[] = $this->estimateProductRow($product, $pricingByProductId[(int) $product->getId()] ?? null);
        }

        return $rows;
    }

    /** @return list<int> ids of every line's product, deduplicated — estimateProductRows()'s seed set */
    private function productIdsFromEstimateLines(Estimate $estimate): array
    {
        $ids = [];
        foreach ($estimate->getLines() as $line) {
            $id = $line->getProduct()?->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The catalog search behind the quote form's product <select> (#399): the full catalog no
     * longer renders into the page, so this is how the admin finds anything not already on one of
     * the quote's lines. Same row shape and price precedence as estimateProductRows(), same
     * deleted-product exclusion, just resolved for a search term instead of a fixed id list.
     */
    #[Route('/estimate/products/search', name: 'admin_estimate_product_search', methods: ['GET'])]
    public function searchEstimateProducts(
        Request $request,
        EntityManagerInterface $entityManager,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
    ): Response {
        $term = trim((string) $request->query->get('q', ''));
        // Plain get() + cast, NOT getInt() — see OrderController::searchOrderProducts() for why:
        // the picker sends company_id="" when no company is chosen yet, and getInt() answers that
        // with an HTTP 400 instead of simply pricing without a price list.
        $companyId = (int) $request->query->get('company_id', 0);
        $company = $companyId > 0 ? $entityManager->find(Company::class, $companyId) : null;
        $regionName = trim((string) $request->query->get('region', ''));

        // #399: nothing is searched below two characters — same floor, same reasoning as
        // OrderController::searchOrderProducts(); an empty term used to skip the LIKE and return
        // the first 50 products of the catalog. Keep in step with MIN_SEARCH_CHARS in app.js.
        if (mb_strlen($term) < 2) {
            return $this->json(['products' => [], 'hasMore' => false]);
        }

        $offset = max(0, (int) $request->query->get('offset', 0));

        $qb = $entityManager->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->where('p.deleted = false')
            ->andWhere('p.name LIKE :term OR p.sku LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('p.name', 'ASC')
            // Stable total order, or LIMIT/OFFSET can repeat or skip rows between pages wherever
            // product names collide — see OrderController::searchOrderProducts().
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult($offset)
            // One over the page size, to answer "is there more?" without a second COUNT.
            ->setMaxResults(self::PRODUCT_SEARCH_PAGE_SIZE + 1);

        $priceList = $company instanceof Company
            ? $companyFulfillmentRegionService->priceListForCompanyRegion($company, $regionName !== '' ? $regionName : null)
            : null;

        $found = $qb->getQuery()->getResult();
        $hasMore = count($found) > self::PRODUCT_SEARCH_PAGE_SIZE;
        $page = array_slice($found, 0, self::PRODUCT_SEARCH_PAGE_SIZE);

        // One query for the page's pricing rather than one per product — see
        // OrderController::searchOrderProducts(); the N+1 this replaces dominated the response time.
        $pricingByProductId = $this->pricingForProducts($entityManager, $priceList, $page);

        $rows = [];
        foreach ($page as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $rows[] = $this->estimateProductRow($product, $pricingByProductId[(int) $product->getId()] ?? null);
        }

        return $this->json(['products' => $rows, 'hasMore' => $hasMore]);
    }

    /**
     * Batched ProductPricing lookup for a set of products on one price list, keyed by product id.
     * OrderController::pricingForProducts()'s twin.
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
            // IDENTITY() rather than pr.getProduct()->getId(), so a product that happens not to be in
            // the identity map cannot trigger a lazy proxy load — see OrderController's twin.
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

    /** @return array{id: string, name: string, sku: string, weight: string, unit: string, taxCode: string, cost: string, originalPrice: string, price: string} */
    private function estimateProductRow(ProductCore $product, ?ProductPricing $pricing): array
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
            'price' => $this->estimateProductPrice($product, $pricing),
        ];
    }

    /**
     * The order form's fallback chain, verbatim (OrderController::orderProductPrice()): the price
     * the company's price list sets for its region, else the product's default price, else its
     * original price — so a picked product never leaves Price blank, and a quote and the order it
     * converts into never disagree about what a line costs.
     */
    private function estimateProductPrice(ProductCore $product, ?ProductPricing $pricing): string
    {
        if ($pricing instanceof ProductPricing) {
            return (string) $pricing->getPrice();
        }

        $base = $product->getDefaultPrice();
        if ($base === null || $base === '') {
            $base = $product->getOriginalPrice();
        }

        return $base ?? '';
    }

    /**
     * The company's own active regions — what actually decides its price list
     * (CompanyFulfillmentRegionService::priceListForCompanyRegion()), unlike estimateLocationRows()
     * below which lists every region system-wide for the per-line Location picker. Order's
     * orderCompanyRegionRows() twin.
     *
     * @return list<string>
     */
    private function estimateCompanyRegionRows(CompanyFulfillmentRegionService $companyFulfillmentRegionService, ?Company $company): array
    {
        if (!$company instanceof Company) {
            return [];
        }

        return array_map(
            static fn (CompanyFulfillmentRegion $row): string => $row->getFulfillmentRegion()->getName(),
            $companyFulfillmentRegionService->activeRowsForCompany($company),
        );
    }

    /**
     * The quote's stored region when the company no longer has it active — what the form warns
     * about. A quote with no region at all is a different case entirely (it has simply never been
     * given one) and must not produce the warning, and neither must "the company has none at all"
     * on a quote that never held one.
     *
     * @param list<string> $activeRegions
     */
    private function staleFulfillmentRegion(Estimate $estimate, array $activeRegions): ?string
    {
        $stored = trim((string) $estimate->getFulfillmentRegion());
        if ($stored === '') {
            return null;
        }

        return $this->matchFulfillmentRegionName($stored, $activeRegions) === null ? $stored : null;
    }

    /**
     * Applies the Fulfillment Region field onto the quote, under the rules #237 settled and #238
     * carries onto quotes. Returns the refusal message, or null when the save may proceed.
     *
     * An ABSENT field means UNCHANGED, never cleared. That is #237's whole bug on the order form:
     * a company whose regions are deactivated renders a nameless disabled input, nothing is posted,
     * and the stored region is wiped on the next save — which then prices the document against a
     * different price list. Guarded on presence, the way applyEstimateCompanyCard() guards its own
     * fields.
     *
     * A SUBMITTED value must be one of the company's active regions or the one the quote already
     * holds. Without that, "required" would only prove the box was non-empty, and a hand-edited
     * POST could name a region the company has no assignment for — which resolves to no price list
     * and prices the quote off the product default.
     *
     * A STALE stored region (set, but no longer among the company's active ones) satisfies the
     * requirement by itself. Refusing it would force an admin to re-point the region of a quote in
     * flight before they could correct a PO number, which is the freeze #237 withdrew.
     *
     * @param list<string> $activeRegions
     */
    private function applyEstimateFulfillmentRegion(Estimate $estimate, Request $request, array $activeRegions): ?string
    {
        $current = trim((string) $estimate->getFulfillmentRegion());

        if ($request->request->has('fulfillment_region')) {
            $submitted = trim((string) $request->request->get('fulfillment_region'));
            if ($submitted !== '') {
                $match = $this->matchFulfillmentRegionName($submitted, $activeRegions);
                if ($match === null && strcasecmp($submitted, $current) !== 0) {
                    return sprintf(
                        '"%s" is not an active fulfillment region for %s.',
                        $submitted,
                        $estimate->getCompany()->getName(),
                    );
                }

                // The stored spelling wins for the stale case, so keeping a region can never
                // rewrite it into whatever casing the POST happened to carry.
                $current = $match ?? $current;
                $estimate->setFulfillmentRegion($current);
            }
            // A blank submitted value is not a clear either — it falls through to the requirement
            // below with whatever the quote already holds.
        }

        // Required wherever it is satisfiable: the company has regions to pick from, so a quote
        // with none has no price list and no correct price. A company with no active regions
        // cannot satisfy it, so the requirement does not apply there — create() has already
        // refused that case, and edit() deliberately allows it.
        if ($activeRegions !== [] && $current === '') {
            // Exactly one active region is an unambiguous answer, and it is the one the rendered
            // form would have posted — the region field pre-selects it for that reason. Answering
            // it here rather than refusing means a caller that never saw the select lands on the
            // region a browser would have sent, instead of being told to choose between one
            // option. OrderController::resolveFulfillmentRegion() does the same; these two rules
            // have to agree or the same POST is accepted on one form and refused on the other.
            if (count($activeRegions) === 1) {
                $estimate->setFulfillmentRegion($activeRegions[0]);

                return null;
            }

            return sprintf(
                'Select a fulfillment region for %s before saving this quote.',
                $estimate->getCompany()->getName(),
            );
        }

        return null;
    }

    /**
     * Case-insensitive, matching how priceListForCompanyRegion() matches region names, and
     * returning the stored spelling rather than the submitted one.
     *
     * @param list<string> $regionNames
     */
    private function matchFulfillmentRegionName(string $submitted, array $regionNames): ?string
    {
        foreach ($regionNames as $regionName) {
            if (strcasecmp($regionName, $submitted) === 0) {
                return $regionName;
            }
        }

        return null;
    }

    /**
     * Options for a line's Location column — the active fulfillment regions, same as order's.
     *
     * @return list<string>
     */
    private function estimateLocationRows(EntityManagerInterface $entityManager): array
    {
        $regions = array_map(
            static fn (FulfillmentRegion $region): string => $region->getName(),
            $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        );

        return $regions !== [] ? $regions : ['Main'];
    }

    /**
     * Per-line tax for the line table's Tax $ column, keyed by the line's position in the
     * collection — display only, computed the same way recomputeFeesAndTax() computes the header
     * tax so the two can never disagree.
     *
     * @return array<int, float>
     */
    /**
     * Stock available for each line's product in its region, keyed by the line's position — the
     * same shape estimatePerLineTax() uses, so the template reads both the same way.
     *
     * Display only. A quote never reserves anything, at any status: the reconciliation subscriber
     * reacts to SalesOrder and SalesOrderLine and has never heard of Estimate, so inventory is only
     * touched once EstimateConversionService::convert() turns an accepted quote into an order. That
     * is exactly why the figure is worth showing (#327) — an admin building a large quote has no
     * other way to know whether it can actually be filled, and nothing about the quote will tell
     * them later either.
     *
     * Unlike the order form there is no own-hold to add back: the quote holds nothing, so raw
     * availability IS what it would be competing for on conversion. Sharing
     * AdminOrderStockValidator with orders keeps the two figures computed one way; a quote showing
     * a different number from the order it becomes would be worse than showing none.
     *
     * Null for a line with no product, or one whose region resolves to no warehouse — the same two
     * cases the order form leaves blank.
     *
     * @return array<int, int|null>
     */
    private function estimatePerLineAvailable(Estimate $estimate, AdminOrderStockValidator $stockValidator, WarehouseFulfillmentRegionService $warehouses, EntityManagerInterface $entityManager): array
    {
        $warehousesByLowerRegionName = $warehouses->warehousesByLowerRegionName();

        $available = [];
        $index = 0;
        foreach ($estimate->getLines() as $line) {
            $product = $line->getProduct();
            $warehouse = $product instanceof ProductCore
                ? OrderInventoryBucketResolver::resolveLineWarehouse($line->getLocation(), $estimate->getFulfillmentRegion(), $warehousesByLowerRegionName)
                : null;

            $available[$index] = $product instanceof ProductCore && $warehouse instanceof Warehouse
                // No order passed: a quote contributes nothing to any bucket, so there is nothing of
                // its own to add back.
                ? $stockValidator->availableFor($product, $warehouse, null, $entityManager)
                : null;

            ++$index;
        }

        return $available;
    }

    private function estimatePerLineTax(Estimate $estimate, OrderTaxBreakdownService $taxBreakdownService): array
    {
        if ($estimate->getLines()->isEmpty()) {
            return [];
        }

        // No fee or shipping rows: only the per-line figures are wanted here, and those rows add
        // tax that belongs to the document rather than to any one line.
        return $taxBreakdownService->computeBreakdownFor($estimate, [])['perLineTax'];
    }

    private function logEstimateAction(Estimate $estimate, string $comment, EntityManagerInterface $entityManager): void
    {
        $estimate->queueActivityLogEntry()
            ->setUserName($this->adminDisplayName())
            ->setComment($comment)
            ->setType('System');
    }

    /**
     * A submit whose hidden `version` field no longer matches what is in the database was rendered
     * from a page that was already stale before this request began — admin A's save committed after
     * admin B loaded the form, and B's fields are edits made against a document that no longer
     * exists in the shape B is editing it as. Nothing from the post is applied; this is called
     * before a single setter runs.
     *
     * The exact counterpart of OrderController::rejectStaleOrderSubmit(), down to the sentence the
     * admin reads: this is the same situation on a different document, and two different wordings
     * for it would be two things to learn rather than one. Only the noun changes, because the screen
     * calls it a quote and its number is a document number rather than an order number.
     *
     * Sends the admin back to a fresh copy of the edit page (current data, current version, so the
     * next submit starts clean) rather than re-rendering their now-unusable post — the flash is the
     * only place the "why" survives that redirect. A redirect rather than a 422 re-render because
     * every other refusal on this screen redirects too (the charge-row and region doors just below),
     * and the estimate form is rebuilt from what it posts.
     *
     * The log entry is the durable half. A refused save that said nothing in the timeline would
     * leave the admin whose work was kept with no way to see that somebody else's had been turned
     * away against it.
     */
    private function rejectStaleEstimateSubmit(Estimate $estimate, int $submittedVersion, EntityManagerInterface $entityManager): Response
    {
        $this->logEstimateAction(
            $estimate,
            sprintf(
                'Save rejected: submitted version %d does not match the quote\'s current version %d. No changes were applied.',
                $submittedVersion,
                $estimate->getVersion(),
            ),
            $entityManager,
        );
        // Writes the log row and nothing else: $estimate itself was never touched, so Doctrine has
        // no UPDATE to emit for it and the refusal cannot move the very version it just refused
        // over.
        $entityManager->flush();

        $this->addFlash('error', sprintf(
            'Estimate %s was changed by someone else while you had this page open. Your edits were not saved — review the current version below and make your changes again.',
            $estimate->getDocumentNumber(),
        ));

        return $this->redirectToRoute('admin_estimate_edit', ['id' => $estimate->getId()]);
    }

    /**
     * Who is pressing the button, for the seam's `setStatus()`.
     *
     * `DocumentActor::forAdmin()` builds the same "First Last, email (id)" displayName this
     * controller has been writing onto quote timelines since #271 — `DocumentActor::displayNameFor()`
     * and the old body here were character-for-character the same computation — so adminDisplayName()
     * now reads it off the actor rather than rebuilding it, and no timeline row changes shape.
     *
     * Falls back to the System actor when nobody is signed in, which is what the old body returned
     * and what `DocumentActorResolver` returns for the same case.
     */
    private function documentActor(): DocumentActor
    {
        $user = $this->getUser();

        return $user instanceof AdminUser ? DocumentActor::forAdmin($user) : DocumentActor::system();
    }

    private function adminDisplayName(): string
    {
        return $this->documentActor()->displayName;
    }

    /**
     * Applies the Estimate Info card's Primary Email / Company Phone onto the estimate's OWN frozen
     * company identity.
     *
     * Order's form writes these straight back onto the live Company row
     * (OrderController::submit()), which is exactly the drift CompanyIdentity exists to prevent:
     * correcting a contact detail while quoting one customer would rewrite their record and every
     * other document that reads through it. The address cards already resolved this the same way.
     *
     * A no-op when neither field was submitted, so a bare/no-JS POST that never rendered the card
     * leaves the snapshot setCompany() froze alone.
     */
    private function applyEstimateCompanyCard(Estimate $estimate, Request $request): void
    {
        $hasEmail = $request->request->has('primary_email');
        $hasPhone = $request->request->has('company_phone');
        if (!$hasEmail && !$hasPhone) {
            return;
        }

        $snapshot = $estimate->getCompanyIdentity()->toArray();
        if ($hasEmail) {
            $snapshot['email'] = $this->nullableString($request->request->get('primary_email'));
        }
        if ($hasPhone) {
            $snapshot['phone'] = $this->nullableString($request->request->get('company_phone'));
        }

        $estimate->setCompanySnapshot($snapshot);
    }

    /**
     * The Estimate Info card's Quote Date, order's Invoice Date equivalent. Ignores unparseable
     * input. Named "Quote Date" here (not "Order Date") because a quote isn't an order;
     * setDocumentDate() is still the shared AbstractSalesDocument setter Order/Cart use too.
     */
    private function applyEstimateQuoteDate(Estimate $estimate, Request $request): void
    {
        $quoteDate = TextInput::calendarDate($request->request->get('quote_date'));
        if ($quoteDate !== null) {
            $estimate->setDocumentDate($quoteDate);
        }
    }

    /**
     * Applies a submitted Billing/Shipping Detail card onto the estimate's own address snapshot —
     * same pattern OrderController::applyOrderAddressFromRequest() uses. The address book is only
     * ever READ here; source_address_id records which entry the snapshot came from. A no-op when the
     * card was never rendered (a bare/no-JS submit made before any company was chosen), so a plain
     * save leaves whatever address create()'s company-default fallback already set untouched.
     */
    /**
     * The quote's own live fee/tax preview, ported from OrderController's
     * feeLinesAjax()/taxBreakdownAjax()/previewOrderFromRequest() verbatim
     * (#full-parity, 2026-09-13 — the owner's ruling: Order's implementation is the source of
     * truth for this, and a quote has the exact same need for a live preview an order does; only
     * whether a line/shipping is priced YET differs, which is a display concern the shared JS
     * layers on top, not a reason for a different computation here).
     */
    #[Route('/estimate/fee-lines', name: 'admin_estimate_fee_lines', methods: ['GET'])]
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

        $preview = $this->previewEstimateFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
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

    #[Route('/estimate/tax-breakdown', name: 'admin_estimate_tax_breakdown', methods: ['GET'])]
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

        $preview = $this->previewEstimateFromRequest($entityManager, $company, $province, $addressId, $linesRaw);
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
     * The quote-typed twin of OrderController::previewOrderFromRequest() — see
     * InvoiceController::previewInvoiceFromRequest()'s own docblock for why this cannot be one
     * shared method across all three documents (PHP's type system, not a business decision).
     */
    private function previewEstimateFromRequest(
        EntityManagerInterface $entityManager,
        Company $company,
        string $province,
        int $addressId,
        string $linesJson,
    ): Estimate {
        $estimate = (new Estimate())->setCompany($company);

        $address = $estimate->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING);
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

            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($product instanceof ProductCore ? $product : null)
                    ->setQuantity(number_format(max(0.0, (float) ($row['qty'] ?? 0)), 2, '.', ''))
                    ->setSubtotal(number_format((float) ($row['subtotal'] ?? 0), 2, '.', ''))
                    // #full-parity, 2026-09-13 — see OrderController::previewOrderFromRequest()'s
                    // own comment on this same fix: a present-but-empty tax_code must fall back to
                    // the product's default, the same as applyLinesFromRequest() already does a few
                    // hundred lines up in this very file.
                    ->setTaxCode($this->nullableString($row['tax_code'] ?? null)
                        ?? ($product instanceof ProductCore ? $product->getSalesTaxCode() : null)),
            );
        }

        return $estimate;
    }

    private function applyEstimateAddressCard(Estimate $estimate, Company $company, string $type, Request $request, EntityManagerInterface $entityManager): void
    {
        $idKey = $type . '_address_id';
        if (!$request->request->has($idKey)) {
            return;
        }

        $snapshot = $estimate->addressForWriting($type);

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
}
