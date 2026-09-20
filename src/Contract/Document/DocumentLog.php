<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * The smallest thing that lets a generic `setStatus()` fill in a timeline row it did not construct
 * (handoff section 8).
 *
 * There are five log entities — `SalesOrderLog`, `InvoiceLog`, `EstimateLog`, `PurchaseOrderLog`
 * and `VendorBillLog` — with identical shapes (`setUserName()`, `setComment()`, `setType()`) and no
 * interface or base class between them. This is that interface. It adds no column, no table and no
 * behaviour: the five classes already have these three methods with these three signatures.
 *
 * ## Why the status setter writes the row, rather than the caller writing it beside the setter
 *
 * Because attribution is then discipline, and this codebase already shows what discipline produces.
 * `SalesOrder::approve()` and `Invoice::issue()`, `complete()` and `cancel()` all take a
 * `DocumentActor` and write an entry. `CreditMemo::issue()`, `CreditMemo::void()`,
 * `DebitMemo::issue()`, `DebitMemo::void()` and `BackorderFulfillmentEntry::complete()` take no
 * actor at all. Five status changes that record nobody, in the same codebase as seven that do.
 *
 * With the row written BY `setStatus()`, a status change that records nobody stops being possible
 * to write.
 *
 * ## The log stores a NAME, not an actor
 *
 * `DocumentActor` is flattened to its `displayName` at the moment of writing, which is what the
 * existing rows hold and what the existing screens read. Nothing here changes that shape.
 */
interface DocumentLog
{
    public function setUserName(?string $userName): self;

    public function setComment(string $comment): self;

    /** 'System' for a derived move, and whatever the verb calls itself otherwise. */
    public function setType(string $type): self;
}
