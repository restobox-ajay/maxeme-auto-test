<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Hook;

use App\Entity\SalesOrder;
use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldValueRepository;
use FeeBCTireBundle\EventSubscriber\BCTireNumberFieldSubscriber;

/**
 * Shared TSBC # lookup for the three injection-point providers below — reads the
 * snapshot BCTireFeeCalculator::applyOrderSnapshot() wrote at order-creation/edit
 * time, never a live company lookup, so it reflects what was true on the order.
 * One render method per surface since each target template has its own markup
 * convention for a field row (admin's .detail-row, customer's grid-based
 * .customer-view-order-info, invoice's free-text note slot).
 */
final class BCTireOrderSnapshotRenderer
{
    public function __construct(
        private readonly CustomFieldValueRepository $customFieldValueRepo,
    ) {}

    public function renderAdminDetailRow(SalesOrder $order): string
    {
        $tsbcNumber = $this->tsbcNumber($order);
        if ($tsbcNumber === null) {
            return '';
        }

        return sprintf(
            '<div class="detail-row"><label>TSBC #:</label><strong>%s</strong></div>',
            $this->displayValue($tsbcNumber)
        );
    }

    public function renderCustomerDetailRow(SalesOrder $order): string
    {
        $tsbcNumber = $this->tsbcNumber($order);
        if ($tsbcNumber === null) {
            return '';
        }

        return sprintf(
            '<div><label>TSBC #:</label><span>%s</span></div>',
            $this->displayValue($tsbcNumber)
        );
    }

    public function renderInvoiceNote(SalesOrder $order): string
    {
        $tsbcNumber = $this->tsbcNumber($order);
        if ($tsbcNumber === null) {
            return '';
        }

        return sprintf(
            '<p style="margin:4px 0 0; font-size:0.85rem;"><strong>TSBC #:</strong> %s</p>',
            $this->displayValue($tsbcNumber)
        );
    }

    /** null = no snapshot was ever written for this order (non-BC order, or predates this feature) — say nothing. */
    private function tsbcNumber(SalesOrder $order): ?string
    {
        $orderId = $order->getId();
        if ($orderId === null) {
            return null;
        }

        $orderFields = $this->customFieldValueRepo->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_ORDER, $orderId);
        if (!array_key_exists(BCTireNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG, $orderFields)) {
            return null;
        }

        return trim($orderFields[BCTireNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG] ?? '');
    }

    private function displayValue(string $tsbcNumber): string
    {
        return htmlspecialchars($tsbcNumber !== '' ? $tsbcNumber : '-', ENT_QUOTES);
    }
}
