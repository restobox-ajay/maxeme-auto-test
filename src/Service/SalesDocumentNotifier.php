<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AbstractSalesDocument;
use App\Entity\SalesOrder;
use App\Entity\Estimate;

/**
 * Quote (Estimate) and order lifecycle emails. One method per client-specified event:
 * request received (customer + admin), quote provided (customer), quote approved
 * (admin, which also fires the same order-received pair a normal checkout order gets),
 * quote declined (admin), and an admin's message on a quote (customer).
 */
final class SalesDocumentNotifier
{
    private const EXPECTED_DELIVERY_PREFIX = 'Expected delivery time:';
    private const ORDER_PHONE_PREFIX = 'Order phone number:';
    private const PAYMENT_NOTE_PREFIX = 'Preferred payment method:';

    public function __construct(
        private readonly EmailNotifier $emailNotifier,
        private readonly CustomerUrlGenerator $customerUrlGenerator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly OrderTaxBreakdownService $taxBreakdownService,
        private readonly Region $region,
    ) {
    }

    private function provinceDisplayName(?object $address): string
    {
        if ($address === null || (string) $address->getProvince() === '') {
            return '';
        }

        return $this->region->provinceName((string) $address->getCountry(), (string) $address->getProvince())
            ?? (string) $address->getProvince();
    }

    public function quoteRequestReceived(Estimate $estimate, ?string $fallbackEmail = null): void
    {
        $this->emailNotifier->send(
            'quote_request_received',
            sprintf('Quote request received: %s', $estimate->getDocumentNumber()),
            'emails/quote_request_received.html.twig',
            $this->estimateContext($estimate),
            $this->emailNotifier->companyRecipientEmails($estimate->getCompany(), $fallbackEmail),
            fromCategory: 'sales',
        );
    }

    public function quoteRequestAdmin(Estimate $estimate): void
    {
        $this->emailNotifier->send(
            'quote_request_admin',
            sprintf('New quote request: %s', $estimate->getDocumentNumber()),
            'emails/quote_request_admin.html.twig',
            $this->estimateContext($estimate, forAdmin: true),
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'sales',
        );
    }

    public function quoteProvided(Estimate $estimate): void
    {
        $this->emailNotifier->send(
            'quote_provided',
            sprintf('Your quote is ready: %s', $estimate->getDocumentNumber()),
            'emails/quote_provided.html.twig',
            $this->estimateContext($estimate),
            $this->emailNotifier->companyRecipientEmails($estimate->getCompany()),
            fromCategory: 'sales',
        );
    }

    public function quoteApprovedAdmin(Estimate $estimate, SalesOrder $order): void
    {
        $context = $this->estimateContext($estimate, forAdmin: true);
        $context['order'] = $order;

        $this->emailNotifier->send(
            'quote_approved_admin',
            sprintf('Quote approved: %s', $estimate->getDocumentNumber()),
            'emails/quote_approved_admin.html.twig',
            $context,
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'sales',
        );
    }

    /**
     * A customer accepted a quote we cannot currently fill.
     *
     * Deliberately a separate email from quoteApprovedAdmin() rather than a line added to it. That
     * one is routine and gets skimmed; this one is the only signal that an order exists which will
     * never ship unless somebody acts. Sharing a subject line with the happy path is how it would
     * get missed.
     *
     * The order is already held as a Draft by the time this sends (EstimateConversionService), so
     * nothing is oversold while it waits — the cost of a missed email is a late order, not a
     * negative inventory. It is still the thing that tells anyone the order is waiting.
     *
     * @param list<array{product: \App\Entity\ProductCore, region: \App\Entity\FulfillmentRegion, requested: int, available: int, missing: int}> $shortfalls
     */
    public function quoteAcceptedShortOfStockAdmin(Estimate $estimate, SalesOrder $order, array $shortfalls): void
    {
        $context = $this->estimateContext($estimate, forAdmin: true);
        $context['order'] = $order;
        $context['shortfalls'] = $shortfalls;
        // estimateContext(forAdmin: true) already sets admin_url to the quote. The order is what
        // actually has to be acted on, so it gets its own link rather than sharing one.
        $context['order_admin_url'] = $this->adminUrlGenerator->generate('admin_order_detail', ['id' => $order->getId()]);

        $this->emailNotifier->send(
            'quote_accepted_short_of_stock_admin',
            sprintf('Action needed — quote %s accepted but short of stock', $estimate->getDocumentNumber()),
            'emails/quote_accepted_short_of_stock_admin.html.twig',
            $context,
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'sales',
        );
    }

    public function quoteDeclinedAdmin(Estimate $estimate): void
    {
        $this->emailNotifier->send(
            'quote_declined_admin',
            sprintf('Quote declined: %s', $estimate->getDocumentNumber()),
            'emails/quote_declined_admin.html.twig',
            $this->estimateContext($estimate, forAdmin: true),
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'sales',
        );
    }

    /**
     * A message an admin added to a quote with "notify customer" ticked — the quote's counterpart to
     * the order_message email OrderController::addLog() sends. See #270.
     */
    public function quoteMessage(Estimate $estimate, string $message): void
    {
        $context = $this->estimateContext($estimate);
        $context['message'] = $message;

        $this->emailNotifier->send(
            'quote_message',
            sprintf('Quote Message: %s', $estimate->getDocumentNumber()),
            'emails/quote_message.html.twig',
            $context,
            $this->emailNotifier->companyRecipientEmails($estimate->getCompany()),
            fromCategory: 'sales',
        );
    }

    /** Admin rejects a customer's quote request/pricing — notifies the customer. See #206. */
    public function quoteRejectedCustomer(Estimate $estimate): void
    {
        $this->emailNotifier->send(
            'quote_rejected_customer',
            sprintf('Your quote request could not be fulfilled: %s', $estimate->getDocumentNumber()),
            'emails/quote_rejected_customer.html.twig',
            $this->estimateContext($estimate),
            $this->emailNotifier->companyRecipientEmails($estimate->getCompany()),
            fromCategory: 'sales',
        );
    }

    /** Same "new order received" pair a normal checkout order sends — reused as-is when a quote converts. */
    public function orderReceived(SalesOrder $order, ?string $fallbackEmail = null): void
    {
        $context = $this->orderContext($order);

        $this->emailNotifier->send(
            'order_received',
            sprintf('Order received: %s', $order->getOrderNumber()),
            'emails/order_received.html.twig',
            $context,
            $this->emailNotifier->orderConfirmationRecipients($order->getCompany(), $fallbackEmail),
            fromCategory: 'sales',
        );

        $adminContext = $context;
        $adminContext['admin_url'] = $this->adminUrlGenerator->generate('admin_order_detail', ['id' => $order->getId()]);

        $this->emailNotifier->send(
            'order_received_admin',
            sprintf('New order received: %s', $order->getOrderNumber()),
            'emails/order_received_admin.html.twig',
            $adminContext,
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'sales',
        );
    }

    /** @return array<string, mixed> */
    private function estimateContext(Estimate $estimate, bool $forAdmin = false): array
    {
        $context = array_merge(
            [
                'estimate' => $estimate,
                'company' => $estimate->getCompany(),
                'estimate_url' => $this->customerUrlGenerator->generate('customer_estimate_detail', ['id' => $estimate->getId()]),
            ],
            $this->documentSummaryContext($estimate),
        );

        if ($forAdmin) {
            $context['admin_url'] = $this->adminUrlGenerator->generate('admin_estimate_detail', ['id' => $estimate->getId()]);
        }

        return $context;
    }

    /**
     * The fee/tax/address-derived fields _quote_summary.html.twig and _order_summary.html.twig
     * both print — shared here since Estimate and SalesOrder carry all of them identically via
     * AbstractSalesDocument.
     *
     * @return array<string, mixed>
     */
    public function documentSummaryContext(AbstractSalesDocument $document): array
    {
        $feeLines = json_decode((string) $document->getFeeLines(), true);
        $feeLines = is_array($feeLines) ? $feeLines : [];
        $feeLinesTotal = array_sum(array_map(static fn (array $l): float => (float) ($l['amount'] ?? 0), $feeLines));

        $taxBreakdown = $this->taxBreakdownService->tryDecodeTaxLinesJson($document->getTaxLines());

        $shippingMethod = strtolower((string) $document->getShippingMethod());

        return [
            'expected_delivery' => $this->specialInstructionValue($document, self::EXPECTED_DELIVERY_PREFIX),
            'special_instructions' => $this->visibleSpecialInstructions($document),
            'is_pickup' => str_contains($shippingMethod, 'pickup'),
            'fee_lines' => $feeLines,
            'fee_lines_total' => $feeLinesTotal,
            'tax_lines' => $taxBreakdown['lines'] ?? [],
            // Resolved here (not via a Twig province_name() call) so the sandboxed email render
            // never has to reach a function — _quote_summary/_order_summary just print these strings.
            'billing_province_name' => $this->provinceDisplayName($document->getEffectiveBillingAddress()),
            'shipping_province_name' => $this->provinceDisplayName($document->getEffectiveShippingAddress()),
        ];
    }

    /** @return list<string> */
    private function specialInstructionParts(AbstractSalesDocument $document): array
    {
        $instructions = trim((string) $document->getSpecialInstructions());
        if ($instructions === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode('|', $instructions)),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function specialInstructionValue(AbstractSalesDocument $document, string $prefix): string
    {
        foreach ($this->specialInstructionParts($document) as $part) {
            if (str_starts_with(strtolower($part), strtolower($prefix))) {
                return trim(substr($part, strlen($prefix)));
            }
        }

        return '';
    }

    private function visibleSpecialInstructions(AbstractSalesDocument $document): string
    {
        $visible = array_values(array_filter(
            $this->specialInstructionParts($document),
            function (string $part): bool {
                $lower = strtolower($part);

                return !str_starts_with($lower, strtolower(self::EXPECTED_DELIVERY_PREFIX))
                    && !str_starts_with($lower, strtolower(self::ORDER_PHONE_PREFIX))
                    && !str_starts_with($lower, strtolower(self::PAYMENT_NOTE_PREFIX));
            },
        ));

        return implode(' | ', $visible);
    }

    /** @return array<string, mixed> */
    private function orderContext(SalesOrder $order): array
    {
        return array_merge(
            [
                'order' => $order,
                'company' => $order->getCompany(),
                'order_url' => $this->customerUrlGenerator->generate('customer_order_detail', ['id' => $order->getId()]),
            ],
            $this->documentSummaryContext($order),
        );
    }
}
