<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Upserts legacy rows into one of this app's tables, keyed on `id`, so the legacy ids survive
 * (appointments, invoices and so on keep pointing at the same client) and a re-run updates rows
 * instead of duplicating them. One transaction per call.
 *
 * On the way every text value is repaired (see LegacyText), and the named datetime columns are
 * converted from the legacy app's wall-clock time (maxeme.timezone) to UTC, which is how this app
 * stores every date.
 */
final class LegacyTableCopier
{
    private const BATCH = 500;

    private readonly \DateTimeZone $legacyZone;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'maxeme.timezone')]
        string $legacyTimezone,
    ) {
        $this->legacyZone = new \DateTimeZone($legacyTimezone);
    }

    /**
     * @param iterable<array<string, mixed>> $rows every row has the same columns, `id` included
     * @param list<string> $localDateTimeColumns columns holding legacy wall-clock datetimes
     *
     * @return int rows written
     */
    public function upsert(string $table, iterable $rows, array $localDateTimeColumns = []): int
    {
        $connection = $this->entityManager->getConnection();
        $count = 0;

        $connection->transactional(function (Connection $connection) use ($table, $rows, $localDateTimeColumns, &$count): void {
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = $this->convert($row, $localDateTimeColumns);
                if (count($batch) === self::BATCH) {
                    $count += $this->writeBatch($connection, $table, $batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $count += $this->writeBatch($connection, $table, $batch);
            }
        });

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $localDateTimeColumns
     *
     * @return array<string, mixed>
     */
    private function convert(array $row, array $localDateTimeColumns): array
    {
        $row = array_map(LegacyText::repair(...), $row);

        foreach ($localDateTimeColumns as $column) {
            if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                $row[$column] = (new \DateTimeImmutable($row[$column], $this->legacyZone))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            }
        }

        return $row;
    }

    /** @param non-empty-list<array<string, mixed>> $rows */
    private function writeBatch(Connection $connection, string $table, array $rows): int
    {
        $columns = array_keys($rows[0]);
        $quoted = array_map($connection->quoteIdentifier(...), $columns);
        $updates = array_map(fn (string $column): string => $this->updateAssignment($connection, $column), array_diff($quoted, [$connection->quoteIdentifier('id')]));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s %s %s',
            $connection->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', array_fill(0, count($rows), '(' . implode(', ', array_fill(0, count($columns), '?')) . ')')),
            $this->conflictClause($connection),
            implode(', ', $updates),
        );

        $params = [];
        foreach ($rows as $row) {
            array_push($params, ...array_values($row));
        }

        $connection->executeStatement($sql, $params);

        return count($rows);
    }

    private function conflictClause(Connection $connection): string
    {
        return match (true) {
            $connection->getDatabasePlatform() instanceof SQLitePlatform => 'ON CONFLICT (id) DO UPDATE SET',
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform => 'ON DUPLICATE KEY UPDATE',
            default => throw new \LogicException('The legacy import supports SQLite and MySQL targets only.'),
        };
    }

    private function updateAssignment(Connection $connection, string $quotedColumn): string
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform
            ? sprintf('%s = excluded.%s', $quotedColumn, $quotedColumn)
            : sprintf('%s = VALUES(%s)', $quotedColumn, $quotedColumn);
    }
}
