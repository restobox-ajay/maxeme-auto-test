<?php

namespace App\Controller\Customer;

use App\Contract\Fee\FeeLine;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Enum\InvoicePaymentStatus;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Service\Pricing\CustomerPricingResolver;
use App\Service\Pricing\ProductVisibilityGuard;
use App\Service\DocumentActor;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Service\AppSettings;
use App\Service\OrderPaymentRollup;
use App\Service\SuggestedPriceCalculator;
use App\Service\StripeOrderPaymentApplier;
use App\Service\OrderTaxBreakdownService;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use PaymentStripeBundle\Service\StripeConfigProvider;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders')]
final class OrderController extends AbstractCustomerController
{
    private const STRIPE_CARD_METHOD = StripeOrderPaymentApplier::METHOD_LABEL;
    private const EXPECTED_DELIVERY_PREFIX = 'Expected delivery time:';
    private const ORDER_PHONE_PREFIX = 'Order phone number:';

    /**
     * Constructor-injected rather than passed to each action, because "is this order paid" is asked
     * from the list query, the sort, the detail page and the two can-I-do-this guards, and threading
     * one service through five call chains as an argument would be noise at every step.
     */
    public function __construct(
        CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        CustomerPricingResolver $pricingResolver,
        SuggestedPriceCalculator $suggestedPriceCalculator,
        WarehouseFulfillmentRegionService $warehouses,
        ProductVisibilityGuard $visibilityGuard,
        private readonly OrderPaymentRollup $paymentRollup,
    ) {
        parent::__construct($companyFulfillmentRegionService, $pricingResolver, $suggestedPriceCalculator, $warehouses, $visibilityGuard);
    }

    #[Route('', name: 'customer_orders', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your orders.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $tab = strtoupper(trim((string) $request->query->get('tab', 'ALL')));
        $tab = in_array($tab, ['ALL', 'PENDING', 'APPROVED', 'CANCELLED'], true) ? $tab : 'ALL';
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }
        // 'warehouse' is still accepted so bookmarked and shared links keep working; 'region' is
        // what the form submits now. A customer picks a fulfillment region — the warehouse behind
        // it is a physical fact they never see (#546).
        $regionFilter = trim((string) $request->query->get(
            'region',
            $request->query->get('warehouse', $filters['region'] ?? $filters['warehouse'] ?? '')
        ));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));
        $sort = $this->normalizedListSort((string) $request->query->get('sort', 'created_at'), [
            'web_id',
            'created_at',
            'product',
            'total',
            'expected_delivery',
            'po',
            'special_instruction',
            'delivery_address',
            'status',
            'payment_status',
        ], 'created_at');
        $dir = $this->normalizedSortDir((string) $request->query->get('dir', 'desc'), 'desc');

        $repo = $entityManager->getRepository(SalesOrder::class);
        $baseQb = $repo->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->setParameter('company', $company)
            ->andWhere('LOWER(o.status) NOT IN (:quoteOnlyStatuses)')
            ->setParameter('quoteOnlyStatuses', ['draft'])
            ->leftJoin('o.orderAddresses', 'sa', 'WITH', "sa.type = 'shipping'");

        // Paid vs unpaid is rolled up from the order's invoices since #539 stage 4 — the order has no
        // payment status of its own any more. One correlated subquery in the same statement that
        // pages the list, so the tabs, the sort and the badge on each row all read the same figure.
        if ($tab === 'PENDING') {
            $baseQb
                ->andWhere('LOWER(o.status) NOT IN (:pendingExcludedStatuses)')
                ->setParameter('pendingExcludedStatuses', ['void', 'closed'])
                ->andWhere($this->paymentRollup->unsettledCondition());
        } elseif ($tab === 'CANCELLED') {
            // 'void' since #539 stage 2 — the tab key stays CANCELLED because that is what a customer
            // calls it, and renaming it would break every bookmarked link to this list.
            $baseQb->andWhere('LOWER(o.status) = :s')->setParameter('s', 'void');
        } elseif ($tab === 'APPROVED') {
            $baseQb
                ->andWhere('LOWER(o.status) NOT IN (:approvedExcludedStatuses)')
                ->setParameter('approvedExcludedStatuses', ['void'])
                ->andWhere($this->paymentRollup->condition(InvoicePaymentStatus::Paid));
        }

        $regionOptions = $this->customerFulfillmentRegionNames($entityManager, $appSettings);

        if ($regionFilter !== '') {
            // Match the order's OWN region, not its shipping address. The options come from
            // FulfillmentRegion, so comparing them against sa.companyName/sa.city compared two
            // unrelated things: picking "Vancouver" returned orders whose address happened to read
            // Vancouver, not orders fulfilled from that region, and it only ever appeared to work
            // where a region name coincided with a city name.
            //
            // o.fulfillmentRegion is the label snapshot the document froze at creation, which is
            // exactly the question this filter is asking.
            $baseQb->andWhere('LOWER(COALESCE(o.fulfillmentRegion, \'\')) = :regionFilter')
                ->setParameter('regionFilter', mb_strtolower($regionFilter));
        }

        $countQb = clone $baseQb;
        $countQb->select('COUNT(DISTINCT o.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $idQb = clone $baseQb;
        $this->applyCustomerOrderListSorting($idQb, $sort, $dir);

        $idRows = $idQb
            ->select('o.id')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();
        $orderIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $idRows);

        $orders = $this->loadOrdersByIds($entityManager, $orderIds);
        $expectedDeliveryByOrderId = [];
        $paymentLabelByOrderId = [];
        foreach ($orders as $order) {
            $expectedDeliveryByOrderId[(int) $order->getId()] = $this->expectedDeliveryLabel($order);
            // Computed here rather than in the template, for the reason the tabs use one subquery:
            // a template that added payments up per row would issue a query per row.
            $paymentLabelByOrderId[(int) $order->getId()] = $this->paymentRollup->forOrder($order)->value;
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('customer/order/_list_rows.html.twig', [
                    'orders' => $orders,
                    'expectedDeliveryByOrderId' => $expectedDeliveryByOrderId,
                    'paymentLabelByOrderId' => $paymentLabelByOrderId,
                ]),
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pageCount,
            ]);
        }

        return $this->render('customer/order/index.html.twig', [
            'orders' => $orders,
            'activeTab' => $tab,
            'regionOptions' => $regionOptions,
            'regionFilter' => $regionFilter,
            'expectedDeliveryByOrderId' => $expectedDeliveryByOrderId,
            'paymentLabelByOrderId' => $paymentLabelByOrderId,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'currentSort' => $sort,
            'currentDir' => $dir,
        ]);
    }

    /**
     * A customer withdrawing an order they placed and have not paid for.
     *
     * Two actions composed, not a third code path (#539 stage 2): each live invoice is cancelled
     * through the same Invoice::cancel() an admin's Cancel button calls, and then the order is
     * voided. The invoices have to go first — a voided order with a live invoice against it would
     * still count as invoiced, and from stage 3 would still hold its stock.
     *
     * This is the same composition CancelStaleUnpaidOrdersCommand performs, and for the same reason:
     * an unpaid ecom order is junk on both sides. It differs from an admin cancelling one invoice of
     * several, where the order is real, the goods are still owed, and voiding it is a separate
     * judgement a person makes.
     */
    #[Route('/{id<\\d+>}/cancel', name: 'customer_order_cancel', methods: ['POST'])]
    public function cancelOrder(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $order = $this->findCustomerOrder($id, $entityManager);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order not found.');
            return $this->redirectToRoute('customer_orders');
        }

        if (!$this->canCustomerCancelOrder($order)) {
            $this->addFlash('info', 'This order cannot be cancelled.');
            return $this->redirectToRoute('customer_orders');
        }

        $actor = $this->getUser();
        $documentActor = $actor instanceof CustomerUser ? DocumentActor::forCustomer($actor) : DocumentActor::system();
        $reason = 'cancelled by the customer.';

        foreach ($order->getInvoices() as $invoice) {
            if (!$invoice->isCancelled()) {
                $invoice->setStatus('Cancelled', $documentActor, sprintf('Invoice cancelled: %s', $reason));
            }
        }

        $order->setStatus(
            'Void',
            $documentActor,
            sprintf('Order voided (was %s): %s', $order->getStatus(), $reason),
        );
        $entityManager->flush();

        $this->addFlash('success', 'Order cancelled.');
        return $this->redirectToRoute('customer_orders', ['tab' => 'CANCELLED']);
    }

    /**
     * @param list<SalesOrder> $orders
     * @return array<int, string>
     */
    private function expectedDeliveryMap(array $orders): array
    {
        $values = [];

        foreach ($orders as $order) {
            $values[(int) $order->getId()] = $this->expectedDeliveryLabel($order);
        }

        return $values;
    }

    /**
     * @param list<SalesOrder> $orders
     * @return array<int, string>
     */
    private function visibleSpecialInstructionsMap(array $orders): array
    {
        $values = [];

        foreach ($orders as $order) {
            $values[(int) $order->getId()] = $this->visibleSpecialInstructions($order);
        }

        return $values;
    }

    private function expectedDeliveryLabel(SalesOrder $order): string
    {
        // Expected Delivery Time is a free-text field, nothing else: show what the customer
        // typed, or nothing. No guessed/computed date.
        return $this->requestedExpectedDelivery($order) ?? '';
    }

    private function visibleSpecialInstructions(SalesOrder $order): string
    {
        $instructions = trim((string) $order->getSpecialInstructions());
        if ($instructions === '') {
            return '-';
        }

        $parts = array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode('|', $instructions)
        ), static fn (string $part): bool => $part !== '');

        $visible = array_values(array_filter(
            $parts,
            fn (string $part): bool => !$this->isExpectedDeliveryInstruction($part) && !$this->isOrderPhoneInstruction($part)
        ));

        return $visible !== [] ? implode(' | ', $visible) : '-';
    }

    private function requestedExpectedDelivery(SalesOrder $order): ?string
    {
        $instructions = trim((string) $order->getSpecialInstructions());
        if ($instructions === '') {
            return null;
        }

        foreach (explode('|', $instructions) as $part) {
            $part = trim($part);
            if (!$this->isExpectedDeliveryInstruction($part)) {
                continue;
            }

            $value = trim(substr($part, strlen(self::EXPECTED_DELIVERY_PREFIX)));
            return $this->normalizeExpectedDeliveryDate($value);
        }

        return null;
    }

    /**
     * The stored value is a free-text <input> (#447), not a date field — "ASAP", "next Tuesday
     * morning", "before noon Fri" are all realistic and none of them parse. A value that IS a real
     * date still gets normalised to the consistent "M j, Y" display; anything else is shown back
     * exactly as the customer typed it (Twig auto-escapes every render site — see
     * templates/customer/order/*.html.twig — so no explicit escaping is needed here).
     */
    private function normalizeExpectedDeliveryDate(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return $normalized;
        }

        return $date->format('M j, Y');
    }

    private function requestedOrderPhone(SalesOrder $order): ?string
    {
        $instructions = trim((string) $order->getSpecialInstructions());
        if ($instructions === '') {
            return null;
        }

        foreach (explode('|', $instructions) as $part) {
            $part = trim($part);
            if (!$this->isOrderPhoneInstruction($part)) {
                continue;
            }

            $value = trim(substr($part, strlen(self::ORDER_PHONE_PREFIX)));
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function isExpectedDeliveryInstruction(string $value): bool
    {
        return str_starts_with(
            strtolower(trim($value)),
            strtolower(self::EXPECTED_DELIVERY_PREFIX)
        );
    }

    private function isOrderPhoneInstruction(string $value): bool
    {
        return str_starts_with(
            strtolower(trim($value)),
            strtolower(self::ORDER_PHONE_PREFIX)
        );
    }

    /**
     * @param list<int> $orderIds
     * @return list<SalesOrder>
     */
    private function loadOrdersByIds(EntityManagerInterface $entityManager, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        /** @var list<SalesOrder> $orders */
        $orders = $entityManager->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->leftJoin('o.lines', 'l')->addSelect('l')
            // Payments hang off the invoices now (#539 stage 4), so the fetch-join that used to
            // bring them along with the order has nothing left to join to.
            ->leftJoin('o.invoices', 'inv')->addSelect('inv')
            ->leftJoin('o.orderAddresses', 'sa', 'WITH', "sa.type = 'shipping'")->addSelect('sa')
            ->andWhere('o.id IN (:ids)')
            ->setParameter('ids', $orderIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($orders as $order) {
            $orderId = $order->getId();
            if ($orderId !== null) {
                $byId[(int) $orderId] = $order;
            }
        }

        $sorted = [];
        foreach ($orderIds as $orderId) {
            if (isset($byId[$orderId])) {
                $sorted[] = $byId[$orderId];
            }
        }

        return $sorted;
    }

    /**
     * @param list<string> $allowed
     */
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

    private function applyCustomerOrderListSorting(\Doctrine\ORM\QueryBuilder $qb, string $sort, string $dir): void
    {
        $qb->resetDQLPart('orderBy');
        $qb->resetDQLPart('groupBy');

        switch ($sort) {
            case 'id':
                $qb->addOrderBy('o.id', $dir);
                break;

            case 'web_id':
                $qb->addOrderBy('o.orderNumber', $dir)
                    ->addOrderBy('o.id', $dir);
                break;

            case 'product':
                $qb->leftJoin('o.lines', 'lineSortOrder')
                    ->groupBy('o.id')
                    ->addOrderBy('MIN(lineSortOrder.name)', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'total':
                $qb->addOrderBy('o.total', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'expected_delivery':
                // Was ordered by o.invoiceDate first, which #539 stage 6 removed from the order —
                // and which was never what this column shows anyway. The expected delivery time is
                // read out of specialInstructions by expectedDeliveryLabel(), so there is no column
                // to sort it by; the order's own date is the closest proxy and is what remains.
                $qb->addOrderBy('o.documentDate', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'po':
                $qb->addOrderBy('o.poNumber', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'special_instruction':
                $qb->addOrderBy('o.specialInstructions', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'delivery_address':
                $qb->addOrderBy('sa.addressLine1', $dir)
                    ->addOrderBy('sa.city', $dir)
                    ->addOrderBy('sa.province', $dir)
                    ->addOrderBy('sa.postalCode', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            // 'warehouse' stays accepted as the sort key so old links keep sorting; both names sort
            // the order's own region, which is what the column shows.
            case 'region':
            case 'warehouse':
                $qb->addOrderBy('o.fulfillmentRegion', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'status':
                $qb->addOrderBy(sprintf(
                    "CASE WHEN LOWER(o.status) = 'void' THEN 3 WHEN %s THEN 2 ELSE 1 END",
                    $this->paymentRollup->condition(InvoicePaymentStatus::Paid),
                ), $dir)
                    ->addOrderBy('o.status', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'payment_status':
                // The rollup score itself, which sorts from "nothing paid" through part-paid to
                // "fully paid" — the order someone clicking this column is asking for.
                $qb->addOrderBy($this->paymentRollup->scoreExpression(), $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'note_for_customer':
                $qb->addOrderBy('sa.deliveryInstructions', $dir)
                    ->addOrderBy('o.createdAt', 'DESC');
                break;

            case 'created_at':
            default:
                $qb->addOrderBy('o.createdAt', $dir)
                    ->addOrderBy('o.id', $dir);
                break;
        }
    }

    #[Route('/{id<\\d+>}', name: 'customer_order_detail', methods: ['GET'])]
    public function detail(int $id, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings, StripeConfigProvider $stripeConfigProvider, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your orders.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $repo = $entityManager->getRepository(SalesOrder::class);
        $order = $repo->createQueryBuilder('o')
            ->andWhere('o.id = :id')->setParameter('id', $id)
            ->andWhere('o.company = :company')->setParameter('company', $company)
            ->leftJoin('o.lines', 'l')->addSelect('l')
            // Payments hang off the invoices now (#539 stage 4), so the fetch-join that used to
            // bring them along with the order has nothing left to join to.
            ->leftJoin('o.invoices', 'inv')->addSelect('inv')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order not found.');
            return $this->redirectToRoute('customer_orders');
        }

        $showPayment = $request->query->getBoolean('payment') && $this->canMakePayment($order);
        $stripeConfig = $this->stripeConfig($stripeConfigProvider);
        $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);
        $sourceEstimate = $entityManager->getRepository(Estimate::class)->findOneBy(['convertedOrder' => $order]);

        return $this->render('customer/order/detail.html.twig', [
            'order' => $order,
            'paymentLabel' => $this->paymentRollup->forOrder($order)->value,
            'expectedDelivery' => $this->expectedDeliveryLabel($order),
            'orderPhone' => $this->requestedOrderPhone($order) ?? $order->getCompanyIdentity()->getPhone(),
            'specialInstructions' => $this->visibleSpecialInstructions($order),
            'showPayment' => $showPayment,
            'stripeEnabled' => $stripeConfig['enabled'],
            'stripeTestMode' => $stripeConfig['testMode'],
            'stripePublishableKey' => $stripeConfig['publishableKey'],
            'stripeCardMethodName' => self::STRIPE_CARD_METHOD,
            'checkoutConfig' => $this->orderPaymentConfig($appSettings),
            'couponsEnabled' => $this->couponsEnabled($appSettings),
            'orderPaymentTotals' => $this->orderPaymentTotals($order, null),
            'taxLines' => $taxBreakdown['lines'],
            'perLineTax' => $taxBreakdown['perLineTax'],
            'perLineTaxLabel' => $taxBreakdown['perLineTaxLabel'],
            'sourceEstimate' => $sourceEstimate,
        ]);
    }

    #[Route('/{id<\d+>}/payment/stripe-intent', name: 'customer_order_payment_stripe_intent', methods: ['POST'])]
    public function createOrderPaymentStripeIntent(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AppSettings $appSettings,
        StripeConfigProvider $stripeConfigProvider,
    ): JsonResponse {
        $order = $this->findCustomerOrder($id, $entityManager);
        if (!$order instanceof SalesOrder || !$this->canMakePayment($order)) {
            return $this->json(['ok' => false, 'message' => 'This order is not available for payment.'], Response::HTTP_BAD_REQUEST);
        }

        $stripeConfig = $this->stripeConfig($stripeConfigProvider);
        if (!$stripeConfig['enabled']) {
            return $this->json(['ok' => false, 'message' => 'Card payment is not available right now.'], Response::HTTP_BAD_REQUEST);
        }

        [$coupon, $couponError] = $this->resolveOrderCouponFromRequest($request, $appSettings, $order);
        if ($couponError !== null) {
            return $this->json(['ok' => false, 'message' => $couponError], Response::HTTP_BAD_REQUEST);
        }

        $totals = $this->orderPaymentTotals($order, $coupon);
        $amount = (int) round(max(0, $totals['total']) * 100);
        if ($amount <= 0) {
            return $this->json(['ok' => false, 'message' => 'The payment total must be greater than zero.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $stripe = new StripeClient($stripeConfig['secretKey']);
            $intent = $stripe->paymentIntents->create([
                'amount' => $amount,
                'currency' => 'cad',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'company_id' => (string) ($order->getCompany()->getId() ?? ''),
                    'order_id' => (string) ($order->getId() ?? ''),
                    'order_number' => $order->getOrderNumber(),
                    'coupon_code' => strtoupper(trim((string) $request->request->get('coupon_code', ''))),
                ],
            ]);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Could not start card payment. Please try again.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'ok' => true,
            'client_secret' => (string) $intent->client_secret,
            'payment_intent_id' => (string) $intent->id,
        ]);
    }

    #[Route('/{id<\d+>}/payment', name: 'customer_order_payment', methods: ['POST'])]
    public function payOrder(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AppSettings $appSettings,
        StripeConfigProvider $stripeConfigProvider,
        StripeOrderPaymentApplier $paymentApplier,
    ): Response {
        $order = $this->findCustomerOrder($id, $entityManager);
        if (!$order instanceof SalesOrder || !$this->canMakePayment($order)) {
            return $this->json(['ok' => false, 'message' => 'This order is not available for payment.'], Response::HTTP_BAD_REQUEST);
        }

        [$coupon, $couponError] = $this->resolveOrderCouponFromRequest($request, $appSettings, $order);
        if ($couponError !== null) {
            return $this->json(['ok' => false, 'message' => $couponError], Response::HTTP_BAD_REQUEST);
        }

        $totals = $this->orderPaymentTotals($order, $coupon);

        $stripeConfig = $this->stripeConfig($stripeConfigProvider);
        if (!$stripeConfig['enabled']) {
            return $this->json(['ok' => false, 'message' => 'Card payment is not available right now.'], Response::HTTP_BAD_REQUEST);
        }

        $intentId = $this->nullableString($request->request->get('payment_intent_id'));
        if ($intentId === null) {
            return $this->json(['ok' => false, 'message' => 'Please complete your card payment before confirming checkout.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $intent = (new StripeClient($stripeConfig['secretKey']))->paymentIntents->retrieve($intentId, []);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Could not verify your card payment. Please try again.'], Response::HTTP_BAD_REQUEST);
        }

        // The coupon has to land on the order before the payment is validated, because the amount
        // Stripe captured is compared against the total this order is actually being charged.
        if ($coupon !== null) {
            $order
                ->setSubtotal($this->decimal($totals['discountedSubtotal']))
                ->setTax($this->decimal($totals['tax']))
                ->setTotal($this->decimal($totals['total']));

            $couponNote = sprintf('Coupon applied on payment: %s (-%s)', $coupon['code'], $this->money($totals['discount']));
            $existingInstructions = $order->getSpecialInstructions();
            if ($existingInstructions === null || !str_contains($existingInstructions, $couponNote)) {
                $order->setSpecialInstructions($existingInstructions ? ($existingInstructions . ' | ' . $couponNote) : $couponNote);
            }
        }

        // Shared with the webhook so the two routes to "this order is paid" cannot drift apart.
        // Nothing above here has been flushed, so returning on failure leaves the order untouched.
        [, $applyError] = $paymentApplier->apply($order, $intent, $totals['total']);
        if ($applyError !== null) {
            return $this->json(['ok' => false, 'message' => $applyError], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'message' => sprintf('Payment for order %s was completed successfully.', $order->getOrderNumber() ?: ('#' . $order->getId())),
            'redirect_url' => $this->generateUrl('customer_order_detail', ['id' => $order->getId()]),
        ]);
    }

    private function findCustomerOrder(int $id, EntityManagerInterface $entityManager): ?SalesOrder
    {
        $user = $this->getUser();
        $company = $this->currentCompany();
        if (!$user instanceof CustomerUser || $company === null) {
            return null;
        }

        $order = $entityManager->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->andWhere('o.id = :id')->setParameter('id', $id)
            ->andWhere('o.company = :company')->setParameter('company', $company)
            ->leftJoin('o.lines', 'l')->addSelect('l')
            // Payments hang off the invoices now (#539 stage 4), so the fetch-join that used to
            // bring them along with the order has nothing left to join to.
            ->leftJoin('o.invoices', 'inv')->addSelect('inv')
            ->leftJoin('o.orderAddresses', 'ba', 'WITH', "ba.type = 'billing'")->addSelect('ba')
            ->leftJoin('o.orderAddresses', 'sa', 'WITH', "sa.type = 'shipping'")->addSelect('sa')
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof SalesOrder ? $order : null;
    }

    private function canMakePayment(SalesOrder $order): bool
    {
        $status = strtolower(trim($order->getStatus()));
        if (in_array($status, ['draft', 'void'], true)) {
            return false;
        }

        return !$this->isOrderPaid($order);
    }

    private function canCustomerCancelOrder(SalesOrder $order): bool
    {
        // Void and Closed are #539 stage 2's successors to Cancelled and Completed; Draft was never
        // the customer's to cancel because they never placed it.
        $status = strtolower(trim($order->getStatus()));
        if (in_array($status, ['void', 'closed', 'draft'], true)) {
            return false;
        }

        // Nor is an order with money against ANY of its invoices, even a part-payment that leaves
        // isOrderPaid() false. Cancelling voids the order by cancelling its invoices, and an invoice
        // that has taken a payment cannot be cancelled at all (#539 stage 4) — it is credited or
        // refunded. Without this the Cancel button would offer something the invoice then refuses.
        foreach ($order->getInvoices() as $invoice) {
            if (!$invoice->isCancelled() && !$invoice->getApplications()->isEmpty()) {
                return false;
            }
        }

        return !$this->isOrderPaid($order);
    }

    /**
     * Has this order been settled — rolled up from its invoices (#539 stage 4).
     *
     * The order carries no payment status of its own any more, and the invoices carry no opinion
     * beyond their own payment rows, so this is the one rule: every invoice that counts is paid, and
     * there is at least one. An order nothing has been billed against is not paid.
     */
    private function isOrderPaid(SalesOrder $order): bool
    {
        return $this->paymentRollup->forOrder($order) === InvoicePaymentStatus::Paid;
    }

    private function orderPaymentConfig(AppSettings $appSettings): array
    {
        return [
            'shippingFee' => 0,
            'freeShippingThreshold' => 0,
            'taxRatePercent' => max(0.0, (float) ($appSettings->get('checkout_tax_rate_percent', '5') ?? '5')),
            'coupons' => $this->couponsEnabled($appSettings)
                ? $this->orderPaymentCoupons($appSettings->get('checkout_coupons', ''))
                : [],
        ];
    }

    /** Admin switch (checkout_coupons_enabled) for whether coupon codes may be used at all. */
    private function couponsEnabled(AppSettings $appSettings): bool
    {
        return ($appSettings->get('checkout_coupons_enabled', 'Yes') ?? 'Yes') !== 'No';
    }

    private function orderPaymentCoupons(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $coupons = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $value = (float) ($row['value'] ?? 0);
            if ($code === '' || !in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
                continue;
            }

            $coupons[] = [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'minSubtotal' => max(0.0, (float) ($row['min_subtotal'] ?? 0)),
                'label' => trim((string) ($row['label'] ?? '')),
            ];
        }

        return $coupons;
    }

    private function resolveOrderCouponFromRequest(Request $request, AppSettings $appSettings, SalesOrder $order): array
    {
        $couponCode = strtoupper(trim((string) $request->request->get('coupon_code', '')));
        if ($couponCode === '') {
            return [null, null];
        }

        if (!$this->couponsEnabled($appSettings)) {
            return [null, 'Coupon codes are not accepted at this time.'];
        }

        $subtotal = (float) $order->getSubtotal();
        foreach ($this->orderPaymentCoupons($appSettings->get('checkout_coupons', '')) as $coupon) {
            if (($coupon['code'] ?? '') !== $couponCode) {
                continue;
            }

            $minSubtotal = (float) ($coupon['minSubtotal'] ?? 0);
            if ($subtotal < $minSubtotal) {
                return [null, sprintf('Coupon %s requires a minimum subtotal of %s.', $couponCode, $this->money($minSubtotal))];
            }

            return [$coupon, null];
        }

        return [null, 'That coupon code is not valid.'];
    }

    private function orderPaymentTotals(SalesOrder $order, ?array $coupon): array
    {
        // Must mirror the stored total's composition (subtotal + shipping + fees + tax, see admin
        // OrderController and CheckoutController save paths) — otherwise the amount charged here
        // diverges from SalesOrder::$total on orders that carry fee lines.
        $subtotal = max(0.0, (float) $order->getSubtotal());
        $shipping = max(0.0, $order->getShippingTotal() ?? 0.0);
        $feeTotal = $this->orderFeeLinesTotal($order);
        $originalPreTax = $subtotal + $shipping + $feeTotal;
        $taxRate = $originalPreTax > 0 ? max(0.0, (float) $order->getTax()) / $originalPreTax : 0.0;

        $discount = 0.0;
        if ($coupon !== null) {
            $discount = ($coupon['type'] ?? '') === 'percent'
                ? $subtotal * ((float) ($coupon['value'] ?? 0) / 100)
                : (float) ($coupon['value'] ?? 0);
        }

        $discount = min(max(0.0, $discount), $subtotal);
        $discountedSubtotal = max(0.0, $subtotal - $discount);
        $preTax = $discountedSubtotal + $shipping + $feeTotal;
        $tax = $preTax * $taxRate;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discountedSubtotal' => $discountedSubtotal,
            'shipping' => $shipping,
            'feeTotal' => $feeTotal,
            'preTax' => $preTax,
            'tax' => $tax,
            'taxRatePercent' => $taxRate * 100,
            'total' => $preTax + $tax,
        ];
    }

    /**
     * The fee rows only. Shipping rows sit in the same snapshot but are already counted as
     * $shipping above, and a row cannot be charged twice.
     */
    private function orderFeeLinesTotal(SalesOrder $order): float
    {
        $total = 0.0;
        foreach ($order->getFeeLineRows() as $line) {
            if ($line->type !== FeeLine::TYPE_SHIPPING) {
                $total += $line->amount;
            }
        }

        return max(0.0, $total);
    }

    private function money(float $amount): string
    {
        return '$' . number_format($amount, 2, '.', ',');
    }

    private function decimal(float $value): string
    {
        return number_format(max(0, $value), 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        return TextInput::nullableString($value);
    }

    private function stripeConfig(StripeConfigProvider $stripeConfigProvider): array
    {
        return $stripeConfigProvider->getConfig();
    }

    /**
     * The customer's copy of the SALES ORDER (#539 stage 6).
     *
     * This route replaces `customer_order_invoice`, which handed the buyer their order printed as an
     * invoice. The portal shows both documents now — the client's words: "Yes. The whole thing is so
     * clean we can hide one of them later but for now just show SO and Invoice." — so this is the
     * order half and invoicePdf() below is the invoice half.
     */
    #[Route('/{id<\\d+>}/sales-order', name: 'customer_order_document', methods: ['GET'])]
    public function orderDocument(int $id, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your order.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $order = $entityManager->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->andWhere('o.id = :id')->setParameter('id', $id)
            ->andWhere('o.company = :company')->setParameter('company', $company)
            ->leftJoin('o.lines', 'l')->addSelect('l')
            ->leftJoin('o.invoices', 'inv')->addSelect('inv')
            ->leftJoin('o.orderAddresses', 'sa', 'WITH', "sa.type = 'shipping'")->addSelect('sa')
            ->leftJoin('o.orderAddresses', 'ba', 'WITH', "ba.type = 'billing'")->addSelect('ba')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order not found.');
            return $this->redirectToRoute('customer_orders');
        }

        $taxBreakdown = $taxBreakdownService->breakdownForOrder($order);

        // The admin's own template, rendered through its is_pdf branch, rather than a customer copy
        // of it. The copy that used to live at customer/order/invoice_pdf.html.twig carried a
        // comment promising it was kept "byte-for-byte in step" with the admin one — a promise no
        // test could hold and nothing enforced. Rendering the same file makes it true by
        // construction, and the is_pdf branch already strips every admin control from it.
        return $this->documentPdf(
            $this->renderView('admin/order/sales_order.html.twig', [
                'order' => $order,
                'billingAddress' => $order->getEffectiveBillingAddress(),
                'shippingAddress' => $order->getEffectiveShippingAddress(),
                'taxLines' => $taxBreakdown['lines'],
                'taxLinesTotal' => $taxBreakdown['total'],
                'is_pdf' => true,
            ]),
            'SalesOrder-' . $order->getOrderNumber() . '.pdf',
        );
    }

    /**
     * The customer's copy of one INVOICE.
     *
     * Scoped by company, exactly as every other customer route is: an invoice belonging to somebody
     * else's company is "not found" rather than forbidden, which is the shape the order routes above
     * already use and gives away nothing about whether the id exists.
     */
    #[Route('/invoice/{id<\\d+>}', name: 'customer_order_invoice_pdf', methods: ['GET'])]
    public function invoicePdf(int $id, EntityManagerInterface $entityManager, OrderTaxBreakdownService $taxBreakdownService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your invoice.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $this->currentCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $invoice = $entityManager->getRepository(Invoice::class)->createQueryBuilder('i')
            ->andWhere('i.id = :id')->setParameter('id', $id)
            ->andWhere('i.company = :company')->setParameter('company', $company)
            ->leftJoin('i.lines', 'l')->addSelect('l')
            ->leftJoin('i.invoiceAddresses', 'a')->addSelect('a')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$invoice instanceof Invoice) {
            $this->addFlash('error', 'Invoice not found.');
            return $this->redirectToRoute('customer_orders');
        }

        // A draft invoice is inert and has not been issued to anybody, so it is not the customer's
        // to download. Their copy exists once it has been issued.
        if ($invoice->isStatus('Draft')) {
            $this->addFlash('error', 'That invoice has not been issued yet.');
            return $this->redirectToRoute('customer_orders');
        }

        $taxBreakdown = $taxBreakdownService->breakdownForOrder($invoice);

        return $this->documentPdf(
            $this->renderView('admin/invoice/invoice.html.twig', [
                'invoice' => $invoice,
                'billingAddress' => $invoice->getEffectiveBillingAddress(),
                'shippingAddress' => $invoice->getEffectiveShippingAddress(),
                'taxLines' => $taxBreakdown['lines'],
                'taxLinesTotal' => $taxBreakdown['total'],
                'is_pdf' => true,
            ]),
            'Invoice-' . $invoice->getDocumentNumber() . '.pdf',
        );
    }

    private function documentPdf(string $html, string $filename): Response
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
