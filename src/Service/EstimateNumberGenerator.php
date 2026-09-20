<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

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
final class EstimateNumberGenerator
{
    public const DEFAULT_PREFIX = 'QO-';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumberAllocator $allocator,
    ) {
    }

    public function next(EntityManagerInterface $entityManager): string
    {
        $prefix = trim((string) $this->appSettings->get('quote_number_prefix', self::DEFAULT_PREFIX));
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        $next = $this->allocator->next($entityManager, 'estimate', $prefix, 'estimate', 'document_number');

        return sprintf('%s%d', $prefix, $next);
    }
}
