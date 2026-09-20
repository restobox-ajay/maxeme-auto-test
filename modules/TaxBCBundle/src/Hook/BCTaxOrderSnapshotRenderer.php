<?php

declare(strict_types=1);

namespace TaxBCBundle\Hook;

use App\Entity\SalesOrder;
use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldValueRepository;
use TaxBCBundle\EventSubscriber\BCPstNumberFieldSubscriber;

/**
 * Shared PST # lookup for the three injection-point providers below — reads
 * the order-scoped bc_pst_number_on_order snapshot BCTaxCalculator::applyOrderSnapshot()
 * wrote at order-creation/edit time (never a live company lookup), so it reflects what
 * was true on the order. Mirrors FeeBCTireBundle's BCTireOrderSnapshotRenderer for
 * TSBC #, one render method per surface for the same reason (each target template has
 * its own field-row markup).
 */
final class BCTaxOrderSnapshotRenderer
{
    public function __construct(
        private readonly CustomFieldValueRepository $customFieldValueRepo,
    ) {}

    public function renderAdminDetailRow(SalesOrder $order): string
    {
        $pstNumber = $this->pstNumber($order);
        if ($pstNumber === null) {
            return '';
        }

        return sprintf(
            '<div class="detail-row"><label>PST #:</label><strong>%s</strong></div>',
            $this->displayValue($pstNumber)
        );
    }

    public function renderCustomerDetailRow(SalesOrder $order): string
    {
        $pstNumber = $this->pstNumber($order);
        if ($pstNumber === null) {
            return '';
        }

        return sprintf(
            '<div><label>PST #:</label><span>%s</span></div>',
            $this->displayValue($pstNumber)
        );
    }

    public function renderInvoiceNote(SalesOrder $order): string
    {
        $pstNumber = $this->pstNumber($order);
        if ($pstNumber === null) {
            return '';
        }

        return sprintf(
            '<p style="margin:4px 0 0; font-size:0.85rem;"><strong>PST #:</strong> %s</p>',
            $this->displayValue($pstNumber)
        );
    }

    /** null = no snapshot was ever written for this order (non-BC order, or predates this feature) — say nothing. */
    private function pstNumber(SalesOrder $order): ?string
    {
        $orderId = $order->getId();
        if ($orderId === null) {
            return null;
        }

        $orderFields = $this->customFieldValueRepo->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_ORDER, $orderId);
        if (!array_key_exists(BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG, $orderFields)) {
            return null;
        }

        return trim($orderFields[BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG] ?? '');
    }

    private function displayValue(string $pstNumber): string
    {
        return htmlspecialchars($pstNumber !== '' ? $pstNumber : '-', ENT_QUOTES);
    }
}
