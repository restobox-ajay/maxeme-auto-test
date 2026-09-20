<?php

namespace App\Controller\Customer;

use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Tax\TaxContext;
use App\Entity\Cart;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\AppSettings;
use App\Service\CartInfoFieldResolver;
use App\Service\CartService;
use App\Service\OrderTaxBreakdownService;
use Doctrine\ORM\EntityManagerInterface;
use FeeBundle\Fee\FeeCalculatorResolver;
use ShippingBundle\Shipping\ShippingResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/cart')]
final class CartController extends AbstractCustomerController
{
    /** How many skipped reorder lines a single warning enumerates before it says "and N more". */
    private const REORDER_SKIP_LIST_LIMIT = 10;

    #[Route('', name: 'customer_cart', methods: ['GET'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        CartService $cartService,
        AppSettings $appSettings,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
        ShippingResolver $shippingResolver,
        CartInfoFieldResolver $cartInfoFieldResolver,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        $company = $this->currentCompany();
        $blocked = $company instanceof Company && !$this->companyHasActiveFulfillmentRegion($entityManager, $company);
        $region = $this->currentRegionForPricing($entityManager, $request->getSession());
        foreach ($cartService->reconcileAgainstRegion($this->resolveFulfillmentRegionEntity($entityManager, $region)) as $message) {
            $this->addFlash('warning', $message);
        }
        $rows = ($company instanceof Company && !$blocked) ? $this->resolveCartRows($entityManager, $company, $cartService, $region) : [];

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);
        $cartHoldExpiresAt = $cartService->getCart()?->getHoldExpiresAt();

        $resolvedRows = array_filter($rows, static fn (array $r) => $r['priceResolved']);
        $hasUnresolvedRows = count($resolvedRows) < count($rows);
        $subtotal = (float) array_sum(array_map(static fn (array $r) => $r['subtotal'], $resolvedRows));

        // Fees, then shipping, then tax. Fees come first because an order-level fee or discount
        // changes the amount shipping is priced against — a coupon that drops the order below a
        // free-shipping threshold cannot be seen by a shipping rule that has already run. Nothing
        // reads fee lines while resolving shipping today, so this is ordering, not a behaviour
        // change; it is the sequence that lets one exist. Tax is last either way: it is computed
        // over the lines, the shipping actually charged, and the fee lines together.
        $province = '';
        $feeLines = [];
        $taxableLines = [];
        if ($company instanceof Company && $resolvedRows !== []) {
            $province = $company->getDefaultShippingAddress()?->getProvince() ?? '';

            // Still assembled by hand, unlike every other calculator input in the app, because the
            // cart page does not price the cart document — resolveCartRows() prices a parallel array
            // and additionally drops products the buyer's price list hides, which no cart line
            // records. Moving this onto FeeContext::fromDocument() means moving the cart page onto
            // Cart::priceItems() first, and that is its own change.
            $feeCartItems = [];
            foreach ($resolvedRows as $row) {
                $feeCartItems[] = ['product' => $row['product'], 'qty' => $row['qty']];
                $taxableLines[] = ['subtotal' => $row['subtotal'], 'taxCode' => $row['product']->getSalesTaxCode()];
            }

            $feeLines = $feeResolver->calculate(new FeeContext($province, $feeCartItems, companyId: $company->getId()));
        }

        $feeTotal = (float) array_sum(array_map(static fn (FeeLine $l) => $l->amount, $feeLines));
        $feeTotalsMainLine = (float) array_sum(array_map(
            static fn (FeeLine $l) => $l->placement === 'main_line' ? $l->amount : 0.0,
            $feeLines,
        ));

        $freeShippingThreshold = max(0.0, (float) ($appSettings->get('checkout_free_shipping_threshold', '150') ?? '150'));
        [$shippingLabel, $shippingAmount, $shippingForcesQuote] = $this->resolveCartShipping($request->getSession(), $company, $rows, $shippingResolver);
        $shippingTbd = $shippingLabel === null || $shippingForcesQuote;
        $rawShippingAmount = $shippingAmount ?? 0.0;
        $shippingEstimate = $this->cartEffectiveShipping($subtotal, $freeShippingThreshold, $rawShippingAmount);
        // Threshold-driven, not tied to the selected option's own price — a naturally-$0 option
        // (e.g. Pickup) being selected must not hide that the promo itself is also active.
        $shippingWaived = !$shippingTbd && $subtotal > 0.0 && $freeShippingThreshold > 0.0 && $subtotal >= $freeShippingThreshold;

        // The cart carries its shipping the same way an order does — as a row, so the figure it
        // stores is the sum of rows and not a column with nothing behind it. TBD means no row.
        $cartFeeLines = array_merge($feeLines, $this->shippingLinesFor(
            $shippingTbd ? null : $shippingLabel,
            $shippingEstimate,
            TaxContext::resolveHighestTaxClass(array_map(static fn (array $l) => $l['taxCode'], $taxableLines)),
        ));

        $taxLines = [];
        $taxTotal = 0.0;
        $taxLinesJson = null;
        if ($company instanceof Company && $resolvedRows !== []) {
            $breakdown = $taxBreakdownService->computeBreakdown($province, $company->getId(), $taxableLines, $cartFeeLines);
            $taxLines = $breakdown['lines'];
            $taxTotal = $breakdown['total'];
            $taxLinesJson = $taxBreakdownService->toJson($breakdown);
        }

        $cartInfoFields = $company instanceof Company ? $cartInfoFieldResolver->getFields($company) : [];

        $this->recordCartTotals(
            $entityManager,
            $cartService->getCart(),
            $region,
            $shippingTbd ? null : $shippingLabel,
            $subtotal,
            $taxTotal,
            $subtotal + $shippingEstimate + $feeTotal + $taxTotal,
            $cartFeeLines,
            $taxLinesJson,
        );

        $response = $this->render('customer/cart/index.html.twig', [
            'rows' => $rows,
            'customerCompany' => $company,
            'cartInfoFields' => $cartInfoFields,
            'hasUnresolvedRows' => $hasUnresolvedRows,
            'subtotal' => $subtotal,
            'feeTotalsMainLine' => $feeTotalsMainLine,
            'shippingEstimate' => $shippingEstimate,
            'shippingTbd' => $shippingTbd,
            'shippingWaived' => $shippingWaived,
            'shippingLabel' => $shippingLabel,
            'freeShippingThreshold' => $freeShippingThreshold,
            'feeLines' => $feeLines,
            'feeTotal' => $feeTotal,
            'taxLines' => $taxLines,
            'taxTotal' => $taxTotal,
            'grandTotal' => $subtotal + $shippingEstimate + $feeTotal + $taxTotal,
            'blocked' => $blocked,
            'pricingRegion' => $region,
            'cartHoldExpiresAt' => $cartHoldExpiresAt,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Write back what this page just worked out, so the money exists somewhere other than the
     * response body.
     *
     * The cart already computed all of this and then discarded it into template variables, which is
     * why Admin/CartController could list carts and show item counts but not a single figure. The
     * cart is a document now and has the columns; filling them is what makes a cart answerable to
     * anyone who is not the customer looking at it.
     *
     * Two things follow from writing on a GET, both deliberate. The stored figure is "as of last
     * render", not live — nothing recomputes it when a price list changes underneath. And because
     * the cart page already touches the cart while reconciling stock, cart.updatedAt tracks
     * last-viewed rather than last-modified.
     *
     * @param list<FeeLine> $feeLines
     */
    private function recordCartTotals(
        EntityManagerInterface $entityManager,
        ?Cart $cart,
        ?string $region,
        ?string $shippingLabel,
        float $subtotal,
        float $tax,
        float $total,
        array $feeLines,
        ?string $taxLinesJson,
    ): void {
        if ($cart === null) {
            return;
        }

        // No shipping figure is passed or written: setFeeLines() derives it from the shipping row
        // in $feeLines, which is the only place it is stated.
        $cart
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax(number_format($tax, 2, '.', ''))
            ->setTotal(number_format($total, 2, '.', ''))
            ->setShippingMethod($shippingLabel)
            ->setFulfillmentRegion($region)
            ->setTaxLines($taxLinesJson)
            ->setFeeLines(FeeLineSnapshot::encode($feeLines));

        $entityManager->flush();
    }

    private function cartEffectiveShipping(float $subtotal, float $freeShippingThreshold, float $shippingAmount): float
    {
        return $subtotal > 0.0 && ($freeShippingThreshold <= 0.0 || $subtotal < $freeShippingThreshold)
            ? $shippingAmount
            : 0.0;
    }

    /**
     * @param list<array{product: ProductCore, qty: int, subtotal: ?float}> $rows
     *
     * @return array{0: ?string, 1: ?float}
     */
    private function resolveCartShipping(
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
        ?Company $company,
        array $rows,
        ShippingResolver $shippingResolver,
    ): array {
        $selectedShippingMethod = $session->get(self::SESSION_SHIPPING_METHOD);
        $selectedLabel = is_array($selectedShippingMethod) ? (string) ($selectedShippingMethod['label'] ?? '') : '';
        if ($selectedLabel === '' || !$company instanceof Company || $rows === []) {
            return [null, null, false];
        }

        $shippingAddress = $company->getDefaultShippingAddress();
        if (!$shippingAddress instanceof CompanyAddress) {
            return [null, null, false];
        }

        // Every row, priced or not: shipping asks about goods rather than money. The document
        // derives the province, the tax class and the address-book link itself.
        $document = $this->shippingDocumentFor($company, $shippingAddress, $rows);

        foreach ($shippingResolver->getAvailableOptions($document) as $option) {
            if ($option->label === $selectedLabel) {
                return [$option->label, $option->amount, $option->forcesQuote];
            }
        }

        return [null, null, false];
    }

    #[Route('/add', name: 'customer_cart_add', methods: ['POST'])]
    public function add(Request $request, CartService $cartService, EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $sku = trim((string) $request->request->get('sku', ''));
        $qty = max(1, min(999, (int) $request->request->get('qty', 1)));

        $conn = $entityManager->getConnection();
        $conn->beginTransaction();
        try {
        $conn->executeStatement('UPDATE cart SET id = id WHERE id = 0');
        if ($sku !== '') {
            $regionName = $this->currentRegionForPricing($entityManager, $request->getSession());
            $message = $cartService->add($sku, $qty, $this->resolveFulfillmentRegionEntity($entityManager, $regionName));
            $this->addFlash($message !== null ? 'warning' : 'success', $message ?? 'Added to cart.');
        }

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);
        $conn->commit();
        } catch (\Throwable $e) {
        $conn->rollBack();
        throw $e;
        }

        return $this->redirectBackOrTo($request, 'customer_cart');
    }

    /**
     * Reorder: add the lines of a past order back into the (server-side) cart. Previously the button
     * only wrote to a legacy client-side localStorage cart, which the server-backed /cart page never
     * reads — so the cart came up empty (issue #143). This adds them to the real cart via
     * CartService instead.
     *
     * The request carries an ORDER ID (and, for the per-line buttons, a line id) — not the lines
     * themselves. The server already has the order; asking the browser to hand it back its own
     * lines was the mistake. Until #230 this route took a client-supplied JSON [{sku, qty}] payload
     * and never referenced an order at all, which meant there was no order to check ownership
     * against and therefore no ownership check possible: anyone logged in could POST any SKU at any
     * quantity to /cart/reorder and have it treated as a past purchase. Loading the order here, and
     * scoping that load to the requesting customer's own company, is what closes that.
     *
     * A line can still fail to reach the cart for three reasons, and the other half of #230 was that
     * none of them were told to the customer line by line:
     *
     *  - its product is gone from the catalog (or the line never had a SKU — a note line);
     *  - its quantity is 0. Zero is a legitimate quantity on an ORDER or a QUOTE — the placeholder /
     *    soft-note line of #229 — but not in a cart, which never holds a zero-quantity line and
     *    whose delete affordance *is* setting a line to 0 (CartService::setQuantity). So a zero line
     *    is skipped here rather than added: max(1, ...) used to round it up into one real unit the
     *    customer never ordered;
     *  - it was added and then taken straight back out by CartService::capOrRemove() because nothing
     *    is available in the region. That last one is why $added cannot be a count of loop
     *    iterations — see below.
     *
     * Each reason gets one aggregated warning naming the lines it applies to, not one flash per line.
     */
    #[Route('/reorder', name: 'customer_cart_reorder', methods: ['POST'])]
    public function reorder(Request $request, CartService $cartService, EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $order = $this->findReorderableOrder($this->postedId($request, 'order'), $entityManager);
        if (!$order instanceof SalesOrder) {
            // Same message whether the order does not exist, is not a number, or belongs to someone
            // else — a customer probing ids must not be able to tell those apart.
            $this->addFlash('error', 'That order could not be found.');

            return $this->redirectBackOrTo($request, 'customer_cart');
        }

        $lines = $this->reorderableLines($order, $this->postedId($request, 'line'));
        if ($lines === []) {
            $this->addFlash('error', 'That order could not be found.');

            return $this->redirectBackOrTo($request, 'customer_cart');
        }

        $region = $this->resolveFulfillmentRegionEntity(
            $entityManager,
            $this->currentRegionForPricing($entityManager, $request->getSession())
        );
        $productRepo = $entityManager->getRepository(ProductCore::class);

        /** @var array<string, true> $attempted SKUs actually handed to CartService::add(), as a set */
        $attempted = [];
        /** @var list<string> $gone labels of lines whose product is no longer in the catalog */
        $gone = [];
        /** @var list<string> $zeroQuantity labels of lines with nothing to add */
        $zeroQuantity = [];
        $messages = [];

        $conn = $entityManager->getConnection();
        $conn->beginTransaction();
        try {
        $conn->executeStatement('UPDATE cart SET id = id WHERE id = 0');
        foreach ($lines as $line) {
            $sku = trim((string) $line->getSku());
            $label = $this->reorderLineLabel($sku, trim($line->getName()));
            // Quantities are decimal strings on the line ('2.00'); the cart deals in whole units.
            $qty = min(999, (int) $line->getQuantity());

            // Quantity is checked before the catalog on purpose: a zero-quantity line is an
            // order/quote-level placeholder, and "that line has no quantity" describes it better
            // than "that product is discontinued" — which it may also be, being a note with no SKU.
            if ($qty <= 0) {
                $zeroQuantity[] = $label;
                continue;
            }

            if ($sku === '' || !$productRepo->findOneBy(['sku' => $sku]) instanceof ProductCore) {
                $gone[] = $label;
                continue;
            }

            $message = $cartService->add($sku, $qty, $region);
            if ($message !== null) {
                $messages[] = $message;
            }
            $attempted[$sku] = true;
        }

        // The true success count: which of the SKUs we handed to add() are in the cart now.
        //
        // Counting loop iterations (the #230 defect) overstates it, because add() calls
        // capOrRemove(), which *removes* the line again when the region has none available — so an
        // order that is entirely out of stock used to report "added to the cart" having added
        // nothing. add()'s ?string return cannot tell "added fine" from "added, then removed": both
        // a cap and a removal return a message, and null covers several no-ops.
        //
        // We ask the cart rather than widening that return value. The cart is the authority on what
        // is in the cart; a richer return type would be a second, derived answer that can drift the
        // next time capOrRemove() changes — and add()/capOrRemove() were only just reworked for
        // issue #217 (a cart must not be blocked by the stock it is itself holding), which is code
        // worth touching as little as possible. It also keeps add()'s signature intact for its other
        // callers. One getItems() call after the loop, not one per line.
        $inCart = $cartService->getItems();
        $added = count(array_intersect_key($attempted, $inCart));

        foreach ($messages as $message) {
            $this->addFlash('warning', $message);
        }

        if ($gone !== []) {
            $this->addFlash('warning', $this->reorderSkipMessage(
                $gone,
                '%s is no longer available, so it was not added to your cart.',
                '%2$d items are no longer available, so they were not added to your cart: %1$s.',
            ));
        }

        if ($zeroQuantity !== []) {
            $this->addFlash('warning', $this->reorderSkipMessage(
                $zeroQuantity,
                '%s was ordered with a quantity of 0, so nothing was added to your cart for it.',
                '%2$d lines were ordered with a quantity of 0, so nothing was added to your cart for them: %1$s.',
            ));
        }

        if ($added === 0) {
            $this->addFlash('warning', 'Nothing from that order was added to your cart.');
        } else {
            $this->addFlash('success', 'Items from your order were added to the cart.');
        }

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);
        $conn->commit();
        } catch (\Throwable $e) {
        $conn->rollBack();
        throw $e;
        }

        return $this->redirectToRoute('customer_cart');
    }

    /**
     * A posted id, defensively. Deliberately not InputBag::getInt(), which throws BadRequestException
     * on a non-numeric value and turns a fat-fingered id into a 400 error page; and deliberately
     * reading through all() rather than get(), which throws on an array (order[]=1). Anything that is
     * not a positive integer becomes 0, which every caller here treats as "no such order/line" and
     * answers with a flash and a redirect.
     */
    private function postedId(Request $request, string $key): int
    {
        $value = $request->request->all()[$key] ?? null;

        return is_scalar($value) ? max(0, (int) $value) : 0;
    }

    /**
     * The order being reordered, or null — loaded by id and scoped to the requesting customer's own
     * company in the same query, so an order belonging to another company is indistinguishable from
     * one that does not exist. This is the check that could not exist while the route took its lines
     * from the request body (#230).
     */
    private function findReorderableOrder(int $orderId, EntityManagerInterface $entityManager): ?SalesOrder
    {
        $company = $this->currentCompany();
        if ($orderId <= 0 || !$company instanceof Company) {
            return null;
        }

        $order = $entityManager->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->andWhere('o.id = :id')->setParameter('id', $orderId)
            ->andWhere('o.company = :company')->setParameter('company', $company)
            ->leftJoin('o.lines', 'l')->addSelect('l')
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof SalesOrder ? $order : null;
    }

    /**
     * The lines to reorder: all of them, or just one for the per-line Reorder buttons. A line id that
     * is not on this order yields none, so a line cannot be pulled in from somebody else's order by
     * pairing it with an order id the customer does own.
     *
     * @return list<SalesOrderLine>
     */
    private function reorderableLines(SalesOrder $order, int $lineId): array
    {
        $lines = [];
        foreach ($order->getLines() as $line) {
            if ($lineId > 0 && $line->getId() !== $lineId) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * How a reorder line is named back to the customer. The SKU is what identifies the line in the
     * catalog; the name is what the customer actually recognises, so both are shown when the line has
     * both. A note line has a name and no SKU; a line whose product was deleted may have only a SKU
     * left.
     */
    private function reorderLineLabel(string $sku, string $name): string
    {
        if ($name !== '' && $sku !== '') {
            return sprintf('%s (%s)', $name, $sku);
        }

        if ($name !== '') {
            return $name;
        }

        return $sku !== '' ? $sku : 'an unnamed line';
    }

    /**
     * One flash for a whole group of skipped lines. A 40-line order that is entirely discontinued
     * should not produce 40 flashes, nor one flash 40 items long, so the enumeration is capped and
     * the rest counted. Labels are de-duplicated: the same product on two lines is one thing to say.
     *
     * @param list<string> $labels
     * @param string       $one    sprintf template taking the single label
     * @param string       $many   sprintf template taking %1$s = the list, %2$d = how many
     */
    private function reorderSkipMessage(array $labels, string $one, string $many): string
    {
        $labels = array_values(array_unique($labels));
        if (count($labels) === 1) {
            return sprintf($one, $labels[0]);
        }

        $shown = array_slice($labels, 0, self::REORDER_SKIP_LIST_LIMIT);
        $list = implode(', ', $shown);
        if (count($labels) > count($shown)) {
            $list .= sprintf(' and %d more', count($labels) - count($shown));
        }

        return sprintf($many, $list, count($labels));
    }

    #[Route('/update', name: 'customer_cart_update', methods: ['POST'])]
    public function update(Request $request, CartService $cartService, EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $regionName = $this->currentRegionForPricing($entityManager, $request->getSession());
        $region = $this->resolveFulfillmentRegionEntity($entityManager, $regionName);
        $qtyBySku = $request->request->all('qty');
        $messages = [];

        $conn = $entityManager->getConnection();
        $conn->beginTransaction();
        try {
        $conn->executeStatement('UPDATE cart SET id = id WHERE id = 0');
        foreach ($qtyBySku as $sku => $qty) {
            $message = $cartService->setQuantity((string) $sku, (int) $qty, $region);
            if ($message !== null) {
                $messages[] = $message;
            }
        }
        if ($messages === []) {
            $this->addFlash('success', 'Cart updated.');
        } else {
            foreach ($messages as $message) {
                $this->addFlash('warning', $message);
            }
        }

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);
        $conn->commit();
        } catch (\Throwable $e) {
        $conn->rollBack();
        throw $e;
        }

        return $this->redirectToRoute('customer_cart');
    }

    #[Route('/remove', name: 'customer_cart_remove', methods: ['POST'])]
    public function remove(Request $request, CartService $cartService, EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $sku = trim((string) $request->request->get('sku', ''));
        if ($sku !== '') {
            $cartService->remove($sku);
            $this->addFlash('success', 'Item removed.');
        }

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);

        return $this->redirectToRoute('customer_cart');
    }

    #[Route('/clear', name: 'customer_cart_clear', methods: ['POST'])]
    public function clear(Request $request, CartService $cartService, EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $cartService->clear();
        $this->addFlash('success', 'Cart cleared.');

        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);

        return $this->redirectToRoute('customer_cart');
    }

}
