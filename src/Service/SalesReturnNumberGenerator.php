<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Same shape as OrderNumberGenerator, EstimateNumberGenerator, InvoiceNumberGenerator and
 * CreditMemoNumberGenerator, scoped to the `sales_return` table and the `sales_return_number_prefix`
 * setting (#596).
 *
 * ## Its own series, and here the reason is not merely tidiness
 *
 * Allocation goes through DocumentNumberAllocator under its own kind, so an RMA's sequence is
 * independent of the order, quote, invoice and credit-note ones. Two kinds sharing a counter means
 * changing one prefix renumbers the other, which is the general argument.
 *
 * The specific one is that this number LEAVES THE BUILDING. It is written on the outside of a box by
 * somebody who does not work here, read off that box by a receiving clerk weeks later, and typed
 * into a search field. RMA-41 arriving when RMA-41 is a credit note and the return was CN-41 is not
 * a reporting inconvenience, it is a parcel nobody can match. A sequence of its own with no holes is
 * worth more here than on any document that stays internal.
 *
 * ## The prefix is on the Document Prefixes screen (since #615)
 *
 * Unchanged from CreditMemoNumberGenerator's answer, including the follow-up: #615 taught that
 * screen to leave an unsubmitted field alone rather than read it as empty, which is what made a
 * fourth and fifth prefix possible without changing how a set is accepted or rejected. Both were
 * exposed together, as this docblock anticipated. The prefix is declared by
 * CoreDocumentPrefixProvider and DEFAULT_PREFIX below is the single copy of its default.
 */
final class SalesReturnNumberGenerator
{
    /**
     * 'RMA-', which is what the trade calls this document and what a customer will expect to see on
     * the label. Not 'SR-', which reads as a revision, and not 'RET-', which reads as a retail code.
     */
    public const DEFAULT_PREFIX = 'RMA-';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumberAllocator $allocator,
    ) {
    }

    public function next(EntityManagerInterface $entityManager): string
    {
        $prefix = trim((string) $this->appSettings->get('sales_return_number_prefix', self::DEFAULT_PREFIX));
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        $next = $this->allocator->next($entityManager, 'sales_return', $prefix, 'sales_return', 'document_number');

        return sprintf('%s%d', $prefix, $next);
    }
}
