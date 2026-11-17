<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Same shape as OrderNumberGenerator, scoped to the estimate table and the
 * previously-reserved-but-unused quote_number_prefix setting.
 *
 * Default must match ConfigController::documentPrefixes()'s own fallback ('QO-') —
 * that page already displays/implies 'QO-' as the default before any setting row
 * exists, so a different default here would silently diverge from what admins see.
 * (Was 'QT' until #436 — never revisited to the 'QO-' convention this app uses.)
 *
 * Allocation goes through DocumentNumberAllocator for the same reason OrderNumberGenerator
 * does — see that class and migrations/Version20260802110000.php (#304).
 */
final class EstimateNumberGenerator extends AbstractDocumentNumberGenerator
{
    public const DEFAULT_PREFIX = 'QO-';

    protected const PREFIX_SETTING = 'quote_number_prefix';
    protected const KIND = 'estimate';
    protected const TABLE = 'estimate';
}
