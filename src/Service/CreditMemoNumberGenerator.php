<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Same shape as OrderNumberGenerator, EstimateNumberGenerator and InvoiceNumberGenerator, scoped to
 * the credit_memo table and the credit_memo_number_prefix setting (#586).
 *
 * ## Its own series, deliberately
 *
 * Allocation goes through DocumentNumberAllocator under its own kind, so a credit note's sequence is
 * independent of the order, quote and invoice ones. That is not tidiness: those three prefixes are
 * separately configurable, and two kinds sharing a counter means changing one prefix renumbers the
 * other. A credit note is also the document an auditor is most likely to ask about, so a sequence
 * with no holes matters more here than anywhere — the allocator only ever increments, and a voided
 * note keeps its number and is never deleted.
 *
 * ## The prefix is on the Document Prefixes screen (since #615)
 *
 * It was not, and the reason recorded here was mechanical: `ConfigController::documentPrefixes()`
 * validated and wrote its three prefixes AS A SET with every field `required`, so a fourth field
 * would have made every existing POST that omitted it submit an empty string, fail
 * ValidDocumentPrefixes and reject the whole set. Exposing this setting meant changing how that
 * form behaved for the other three, which #586 judged to be beyond its remit.
 *
 * #615 made exactly that change: a field that was not submitted is now left alone rather than read
 * as empty, so a set is still accepted or rejected whole without absence meaning blank. The prefix
 * is declared by CoreDocumentPrefixProvider and DEFAULT_PREFIX below is the single copy of its
 * default.
 */
final class CreditMemoNumberGenerator extends AbstractDocumentNumberGenerator
{
    /**
     * 'CN-' for Credit Note, matching the language Zoho Books uses and the language #586 uses
     * throughout. Not 'CM-', which reads as a unit of length.
     */
    public const DEFAULT_PREFIX = 'CN-';

    protected const PREFIX_SETTING = 'credit_memo_number_prefix';
    protected const KIND = 'credit_memo';
    protected const TABLE = 'credit_memo';
}
