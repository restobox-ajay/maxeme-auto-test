<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use App\Entity\AbstractDocumentAddress;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\CreditMemoRefund;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Event\CreditMemoIssuedEvent;
use App\Service\CompanyDirectory;
use App\Service\CreditMemoNumberGenerator;
use App\Service\CustomerAccountDirectory;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Service\OrderNumberGenerator;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceOrderImport;
use WooCommerceBundle\Repository\WooCommerceOrderImportRepository;

/**
 * Turns one WooCommerce order webhook payload into a real SalesOrder + Invoice (#739/#742).
 *
 * ## Why a SalesOrder at all, and why it is invoiced immediately
 *
 * Settled earlier in this project's design discussion, grounded against how NetSuite/QuickBooks
 * treat an externally-paid e-commerce order: Woo always HAS an order (there is no draft/quote stage
 * on the storefront by the time the webhook fires — the customer already paid), so this always goes
 * through OrderInvoicingService::invoiceInFull() rather than #742's order-optional path, which
 * remains for callers that genuinely may not have an order. The order is still raised, not skipped,
 * because inventory's approved/pending bucket accounting and the order-level activity timeline both
 * assume one exists. The invoice is raised with Issue intent (not AwaitingPayment): Woo settles
 * payment on ITS side before the webhook ever fires, so by the time this runs the money has already
 * arrived — issuing outright records that, the same way a Sales Receipt (as opposed to an Invoice)
 * does in NetSuite/QuickBooks for a channel that collects payment itself.
 *
 * ## Idempotency and atomicity
 *
 * A webhook can be redelivered (Woo's own retry policy, or an admin re-testing a webhook). The
 * (connection, woo_order_id) uniqueness on WooCommerceOrderImport is the guard: a second delivery
 * for an order already Imported or Error'd is a silent no-op, returning the existing row rather than
 * minting a second SalesOrder or re-attempting a build an admin hasn't fixed yet. The whole build —
 * company/customer resolution, product resolution, the order, and the invoice — is one database
 * transaction: an order committed without its invoice would violate the same "every order has
 * exactly one invoice" invariant OrderInvoicingService's own docblock describes, so a failure
 * partway rolls the whole thing back and is recorded as a plain Error row instead, for an admin to
 * see on the orders screen and act on by hand. This mirrors Customer\CheckoutController::
 * processCheckoutSubmission()'s own transaction shape exactly — persist, mutate/invoice, one flush,
 * wrapped in an explicit transaction — rather than inventing a second way to make an
 * order-plus-invoice atomic.
 *
 * ## Order status gating
 *
 * Woo settles payment on ITS side before the webhook ever fires — but only for the statuses that
 * actually mean "paid" (processing/completed). A `pending`/`on-hold` order hasn't been paid yet, and
 * a `failed`/`cancelled`/`refunded`/`trash`/`checkout-draft` order never will be (or no longer
 * counts): invoicing any of those would record revenue that was never actually collected. So
 * anything outside self::INVOICEABLE_STATUSES is recorded as a Skipped row — no company/product
 * resolution, no SalesOrder, no Invoice — rather than treated identically to a genuinely paid order.
 * Unlike an Error row, a Skipped row IS re-evaluated on a later redelivery of the same Woo order
 * (e.g. once the connection also subscribes to the order.updated topic and a pending order later
 * transitions to processing): see the idempotency check below.
 */
final class WooCommerceOrderImportService
{
    private const SOURCE = 'WooCommerce';

    /** The only Woo order statuses that mean "payment has actually settled" — see the class docblock. */
    private const INVOICEABLE_STATUSES = ['processing', 'completed'];

    public function __construct(
        private readonly WooCommerceOrderImportRepository $imports,
        private readonly WooCommerceProductResolver $products,
        private readonly CompanyDirectory $companyDirectory,
        private readonly CustomerAccountDirectory $customerAccounts,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly WarehouseFulfillmentRegionService $warehouseRegions,
        private readonly OrderInvoicingService $invoicing,
        private readonly CreditMemoNumberGenerator $creditMemoNumbers,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /** @param array<string, mixed> $payload A WooCommerce order object, as delivered on the order.* webhook topics. */
    public function import(WooCommerceConnection $connection, array $payload, EntityManagerInterface $entityManager): WooCommerceOrderImport
    {
        $wooOrderId = (int) ($payload['id'] ?? 0);
        if ($wooOrderId <= 0) {
            throw new \InvalidArgumentException('Order payload has no id.');
        }

        $existing = $this->imports->findOneForConnectionAndWooOrderId($connection, $wooOrderId);
        if ($existing instanceof WooCommerceOrderImport) {
            if ($existing->isError()) {
                return $existing;
            }
            if ($existing->getStatus() === WooCommerceOrderImport::STATUS_IMPORTED) {
                return $this->handleUpdateToImportedOrder($existing, $payload, $entityManager);
            }
            // Skipped: falls through to be re-evaluated below, same $record.
        }

        $record = $existing ?? new WooCommerceOrderImport();
        $record
            ->setConnection($connection)
            ->setWooOrderId($wooOrderId)
            ->setWooOrderNumber((string) ($payload['number'] ?? $wooOrderId));

        $wooStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        if (!in_array($wooStatus, self::INVOICEABLE_STATUSES, true)) {
            $record->markSkipped(sprintf(
                'Woo order status is "%s" — not invoiceable yet.',
                $wooStatus !== '' ? $wooStatus : 'unknown',
            ));
            $entityManager->persist($record);
            $entityManager->flush();

            return $record;
        }

        $conn = $entityManager->getConnection();
        $ownsTransaction = !$conn->isTransactionActive();
        if ($ownsTransaction) {
            $conn->beginTransaction();
        }

        try {
            $company = $this->resolveCompany($entityManager, $payload);
            $this->resolveCustomer($entityManager, $payload, $company);

            $order = $this->buildOrder($entityManager, $connection, $company, $payload);
            $entityManager->persist($order);

            $actor = DocumentActor::automation('WooCommerce import');
            $invoice = $this->invoicing->invoiceInFull($order, $entityManager, $actor, InvoiceIssueIntent::Issue);

            $connection->touchLastOrderAt();

            $record->markImported($company, $order, $invoice);
            $entityManager->persist($record);
            $entityManager->flush();

            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            // A second, un-transacted save for the failure record itself: the rollback above
            // undoes the order/invoice/product work, and this row is the only place that failure
            // is visible afterwards — the "All orders" screen's whole reason for existing.
            $record->markError($e->getMessage());
            $entityManager->persist($record);
            $entityManager->flush();
        }

        return $record;
    }

    /**
     * An update to an order this connection already invoiced. The only thing this ever does is
     * notice a full refund and raise a credit note for it — see the class docblock's "Order status
     * gating" section. Everything else about an Imported row (a plain edit, an on-hold blip, a
     * partial refund that leaves the order's own status unchanged) is deliberately left alone: this
     * app does not re-derive an invoice from a live Woo order on every update, only react to the one
     * fact that means money genuinely came back.
     *
     * Partial refunds are a known, real gap, not an oversight: Woo fires this same order.updated
     * topic for a partial refund too (via its own `woocommerce_order_refunded` hook), but the
     * order's own `status` stays whatever it was, so the check below does not fire, and no partial
     * credit note is raised. Closing that needs matching Woo's `refunds` array against what has
     * already been credited (Woo can report several partial refunds before a full one, if ever), and
     * is intentionally out of scope here — this handles exactly the case that was found and asked
     * for: a full refund.
     */
    private function handleUpdateToImportedOrder(
        WooCommerceOrderImport $record,
        array $payload,
        EntityManagerInterface $entityManager,
    ): WooCommerceOrderImport {
        if ($record->getCreditMemo() instanceof CreditMemo) {
            return $record;
        }

        $wooStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        if ($wooStatus !== 'refunded') {
            return $record;
        }

        $invoice = $record->getInvoice();
        if (!$invoice instanceof Invoice) {
            // Unreachable in practice — an Imported row always carries the invoice markImported()
            // gave it — but a null here means there is nothing to credit, not a reason to throw.
            return $record;
        }

        try {
            $creditMemo = $this->creditFullInvoice($entityManager, $invoice, $payload);
            $record->attachCreditMemo($creditMemo);
            $entityManager->persist($record);
            $entityManager->flush();

            // AFTER the flush, matching CreditMemoController::performAction()'s own ordering — a
            // subscriber reacting to this (e.g. a restock, were this note ever to carry one) needs
            // the note's real database id, which only exists once it has actually been flushed.
            $this->eventDispatcher->dispatch(new CreditMemoIssuedEvent($creditMemo));
        } catch (\DomainException) {
            // A refusal from CreditMemo's own gate — e.g. the invoice was already credited in full
            // by some other means before this webhook arrived, so there is nothing left to raise.
            // The Imported row's own success is real and untouched; this doesn't get recorded as an
            // Error, because nothing about the ORIGINAL import failed. $creditMemo stays null, so a
            // redelivery of the same refund tries again rather than being stuck silently forever.
        }

        return $record;
    }

    /**
     * Raises, issues, and immediately refunds a credit note for everything still creditable on
     * $invoice — one line per invoice line, at whatever quantity earlier credit notes (if any)
     * haven't already taken, exactly as CreditMemoController::rowsFromInvoice()'s own maximum does.
     *
     * Issued and refunded in the same call rather than left Open: Woo already paid the money back to
     * the customer through its own payment gateway by the time this webhook fires (see
     * WC_REST_Authentication/wc_create_refund — the refund is real before `woocommerce_order_refunded`
     * ever dispatches), so there is no unspent balance sitting on our books to apply later. Recording
     * an Open note with no refund against it would say a customer has store credit they were, in
     * fact, already given back in cash.
     *
     * Restock is left false (the default): nothing in a Woo refund payload says whether the physical
     * goods came back, and CreditMemo's own docblock is explicit that guessing here is wrong — see
     * its "$restock" section. An admin who knows the goods came back can restock by hand from this
     * note's own detail page.
     */
    private function creditFullInvoice(EntityManagerInterface $entityManager, Invoice $invoice, array $payload): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($invoice->getCompany())
            ->setInvoice($invoice)
            ->setDocumentNumber($this->creditMemoNumbers->next($entityManager))
            ->setReason(sprintf('WooCommerce order #%s was refunded.', (string) ($payload['number'] ?? $payload['id'] ?? '')));

        foreach ($invoice->getAddresses() as $address) {
            $memo->copyAddressFrom($address);
        }
        $memo->copyCompanySnapshotFrom($invoice);
        $memo->setFulfillmentRegion($invoice->getFulfillmentRegion());

        $subtotalCents = 0;
        $sortOrder = 0;
        foreach ($invoice->getLines() as $invoiceLine) {
            $remaining = self::creditableRemainder($invoiceLine);
            if (QuantityScale::compare($remaining, 0) <= 0) {
                continue;
            }

            $quantity = (float) $remaining;
            $price = round((float) ($invoiceLine->getPrice() ?? 0), 2);
            $lineSubtotal = round($quantity * $price, 2);
            $subtotalCents += (int) round($lineSubtotal * 100);

            $line = (new CreditMemoLine())
                ->setInvoiceLine($invoiceLine)
                ->setProduct($invoiceLine->getProduct())
                ->setName($invoiceLine->getName())
                ->setSku($invoiceLine->getSku())
                ->setLocation($invoiceLine->getLocation())
                ->setUnit($invoiceLine->getUnit())
                ->setTaxCode($invoiceLine->getTaxCode())
                ->setQuantity(number_format($quantity, 2, '.', ''))
                ->setPrice(number_format($price, 2, '.', ''))
                ->setSubtotal(number_format($lineSubtotal, 2, '.', ''))
                ->setSortOrder($sortOrder++);

            $memo->addLine($line);
            $entityManager->persist($line);
        }

        // The invoice's own tax, not recomputed: this credits what is LEFT of the invoice, and an
        // invoice with earlier partial credits already reflects those in its own remaining lines —
        // the tax figure has no equivalent "remainder" concept to net separately, so this follows
        // CreditMemoController's own create() flow, which takes tax as a flat figure on the note
        // rather than deriving it per line.
        $taxCents = (int) round((float) ($invoice->getTax() ?? '0') * 100);

        $memo
            ->setSubtotal(number_format($subtotalCents / 100, 2, '.', ''))
            ->setTax(number_format($taxCents / 100, 2, '.', ''))
            ->setTotal(number_format(($subtotalCents + $taxCents) / 100, 2, '.', ''));

        $entityManager->persist($memo);
        $memo->issue();

        $refund = (new CreditMemoRefund())
            ->setMethod(trim((string) ($payload['payment_method_title'] ?? '')) ?: 'WooCommerce')
            ->setAmount($memo->getTotal())
            ->setComment(sprintf('Refunded on WooCommerce order #%s.', (string) ($payload['number'] ?? $payload['id'] ?? '')));
        $memo->recordRefund($refund);
        $entityManager->persist($refund);

        return $memo;
    }

    /** What's left of an invoice line to credit: billed less already-credited, never negative. Mirrors CreditMemoController's own. */
    private static function creditableRemainder(InvoiceLine $invoiceLine): string
    {
        $remaining = QuantityScale::sub($invoiceLine->getQuantity(), $invoiceLine->getCreditedUnits());

        return QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0);
    }

    /** @param array<string, mixed> $payload */
    private function resolveCompany(EntityManagerInterface $entityManager, array $payload): Company
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $name = trim((string) ($billing['company'] ?? ''));
        if ($name === '') {
            $name = trim(trim((string) ($billing['first_name'] ?? '')) . ' ' . trim((string) ($billing['last_name'] ?? '')));
        }
        if ($name === '') {
            $name = trim((string) ($billing['email'] ?? '')) ?: sprintf('WooCommerce Customer %d', (int) ($payload['id'] ?? 0));
        }

        $company = $this->companyDirectory->findOrCreateByName($entityManager, $name);

        $email = trim((string) ($billing['email'] ?? ''));
        if ($email !== '' && $company->getPrimaryEmail() === null) {
            $company->setPrimaryEmail($email);
        }
        $phone = trim((string) ($billing['phone'] ?? ''));
        if ($phone !== '' && $company->getPhoneNumber() === null) {
            $company->setPhoneNumber($phone);
        }

        return $company;
    }

    /** @param array<string, mixed> $payload */
    private function resolveCustomer(EntityManagerInterface $entityManager, array $payload, Company $company): void
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $email = trim((string) ($billing['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $existing = $this->customerAccounts->findByEmail($email);
        if ($existing === null) {
            $user = $this->customerAccounts->create($entityManager, $email, $company);
            if (($billing['first_name'] ?? '') !== '') {
                $user->setFirstName((string) $billing['first_name']);
            }
            if (($billing['last_name'] ?? '') !== '') {
                $user->setLastName((string) $billing['last_name']);
            }
            $entityManager->persist($user);
        }
    }

    /** @param array<string, mixed> $payload */
    private function buildOrder(
        EntityManagerInterface $entityManager,
        WooCommerceConnection $connection,
        Company $company,
        array $payload,
    ): SalesOrder {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($this->orderNumbers->next($entityManager))
            ->setSource(self::SOURCE)
            ->setPoNumber(sprintf('Woo #%s', (string) ($payload['number'] ?? $payload['id'] ?? '')))
            ->setPaymentMethod(trim((string) ($payload['payment_method_title'] ?? '')) ?: null);

        $region = $this->warehouseRegions->regionForWarehouse($connection->getDefaultWarehouse());
        if ($region !== null) {
            $order->setFulfillmentRegion($region->getName());
        }

        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $this->writeAddress($order, AbstractDocumentAddress::TYPE_BILLING, $billing);

        $shippingSource = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        // Woo sends a shipping object even when the buyer chose "ship to billing address" at
        // checkout, but leaves most of its fields blank in that case — an address with no street
        // is not a real shipping address, so this falls back to the billing one rather than
        // freezing an empty snapshot.
        $shippingSource = trim((string) ($shippingSource['address_1'] ?? '')) !== ''
            ? $shippingSource + ['email' => $billing['email'] ?? null]
            : $billing;
        $this->writeAddress($order, AbstractDocumentAddress::TYPE_SHIPPING, $shippingSource);

        $subtotal = 0.0;
        $sortOrder = 0;
        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $product = $this->products->resolve($entityManager, $connection, $company, $lineItem);

            $quantity = (float) ($lineItem['quantity'] ?? 0);
            $lineTotal = (float) ($lineItem['total'] ?? 0);
            $unitPrice = $quantity > 0.0 ? $lineTotal / $quantity : (float) ($lineItem['price'] ?? 0);

            $line = (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setUnit($product->getUnit())
                ->setQuantity(number_format($quantity, 2, '.', ''))
                ->setCost((string) ($product->getCostPrice() ?? '0.00'))
                ->setPrice(number_format($unitPrice, 2, '.', ''))
                ->setSubtotal(number_format($lineTotal, 2, '.', ''))
                ->setTaxCode($product->getSalesTaxCode())
                ->setSortOrder($sortOrder++);

            $order->addLine($line);
            $subtotal += $lineTotal;
        }

        $tax = (float) ($payload['total_tax'] ?? 0);
        $total = (float) ($payload['total'] ?? ($subtotal + $tax));

        $order
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax(number_format($tax, 2, '.', ''))
            ->setTotal(number_format($total, 2, '.', ''));

        return $order;
    }

    /**
     * Writes straight onto the order's own address snapshot rather than going through
     * setBillingAddressFrom()/copyFrom(): those exist to copy a real CompanyAddress out of the
     * address book (and stamp sourceAddress with it, which then needs that row persisted for
     * Doctrine's cascade to follow). Woo's billing/shipping object is not a book entry — it is
     * exactly the "typed straight onto an order, no book entry" case AbstractDocumentAddress's own
     * $sourceAddress docblock already describes as normal and nullable.
     *
     * @param array<string, mixed> $address A Woo billing or shipping object.
     */
    private function writeAddress(SalesOrder $order, string $type, array $address): void
    {
        $order->addressForWriting($type)
            ->setFirstName($this->nullableString($address['first_name'] ?? null))
            ->setLastName($this->nullableString($address['last_name'] ?? null))
            ->setCompanyName($this->nullableString($address['company'] ?? null))
            ->setEmailPrimary($this->nullableString($address['email'] ?? null))
            ->setPhone($this->nullableString($address['phone'] ?? null))
            ->setAddressLine1($this->nullableString($address['address_1'] ?? null))
            ->setAddressLine2($this->nullableString($address['address_2'] ?? null))
            ->setCity($this->nullableString($address['city'] ?? null))
            ->setProvince($this->nullableString($address['state'] ?? null))
            ->setCountry($this->nullableString($address['country'] ?? null))
            ->setPostalCode($this->nullableString($address['postcode'] ?? null));
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }
}
