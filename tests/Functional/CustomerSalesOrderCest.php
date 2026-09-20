<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use Twig\Environment;

/** Covers Customer\OrderController — /orders listing (company-scoping, draft exclusion, tab
 *  filtering), detail() company-scoping, invoice() PDF download, and cancelOrder()'s CSRF/
 *  status-guard/company-scoping. Stripe payment routes (payment/stripe-intent, payment) are
 *  intentionally out of scope here: they call the real Stripe API, which isn't reachable in this
 *  suite — see StripeOrderPaymentApplierTest / StripeWebhookControllerTest for that path. */
final class CustomerSalesOrderCest
{
    private function makeCompany(FunctionalTester $I, string $name = 'Orders Test Co'): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('ORD-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function loginAs(FunctionalTester $I, Company $company): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('co-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    /**
     * A Draft order — which is what a new SalesOrder simply IS since #539 stage 2. There is no
     * setStatus() to put one anywhere else: Approved and Void are named actions, and every other
     * status is derived from the order's invoices on each flush.
     */
    private function makeDraftOrder(FunctionalTester $I, Company $company): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setSubtotal('100.00')
            ->setFeeLines(FeeLineSnapshot::encode([
                new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 10.0, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
            ]))
            ->setTax('5.00')
            ->setTotal('115.00');
        $I->haveInRepository($order);

        return $order;
    }

    /**
     * A live order the customer can see and act on: the Draft above, accepted through the action a
     * real placement uses. It has no invoices yet, so the deriver settles it at Approved — the
     * weakest live status, and therefore the one that proves a screen is not quietly gating on
     * something further along.
     */
    private function makeOrder(FunctionalTester $I, Company $company): SalesOrder
    {
        $order = $this->makeDraftOrder($I, $company);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    /**
     * Bills the whole order on one issued invoice, exactly as every real creation path does.
     *
     * This is how a fixture reaches Invoiced or Closed: those statuses cannot be written, only
     * derived from the invoice set, so an order that needs one has to actually have the invoice.
     * Writing the string directly would assert something the deriver does not agree with.
     */
    private function invoiceInFull(FunctionalTester $I, SalesOrder $order): Invoice
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $invoice = $I->grabService(OrderInvoicingService::class)->invoiceInFull(
            $order,
            $entityManager,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $entityManager->flush();

        return $invoice;
    }

    /**
     * Settled in full, the only way anything is settled since #539 stage 4: money recorded against
     * the invoice. Its payment status is derived from these rows and cannot be written directly.
     */
    private function settle(FunctionalTester $I, Invoice $invoice): Invoice
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($invoice->getTotal());
        $invoice->recordPayment(DocumentActor::system(), $payment);
        $entityManager->persist($payment);
        $entityManager->flush();

        return $invoice;
    }

    /** Withdrawn the way the cancel route withdraws one: every live invoice cancelled, order voided. */
    private function voidOrder(FunctionalTester $I, SalesOrder $order): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        foreach ($order->getInvoices() as $invoice) {
            if (!$invoice->isCancelled()) {
                $invoice->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: fixture setup.');
            }
        }
        $order->setStatus(
            'Void',
            DocumentActor::system(),
            sprintf('Order voided (was %s): %s', $order->getStatus(), 'fixture setup.'),
        );
        $entityManager->flush();
    }

    /**
     * The order as the DATABASE now holds it.
     *
     * refresh() rather than clear(): the request under test wrote through its own entity manager, so
     * whatever this side is holding may be stale, but clearing would detach the company and customer
     * the rest of the fixture still references.
     */
    private function reload(FunctionalTester $I, SalesOrder $order): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $reloaded = $entityManager->find(SalesOrder::class, $order->getId());
        $entityManager->refresh($reloaded);

        return $reloaded;
    }

    private function reloadInvoice(FunctionalTester $I, Invoice $invoice): Invoice
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $reloaded = $entityManager->find(Invoice::class, $invoice->getId());
        $entityManager->refresh($reloaded);

        return $reloaded;
    }

    public function indexListsOnlyTheLoggedInCompanysNonDraftOrders(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I, 'Other Orders Co');

        $own = $this->makeOrder($I, $company);
        $this->makeDraftOrder($I, $company);
        $theirs = $this->makeOrder($I, $otherCompany);

        $this->loginAs($I, $company);

        $I->amOnPage('/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see($own->getOrderNumber());
        $I->dontSee($theirs->getOrderNumber());
    }

    public function reorderControlsAreNativePostForms(FunctionalTester $I): void
    {
        // #143 no-JS follow-up: the Reorder controls must be real <form method="post"> targeting
        // /cart/reorder with a submit button — not JS-only buttons — so a no-JS browser can reorder.
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders');
        $I->seeElement('form[action="/cart/reorder"][method="post"] button[type="submit"]');

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeElement('form[action="/cart/reorder"][method="post"] button[type="submit"]');
    }

    /**
     * The tab key is still CANCELLED and the badge a customer reads still says "Cancelled" — that is
     * what they call it — but the status underneath is Void since #539 stage 2, and the tab filters
     * on that. A live order must not appear here.
     */
    public function cancelledTabShowsOnlyCancelledOrders(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $cancelled = $this->makeOrder($I, $company);
        $this->voidOrder($I, $cancelled);
        $live = $this->makeOrder($I, $company);

        $this->loginAs($I, $company);

        $I->amOnPage('/orders?tab=CANCELLED');
        $I->seeResponseCodeIsSuccessful();
        $I->see($cancelled->getOrderNumber());
        $I->dontSee($live->getOrderNumber());
    }

    /**
     * Closed is #539 stage 2's successor to Completed, and a fixture reaches it the only way anything
     * can: the order is billed in full and that invoice is settled. The tab itself keys off payment,
     * so the unpaid order beside it stays out.
     */
    public function approvedTabExcludesUnpaidOrders(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $paid = $this->makeOrder($I, $company);
        $this->settle($I, $this->invoiceInFull($I, $paid));
        $unpaid = $this->makeOrder($I, $company);

        $I->assertSame(
            SalesOrderStatus::Closed->value,
            $this->reload($I, $paid)->getStatus(),
            'guard: a fully invoiced, fully paid order derives to Closed',
        );

        $this->loginAs($I, $company);

        $I->amOnPage('/orders?tab=APPROVED');
        $I->seeResponseCodeIsSuccessful();
        $I->see($paid->getOrderNumber());
        $I->dontSee($unpaid->getOrderNumber());
    }

    public function detailShowsTheOwnCompanysOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($order->getOrderNumber());
    }

    /**
     * The "Fulfillment Region" fallback used to read `ship.label` when `fulfillmentRegion` was
     * blank — `AdminOrderAddress` (what `getEffectiveShippingAddress()` actually returns) has no
     * `label` property at all, only `CompanyAddress` does, so any order with a shipping address
     * and no `fulfillmentRegion` crashed the whole detail page with a Twig RuntimeError.
     */
    public function detailDoesNotCrashWhenFulfillmentRegionIsBlankAndAShippingAddressExists(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $order->addressForWriting(\App\Entity\AbstractDocumentAddress::TYPE_SHIPPING)
            ->setCity('Vancouver')
            ->setProvince('BC');
        $I->haveInRepository($order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($order->getOrderNumber());
    }

    /**
     * The "Fulfillment Region" fallback briefly (commit 8e786a2, fixing an earlier crash on
     * ship.label) fell back to order.shippingCompanyName — the exact same value already shown
     * two rows up as "Company Name" — so a blank region silently duplicated the company name
     * into an unrelated field (seen live on /orders/4016) instead of just showing nothing. A
     * bare "seeResponseCodeIsSuccessful" check doesn't catch that regression since the page
     * still renders fine either way; this asserts the actual displayed value.
     */
    public function detailShowsADashForFulfillmentRegionWhenBlankRatherThanTheCompanyName(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $order->setShippingCompanyName('Haney and Blanchard LLC');
        $I->haveInRepository($order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            '-',
            trim($I->grabTextFrom("//label[text()='Fulfillment Region:']/following-sibling::span"))
        );
    }

    /**
     * #368: expectedDeliveryLabel() used to gate the customer's own typed delivery time behind an
     * order-status check, so a freshly placed order never showed the value the customer had just
     * typed. The customer-supplied value must show whatever the order's status says.
     *
     * The fixture is deliberately Approved — the earliest live status, with nothing invoiced against
     * it — because that is the state any surviving status gate would still exclude.
     */
    public function detailShowsTheCustomersRequestedDeliveryTimeEvenBeforeAnythingIsInvoiced(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $order->setSpecialInstructions('Expected delivery time: 2026-08-10');
        $I->haveInRepository($order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            'Aug 10, 2026',
            trim($I->grabTextFrom("//label[text()='Expected Delivery Time:']/following-sibling::span"))
        );
    }

    /**
     * #447: the Expected Delivery Time field the customer typed at checkout is free text
     * (templates/customer/checkout/index.html.twig), not a date field — "ASAP" is a completely
     * realistic entry. normalizeExpectedDeliveryDate() used to try DateTimeImmutable on it, catch
     * the failure, and return null — discarding the customer's stated preference outright.
     */
    public function detailShowsFreeTextDeliveryTimeInsteadOfDiscardingIt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $order->setSpecialInstructions('Expected delivery time: ASAP');
        $I->haveInRepository($order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            'ASAP',
            trim($I->grabTextFrom("//label[text()='Expected Delivery Time:']/following-sibling::span"))
        );
    }

    /**
     * #447: visibleSpecialInstructions() filters by prefix (isExpectedDeliveryInstruction), not by
     * whether the value happens to parse as a date, so a free-text value must still be excluded
     * from Special Instructions -- it has its own row. This pins that the fix does not
     * accidentally make it show up in both places.
     */
    public function detailDoesNotDuplicateFreeTextDeliveryTimeIntoSpecialInstructions(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $order->setSpecialInstructions('Expected delivery time: ASAP | Leave at side door');
        $I->haveInRepository($order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $specialInstructions = trim($I->grabTextFrom("//label[text()='Special Instructions #:']/following-sibling::span"));
        $I->assertStringNotContainsString('ASAP', $specialInstructions);
        $I->assertStringContainsString('Leave at side door', $specialInstructions);
    }

    public function detailForAnotherCompanysOrderRedirectsNotFound(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I, 'Other Orders Co 2');
        $theirs = $this->makeOrder($I, $otherCompany);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $theirs->getId());
        $I->seeCurrentUrlEquals('/orders');
        $I->see('Order not found.');
    }

    /**
     * BOTH documents, which is the client's decision for #539 stage 6: "Yes. The whole thing is so
     * clean we can hide one of them later but for now just show SO and Invoice."
     */
    public function theOrderPageOffersBothTheSalesOrderAndItsInvoice(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a[href="/orders/' . $order->getId() . '/sales-order"]');
        $I->seeElement('a[href="/orders/invoice/' . $invoice->getId() . '"]');
        $I->see($invoice->getDocumentNumber());
    }

    public function salesOrderDownloadsAPdfForTheOwnOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId() . '/sales-order');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource());
    }

    public function invoiceDownloadsAPdfForTheOwnOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/invoice/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource());
    }

    /** Another company's invoice is "not found", the same answer their order gets. */
    public function anotherCompanysInvoiceIsNotDownloadable(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I, 'Other Invoice Co');
        $theirInvoice = $this->invoiceInFull($I, $this->makeOrder($I, $otherCompany));
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/invoice/' . $theirInvoice->getId());
        $I->seeCurrentUrlEquals('/orders');
        $I->see('Invoice not found.');
    }

    /**
     * customer/order/invoice_pdf.html.twig used to be its own, older layout with no fee-line
     * rows in the products table at all and every fee (main_line + after_tax mixed together)
     * dumped as its own totals-box row — the client asked for the customer-downloaded invoice to
     * look exactly like the admin one, which already got this right.
     *
     * #539 stage 6 makes that identity structural rather than a promise: the customer copy IS the
     * admin template, rendered through its is_pdf branch, and the near-duplicate file is gone. This
     * test now renders what the route renders. The download response is a compiled PDF binary
     * (dompdf), so its text isn't reliably greppable — hence rendering directly.
     */
    public function invoiceTemplateFoldsMainLineFeesIntoSubtotalAndKeepsAfterTaxFeesSeparate(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $invoice->setSubtotal('175.00');
        $invoice->setFeeLines(json_encode([
            ['slug' => 'bc-enviro-fee', 'label' => 'BC Environmental Fee', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line'],
            ['slug' => 'bc-recycling-fee', 'label' => 'BC Recycling Fee', 'taxClass' => 'E', 'amount' => 10.0, 'placement' => 'main_line'],
            ['slug' => 'late-fee', 'label' => 'Late Payment Fee', 'taxClass' => 'E', 'amount' => 5.0, 'placement' => 'after_tax_line'],
        ]));
        $I->flushToDatabase();

        // Rendering the template directly (rather than through the real /orders/invoice/{id}
        // request, whose response is a compiled PDF binary) skips the kernel's normal request
        // cycle, so the `app` Twig global's `.request` is otherwise null — the template reads
        // app.request.schemeAndHttpHost as a website_url fallback.
        $requestStack = $I->grabService(RequestStack::class);
        $requestStack->push(Request::create('https://invoice-test.example/orders/invoice/' . $invoice->getId()));

        $twig = $I->grabService(Environment::class);
        $html = $twig->render('admin/invoice/invoice.html.twig', [
            'invoice' => $invoice,
            'billingAddress' => null,
            'shippingAddress' => null,
            'taxLines' => [],
            'taxLinesTotal' => 0.0,
            'is_pdf' => true,
        ]);

        $I->assertSame(1, substr_count($html, 'BC Environmental Fee'), 'main_line fee should render exactly once (product table row only).');
        $I->assertSame(1, substr_count($html, 'BC Recycling Fee'), 'main_line fee should render exactly once (product table row only).');
        $I->assertSame(1, substr_count($html, 'Late Payment Fee'), 'after_tax fee should render exactly once (totals box row only).');

        // The after_tax fee must come from the totals box, not the product table: it appears
        // after "Subtotal excl. taxes" in the document, same as admin/order/invoice.html.twig.
        $I->assertTrue(
            strpos($html, 'Subtotal excl. taxes') < strpos($html, 'Late Payment Fee'),
            'after_tax fee row should be positioned in the totals box, after "Subtotal excl. taxes".'
        );

        // Column set matches admin's invoice exactly (Quantity/Name/U-M/SKU/Price/Total/Batch),
        // not the old template's Price/Quantity/Before Tax/Tax Code/Total Tax layout.
        $I->assertStringContainsString('<th>U/M</th>', $html);
        $I->assertStringContainsString('<th>SKU</th>', $html);
        $I->assertStringContainsString('<th>Batch</th>', $html);
    }

    private function grabCancelToken(FunctionalTester $I, SalesOrder $order): string
    {
        $I->amOnPage('/orders/' . $order->getId());
        $html = $I->grabPageSource();
        preg_match('/<form[^>]*action="[^"]*\/cancel"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    /**
     * A customer cancel is two actions composed (#539 stage 2), and BOTH halves are the point: every
     * live invoice on the order is cancelled and then the order is voided.
     *
     * Asserting only the order would pass a build that voids it while leaving the invoice live —
     * which would still bill the customer and, from stage 3, still hold the stock. The order's own
     * status cannot rescue that: Void is terminal and is never re-derived, so a live invoice under a
     * voided order is invisible to everything except this assertion.
     */
    public function cancellingALiveOrderVoidsItAndCancelsItsInvoice(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $this->loginAs($I, $company);

        // The redirect target (customer_orders) special-cases XHR requests by returning a JSON
        // list fragment with no flash box, so success is verified on persisted state rather than
        // on flash text — matching how the ajax'd redirect actually responds in this controller.
        $token = $this->grabCancelToken($I, $order);
        $I->sendAjaxPostRequest('/orders/' . $order->getId() . '/cancel', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(SalesOrderStatus::Void->value, $this->reload($I, $order)->getStatus());
        $I->assertSame(
            'Cancelled',
            $this->reloadInvoice($I, $invoice)->getStatus(),
            'a cancelled order must not leave a live invoice billing the customer behind it',
        );
    }

    public function cancellingWithAnInvalidCsrfTokenLeavesTheOrderUnchanged(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/orders/' . $order->getId() . '/cancel', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);

        // Neither half of the composed cancel may have run.
        $I->assertSame(SalesOrderStatus::Invoiced->value, $this->reload($I, $order)->getStatus());
        $I->assertSame('Pending', $this->reloadInvoice($I, $invoice)->getStatus());
    }

    /**
     * Closed is #539 stage 2's successor to Completed, and canCustomerCancelOrder() refuses it.
     *
     * The order gets there the only way anything can — its one invoice bills the whole of it and is
     * then settled, which the deriver reads as Closed. The ORDER's own payment status is left unpaid
     * on purpose: that isolates the status guard, since an order the paid-check already refuses
     * would prove nothing about it.
     */
    public function aClosedOrderCannotBeCancelled(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $invoice = $this->invoiceInFull($I, $order);
        $this->loginAs($I, $company);

        // The Cancel form only renders while the order is in a cancellable status, so the token is
        // scraped in that state, then the invoice is settled (the token is the app-wide one, so it
        // stays valid) to exercise the status guard on the actual request.
        $token = $this->grabCancelToken($I, $order);

        $this->settle($I, $I->grabService(EntityManagerInterface::class)->getRepository(Invoice::class)->find($invoice->getId()));

        $I->assertSame(
            SalesOrderStatus::Closed->value,
            $this->reload($I, $order)->getStatus(),
            'guard: settling the only invoice must derive the order to Closed',
        );

        $I->sendAjaxPostRequest('/orders/' . $order->getId() . '/cancel', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(SalesOrderStatus::Closed->value, $this->reload($I, $order)->getStatus());
        $I->assertSame('Pending', $this->reloadInvoice($I, $invoice)->getStatus());
    }

    public function cancellingAnotherCompanysOrderRedirectsNotFound(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I, 'Other Orders Co 3');
        $theirs = $this->makeOrder($I, $otherCompany);
        $this->loginAs($I, $company);

        // A real request as this company can never render the cancel form for another company's
        // order, so the token is scraped by briefly reassigning the order to this company, then
        // reassigned back before the real request — leaving company-scoping as the only thing
        // that can refuse it.
        $em = $I->grabService(EntityManagerInterface::class);
        $orderEntity = $em->getRepository(SalesOrder::class)->find($theirs->getId());
        $orderEntity->setCompany($company);
        $em->flush();

        $token = $this->grabCancelToken($I, $theirs);

        $orderEntity->setCompany($otherCompany);
        $em->flush();

        $I->sendAjaxPostRequest('/orders/' . $theirs->getId() . '/cancel', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(SalesOrder::class, [
            'id' => $theirs->getId(),
            'status' => SalesOrderStatus::Approved->value,
            'company' => $otherCompany->getId(),
        ]);
    }

    /**
     * The payment form used to mint a per-endpoint `customer_order_payment_<id>` token that nothing
     * validated any more, so the central check rejected every submission and customer payment was
     * refused outright (#247). Stripe itself is not reachable from this suite, so the assertion is
     * that the request gets *past* CSRF and is answered by payOrder()'s own validation — a 403 here
     * would mean the form is minting a token the app does not accept.
     */
    public function thePaymentFormMintsATokenTheRequestIsActuallyAcceptedWith(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $order = $this->makeOrder($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId() . '?payment=1');
        $html = $I->grabPageSource();
        preg_match(
            '/<form[^>]*action="[^"]*\/payment"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/',
            $html,
            $matches
        );
        $I->assertNotEmpty($matches[1] ?? '', 'The order payment form does not render a CSRF token.');

        $I->sendAjaxPostRequest('/orders/' . $order->getId() . '/payment', ['_token' => $matches[1]]);
        $I->seeResponseCodeIs(400);

        $I->sendAjaxPostRequest('/orders/' . $order->getId() . '/payment', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);
    }
}
