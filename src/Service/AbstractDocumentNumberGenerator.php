<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The one "next <prefix><n>" for a document type. Each subclass names its own prefix setting,
 * default prefix, allocator kind, table and number column; the subclasses' docblocks say why each
 * type keeps a sequence of its own.
 *
 * The prefix is admin-configurable (Settings > Document Prefixes) and blank falls back to the
 * default. Allocation goes through DocumentNumberAllocator, which is atomic and only ever increments
 * (migrations/Version20260802110000.php, #304).
 */
abstract class AbstractDocumentNumberGenerator
{
    /** The prefix used before any setting row exists (CoreDocumentPrefixProvider shows it too). */
    public const DEFAULT_PREFIX = '';

    /** The app_setting key holding the admin's prefix. */
    protected const PREFIX_SETTING = '';

    /** DocumentNumberAllocator's counter kind for this document type. */
    protected const KIND = '';

    /** The table and column the allocator seeds a new prefix's counter from (MAX of existing numbers). */
    protected const TABLE = '';
    protected const COLUMN = 'document_number';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumberAllocator $allocator,
    ) {
    }

    public function next(EntityManagerInterface $entityManager): string
    {
        $prefix = trim((string) $this->appSettings->get(static::PREFIX_SETTING, static::DEFAULT_PREFIX));
        if ($prefix === '') {
            $prefix = static::DEFAULT_PREFIX;
        }

        $next = $this->allocator->next($entityManager, static::KIND, $prefix, static::TABLE, static::COLUMN);

        return sprintf('%s%d', $prefix, $next);
    }
}
