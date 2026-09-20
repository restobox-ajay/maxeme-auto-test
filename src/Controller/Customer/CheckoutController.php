<?php

namespace App\Controller\Customer;

use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Shipping\ShippingOption;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Enum\InvoiceIssueIntent;
use App\Service\AppSettings;
use App\Service\CartService;
use App\Service\DocumentActor;
use App\Service\EstimateNumberGenerator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\OrderInvoicingService;
use App\Service\OrderNumberGenerator;
use App\Service\OrderTaxBreakdownService;
use App\Service\TextInput;
use App\Service\SalesDocumentNotifier;
use App\Validation\Constraint\ValidCouponCode;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use FeeBundle\Fee\FeeCalculatorResolver;
use PaymentBundle\Payment\PaymentMethodResolver;
use Psr\Log\LoggerInterface;
use ShippingBundle\Shipping\ShippingResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use TaxBundle\Tax\TaxCalculatorResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/checkout')]
final class CheckoutController extends AbstractCustomerController
{
    private const EXPECTED_DELIVERY_PREFIX = 'Expected delivery time:';
    private const SESSION_ADDRESS_ID = 'customer_checkout_ship_address_id';
    private const SESSION_BILL_ADDRESS_ID = 'customer_checkout_bill_address_id';
    private const SESSION_COUPON_CODE = 'customer_checkout_coupon_code';
    private const SESSION_PAYMENT_METHOD = 'customer_checkout_payment_method';

    #[Route('', name: 'customer_checkout', methods: ['GET'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        AppSettings $appSettings,
        ShippingResolver $shippingResolver,
        FeeCalculatorResolver $feeResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CartService $cartService,
        OrderTaxBreakdownService $taxBreakdownService,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to continue to checkout.');

            return $this->redirectToRoute('customer_login');
        }

        $company = $this->resolveActorCompany($actor, $entityManager);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');

            return $this->redirectToRoute('customer_home');
        }

        if (!$this->companyHasActiveFulfillmentRegion($entityManager, $company)) {
            $this->addFlash('error', 'No fulfillment region is currently enabled for your account. Please contact your account representative.');

            return $this->redirectToRoute('customer_cart');
        }

        $region = $this->currentRegionForPricing($entityManager, $request->getSession());
        foreach ($cartService->reconcileAgainstRegion($this->resolveFulfillmentRegionEntity($entityManager, $region)) as $message) {
            $this->addFlash('warning', $message);
        }
        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);
        $rows = $this->resolveCartRows($entityManager, $company, $cartService, $region);
        if ($rows === []) {
            $this->addFlash('error', 'Your cart is empty. Add some products before checking out.');

            return $this->redirectToRoute('customer_cart');
        }

        $session = $request->getSession();
        $addresses = $this->companyAddressesForCheckout($company);
        $shippingAddress = $this->resolveSelectedShippingAddress($session, $addresses);
        $session->set(self::SESSION_ADDRESS_ID, $shippingAddress?->getId());

        $billingAddress = $this->resolveSelectedBillingAddress($session, $addresses);
        $session->set(self::SESSION_BILL_ADDRESS_ID, $billingAddress?->getId());

        $shippingValues = $this->checkoutShippingDefaults($actor, $company, $shippingAddress);
        $checkoutConfig = $this->checkoutConfig($appSettings);

        $province = $shippingAddress?->getProvince() ?? '';

        $resolvedRows = array_filter($rows, static fn (array $r) => $r['priceResolved']);
        $hasUnresolvedRows = count($resolvedRows) < count($rows);

        // The last fee/tax inputs still assembled by hand, here and in placeOrder() below. Two
        // things stop these reading off the cart document: the page prices a parallel row array
        // rather than the cart's own lines (see CartController), and the address being priced
        // against is the one the customer picked on this page, which the cart does not record until
        // it is converted. Shipping no longer needs them — it takes the document below.
        $feeCartItems = [];
        $taxableLines = [];
        $rawTaxCodes = [];
        foreach ($rows as $row) {
            // Every row, priced or not, so an unpriced item's tax class still counts toward the
            // class the shipping row is written at. Only the taxable-lines array is price-filtered.
            $feeCartItems[] = ['product' => $row['product'], 'qty' => $row['qty']];
            $rawTaxCodes[] = $row['product']->getSalesTaxCode();
            if ($row['priceResolved']) {
                $taxableLines[] = ['subtotal' => $row['subtotal'], 'taxCode' => $row['product']->getSalesTaxCode()];
            }
        }

        $subtotal = (float) array_sum(array_map(static fn (array $r) => $r['subtotal'], $resolvedRows));

        $shippingOptions = [];
        if ($shippingAddress instanceof CompanyAddress) {
            // Every row, priced or not, against the address the customer picked here. The document
            // derives the province, the tax class and the address-book link itself.
            $shippingOptions = $shippingResolver->getAvailableOptions(
                $this->shippingDocumentFor($company, $shippingAddress, $rows)
            );
        }
        $shippingResolvedForDisplay = $shippingOptions !== [];

        $selectedShippingMethod = $session->get(self::SESSION_SHIPPING_METHOD);
        $selectedShippingLabel = is_array($selectedShippingMethod) ? (string) ($selectedShippingMethod['label'] ?? '') : '';
        $matchedShippingOption = $this->matchShippingOption($shippingOptions, $selectedShippingLabel);
        if ($matchedShippingOption === null) {
            $matchedShippingOption = $shippingOptions[0] ?? null;
            $session->set(self::SESSION_SHIPPING_METHOD, $matchedShippingOption !== null ? ['label' => $matchedShippingOption->label] : null);
        }
        // Amount always comes from the freshly-resolved option, never from the session/client — see matchShippingOption().
        $shippingAmount = $matchedShippingOption?->amount ?? 0.0;
        $forcesQuoteForDisplay = $matchedShippingOption?->forcesQuote ?? false;

        $paymentMethods = $paymentMethodResolver->getAvailableForCompany($company);
        $selectedPaymentSlug = (string) $session->get(self::SESSION_PAYMENT_METHOD, '');
        $matchedPaymentMethod = $this->matchPaymentMethod($paymentMethods, $selectedPaymentSlug);
        if ($matchedPaymentMethod === null) {
            $matchedPaymentMethod = $paymentMethods[0] ?? null;
        }
        $session->set(self::SESSION_PAYMENT_METHOD, $matchedPaymentMethod?->getSlug());

        $couponsEnabled = (bool) ($checkoutConfig['couponsEnabled'] ?? true);
        if (!$couponsEnabled) {
            // Coupons were switched off after this code was stored: drop it rather than keep
            // discounting silently. No flash — the customer did nothing wrong.
            $session->remove(self::SESSION_COUPON_CODE);
        }
        $couponCode = $couponsEnabled ? (string) $session->get(self::SESSION_COUPON_CODE, '') : '';
        [$coupon, $couponError] = $this->resolveCheckoutCoupon($checkoutConfig['coupons'], $couponCode, $subtotal);
        if ($couponError !== null) {
            $this->addFlash('error', $couponError);
            $session->remove(self::SESSION_COUPON_CODE);
            $couponCode = '';
        }

        $feeLines = $feeResolver->calculate(new FeeContext($province, $feeCartItems, companyId: $company->getId()));
        $feeTotal = (float) array_sum(array_map(static fn (FeeLine $l) => $l->amount, $feeLines));
        $feeTotalsMainLine = (float) array_sum(array_map(
            static fn (FeeLine $l) => $l->placement === 'main_line' ? $l->amount : 0.0,
            $feeLines,
        ));

        $effectiveShipping = $this->checkoutEffectiveShipping($subtotal, $checkoutConfig, $coupon, $shippingAmount);
        $taxBreakdown = $taxBreakdownService->computeBreakdown(
            $province,
            $company->getId(),
            $taxableLines,
            array_merge($feeLines, $this->shippingLinesFor($matchedShippingOption?->label, $effectiveShipping, TaxContext::resolveHighestTaxClass($rawTaxCodes))),
        );

        $totals = $this->checkoutTotals($subtotal, $checkoutConfig, $coupon, $shippingAmount, $feeTotal, $taxBreakdown['total']);

        return $this->render('customer/checkout/index.html.twig', [
            'company' => $company,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'addresses' => $addresses,
            'selectedShippingAddressId' => $shippingAddress?->getId(),
            'selectedBillingAddressId' => $billingAddress?->getId(),
            'shippingValues' => $shippingValues,
            'paymentMethods' => $paymentMethods,
            'selectedPaymentMethodSlug' => $matchedPaymentMethod?->getSlug(),
            'paymentTermName' => $this->companyPaymentTermName($entityManager, $company),
            'rows' => $rows,
            'hasUnresolvedRows' => $hasUnresolvedRows,
            'shippingResolved' => $shippingResolvedForDisplay,
            'willBeEstimate' => $hasUnresolvedRows || !$shippingResolvedForDisplay || $forcesQuoteForDisplay,
            'subtotal' => $subtotal,
            'feeTotalsMainLine' => $feeTotalsMainLine,
            'shippingOptions' => $shippingOptions,
            'selectedShippingLabel' => $matchedShippingOption?->label,
            'shippingForcesQuote' => $forcesQuoteForDisplay,
            'feeLines' => $feeLines,
            'feeTotal' => $feeTotal,
            'taxLines' => $taxBreakdown['lines'],
            'couponCode' => $couponCode,
            'coupon' => $coupon,
            'couponsEnabled' => $couponsEnabled,
            'totals' => $totals,
            'shippingWaived' => $this->freeShippingWaived($subtotal, $checkoutConfig, $coupon),
            'freeShippingThreshold' => (float) ($checkoutConfig['freeShippingThreshold'] ?? 0),
            'pricingRegion' => $region,
            'cartHoldExpiresAt' => $cartService->getCart()?->getHoldExpiresAt(),
        ]);
    }

    #[Route('/address', name: 'customer_checkout_set_address', methods: ['POST'])]
    public function setAddress(Request $request): RedirectResponse
    {
        $addressId = (int) $request->request->get('ship_address_id', 0);
        $request->getSession()->set(self::SESSION_ADDRESS_ID, $addressId > 0 ? $addressId : null);
        // Address changed: the previously chosen shipping method may no longer be valid/available.
        $request->getSession()->remove(self::SESSION_SHIPPING_METHOD);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('/billing-address', name: 'customer_checkout_set_billing_address', methods: ['POST'])]
    public function setBillingAddressFrom(Request $request): RedirectResponse
    {
        $addressId = (int) $request->request->get('bill_address_id', 0);
        $request->getSession()->set(self::SESSION_BILL_ADDRESS_ID, $addressId > 0 ? $addressId : null);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('/shipping', name: 'customer_checkout_set_shipping', methods: ['POST'])]
    public function setShipping(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        // Radio value is "label|amount", but the amount is client-supplied and untrusted —
        // only the label is stored. index()/submit() always re-resolve the real amount
        // server-side from ShippingResolver's current options (see matchShippingOption()).
        $choice = (string) $request->request->get('shipping_method_choice', '');
        $label = $choice !== '' ? trim(explode('|', $choice, 2)[0]) : '';
        $session->set(self::SESSION_SHIPPING_METHOD, $label !== '' ? ['label' => $label] : null);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('/payment-method', name: 'customer_checkout_set_payment_method', methods: ['POST'])]
    public function setPaymentMethod(Request $request): RedirectResponse
    {
        $slug = (string) $request->request->get('payment_method', '');
        // Validity against the company's currently available methods is re-checked
        // on every index()/submit() call — never trusted from the session alone.
        $request->getSession()->set(self::SESSION_PAYMENT_METHOD, $slug !== '' ? $slug : null);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('/coupon', name: 'customer_checkout_set_coupon', methods: ['POST'])]
    public function setCoupon(Request $request, AppSettings $appSettings): RedirectResponse
    {
        if (!$this->couponsEnabled($appSettings)) {
            // The field is hidden when coupons are off, so this is a stale page or a direct POST.
            $request->getSession()->remove(self::SESSION_COUPON_CODE);
            $this->addFlash('error', 'Coupon codes are not accepted at this time.');

            return $this->redirectToRoute('customer_checkout');
        }

        $code = strtoupper(trim((string) $request->request->get('coupon_code', '')));
        $request->getSession()->set(self::SESSION_COUPON_CODE, $code);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('/coupon/remove', name: 'customer_checkout_remove_coupon', methods: ['POST'])]
    public function removeCoupon(Request $request): RedirectResponse
    {
        $request->getSession()->remove(self::SESSION_COUPON_CODE);
    

        return $this->redirectToRoute('customer_checkout');
    }

    #[Route('', name: 'customer_checkout_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        EntityManagerInterface $entityManager,
        AppSettings $appSettings,
        ShippingResolver $shippingResolver,
        FeeCalculatorResolver $feeResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CartService $cartService,
        OrderTaxBreakdownService $taxBreakdownService,
        OrderNumberGenerator $orderNumberGenerator,
        EstimateNumberGenerator $estimateNumberGenerator,
        LoggerInterface $logger,
        SalesDocumentNotifier $notifier,
        EventDispatcherInterface $eventDispatcher,
        TaxCalculatorResolver $taxResolver,
        OrderInvoicingService $orderInvoicing,
        BackorderSplitResolver $backorderSplits,
    ): Response {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to continue to checkout.');

            return $this->redirectToRoute('customer_login');
        }

        $company = $this->resolveActorCompany($actor, $entityManager);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');

            return $this->redirectToRoute('customer_home');
        }

        if (!$this->companyHasActiveFulfillmentRegion($entityManager, $company)) {
            $this->addFlash('error', 'No fulfillment region is currently enabled for your account. Please contact your account representative.');

            return $this->redirectToRoute('customer_cart');
        }

        $checkoutConfig = $this->checkoutConfig($appSettings);
        [$document, $error] = $this->processCheckoutSubmission($request, $entityManager, $company, $actor, $checkoutConfig, $shippingResolver, $feeResolver, $paymentMethodResolver, $cartService, $taxBreakdownService, $orderNumberGenerator, $estimateNumberGenerator, $logger, $taxResolver, $orderInvoicing, $backorderSplits);

        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('customer_checkout');
        }

        $session = $request->getSession();
        $session->remove(self::SESSION_ADDRESS_ID);
        $session->remove(self::SESSION_SHIPPING_METHOD);
        $session->remove(self::SESSION_COUPON_CODE);
        $cartService->clear();
        // The order just placed already reserves this inventory in the Pending bucket (via
        // InventoryReconciliationSubscriber) — dispatching with the now-empty/cleared cart
        // makes CartHoldBundle (if listening) release the now-redundant hold immediately, rather
        // than leaving it to double-count Available until it expires.
        $this->syncCartHold($request, $entityManager, $cartService, $eventDispatcher);

        if ($document instanceof Estimate) {
            $notifier->quoteRequestReceived($document, $actor->getEmail());
            $notifier->quoteRequestAdmin($document);

            $this->addFlash('success', sprintf('Quote request %s was sent successfully.', $document->getDocumentNumber()));

            return $this->redirectToRoute('customer_estimate_detail', ['id' => $document->getId()]);
        }

        $notifier->orderReceived($document, $actor->getEmail());

        // An order whose invoice is On Hold is placed but not settled — the customer still has to
        // pay it on the order page, so say so rather than implying the transaction is finished.
        // Read off the INVOICE, not the order: since #539 stage 2 the order's status says how much
        // has been invoiced, and nothing about whether the money arrived.
        $awaitingPayment = false;
        foreach ($document->getInvoices() as $invoice) {
            $awaitingPayment = $awaitingPayment || $invoice->isStatus('On Hold');
        }

        $this->addFlash('success', $awaitingPayment
            ? sprintf('Order %s was placed. Enter your card details below to complete payment.', $document->getOrderNumber())
            : sprintf('Order %s was placed successfully.', $document->getOrderNumber()));

        return $this->redirectToRoute('customer_order_detail', ['id' => $document->getId()]);
    }

    /**
     * @return array{0: ?SalesOrder, 1: ?string}|array{0: ?Estimate, 1: ?string}
     */
    private function processCheckoutSubmission(
        Request $request,
        EntityManagerInterface $entityManager,
        Company $company,
        CustomerUser $actor,
        array $checkoutConfig,
        ShippingResolver $shippingResolver,
        FeeCalculatorResolver $feeResolver,
        PaymentMethodResolver $paymentMethodResolver,
        CartService $cartService,
        OrderTaxBreakdownService $taxBreakdownService,
        OrderNumberGenerator $orderNumberGenerator,
        EstimateNumberGenerator $estimateNumberGenerator,
        LoggerInterface $logger,
        TaxCalculatorResolver $taxResolver,
        OrderInvoicingService $orderInvoicing,
        BackorderSplitResolver $backorderSplits,
    ): array {
        $session = $request->getSession();
        // Same region the catalog/cart priced against — re-resolved here so a region
        // deactivated mid-session falls back to a currently-allowed one, never a stale price list.
        $region = $this->currentRegionForPricing($entityManager, $session);
        $rows = $this->resolveCartRows($entityManager, $company, $cartService, $region);
        if ($rows === []) {
            return [null, 'Your cart is empty.'];
        }

        $addresses = $this->companyAddressesForCheckout($company);
        $shippingAddress = $this->resolveSelectedShippingAddress($session, $addresses);
        if (!$shippingAddress instanceof CompanyAddress) {
            return [null, 'Please select a shipping address before placing the order.'];
        }
        // Older addresses saved before Province became required can still be missing it —
        // shipping/tax calculation needs a real province, so block here with a clear message
        // rather than computing shipping/tax against an empty province.
        if (trim((string) $shippingAddress->getProvince()) === '') {
            return [null, 'Your shipping address is missing a Province. Please edit it before placing the order.'];
        }

        $billingAddress = $this->resolveSelectedBillingAddress($session, $addresses);

        // Mirrors how shipping is read (see $selectedShippingMethod below): the selection lives
        // server-side in session, set by setPaymentMethod(), never trusted straight off the
        // main order form. Re-resolved here against the company's currently available methods.
        $paymentMethodSlug = (string) $session->get(self::SESSION_PAYMENT_METHOD, '');
        $paymentMethod = $this->matchPaymentMethod($paymentMethodResolver->getAvailableForCompany($company), $paymentMethodSlug);
        if ($paymentMethod === null) {
            return [null, 'Please select a payment method before placing the order.'];
        }

        // No payment is taken here. Checkout's job is to persist the order; money is collected
        // afterwards against that persisted order by Customer\OrderController's payment routes,
        // which validate the PaymentIntent against $order->getTotal() and its metadata.order_id.
        // Taking the card first left nothing to check the captured amount against, and nothing for
        // a Stripe webhook to attach a payment to when the browser never came back.
        $paymentValidationResult = $paymentMethod->validate(['company' => $company]);
        if (!$paymentValidationResult->success) {
            return [null, $paymentValidationResult->message ?? 'The selected payment method could not be validated. Please try again.'];
        }

        // The shipping amount is never trusted from the session/client — it is always re-resolved
        // here from the current, authoritative ShippingResolver options for this cart/address.
        $shippingOptions = $shippingResolver->getAvailableOptions(
            $this->shippingDocumentFor($company, $shippingAddress, $rows)
        );
        $shippingUnresolved = $shippingOptions === [];

        $selectedShippingMethod = $session->get(self::SESSION_SHIPPING_METHOD);
        $shippingLabel = is_array($selectedShippingMethod) ? (string) ($selectedShippingMethod['label'] ?? '') : '';
        $matchedShippingOption = null;
        if (!$shippingUnresolved) {
            // Options exist but the customer hasn't picked one — an input gap, not a TBD state.
            if ($shippingLabel === '') {
                return [null, 'Please choose a delivery option before placing the order.'];
            }
            $matchedShippingOption = $this->matchShippingOption($shippingOptions, $shippingLabel);
            if ($matchedShippingOption === null) {
                return [null, 'The selected delivery option is no longer available. Please choose again.'];
            }
            $shippingLabel = $matchedShippingOption->label;
        }
        $forcesQuote = $matchedShippingOption?->forcesQuote ?? false;

        $hasUnresolvedPricing = false;
        foreach ($rows as $row) {
            if (!$row['priceResolved']) {
                $hasUnresolvedPricing = true;
                break;
            }
        }

        $subtotal = (float) array_sum(array_map(static fn (array $r) => $r['subtotal'] ?? 0.0, $rows));

        if ($hasUnresolvedPricing || $shippingUnresolved || $forcesQuote) {
            return $this->buildAndPersistEstimate(
                $request, $entityManager, $company, $actor, $rows, $shippingAddress, $billingAddress, $paymentMethod,
                $matchedShippingOption, $feeResolver, $taxBreakdownService, $estimateNumberGenerator, $region, $logger,
            );
        }

        // Everything resolved — a real order, not a quote. Client requirement is explicit that
        // "quotes happen when... no price or shipping is TBD"; an earlier version of this code
        // kept every checkout on 'Waiting for Quote' regardless, which was exactly the "old Quote
        // code" the client asked to have cleaned up. Corrected 2026-07-14.
        $couponCode = ($checkoutConfig['couponsEnabled'] ?? true) ? (string) $session->get(self::SESSION_COUPON_CODE, '') : '';
        [$coupon, $couponError] = $this->resolveCheckoutCoupon($checkoutConfig['coupons'], $couponCode, $subtotal);
        if ($couponError !== null) {
            return [null, $couponError];
        }

        return $this->buildAndPersistOrder(
            $request, $entityManager, $company, $actor, $checkoutConfig, $rows, $shippingAddress, $billingAddress, $paymentMethod,
            $matchedShippingOption, $shippingLabel, $coupon, $feeResolver, $taxBreakdownService, $orderNumberGenerator,
            $region, $subtotal, $logger, $taxResolver, $cartService, $orderInvoicing, $backorderSplits,
        );
    }

    /**
     * @return array{0: ?SalesOrder, 1: ?string}
     */
    private function buildAndPersistOrder(
        Request $request,
        EntityManagerInterface $entityManager,
        Company $company,
        CustomerUser $actor,
        array $checkoutConfig,
        array $rows,
        CompanyAddress $shippingAddress,
        ?CompanyAddress $billingAddress,
        PaymentMethodInterface $paymentMethod,
        ShippingOption $matchedShippingOption,
        string $shippingLabel,
        ?array $coupon,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
        OrderNumberGenerator $orderNumberGenerator,
        ?string $region,
        float $subtotal,
        LoggerInterface $logger,
        TaxCalculatorResolver $taxResolver,
        CartService $cartService,
        OrderInvoicingService $orderInvoicing,
        BackorderSplitResolver $backorderSplits,
    ): array {
        $order = new SalesOrder();
        $billingName = trim((string) $request->request->get('bill_first_name', '') . ' ' . (string) $request->request->get('bill_last_name', ''));
        $order->setCompany($company);
        $order->setOrderNumber($orderNumberGenerator->next($entityManager));
        // No status assignment here any more (#539 stage 2). The customer placing the order is what
        // approves it — the gate is called below, once the order is persisted, so the acceptance
        // lands on its timeline. Whether the money has arrived is the INVOICE's question now: a
        // card-style method raises it On Hold, which holds no stock and is what the stale-unpaid
        // sweep looks for, and anything settled later by arrangement (pay on delivery, transfer,
        // trade credit) raises it issued and worked while still unpaid.
        $order->setSource('Customer');
        $order->setUserName(trim((string) (($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? ''))) ?: $actor->getEmail());
        $order->setBillingName($billingName !== '' ? $billingName : $company->getName());
        $orderCompanyName = $this->nullableString($request->request->get('order_company_name')) ?? $company->getName();
        $orderPhoneNumber = $this->nullableString($request->request->get('order_phone_number'));

        $order->setShippingName($this->nullableString($request->request->get('ship_name')) ?? $company->getName());
        $order->setShippingCompanyName($orderCompanyName);
        // No setDocumentDate() here, or on the estimate path below: SalesDocumentDateStamp dates both at
        // persist time, from the display timezone rather than from PHP's UTC default.
        $order->setPoNumber(TextInput::nullableStringMax($request->request->get('po_number'), 80));
        // No payment status written here since #539 stage 4: whether money has arrived is the
        // invoice's question, derived from its own payment rows, and the invoice this checkout
        // raises below is born Not Paid because nothing has been paid yet.
        $order->setPaymentMethod($paymentMethod->getName());
        $order->setPaymentTerm($this->companyPaymentTermName($entityManager, $company));

        if ($billingAddress instanceof CompanyAddress) {
            $order->setBillingAddressFrom($billingAddress);
        }

        $shippingNameFallback = trim((string) (($shippingAddress->getFirstName() ?? '') . ' ' . ($shippingAddress->getLastName() ?? '')));
        $shippingCompanyFallback = $shippingAddress->getCompanyName() ?: $company->getName();
        $order->setShippingAddressFrom($shippingAddress);
        $order->setShippingName($this->nullableString($request->request->get('ship_name')) ?? ($shippingNameFallback !== '' ? $shippingNameFallback : $company->getName()));
        $order->setShippingCompanyName($orderCompanyName ?: $shippingCompanyFallback);

        foreach ($rows as $row) {
            $orderLine = (new SalesOrderLine())
                ->setProduct($row['product'])
                ->setName($row['product']->getName())
                ->setLocation(null)
                ->setSku($row['product']->getSku())
                ->setQuantity($this->decimal((float) $row['qty']))
                ->setWeight($row['product']->getWeight())
                ->setUnit($row['product']->getUnit())
                ->setTaxCode($row['product']->getSalesTaxCode())
                ->setCost($this->decimal((float) ($row['product']->getCostPrice() ?? 0)))
                ->setPrice($this->decimal($row['price']))
                ->setSubtotal($this->decimal($row['subtotal']));
            $order->addLine($orderLine);
        }

        $shippingAmount = $matchedShippingOption->amount;

        $feeLines = $feeResolver->calculate(FeeContext::fromDocument($order, $paymentMethod->getSlug()));
        $feeTotal = (float) array_sum(array_map(fn (FeeLine $l) => $l->amount, $feeLines));

        $effectiveShipping = $this->checkoutEffectiveShipping($subtotal, $checkoutConfig, $coupon, $shippingAmount);
        // Shipping joins the fee rows after $feeTotal is summed, not before: checkoutTotals() states
        // the shipping figure itself, and counting the row in both places would charge it twice.
        $feeLines = array_merge($feeLines, $this->shippingLinesFor($matchedShippingOption->label, $effectiveShipping, $order->getHighestTaxClass()));
        $taxBreakdown = $taxBreakdownService->computeBreakdownFor($order, $feeLines);

        $totals = $this->checkoutTotals($subtotal, $checkoutConfig, $coupon, $shippingAmount, $feeTotal, $taxBreakdown['total']);
        $specialInstructions = $this->combineSpecialInstructions(
            $this->nullableString($request->request->get('expected_delivery_time')),
            $this->nullableString($request->request->get('checkout_notes')),
        );
        if ($orderPhoneNumber !== null) {
            $phoneNote = 'Order phone number: ' . $orderPhoneNumber;
            $specialInstructions = $specialInstructions !== null && $specialInstructions !== ''
                ? $specialInstructions . ' | ' . $phoneNote
                : $phoneNote;
        }
        if ($coupon !== null) {
            $couponNote = sprintf('Coupon applied: %s (-%s)', $coupon['code'], $this->money($totals['discount']));
            $specialInstructions = $specialInstructions !== null && $specialInstructions !== ''
                ? $specialInstructions . ' | ' . $couponNote
                : $couponNote;
        }
        $order->setSpecialInstructions($specialInstructions);
        $order->setShippingMethod($shippingLabel);
        $order->setFulfillmentRegion($region);

        $order->setFeeLines(FeeLineSnapshot::encode($feeLines));

        $order
            ->setSubtotal($this->decimal($subtotal))
            ->setTax($this->decimal($totals['tax']))
            ->setTotal($this->decimal($totals['total']))
            ->setTaxLines($taxBreakdownService->toJson($taxBreakdown));

        // Stock re-check, inside the same transaction as the order it would otherwise be committed
        // under (#301) — the rows resolved at the top of processCheckoutSubmission() reflect
        // availability as of THAT read; anything can have changed by the time this actually
        // commits, and a hold is a reservation, not a guarantee. Using the EntityManager's own
        // Connection::beginTransaction()/commit() (not raw SQL) matters here specifically because
        // reconcileAgainstRegion() and the order save below both call EntityManager::flush()
        // internally, and flush() manages its own transaction — nesting only composes correctly
        // (a savepoint instead of a second real BEGIN) through DBAL's own tracked nesting counter.
        // isTransactionActive() skips opening a second one if this is ever called from inside an
        // already-open transaction.
        $conn = $entityManager->getConnection();
        $ownsTransaction = !$conn->isTransactionActive();
        if ($ownsTransaction) {
            $conn->beginTransaction();
        }

        // The named actions below take an explicit actor rather than reaching for the ambient user,
        // which is what lets the same actions be called by the stale-unpaid sweep (#539 stage 2).
        $documentActor = DocumentActor::forCustomer($actor);

        try {
            // Forces SQLite's write lock now, before the stock re-check below reads anything —
            // beginTransaction() alone is deferred and takes no lock until the first write, so
            // without this the re-check could still run against a value a concurrent request is
            // about to change (#301).
            $conn->executeStatement('UPDATE cart SET id = id WHERE id = 0');
            $stockMessages = $cartService->reconcileAgainstRegion($this->resolveFulfillmentRegionEntity($entityManager, $region));
            if ($stockMessages !== []) {
                if ($ownsTransaction) {
                    $conn->rollBack();
                }

                return [null, implode(' ', $stockMessages) . ' Please review your cart and try again.'];
            }

            $entityManager->persist($order);
            // Placing the order is accepting it, so it is approved here rather than left for an
            // admin to confirm — the behaviour a checkout has always had. Through the gate, which is
            // the only way a status changes (ruling R1); it also writes the approval onto the
            // order's timeline.
            $order->setStatus('Approved', $documentActor, 'Order approved.');
            // Which of these units exist and which are a promise (#548). Between the approval and
            // the invoice, and in that order for a reason: a Draft promises nothing, so the split
            // needs the approved status to compute anything at all, and the invoice raised below
            // reads each line's backordered quantity to decide how much of what it bills has stock
            // behind it (see InvoiceReservationSubject). Splitting after the invoice would leave
            // the promised units held twice.
            $backorderSplits->applyToOrderLines($order, $order->getStatus(), $entityManager);
            // Inside the same transaction as the order, deliberately: #539 stage 1's invariant is
            // that every order has exactly one invoice, and an order committed without one would
            // break it permanently — there is no later pass that fixes it up. Raised after persist()
            // so SalesDocumentDateStamp has dated the order this copies its date from.
            //
            // The checkout is what knows whether the money has arrived, so it says so rather than
            // having OrderInvoicingService infer it back out of a status that no longer carries it.
            $orderInvoicing->invoiceInFull(
                $order,
                $entityManager,
                $documentActor,
                $paymentMethod->requiresImmediatePayment()
                    ? InvoiceIssueIntent::AwaitingPayment
                    : InvoiceIssueIntent::Issue,
            );
            // A checkout order opens its log the same way an admin-created one does — same wording,
            // same type, same customerNotified default — so the two read alike (#271).
            $order->queueActivityLogEntry()
                ->setUserName($documentActor->displayName)
                ->setComment(sprintf('Order %s created by %s.', $order->getOrderNumber(), $actor->getUserIdentifier()))
                ->setType('System');
            $entityManager->flush();

            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (UniqueConstraintViolationException $e) {
            if ($ownsTransaction && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            // order_number collided with a concurrently-created order (see OrderNumberGenerator) —
            // expected occasionally under concurrent checkouts, not a bug; ask the user to resubmit
            // so a fresh number is generated on the next attempt.
            $logger->warning('Checkout order number collided with a concurrent order.', [
                'companyId' => $company->getId(),
                'customerUserId' => $actor->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'exception' => $e,
            ]);

            return [null, 'We could not place your order right now. Please try again.'];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            $logger->error('Customer checkout order save failed.', [
                'companyId' => $company->getId(),
                'customerUserId' => $actor->getId(),
                'exception' => $e,
            ]);

            return [null, 'We could not place your order right now. Please try again.'];
        }

        $feeResolver->applyOrderSnapshots($order, $company->getId());
        $taxResolver->applyOrderSnapshots($order, $company->getId());
        $entityManager->flush();

        return [$order, null];
    }

    /**
     * The customer-side equivalent of EstimateController::adminDisplayName() — the same
     * "First Last, email (id)" shape the admin log entries carry, so the User column of a document
     * log reads identically whichever side wrote the row.
     *
     * The shape itself moved to DocumentActor (#539 stage 2), where the named actions on SalesOrder
     * and Invoice read it from, so a timeline entry written by an action and one written here can
     * no longer disagree about how the same person is named.
     */
    private function documentActorName(CustomerUser $actor): string
    {
        return DocumentActor::forCustomer($actor)->displayName;
    }

    /**
     * @return array{0: ?Estimate, 1: ?string}
     */
    private function buildAndPersistEstimate(
        Request $request,
        EntityManagerInterface $entityManager,
        Company $company,
        CustomerUser $actor,
        array $rows,
        CompanyAddress $shippingAddress,
        ?CompanyAddress $billingAddress,
        PaymentMethodInterface $paymentMethod,
        ?ShippingOption $matchedShippingOption,
        FeeCalculatorResolver $feeResolver,
        OrderTaxBreakdownService $taxBreakdownService,
        EstimateNumberGenerator $estimateNumberGenerator,
        ?string $region,
        LoggerInterface $logger,
    ): array {
        $estimate = new Estimate();
        $billingName = trim((string) $request->request->get('bill_first_name', '') . ' ' . (string) $request->request->get('bill_last_name', ''));
        $estimate->setCompany($company);
        $estimate->setDocumentNumber($estimateNumberGenerator->next($entityManager));
        // Draft -> Submitted through the seam's one door. The quote is born Draft, so this is a real
        // move and it opens the timeline with the customer who submitted it — which is what the
        // admin screen had to infer from the created-by line before.
        $estimate->setStatus('Submitted', DocumentActor::forCustomer($actor), 'Quote requested at checkout.');
        $estimate->setSource('Customer');
        $estimate->setUserName(trim((string) (($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? ''))) ?: $actor->getEmail());
        $estimate->setBillingName($billingName !== '' ? $billingName : $company->getName());
        $orderCompanyName = $this->nullableString($request->request->get('order_company_name')) ?? $company->getName();
        $orderPhoneNumber = $this->nullableString($request->request->get('order_phone_number'));

        $estimate->setPoNumber(TextInput::nullableStringMax($request->request->get('po_number'), 80));

        if ($billingAddress instanceof CompanyAddress) {
            $estimate->setBillingAddressFrom($billingAddress);
        }

        $shippingNameFallback = trim((string) (($shippingAddress->getFirstName() ?? '') . ' ' . ($shippingAddress->getLastName() ?? '')));
        $shippingCompanyFallback = $shippingAddress->getCompanyName() ?: $company->getName();
        $estimate->setShippingAddressFrom($shippingAddress);
        $estimate->setShippingName($this->nullableString($request->request->get('ship_name')) ?? ($shippingNameFallback !== '' ? $shippingNameFallback : $company->getName()));
        $estimate->setShippingCompanyName($orderCompanyName ?: $shippingCompanyFallback);

        $allPriced = true;
        foreach ($rows as $row) {
            $line = (new EstimateLine())
                ->setProduct($row['product'])
                ->setName($row['product']->getName())
                ->setLocation(null)
                ->setSku($row['product']->getSku())
                ->setQuantity($this->decimal((float) $row['qty']))
                ->setWeight($row['product']->getWeight())
                ->setUnit($row['product']->getUnit())
                ->setTaxCode($row['product']->getSalesTaxCode())
                ->setCost($this->decimal((float) ($row['product']->getCostPrice() ?? 0)));

            if ($row['priceResolved']) {
                $line->setPrice($this->decimal($row['price']));
                $line->setSubtotal($this->decimal($row['subtotal']));
            } else {
                $allPriced = false;
            }

            $estimate->addLine($line);
        }

        // Fees are computed even though the estimate as a whole is not priced: every line that does
        // have a price is charged for. A TBD line is left out, which is the rule the admin estimate
        // form has always applied — see AbstractSalesDocument::getCartItems().
        $feeLines = $feeResolver->calculate(FeeContext::fromDocument($estimate, $paymentMethod->getSlug()));

        // Shipping is known independently of pricing — record it now so the admin has less to fill
        // in. No free-shipping threshold is applied here, as it never was: the threshold is judged
        // against a subtotal this estimate does not have yet.
        $feeLines = array_merge(
            $feeLines,
            $this->shippingLinesFor($matchedShippingOption?->label, $matchedShippingOption?->amount ?? 0.0, $estimate->getHighestTaxClass()),
        );
        $estimate->setFeeLines(FeeLineSnapshot::encode($feeLines));
        if ($matchedShippingOption !== null) {
            $estimate->setShippingMethod($matchedShippingOption->label);
        }

        // The per-line tax snapshot is frozen whatever the pricing state is, exactly as the admin
        // quote form freezes it on every save: a priced row's own tax is real, and the rows that
        // aren't priced carry OrderTaxBreakdownService's TBD label rather than being left with no
        // entry at all — which is what used to make the customer's own quote read "No tax" against
        // every line until an admin happened to press Save (#254/#255).
        $taxBreakdown = $taxBreakdownService->computeBreakdownFor($estimate, $feeLines);
        $estimate->setTaxLines($taxBreakdownService->toJson($taxBreakdown));

        // Subtotal is the goods, so every line has to be priced for it to mean anything; tax is
        // charged on the shipping row too, so it needs shipping as well. Anything short of that is
        // left null (TBD) rather than shown as a misleadingly partial figure. Same split as
        // EstimateController::holdBackUnstatableTotals(), so an admin's first Save on this quote
        // does not change which fields are populated (#254).
        if ($allPriced) {
            $subtotal = (float) array_sum(array_map(static fn (array $r) => $r['subtotal'] ?? 0.0, $rows));
            $estimate->setSubtotal($this->decimal($subtotal));

            if ($matchedShippingOption !== null) {
                $estimate->setTax($this->decimal(round((float) $taxBreakdown['total'], 2)));
            }
        }

        $specialInstructions = $this->combineSpecialInstructions(
            $this->nullableString($request->request->get('expected_delivery_time')),
            $this->nullableString($request->request->get('checkout_notes')),
        );
        if ($orderPhoneNumber !== null) {
            $phoneNote = 'Order phone number: ' . $orderPhoneNumber;
            $specialInstructions = $specialInstructions !== null && $specialInstructions !== ''
                ? $specialInstructions . ' | ' . $phoneNote
                : $phoneNote;
        }
        $paymentNote = 'Preferred payment method: ' . $paymentMethod->getName();
        $specialInstructions = $specialInstructions !== null && $specialInstructions !== ''
            ? $specialInstructions . ' | ' . $paymentNote
            : $paymentNote;
        $estimate->setSpecialInstructions($specialInstructions);
        $estimate->setFulfillmentRegion($region);

        try {
            $entityManager->persist($estimate);
            // Same creation entry the order above gets, so a customer-submitted quote does not open
            // with an empty activity log either (#271).
            $estimate->queueActivityLogEntry()
                ->setUserName($this->documentActorName($actor))
                ->setComment(sprintf('Estimate %s created by %s.', $estimate->getDocumentNumber(), $actor->getUserIdentifier()))
                ->setType('System');
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            $logger->warning('Checkout estimate number collided with a concurrent estimate.', [
                'companyId' => $company->getId(),
                'customerUserId' => $actor->getId(),
                'documentNumber' => $estimate->getDocumentNumber(),
                'exception' => $e,
            ]);

            return [null, 'We could not submit your quote request right now. Please try again.'];
        } catch (\Throwable $e) {
            $logger->error('Customer checkout estimate save failed.', [
                'companyId' => $company->getId(),
                'customerUserId' => $actor->getId(),
                'exception' => $e,
            ]);

            return [null, 'We could not submit your quote request right now. Please try again.'];
        }

        return [$estimate, null];
    }

    /**
     * @param list<ShippingOption> $shippingOptions
     */
    private function matchShippingOption(array $shippingOptions, string $label): ?ShippingOption
    {
        foreach ($shippingOptions as $option) {
            if ($option->label === $label) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param PaymentMethodInterface[] $paymentMethods
     */
    private function matchPaymentMethod(array $paymentMethods, string $slug): ?PaymentMethodInterface
    {
        if ($slug === '') {
            return null;
        }

        foreach ($paymentMethods as $method) {
            if ($method->getSlug() === $slug) {
                return $method;
            }
        }

        return null;
    }

    private function resolveActorCompany(CustomerUser $actor, EntityManagerInterface $entityManager): ?Company
    {
        $company = $actor->getCompany();
        if ($company instanceof Company) {
            return $company;
        }

        $email = strtolower(trim($actor->getEmail()));
        if ($email === '') {
            return null;
        }

        $matchedCompany = $entityManager->getRepository(Company::class)
            ->createQueryBuilder('company')
            ->andWhere('LOWER(company.primaryEmail) = :email')
            ->andWhere('company.status = :status')
            ->setParameter('email', $email)
            ->setParameter('status', 'Active')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$matchedCompany instanceof Company) {
            return null;
        }

        $actor->setCompany($matchedCompany);
        $entityManager->flush();

        return $matchedCompany;
    }

    private function checkoutShippingDefaults(CustomerUser $actor, Company $company, ?CompanyAddress $shippingAddress): array
    {
        $fullName = trim((string) (($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')));

        return [
            'ship_name' => $fullName !== '' ? $fullName : $actor->getEmail(),
            'ship_company' => $company->getName(),
            'ship_address1' => (string) ($shippingAddress?->getAddressLine1() ?? ''),
            'ship_address2' => (string) ($shippingAddress?->getAddressLine2() ?? ''),
            'ship_city' => (string) ($shippingAddress?->getCity() ?? ''),
            'ship_zip' => (string) ($shippingAddress?->getPostalCode() ?? ''),
            'ship_province' => (string) ($shippingAddress?->getProvince() ?? ''),
            'ship_country' => (string) ($shippingAddress?->getCountry() ?? 'CA'),
            'checkout_notes' => '',
        ];
    }

    /**
     * Every address in the company's book, usable for either Shipping or Billing at checkout —
     * isDefaultShipping()/isDefaultBilling() only decide which entry is pre-selected in each
     * dropdown, they no longer partition the list.
     *
     * @return list<CompanyAddress>
     */
    private function companyAddressesForCheckout(Company $company): array
    {
        $addresses = [];

        foreach ($company->getAddresses() as $address) {
            if (!$address instanceof CompanyAddress) {
                continue;
            }

            $addresses[] = $address;
        }

        usort($addresses, static function (CompanyAddress $left, CompanyAddress $right): int {
            if ($left->isDefaultShipping() !== $right->isDefaultShipping()) {
                return $left->isDefaultShipping() ? -1 : 1;
            }

            return $left->getCreatedAt() <=> $right->getCreatedAt();
        });

        return $addresses;
    }

    /**
     * @param list<CompanyAddress> $addresses
     */
    private function resolveSelectedShippingAddress(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, array $addresses): ?CompanyAddress
    {
        $selectedId = (int) $session->get(self::SESSION_ADDRESS_ID, 0);
        $defaultAddress = null;
        $firstAddress = $addresses[0] ?? null;

        foreach ($addresses as $address) {
            if ($defaultAddress === null && $address->isDefaultShipping()) {
                $defaultAddress = $address;
            }

            if ($selectedId > 0 && $address->getId() === $selectedId) {
                return $address;
            }
        }

        return $defaultAddress instanceof CompanyAddress ? $defaultAddress : $firstAddress;
    }

    /**
     * @param list<CompanyAddress> $addresses
     */
    private function resolveSelectedBillingAddress(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, array $addresses): ?CompanyAddress
    {
        $selectedId = (int) $session->get(self::SESSION_BILL_ADDRESS_ID, 0);
        $defaultAddress = null;
        $firstAddress = $addresses[0] ?? null;

        foreach ($addresses as $address) {
            if ($defaultAddress === null && $address->isDefaultBilling()) {
                $defaultAddress = $address;
            }

            if ($selectedId > 0 && $address->getId() === $selectedId) {
                return $address;
            }
        }

        return $defaultAddress instanceof CompanyAddress ? $defaultAddress : $firstAddress;
    }

    private function checkoutConfig(AppSettings $appSettings): array
    {
        $shippingFee = max(0.0, (float) ($appSettings->get('checkout_shipping_fee', '30') ?? '30'));
        $freeShippingThreshold = max(0.0, (float) ($appSettings->get('checkout_free_shipping_threshold', '150') ?? '150'));

        $couponsEnabled = $this->couponsEnabled($appSettings);

        return [
            'shippingFee' => $shippingFee,
            'freeShippingThreshold' => $freeShippingThreshold,
            'couponsEnabled' => $couponsEnabled,
            // With coupons switched off there are no valid codes at all, so nothing can be applied
            // by any route that reads this config.
            'coupons' => $couponsEnabled ? $this->checkoutCoupons($appSettings->get('checkout_coupons', '')) : [],
        ];
    }

    /** Admin switch (checkout_coupons_enabled) for whether coupon codes may be used at all. */
    private function couponsEnabled(AppSettings $appSettings): bool
    {
        return ($appSettings->get('checkout_coupons_enabled', 'Yes') ?? 'Yes') !== 'No';
    }

    private function checkoutCoupons(?string $raw): array
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

    private function resolveCheckoutCoupon(array $coupons, string $couponCode, float $subtotal): array
    {
        if ($couponCode === '') {
            return [null, null];
        }

        $matched = null;
        foreach ($coupons as $coupon) {
            if (($coupon['code'] ?? '') === $couponCode) {
                $matched = $coupon;
                break;
            }
        }

        $violations = Validation::createValidator()->validate($matched, new ValidCouponCode($couponCode, $subtotal));
        if (count($violations) > 0) {
            return [null, (string) $violations[0]->getMessage()];
        }

        return [$matched, null];
    }

    /**
     * The shipping amount actually charged after the free-shipping-threshold
     * override — must match what checkoutTotals() will charge, since tax on
     * shipping should be $0 when shipping itself is waived.
     */
    private function discountedSubtotalForShipping(float $subtotal, ?array $coupon): float
    {
        $discount = 0.0;
        if ($coupon !== null) {
            $discount = ($coupon['type'] ?? '') === 'percent'
                ? $subtotal * ((float) ($coupon['value'] ?? 0) / 100)
                : (float) ($coupon['value'] ?? 0);
        }
        $discount = min(max(0.0, $discount), $subtotal);

        return max(0.0, $subtotal - $discount);
    }

    private function checkoutEffectiveShipping(float $subtotal, array $checkoutConfig, ?array $coupon, float $shippingAmount): float
    {
        $discountedSubtotal = $this->discountedSubtotalForShipping($subtotal, $coupon);
        $freeShippingThreshold = (float) ($checkoutConfig['freeShippingThreshold'] ?? 0);

        return $discountedSubtotal > 0.0 && ($freeShippingThreshold <= 0.0 || $discountedSubtotal < $freeShippingThreshold)
            ? $shippingAmount
            : 0.0;
    }

    /**
     * Whether the free-shipping-threshold promo is active for this cart, independent of which
     * delivery option happens to be selected (a naturally-$0 option like Pickup being selected
     * must not hide that paid options would also be free once the threshold is met).
     */
    private function freeShippingWaived(float $subtotal, array $checkoutConfig, ?array $coupon): bool
    {
        $discountedSubtotal = $this->discountedSubtotalForShipping($subtotal, $coupon);
        $freeShippingThreshold = (float) ($checkoutConfig['freeShippingThreshold'] ?? 0);

        return $discountedSubtotal > 0.0 && $freeShippingThreshold > 0.0 && $discountedSubtotal >= $freeShippingThreshold;
    }

    private function checkoutTotals(float $subtotal, array $checkoutConfig, ?array $coupon, float $shippingAmount = 0.0, float $feeTotal = 0.0, float $taxTotal = 0.0): array
    {
        $discount = 0.0;
        if ($coupon !== null) {
            $discount = ($coupon['type'] ?? '') === 'percent'
                ? $subtotal * ((float) ($coupon['value'] ?? 0) / 100)
                : (float) ($coupon['value'] ?? 0);
        }

        $discount = min(max(0.0, $discount), $subtotal);
        $discountedSubtotal = max(0.0, $subtotal - $discount);
        $shipping = $this->checkoutEffectiveShipping($subtotal, $checkoutConfig, $coupon, $shippingAmount);
        $preTax = $discountedSubtotal + $shipping + $feeTotal;

        return [
            'discount' => $discount,
            'discountedSubtotal' => $discountedSubtotal,
            'shipping' => $shipping,
            'feeTotal' => $feeTotal,
            'preTax' => $preTax,
            'tax' => $taxTotal,
            'total' => $preTax + $taxTotal,
        ];
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

    private function combineSpecialInstructions(?string $expectedDeliveryTime, ?string $checkoutNotes): ?string
    {
        $parts = [];

        if ($expectedDeliveryTime !== null) {
            $parts[] = self::EXPECTED_DELIVERY_PREFIX . ' ' . $expectedDeliveryTime;
        }

        if ($checkoutNotes !== null) {
            $parts[] = $checkoutNotes;
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }

    private function companyPaymentTermName(EntityManagerInterface $entityManager, Company $company): ?string
    {
        $paymentTermId = (int) ($company->getPaymentTermId() ?? 0);
        if ($paymentTermId <= 0) {
            return null;
        }

        $connection = $entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['payment_term'])) {
            return null;
        }

        $name = $connection->fetchOne(
            "SELECT name FROM payment_term WHERE id = :id LIMIT 1",
            ['id' => $paymentTermId]
        );

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
