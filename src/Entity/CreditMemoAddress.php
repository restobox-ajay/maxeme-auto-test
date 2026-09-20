<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A credit note's own frozen billing or shipping address (#586).
 *
 * Frozen for the reason every document snapshots one, and for one extra reason that is specific to
 * this document: a credit note is raised against an invoice which itself froze an address, and the
 * two have to agree forever. Copying the invoice's snapshot rather than re-reading the address book
 * is what makes "credited back to where it was billed" a fact rather than a hope — the customer may
 * well have moved between the sale and the return, and the note is a record of the original
 * transaction being partly undone, not of where they live now.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_memo_address')]
#[ORM\UniqueConstraint(name: 'uniq_credit_memo_address_type', fields: ['creditMemo', 'type'])]
class CreditMemoAddress extends AbstractDocumentAddress
{
    #[ORM\ManyToOne(targetEntity: CreditMemo::class, inversedBy: 'memoAddresses')]
    #[ORM\JoinColumn(name: 'credit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CreditMemo $creditMemo;

    public function getCreditMemo(): CreditMemo { return $this->creditMemo; }
    public function setCreditMemo(CreditMemo $creditMemo): self { $this->creditMemo = $creditMemo; return $this; }
}
