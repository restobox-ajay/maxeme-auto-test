<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A purchase order's own frozen vendor address — Order To (the buy-side mirror of the sell side's
 * Billing) or Ship From (the mirror of Shipping): where the order is sent, and where the vendor
 * ships from. One row per (order, type), enforced by the unique constraint, the same guarantee
 * `SalesOrderAddress` gives its own two types.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_address')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_order_address_type', fields: ['purchaseOrder', 'type'])]
class PurchaseOrderAddress extends AbstractPurchaseDocumentAddress
{
    /** Where the purchase order is sent — the buy-side mirror of a sales order's Billing address. */
    public const TYPE_ORDER_TO = 'order_to';

    /** Where the vendor ships the goods from — the buy-side mirror of Shipping. */
    public const TYPE_SHIP_FROM = 'ship_from';

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'orderAddresses')]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    public function getPurchaseOrder(): PurchaseOrder { return $this->purchaseOrder; }
    public function setPurchaseOrder(PurchaseOrder $purchaseOrder): self { $this->purchaseOrder = $purchaseOrder; return $this; }

    public function isOrderTo(): bool { return $this->type === self::TYPE_ORDER_TO; }
    public function isShipFrom(): bool { return $this->type === self::TYPE_SHIP_FROM; }
}
