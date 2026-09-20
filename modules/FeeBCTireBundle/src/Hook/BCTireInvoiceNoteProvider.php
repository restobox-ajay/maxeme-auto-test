<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\SalesOrder;
use App\Service\AppSettings;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;

/**
 * Targets 'invoice_note', called from templates/admin/invoice/invoice.html.twig — which since #539
 * stage 6 is also the customer's downloaded copy, rendered through its own is_pdf branch, so this
 * one provider still covers both surfaces for free.
 *
 * The context key stays `order` and stays a SalesOrder, for the reason spelled out in
 * TaxBCBundle\Hook\BCTaxInvoiceNoteProvider: the TSBC # is a custom-field snapshot on the ORDER.
 */
final class BCTireInvoiceNoteProvider implements InjectionPointProviderInterface
{
    // Must match a key in BCTireFeeConfigController::DISPLAY_SETTING_LABELS.
    private const SETTING_KEY = 'fee_bc_tire_show_invoice';

    public function __construct(
        private readonly BCTireOrderSnapshotRenderer $renderer,
        private readonly AppSettings $appSettings,
    ) {}

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
        return BCTireFeeCalculator::SOURCE;
    }

    public function render(array $context): string
    {
        if ($this->appSettings->get(self::SETTING_KEY, '1') !== '1') {
            return '';
        }

        $order = $context['order'] ?? null;

        return $order instanceof SalesOrder ? $this->renderer->renderInvoiceNote($order) : '';
    }
}
