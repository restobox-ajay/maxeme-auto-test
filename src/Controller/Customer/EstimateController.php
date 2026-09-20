<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\SalesOrder;
use App\Enum\QuoteConversionInvoicing;
use App\Service\AppSettings;
use App\Service\DocumentActorResolver;
use App\Service\EstimateAlreadyConvertedException;
use App\Service\EstimateConversionService;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\OrderTaxBreakdownService;
use App\Service\SalesDocumentNotifier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use FeeBundle\Fee\FeeCalculatorResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TaxBundle\Tax\TaxCalculatorResolver;

#[Route('/estimates')]
final class EstimateController extends AbstractCustomerController
{
    private const EXPECTED_DELIVERY_PREFIX = 'Expected delivery time:';
    private const ORDER_PHONE_PREFIX = 'Order phone number:';
    private const PAYMENT_NOTE_PREFIX = 'Preferred payment method:';

    #[Route('', name: 'customer_estimates', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your quote requests.');

            return $this->redirectToRoute('customer_login');
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');

            return $this->redirectToRoute('customer_home');
        }

        $tab = strtoupper(trim((string) $request->query->get('tab', 'ALL')));
        $tab = in_array($tab, ['ALL', 'SUBMITTED', 'PRICED', 'ACCEPTED', 'REJECTED'], true) ? $tab : 'ALL';

        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }
        // filters[warehouse] is still accepted so bookmarked and shared links keep working;
        // filters[region] is what the form submits now. A customer picks a fulfillment region --
        // the warehouse behind it is a physical fact they never see (#546).
        $regionFilter = trim((string) ($filters['region'] ?? $filters['warehouse'] ?? ''));

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));
        $sort = $this->normalizedListSort((string) $request->query->get('sort', 'created_at'), [
            'id', 'product', 'po', 'region', 'warehouse', 'status', 'total', 'created_at',
        ], 'created_at');
        $dir = $this->normalizedSortDir((string) $request->query->get('dir', 'desc'), 'desc');

        // Draft estimates are admin-only, same convention as SalesOrder's Draft status.
        $baseQb = $entityManager->getRepository(Estimate::class)->createQueryBuilder('e')
            ->andWhere('e.company = :company')->setParameter('company', $company)
            ->andWhere('e.status != :draft')->setParameter('draft', 'Draft');

        if ($tab !== 'ALL') {
            // Against the vocabulary now, not `EstimateStatus::from()`, which threw a raw
            // \ValueError on a tab somebody typed into the URL. Unknown tabs match nothing rather
            // than 500, and the set they are checked against is the one the app actually ships.
            $tabStatus = ucfirst(strtolower($tab));
            $baseQb->andWhere('e.status = :tabStatus')
                ->setParameter('tabStatus', isset(Estimate::listStatuses()[$tabStatus]) ? $tabStatus : '');
        }

        if ($regionFilter !== '') {
            $baseQb->andWhere('LOWER(COALESCE(e.fulfillmentRegion, \'\')) = :regionFilter')
                ->setParameter('regionFilter', mb_strtolower($regionFilter));
        }

        $countQb = clone $baseQb;
        $total = (int) $countQb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $idQb = clone $baseQb;
        $this->applyEstimateListSorting($idQb, $sort, $dir);
        $idRows = $idQb->select('e.id')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();
        $estimateIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $idRows);

        $estimates = $this->loadEstimatesByIds($entityManager, $estimateIds);
        $expectedDeliveryByEstimateId = [];
        $specialInstructionsByEstimateId = [];
        foreach ($estimates as $estimate) {
            $estimateId = (int) $estimate->getId();
            $expectedDeliveryByEstimateId[$estimateId] = $this->requestedExpectedDelivery($estimate);
            $specialInstructionsByEstimateId[$estimateId] = $this->visibleSpecialInstructions($estimate);
        }

        return $this->render('customer/estimate/index.html.twig', [
            'estimates' => $estimates,
            'activeTab' => $tab,
            'regionOptions' => $this->customerFulfillmentRegionNames($entityManager, $appSettings),
            'regionFilter' => $regionFilter,
            'expectedDeliveryByEstimateId' => $expectedDeliveryByEstimateId,
            'specialInstructionsByEstimateId' => $specialInstructionsByEstimateId,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'currentSort' => $sort,
            'currentDir' => $dir,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'customer_estimate_detail', methods: ['GET'])]
    public function detail(int $id, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $estimate = $this->companyScopedEstimate($id, $entityManager);
        if ($estimate === null) {
            return $this->redirectToRoute('customer_estimates');
        }

        $taxBreakdown = $taxBreakdownService->tryDecodeTaxLinesJson($estimate->getTaxLines()) ?? [
            'lines' => [],
            'total' => 0.0,
            'perLineTax' => [],
            'perLineTaxLabel' => [],
        ];

        return $this->render('customer/estimate/detail.html.twig', [
            'estimate' => $estimate,
            'expectedDelivery' => $this->requestedExpectedDelivery($estimate),
            'specialInstructions' => $this->visibleSpecialInstructions($estimate),
            'taxLines' => $taxBreakdown['lines'],
            'taxLinesTotal' => $taxBreakdown['total'],
            'perLineTax' => $taxBreakdown['perLineTax'],
            'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
        ]);
    }

    #[Route('/{id<\d+>}/quote', name: 'customer_estimate_quote', methods: ['GET'])]
    public function quotePdf(int $id, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $estimate = $this->companyScopedEstimate($id, $entityManager);
        if ($estimate === null) {
            return $this->redirectToRoute('customer_estimates');
        }

        $taxBreakdown = $taxBreakdownService->tryDecodeTaxLinesJson($estimate->getTaxLines()) ?? [
            'lines' => [],
            'total' => 0.0,
        ];

        $html = $this->renderView('customer/estimate/quote_pdf.html.twig', [
            'estimate' => $estimate,
            'billingAddress' => $estimate->getEffectiveBillingAddress(),
            'shippingAddress' => $estimate->getEffectiveShippingAddress(),
            'taxLines' => $taxBreakdown['lines'],
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

    #[Route('/{id<\d+>}/accept', name: 'customer_estimate_accept', methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        EstimateConversionService $conversionService,
        SalesDocumentNotifier $notifier,
        FeeCalculatorResolver $feeResolver,
        TaxCalculatorResolver $taxResolver,
        LoggerInterface $logger,
        AdminOrderStockValidator $stockValidator,
    ): Response {
        $estimate = $this->companyScopedEstimate($id, $entityManager);
        if ($estimate === null) {
            return $this->redirectToRoute('customer_estimates');
        }

        $token = (string) $request->request->get('_token', '');

        // Ahead of the status check, because a resubmit that arrives after the first one finished
        // — the extra click, the stale tab, the retried request — finds the quote Accepted and
        // would otherwise be told it "is not ready to accept yet", which is both confusing and
        // wrong: it is accepted, and here is the order. Forwarding is also the answer that cannot
        // create a second one. The genuinely simultaneous case is caught inside convert() instead,
        // where the database can settle it; this only spares the common case a pointless
        // transaction. An Accepted quote with no order behind it still falls through to the status
        // check below — that is a state nothing should be converting either.
        $alreadyConverted = $estimate->getConvertedOrder();
        if ($alreadyConverted instanceof SalesOrder) {
            $this->addFlash('success', sprintf('Estimate %s was already accepted — order %s.', $estimate->getDocumentNumber(), $alreadyConverted->getOrderNumber()));

            return $this->redirectToRoute('customer_order_detail', ['id' => $alreadyConverted->getId()]);
        }

        if (!$estimate->isStatus('Priced')) {
            $this->addFlash('error', 'This estimate is not ready to accept yet.');

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        }

        $companyId = (int) $estimate->getCompany()->getId();
        $documentNumber = $estimate->getDocumentNumber();

        try {
            // One transaction around everything the acceptance produces. Without it the single
            // flush committed the order while the reservation that flush triggers ran afterwards,
            // so a reservation failure left a committed order holding no stock.
            $order = $entityManager->wrapInTransaction(
                function (EntityManagerInterface $em) use ($estimate, $conversionService, $feeResolver, $taxResolver, $companyId): SalesOrder {
                    $order = $conversionService->convert(
                        $estimate,
                        $em,
                        $this->customerDisplayName(),
                        // Unchanged behaviour, now said out loud. A buyer clicking Accept has
                        // committed to the purchase, so the order and the invoice that bills it are
                        // raised together in this one transaction (#539 stage 1) — which is exactly
                        // what the ADMIN side deliberately does not do, and naming it here is what
                        // stops the two paths being confused for one. It is also the default, so
                        // this line states an intention rather than changing one.
                        invoicing: QuoteConversionInvoicing::RaiseInvoice,
                    );

                    // Must be flushed before the snapshots below: both writers return early on an
                    // order with no id yet, so calling them first would compile, run, and silently
                    // record nothing. This flush is also what gives
                    // InventoryReconciliationSubscriber its postFlush pass — inside the
                    // transaction, so the reservation rolls back with the order if anything fails.
                    $em->flush();

                    // What every other order-creation path does once its fee/tax lines are final
                    // (CheckoutController, admin order create/edit). Conversion copies those lines
                    // across verbatim, so a converted order is just as final as any other — it was
                    // simply the one path that never asked for the snapshots, which is why a quote
                    // accepted by a BC company produced an order with no PST # or TSBC # on record.
                    $feeResolver->applyOrderSnapshots($order, $companyId);
                    $taxResolver->applyOrderSnapshots($order, $companyId);

                    return $order;
                },
            );
        } catch (EstimateAlreadyConvertedException) {
            // Lost the race to a simultaneous accept, which has already created the order. Nothing
            // was written by this request. The redirect deliberately goes back to the quote rather
            // than straight to the order: wrapInTransaction() closes the EntityManager on the way
            // out, so the winning order can't be read here — the next request reads it and the
            // already-accepted branch above does the forwarding.
            $this->addFlash('error', sprintf('Estimate %s has already been accepted.', $documentNumber));

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        } catch (UniqueConstraintViolationException $e) {
            // order_number collided with a concurrently-created order (see OrderNumberGenerator,
            // which documents that it is not race-proof and that callers must handle this).
            // Checkout answers it the same way: nothing is saved, so ask for a resubmit, which
            // generates a fresh number.
            $logger->warning('Quote acceptance order number collided with a concurrent order.', [
                'estimateId' => $id,
                'companyId' => $companyId,
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not accept this quote right now. Please try again.');

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        } catch (\Throwable $e) {
            // The transaction rolled back, so there is no half-created order to explain — the quote
            // is still Priced and still acceptable. Report that rather than a 500 on a page whose
            // only action is this one.
            $logger->error('Quote acceptance failed.', [
                'estimateId' => $id,
                'companyId' => $companyId,
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not accept this quote right now. Please try again.');

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        }

        $actor = $this->getUser();
        // The order is committed by the time these run, so nothing here may turn a successful
        // acceptance into an error the customer sees. EmailNotifier::send() already swallows
        // delivery failures, but the context each notification assembles beforehand sits outside
        // that guard — this catches what it doesn't.
        try {
            $notifier->quoteApprovedAdmin($estimate, $order);

            // Conversion holds the order as a Draft when stock cannot cover it, rather than
            // refusing the customer's acceptance (EstimateConversionService). That decision is
            // invisible from the outside — the customer is told their order is placed, and it is,
            // it simply will not ship — so this is the only thing that tells an admin an order is
            // sitting there needing a decision.
            //
            // Recomputed rather than carried out of the transaction: the order is a Draft by now,
            // and a Draft reserves nothing, so the figures are the same ones convert() saw.
            $shortfalls = $stockValidator->shortfallsForOrder($order, $entityManager);
            if ($shortfalls !== []) {
                $notifier->quoteAcceptedShortOfStockAdmin($estimate, $order, $shortfalls);
            }

            $notifier->orderReceived($order, $actor instanceof CustomerUser ? $actor->getEmail() : null);
        } catch (\Throwable $e) {
            $logger->error('Quote acceptance notifications failed after the order was created.', [
                'estimateId' => $id,
                'orderId' => $order->getId(),
                'exception' => $e,
            ]);
        }

        // Name the invoice too. Accepting a quote here raises one against the customer in the same
        // breath as the order, and the message used to mention only the order — so the one document
        // that asks them for money arrived unannounced. Wording only: nothing about what this path
        // creates has changed.
        //
        // Read off the order rather than returned out of convert(), because raise() links both sides
        // (SalesOrder::addInvoice) and numbers the invoice before the flush, so by here the
        // collection holds the invoice this acceptance just raised. getInvoices() rather than
        // getCountingInvoices(): an order held back as a Draft for stock still raised an invoice,
        // it is simply a draft one, and the customer should be told about it by name either way.
        $invoice = $order->getInvoices()->first() ?: null;
        $this->addFlash('success', $invoice !== null
            ? sprintf(
                'Estimate %s accepted — order %s and invoice %s have been created.',
                $documentNumber,
                $order->getOrderNumber(),
                $invoice->getDocumentNumber(),
            )
            // No invoice is not a state this path produces today; the sentence exists so that if it
            // ever stops producing one, the customer is told the truth rather than an invoice number
            // that is not there.
            : sprintf('Estimate %s accepted — order %s has been created.', $documentNumber, $order->getOrderNumber()));

        return $this->redirectToRoute('customer_order_detail', ['id' => $order->getId()]);
    }

    #[Route('/{id<\d+>}/reject', name: 'customer_estimate_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, EntityManagerInterface $entityManager, SalesDocumentNotifier $notifier, DocumentActorResolver $actors): Response
    {
        $estimate = $this->companyScopedEstimate($id, $entityManager);
        if ($estimate === null) {
            return $this->redirectToRoute('customer_estimates');
        }

        $token = (string) $request->request->get('_token', '');
        // A quote can only be declined once it's actually been priced — declining a request that
        // hasn't been quoted yet makes no sense (there's nothing to decline). See #202.
        if (!$estimate->isStatus('Priced')) {
            $this->addFlash('error', 'This quote cannot be declined yet.');

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        }

        $estimate->setStatus('Rejected', $actors->resolve(), 'Quote declined by the customer.');
        $entityManager->flush();

        $notifier->quoteDeclinedAdmin($estimate);

        $this->addFlash('success', sprintf('Estimate %s was rejected.', $estimate->getDocumentNumber()));

        return $this->redirectToRoute('customer_estimates');
    }

    /**
     * #388: reject() is deliberately Priced-only (#202) — there's nothing to *decline* before a
     * price exists. But a customer still needs a way to *withdraw* a request they no longer want
     * while it's awaiting pricing; before this there was truly nothing to click on a Submitted
     * quote besides Download. Reuses the Rejected status (terminal, no order, same as a declined
     * Priced quote) rather than adding a new enum case for what is the same outcome reached at an
     * earlier stage.
     */
    #[Route('/{id<\d+>}/cancel', name: 'customer_estimate_cancel', methods: ['POST'])]
    public function cancel(int $id, EntityManagerInterface $entityManager, SalesDocumentNotifier $notifier, DocumentActorResolver $actors): Response
    {
        $estimate = $this->companyScopedEstimate($id, $entityManager);
        if ($estimate === null) {
            return $this->redirectToRoute('customer_estimates');
        }

        if (!$estimate->isStatus('Submitted')) {
            $this->addFlash('error', 'This quote request can no longer be cancelled.');

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $id]);
        }

        $estimate->setStatus('Rejected', $actors->resolve(), 'Quote request cancelled by the customer.');
        $entityManager->flush();

        $notifier->quoteDeclinedAdmin($estimate);

        $this->addFlash('success', sprintf('Estimate %s was cancelled.', $estimate->getDocumentNumber()));

        return $this->redirectToRoute('customer_estimates');
    }

    private function companyScopedEstimate(int $id, EntityManagerInterface $entityManager): ?Estimate
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your quote requests.');

            return null;
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');

            return null;
        }

        $estimate = $entityManager->getRepository(Estimate::class)->createQueryBuilder('e')
            ->andWhere('e.id = :id')->setParameter('id', $id)
            ->andWhere('e.company = :company')->setParameter('company', $company)
            ->andWhere('e.status != :draft')->setParameter('draft', 'Draft')
            ->leftJoin('e.lines', 'l')->addSelect('l')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$estimate instanceof Estimate) {
            $this->addFlash('error', 'Quote request not found.');

            return null;
        }

        return $estimate;
    }

    /**
     * Log-author name for the conversion entries accept() writes, in the same shape the admin side
     * uses (AdminEstimateController::adminDisplayName()) so one document's history reads
     * consistently whoever acted on it.
     */
    private function customerDisplayName(): string
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            return 'System';
        }

        $name = trim($user->getFirstName() . ' ' . $user->getLastName());
        $name = $name !== '' ? $name . ', ' . $user->getEmail() : $user->getEmail();

        return $name . ' (' . $user->getId() . ')';
    }

    private function applyEstimateListSorting(QueryBuilder $qb, string $sort, string $dir): void
    {
        switch ($sort) {
            case 'product':
                $qb->leftJoin('e.lines', 'lineSort')
                    ->groupBy('e.id')
                    ->addOrderBy('MIN(lineSort.name)', $dir)
                    ->addOrderBy('e.createdAt', 'DESC');
                break;
            case 'po':
                $qb->addOrderBy('e.poNumber', $dir)->addOrderBy('e.createdAt', 'DESC');
                break;
            // 'warehouse' stays accepted as the sort key so old links keep sorting; both names sort
            // the quote's own region, which is what the column shows.
            case 'region':
            case 'warehouse':
                $qb->addOrderBy('e.fulfillmentRegion', $dir)->addOrderBy('e.createdAt', 'DESC');
                break;
            case 'status':
                $qb->addOrderBy('e.status', $dir)->addOrderBy('e.createdAt', 'DESC');
                break;
            case 'total':
                $qb->addOrderBy('e.total', $dir)->addOrderBy('e.createdAt', 'DESC');
                break;
            case 'id':
                $qb->addOrderBy('e.id', $dir);
                break;
            case 'created_at':
            default:
                $qb->addOrderBy('e.createdAt', $dir);
                break;
        }
    }

    /** @param list<int> $ids @return list<Estimate> */
    private function loadEstimatesByIds(EntityManagerInterface $entityManager, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $estimates = $entityManager->getRepository(Estimate::class)->createQueryBuilder('e')
            ->leftJoin('e.lines', 'l')->addSelect('l')
            ->leftJoin('e.estimateAddresses', 'sa', 'WITH', "sa.type = 'shipping'")->addSelect('sa')
            ->andWhere('e.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($estimates as $estimate) {
            $estimateId = $estimate->getId();
            if ($estimateId !== null) {
                $byId[$estimateId] = $estimate;
            }
        }

        $sorted = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $sorted[] = $byId[$id];
            }
        }

        return $sorted;
    }

    private function requestedExpectedDelivery(Estimate $estimate): string
    {
        foreach ($this->specialInstructionParts($estimate) as $part) {
            if (str_starts_with(strtolower($part), strtolower(self::EXPECTED_DELIVERY_PREFIX))) {
                return trim(substr($part, strlen(self::EXPECTED_DELIVERY_PREFIX)));
            }
        }

        return '';
    }

    private function visibleSpecialInstructions(Estimate $estimate): string
    {
        $visible = array_values(array_filter(
            $this->specialInstructionParts($estimate),
            function (string $part): bool {
                $lower = strtolower($part);

                return !str_starts_with($lower, strtolower(self::EXPECTED_DELIVERY_PREFIX))
                    && !str_starts_with($lower, strtolower(self::ORDER_PHONE_PREFIX))
                    && !str_starts_with($lower, strtolower(self::PAYMENT_NOTE_PREFIX));
            },
        ));

        return $visible !== [] ? implode(' | ', $visible) : '-';
    }

    /** @return list<string> */
    private function specialInstructionParts(Estimate $estimate): array
    {
        $instructions = trim((string) $estimate->getSpecialInstructions());
        if ($instructions === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode('|', $instructions)),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function normalizedListSort(string $sort, array $allowed, string $default): string
    {
        $sort = trim($sort);

        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    private function normalizedSortDir(string $dir, string $default = 'asc'): string
    {
        $dir = strtolower(trim($dir));
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = strtolower(trim($default)) === 'desc' ? 'desc' : 'asc';
        }

        return $dir;
    }
}
