<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use App\Maxeme\Legacy\LegacyText;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `client` → maxeme_client, ids kept. Deleted (inactive) clients come too: their history does.
 *
 * Home / work / cell number become phone 1 / 2 / 3. The legacy free-text address and note become
 * the client's first address-book entry and first note, but only for a client who has none yet, so
 * a re-run neither duplicates them nor touches what staff have written since.
 */
final class ClientImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
        private readonly EntityManagerInterface $entityManager,
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
        $count = $this->copier->upsert('maxeme_client', $legacy->iterateAssociative(
            'SELECT id, first_name, last_name, preferred_name, email, home_number AS phone_1, work_number AS phone_2,
                    cell_number AS phone_3, active, last_updated
               FROM client ORDER BY id',
        ), ['last_updated']);

        $local = $this->entityManager->getConnection();
        $local->transactional(static function (Connection $local) use ($legacy): void {
            $rows = $legacy->iterateAssociative(
                "SELECT id, address, note FROM client WHERE TRIM(COALESCE(address, '')) <> '' OR TRIM(COALESCE(note, '')) <> '' ORDER BY id",
            );
            foreach ($rows as $row) {
                $client = (int) $row['id'];
                $at = $local->fetchOne('SELECT last_updated FROM maxeme_client WHERE id = ?', [$client]);
                $address = trim((string) LegacyText::repair($row['address']));
                $note = trim((string) LegacyText::repair($row['note']));

                if ($address !== '') {
                    $local->executeStatement(
                        'INSERT INTO maxeme_client_address (client_id, address_line1, position, created_at)
                         SELECT ?, ?, 0, ? WHERE NOT EXISTS (SELECT 1 FROM maxeme_client_address WHERE client_id = ?)',
                        [$client, $address, $at, $client],
                    );
                }
                if ($note !== '') {
                    $local->executeStatement(
                        'INSERT INTO maxeme_client_note (client_id, user_name, text, created_at)
                         SELECT ?, NULL, ?, ? WHERE NOT EXISTS (SELECT 1 FROM maxeme_client_note WHERE client_id = ?)',
                        [$client, $note, $at, $client],
                    );
                }
            }
        });

        return $count;
    }
}
