<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\DocumentChargeKind;
use Doctrine\ORM\Mapping as ORM;

/** An invoice's custom fee or discount, copied from its repair order when issued (see AbstractDocumentCharge). */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_invoice_charge')]
class InvoiceCharge extends AbstractDocumentCharge
{
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'charges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    public function __construct(Invoice $invoice, DocumentChargeKind $kind)
    {
        parent::__construct($kind);
        $this->invoice = $invoice;
    }

    public function getInvoice(): Invoice { return $this->invoice; }
}
