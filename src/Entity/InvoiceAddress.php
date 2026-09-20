<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An invoice's own frozen billing or shipping address.
 *
 * Frozen for the reason every document snapshots one: an invoice is a record of where goods were
 * billed and sent, and re-reading the address book later would let that record change after the
 * fact. When an invoice is raised from an order it copies the order's snapshot rather than the
 * address book, so the two documents can never disagree about the same shipment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_address')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_address_type', fields: ['invoice', 'type'])]
class InvoiceAddress extends AbstractDocumentAddress
{
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'invoiceAddresses')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this; }
}
