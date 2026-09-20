<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * One document type a lock can be held against, as declared by whoever owns it (#759).
 *
 * Carries exactly what `DocumentLockService` needs and used to hardcode per type: the stored key,
 * the class, how to print a refusal ("Order SO-1042 is locked…"), and which child entities count as
 * writes to the document rather than to its history. `$owners` is
 * `DocumentLockService::OWNERS`'s per-type slice — a child entity class mapped to the accessor that
 * reaches back to the document, so `DocumentLockFlushGuard` can resolve a line/address/payment write
 * to the document it belongs to.
 */
final class LockableDocument
{
    /**
     * @param string $typeKey the `document_lock.document_type` value, e.g. 'purchase_order'
     * @param class-string $documentClass the document entity
     * @param string $labelPrefix printed before the document's own number in a refusal — "Order", "Invoice", "PO"
     * @param string $numberAccessor method on the document returning its printed number, e.g. 'getDocumentNumber'
     * @param array<class-string, string> $owners child entity class => accessor method back to the document.
     *                                            Lines and address snapshots always belong here; a
     *                                            log, an audit trail or an email record never does —
     *                                            see DocumentLockService's own docblock for why.
     */
    public function __construct(
        public readonly string $typeKey,
        public readonly string $documentClass,
        public readonly string $labelPrefix,
        public readonly string $numberAccessor,
        public readonly array $owners = [],
    ) {
    }
}
