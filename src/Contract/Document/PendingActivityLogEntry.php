<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * What `newLogEntry()` returns now, instead of a persisted per-document-type row.
 *
 * Five entities — `SalesOrderLog`, `InvoiceLog`, `EstimateLog`, `PurchaseOrderLog`,
 * `VendorBillLog` — used to exist for this, one per document type, each its own table, each with
 * an owned `OneToMany` that cascade-persisted for free whenever the parent document flushed. They
 * duplicated `AuditLog` in everything but name: same actor, same narrative text, same timestamp.
 * `AuditLog` already carries a bare `entityType`/`entityId` pair rather than a typed foreign key —
 * the one deliberate exception this codebase's "no polymorphic FK" rule has always made, because an
 * append-only historical row has to outlive the record it describes (see `AuditLog::$impersonatorAdminId`'s
 * own docblock making the same point about an actor). Five tables restating that exception five
 * times, each with its own typed FK, was the thing actually worth fixing — not a reason to keep it.
 *
 * An entity cannot call `AuditLogger` directly — it is a service, and Doctrine entities in this
 * codebase carry no injected dependencies. So `newLogEntry()` still returns something that
 * implements {@see DocumentLog} and answers to the same three setters every call site already
 * calls, unchanged: this object, held in memory on the document that created it
 * (`AbstractSalesDocument::$pendingActivityLog` / the buy-side equivalent) until
 * `AuditLogSubscriber` drains it into a real `AuditLog` row in `postFlush` — after the owning
 * document's own id is resolved, which is the reason this has to wait for postFlush and cannot
 * write itself. This is deliberately the ONLY path that writes one of these rows — not a second,
 * automatic one running alongside a manual one, which is exactly the shape that double-recorded
 * every quote note and status change before #273 was fixed by excluding these document logs from
 * the generic subscriber. There is nothing left to exclude once this is the only door.
 */
final class PendingActivityLogEntry implements DocumentLog
{
    private ?string $userName = null;
    private string $comment = '';
    private string $type = 'System';
    private bool $recipientNotified = false;

    public function setUserName(?string $userName): self
    {
        $this->userName = $userName;

        return $this;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Not part of {@see DocumentLog} — setting this always required knowing the concrete class
     * (`InvoiceLog::setCustomerNotified()`, `PurchaseOrderLog::setVendorNotified()`; `VendorBillLog`
     * carried no such column at all), never reached through the polymorphic three-method interface.
     * Every caller that used to set it already holds this concrete class, not `DocumentLog`, so
     * this stays reachable the same way.
     */
    public function setRecipientNotified(bool $notified): self
    {
        $this->recipientNotified = $notified;

        return $this;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isRecipientNotified(): bool
    {
        return $this->recipientNotified;
    }
}
