<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One admin-defined field's value on one invoice.
 *
 * Modelled on {@see CustomFieldValueOrder}, deliberately line for line: its own table, its own FK
 * to the document with ON DELETE CASCADE, and a unique constraint on (definition_id, invoice_id) so
 * a definition can hold at most one value per invoice. Version20260805040000 explains why these are
 * one table per object type rather than one shared table keyed on a bare int — without the FK there
 * is no cascade, and a deleted document leaves orphan rows behind.
 *
 * This stores NOTHING the invoice itself already states. An invoice is a snapshot, and nothing here
 * touches a stored figure or column on it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_invoice')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_INVOICE', columns: ['definition_id', 'invoice_id'])]
class CustomFieldValueInvoice extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getObjectId(): int { return $this->invoice->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setInvoice($reference);
    }
}
