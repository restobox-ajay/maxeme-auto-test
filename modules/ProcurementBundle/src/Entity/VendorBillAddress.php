<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A vendor bill's own frozen Remit To address — where paying this bill sends the money, often a
 * different legal entity from the vendor itself (a factor, a lockbox, a parent's accounts
 * department). One row per bill, since a bill has exactly this one address concept: the buy-side
 * mirror of Billing, with no Shipping counterpart — a bill's receiving location is the warehouse
 * it names, not a vendor address, and that is already a separate, existing mechanism.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_bill_address')]
#[ORM\UniqueConstraint(name: 'uniq_vendor_bill_address_type', fields: ['bill', 'type'])]
class VendorBillAddress extends AbstractPurchaseDocumentAddress
{
    public const TYPE_REMIT_TO = 'remit_to';

    #[ORM\ManyToOne(targetEntity: VendorBill::class, inversedBy: 'billAddresses')]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VendorBill $bill;

    public function getBill(): VendorBill { return $this->bill; }
    public function setBill(VendorBill $bill): self { $this->bill = $bill; return $this; }

    public function isRemitTo(): bool { return $this->type === self::TYPE_REMIT_TO; }
}
