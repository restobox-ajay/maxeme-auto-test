<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Legacy `service` → maxeme_service, ids kept. The price was a free-text string (blank = none). */
final class ServiceItemImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'services';
    }

    public static function order(): int
    {
        return 50;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_service', $legacy->iterateAssociative(
            "SELECT id, name, preferred_name, NULLIF(price, '') AS price, active = '1' AS active, last_updated
               FROM service ORDER BY id",
        ), ['last_updated']);
    }
}
