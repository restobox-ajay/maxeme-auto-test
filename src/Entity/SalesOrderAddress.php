<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An order's own frozen billing or shipping address.
 *
 * One row per (order, type), enforced by the unique constraint — the schema guarantees at most one
 * billing and one shipping address rather than trusting code to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_address')]
#[ORM\UniqueConstraint(name: 'uniq_sales_order_address_type', fields: ['order', 'type'])]
class SalesOrderAddress extends AbstractDocumentAddress
{
    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'orderAddresses')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    public function getOrder(): SalesOrder { return $this->order; }
    public function setOrder(SalesOrder $order): self { $this->order = $order; return $this; }
}
