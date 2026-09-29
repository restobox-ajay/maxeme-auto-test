<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Legacy `appointment` → maxeme_appointment, ids kept (after clients and vehicles). */
final class AppointmentImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'appointments';
    }

    public static function order(): int
    {
        return 60;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_appointment', $legacy->iterateAssociative(
            'SELECT id, client_id, vehicle_id, start_time, end_time, status, note, last_updated
               FROM appointment ORDER BY id',
        ), ['start_time', 'end_time', 'last_updated']);
    }
}
