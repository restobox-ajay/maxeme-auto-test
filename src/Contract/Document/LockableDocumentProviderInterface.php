<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * Lets whoever owns a document make it lockable (#759).
 *
 * Before this, `DocumentLockService::TYPES`/`OWNERS` hardcoded the same three documents core owns —
 * Estimate, SalesOrder, Invoice. `PurchaseOrder` and `VendorBill` could not join them without core
 * naming `ProcurementBundle`'s entity classes directly, the layering violation "core reads nothing a
 * bundle writes" exists to prevent. Modelled on `DocumentPrefixProviderInterface` (#615) and
 * `CustomFieldObjectTypeProviderInterface` (#745), the third instance of the same shape: a hardcoded
 * map in core, unreachable for a bundle-owned document.
 *
 * ## What a bundle must do to make one of its documents lockable
 *
 * 1. Declare it here: a `LockableDocument` naming the document class, its printed label, and every
 *    child entity (lines, address snapshots, anything that changes what the document IS rather than
 *    what happened to it) that must be frozen alongside it.
 * 2. Tag the provider `app.lockable_document_provider`.
 * 3. Add `lock{Document}()`/`unlock{Document}()` routes to the document's own controller, calling
 *    `App\Service\Document\DocumentLockService::lock()`/`unlock()` — see `EstimateController` or
 *    `OrderController` for the shape; nothing else decides who may lock or unlock, so a bundle never
 *    reimplements the `ROLE_SUPER_ADMIN`-to-unlock asymmetry.
 * 4. Wire `lockUrl`/`unlockUrl` and `document_is_locked()`/`document_lock()` into the document's own
 *    action bar template.
 *
 * No physical schema change is ever needed for step 1: `document_lock` is one shared table keyed by
 * `(document_type, document_id)`, not a table per document — see `App\Entity\DocumentLock`'s own
 * docblock for why a fifth document joins by passing its own key rather than a migration.
 *
 * A provider MUST NOT contribute a `typeKey` another provider already owns. Core wins on collision
 * and the duplicate is dropped, exactly as the other two catalogues do — a bundle quietly taking
 * over `order` would let it lock and unlock a sales order through the wrong table's rows.
 */
interface LockableDocumentProviderInterface
{
    /**
     * The documents this provider owns.
     *
     * @return list<LockableDocument>
     */
    public function lockableDocuments(): array;
}
