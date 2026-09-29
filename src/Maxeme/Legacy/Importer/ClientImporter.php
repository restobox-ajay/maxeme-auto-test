<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Legacy `client` → maxeme_client, ids kept. Deleted (inactive) clients come too: their history does. */
final class ClientImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'clients';
    }

    public static function order(): int
    {
        return 20;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_client', $legacy->iterateAssociative(
            'SELECT id, first_name, last_name, preferred_name, email, home_number, work_number, cell_number,
                    address, note, active, last_updated
               FROM client ORDER BY id',
        ), ['last_updated']);
    }
}
