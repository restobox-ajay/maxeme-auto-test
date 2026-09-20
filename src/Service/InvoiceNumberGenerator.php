<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Same shape as OrderNumberGenerator and EstimateNumberGenerator, scoped to the invoice table and
 * the invoice_number_prefix setting (#539).
 *
 * Default 'INV-' is what the Document Prefixes screen shows before any setting row exists. It used
 * to be repeated in ConfigController, with a warning here that the two must be kept in step; since
 * #615 CoreDocumentPrefixProvider reads this constant instead, so there is one copy and nothing to
 * keep in step. That is why the constant is public.
 *
 * Allocation goes through DocumentNumberAllocator under its own kind, so the invoice sequence is
 * independent of the order and quote ones — their prefixes are separately configurable, so their
 * counters must be separate too. See migrations/Version20260802110000.php (#304).
 *
 * A cancelled invoice keeps its number and is never deleted, and the allocator only ever
 * increments, so the sequence has no holes an auditor would have to ask about.
 */
final class InvoiceNumberGenerator
{
    public const DEFAULT_PREFIX = 'INV-';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumberAllocator $allocator,
    ) {
    }

    public function next(EntityManagerInterface $entityManager): string
    {
        $prefix = trim((string) $this->appSettings->get('invoice_number_prefix', self::DEFAULT_PREFIX));
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        $next = $this->allocator->next($entityManager, 'invoice', $prefix, 'invoice', 'document_number');

        return sprintf('%s%d', $prefix, $next);
    }
}
