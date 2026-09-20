<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A debit memo's own frozen Remit To address — the buy-side mirror of `App\Entity\CreditMemoAddress`.
 *
 * A debit memo raised from a bill copies that bill's own frozen Remit To snapshot rather than
 * re-reading the vendor's address book, for the same reason CreditMemoAddress copies the invoice's:
 * a debit memo is a record of the original transaction being partly reversed, and the two must
 * agree forever, not follow wherever the vendor's address book has moved to since.
 *
 * Entity-only in this pass: DebitMemo needed a concrete implementation of
 * `AbstractPurchaseDocument::getAddresses()`/`newAddress()` the moment that contract gained a real
 * body (the PO/Bill address-book feature), the same way CreditMemo already had one. Theming
 * DebitMemo's own screens onto the shared buy-side templates is a separate piece of work.
 */
#[ORM\Entity]
#[ORM\Table(name: 'debit_memo_address')]
#[ORM\UniqueConstraint(name: 'uniq_debit_memo_address_type', fields: ['debitMemo', 'type'])]
class DebitMemoAddress extends AbstractPurchaseDocumentAddress
{
    public const TYPE_REMIT_TO = 'remit_to';

    #[ORM\ManyToOne(targetEntity: DebitMemo::class, inversedBy: 'memoAddresses')]
    #[ORM\JoinColumn(name: 'debit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DebitMemo $debitMemo;

    public function getDebitMemo(): DebitMemo { return $this->debitMemo; }
    public function setDebitMemo(DebitMemo $debitMemo): self { $this->debitMemo = $debitMemo; return $this; }
}
