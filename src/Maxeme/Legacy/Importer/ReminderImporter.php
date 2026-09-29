<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Legacy `reminder` → maxeme_reminder, ids kept (after invoices). The unused initiator is not carried over. */
final class ReminderImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'reminders';
    }

    public static function order(): int
    {
        return 80;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_reminder', $legacy->iterateAssociative(
            "SELECT r.id, r.client_id, r.vehicle_id, i.id AS invoice_id, COALESCE(NULLIF(r.status, ''), 'new') AS status, r.note,
                    COALESCE(r.created_on, r.reminder_date, NOW()) AS created_on,
                    COALESCE(r.last_modified, r.created_on, NOW()) AS last_modified,
                    COALESCE(r.reminder_date, r.created_on, NOW()) AS reminder_date
               FROM reminder r
               LEFT JOIN invoice i ON i.id = r.invoice_id
              WHERE r.client_id IS NOT NULL AND r.vehicle_id IS NOT NULL
              ORDER BY r.id",
        ), ['created_on', 'last_modified', 'reminder_date']);
    }
}
