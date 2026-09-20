<?php

declare(strict_types=1);

namespace TaxBCBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\SalesOrder;
use TaxBCBundle\Tax\BCTaxCalculator;

/**
 * Targets 'invoice_note', called from templates/admin/invoice/invoice.html.twig — which since #539
 * stage 6 is also the customer's downloaded copy, rendered through its own is_pdf branch, so one
 * provider still covers both surfaces. Same as FeeBCTireBundle\Hook\BCTireInvoiceNoteProvider does
 * for TSBC #.
 *
 * The context key stays `order` and stays a SalesOrder: the PST # this prints is a custom-field
 * snapshot on the ORDER object (BCTaxCalculator::applyOrderSnapshot() writes it at order
 * create/edit time), so the order is what can answer for it. The invoice rides beside it in the
 * context and is not read here. An invoice with no order passes null and this says nothing, which
 * is what it already did for a non-BC order.
 */
final class BCTaxInvoiceNoteProvider implements InjectionPointProviderInterface
{
    public function __construct(private readonly BCTaxOrderSnapshotRenderer $renderer) {}

    public function getPoint(): string
    {
        return 'invoice_note';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return BCTaxCalculator::SOURCE;
    }

    public function render(array $context): string
    {
        $order = $context['order'] ?? null;

        return $order instanceof SalesOrder ? $this->renderer->renderInvoiceNote($order) : '';
    }
}
