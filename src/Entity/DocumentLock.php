<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row per document somebody has deliberately frozen. No row means not locked.
 *
 * ## Why a shared table and not a boolean column on each document
 *
 * Three documents need a lock today — estimate, sales order, invoice — and the credit memo, the
 * sales return, the purchase order and the vendor bill are the same shape and would each want one
 * next. A column per document is four migrations, four entity edits and four `isLocked()` methods
 * that will drift; a type-plus-id row is one table and one service, and a fifth document joins by
 * passing its own key.
 *
 * The deciding argument is not the count, though. It is that **a lock is not part of what the
 * document is.** It records something a named person did TO the document at a moment in time, with
 * a reason — which is exactly the class of fact that
 * {@see \App\Service\Document\SalesDocumentCloner} refuses to copy, and exactly the class of fact
 * `AbstractSalesDocument`'s own docblock keeps off the header (payments, reservations, the timeline,
 * the converted-order link). Holding it beside the header would put "what happened to it" back on
 * the row that says "what it was".
 *
 * That is not aesthetics. It settles a real bug before it can be written: with a `locked` column,
 * every copier in the application — the cloner, `EstimateConversionService`, `OrderInvoicingService`
 * — has to REMEMBER not to carry it, and the day one forgets, a copy is born frozen for no reason
 * anybody can see. Keyed by (type, id), a copy has a new id and therefore no lock row, so the
 * problem does not exist to be forgotten.
 *
 * A column would also have had to land on `AbstractSalesDocument` to reach all three without a
 * per-entity edit, and that mapped superclass is `Cart`'s as well. A cart is a live basket and has
 * no lock to hold.
 *
 * ## Who and when are stored because a refusal has to answer "says who"
 *
 * "This document is locked" with no author is the kind of message that ends in somebody editing the
 * database by hand. The refusal names the person and the moment, so the reader knows who to ask.
 *
 * ## The unique index is the real enforcement
 *
 * Two admins pressing Lock at once would otherwise write two rows, and `unlock()` would delete one
 * of them and leave the document locked by a row nobody knew about. `uniq_document_lock` makes the
 * second insert fail instead.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_lock')]
#[ORM\UniqueConstraint(name: 'uniq_document_lock', columns: ['document_type', 'document_id'])]
class DocumentLock
{
    /**
     * The three document types core locks today. The value is the document's own table name, so a
     * row read in SQL says what it points at without a lookup.
     */
    public const TYPE_ESTIMATE = 'estimate';
    public const TYPE_SALES_ORDER = 'sales_order';
    public const TYPE_INVOICE = 'invoice';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'document_type', length: 32)]
    private string $documentType = '';

    /**
     * NOT a foreign key, and it cannot be one: a single column cannot reference three tables. The
     * cost is that a deleted document would strand its lock row — which is a state this feature
     * makes unreachable, since a locked document is precisely one that cannot be deleted.
     */
    #[ORM\Column(name: 'document_id')]
    private int $documentId = 0;

    #[ORM\Column(name: 'locked_at')]
    private \DateTimeImmutable $lockedAt;

    /** The display name of whoever locked it — a DocumentActor's, the same string a timeline row carries. */
    #[ORM\Column(name: 'locked_by', length: 120)]
    private string $lockedBy = '';

    #[ORM\Column(name: 'reason', length: 255, nullable: true)]
    private ?string $reason = null;

    public function __construct()
    {
        $this->lockedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDocumentType(): string { return $this->documentType; }
    public function setDocumentType(string $documentType): self { $this->documentType = $documentType; return $this; }

    public function getDocumentId(): int { return $this->documentId; }
    public function setDocumentId(int $documentId): self { $this->documentId = $documentId; return $this; }

    public function getLockedAt(): \DateTimeImmutable { return $this->lockedAt; }

    public function getLockedBy(): string { return $this->lockedBy; }
    public function setLockedBy(string $lockedBy): self { $this->lockedBy = $lockedBy; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }
}
