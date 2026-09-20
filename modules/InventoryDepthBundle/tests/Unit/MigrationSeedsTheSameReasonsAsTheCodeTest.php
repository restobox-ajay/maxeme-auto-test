<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260830160000;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// `migrations/` is not in composer's autoload map — the chain is normally driven by
// `doctrine:migrations:migrate`, which discovers the files by path — so the class has to be pulled
// in by hand. tests/Migrations/InventoryReanchorTest.php sidesteps this by shelling out to the
// console; that is the right tool for asserting what a whole CHAIN does to real rows, and the wrong
// one for asserting what eight INSERTs in a single file say, which needs no chain and no subprocess.
require_once dirname(__DIR__, 4) . '/migrations/Version20260830160000.php';

/**
 * The eight reasons the migration seeds are the eight the code declares (#585).
 *
 * ## Why the two copies exist at all
 *
 * Version20260830160000 writes the rows as literal SQL rather than calling
 * InventoryAdjustmentReason::defaults(). A migration has to produce the same rows in five years as
 * it did the day it ran, and one that reads a class the application keeps editing does not — replay
 * would depend on the current shape of the code instead of on the file. That is the right trade and
 * it has a cost: the eight rows are written down twice, and two copies drift.
 *
 * This is what stops them. A migrated database and a metadata-built one (both test suites, via
 * InventoryAdjustmentReasonRepository::ensureCatalogue()) must offer the operator the same list, or
 * the screen behaves differently in production from every test that covers it — which is the least
 * discoverable form of this bug and the reason it is worth a test of its own.
 *
 * ## How it checks
 *
 * By RUNNING the migration into an in-memory SQLite and reading the rows back, not by grepping the
 * source. The same lesson RawSqlTablesSurviveTheChainTest records: its first version inspected the
 * migration text and passed while the bug it existed for was reinstated, because the statement was
 * generated in a loop and the literal it searched for appeared nowhere. Only the schema and the data
 * a migration actually produces are authoritative.
 *
 * `help` is compared as PRESENT rather than EQUAL. It is prose that will be reworded, and holding
 * two copies of a paragraph to the character would make every improvement to the wording a
 * two-file edit with a red test in between. The label and the two statuses are what has to match —
 * those are what the screen derives behaviour from.
 */
final class MigrationSeedsTheSameReasonsAsTheCodeTest extends TestCase
{
    /**
     * The rows the migration writes, obtained by executing it.
     *
     * `up()` only queues SQL, so the statements are replayed against a throwaway connection here.
     * The migration does not read `$schema` — it is `ADD COLUMN`s and `CREATE TABLE`s, expressed as
     * SQL because SQLite's limits are what they have to be written against — so an empty one is
     * enough to call it.
     *
     * @return array<string, array{label: string, from: ?string, to: ?string, reversal: int, sort: int, help: ?string, active: int}>
     */
    private function seededRows(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $migration = new Version20260830160000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            // The two ALTER TABLEs target tables this throwaway database has never heard of. They
            // are not what this test is about, and creating stand-ins for them would be asserting
            // that a hand-written fake matches the real schema — a different test, and a worse one.
            if (str_contains($query->getStatement(), 'inventory_movement')) {
                continue;
            }

            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $rows = [];
        foreach ($connection->fetchAllAssociative('SELECT * FROM inventory_adjustment_reason ORDER BY sort_key ASC') as $row) {
            $rows[(string) $row['code']] = [
                'label' => (string) $row['label'],
                'from' => $row['from_status'] === null ? null : (string) $row['from_status'],
                'to' => $row['to_status'] === null ? null : (string) $row['to_status'],
                'reversal' => (int) $row['reversal'],
                'sort' => (int) $row['sort_key'],
                'help' => $row['help'] === null ? null : (string) $row['help'],
                'active' => (int) $row['active'],
            ];
        }

        return $rows;
    }

    public function testTheMigrationSeedsEveryReasonTheCodeDeclaresAndNoOthers(): void
    {
        $seeded = $this->seededRows();
        $declared = [];

        foreach (InventoryAdjustmentReason::defaults() as $default) {
            $declared[] = $default['code'];
        }

        self::assertSame(
            $declared,
            array_keys($seeded),
            'the migration and InventoryAdjustmentReason::defaults() offer different reasons, in a different order — '
            . 'so a migrated instance and a metadata-built one are two different screens',
        );
    }

    public function testEverySeededRowCarriesTheStatusPairTheCodeDeclares(): void
    {
        $seeded = $this->seededRows();

        foreach (InventoryAdjustmentReason::defaults() as $default) {
            $row = $seeded[$default['code']] ?? null;
            self::assertIsArray($row, sprintf('reason "%s" is declared in code and never seeded', $default['code']));

            self::assertSame($default['label'], $row['label'], sprintf('reason "%s" is labelled differently by the migration', $default['code']));
            self::assertSame($default['from'], $row['from'], sprintf('reason "%s" takes stock out of a different status in the migration', $default['code']));
            self::assertSame($default['to'], $row['to'], sprintf('reason "%s" puts stock into a different status in the migration', $default['code']));
            self::assertSame((int) $default['reversal'], $row['reversal'], sprintf('reason "%s" disagrees about whether it ties to an original entry', $default['code']));
            self::assertSame(1, $row['active'], 'a reason nobody can pick is not a reason');
            self::assertNotNull($row['help'], sprintf('reason "%s" ships with no explanation, which is the half an operator reads', $default['code']));
        }
    }

    /**
     * The two reasons that put stock back are first in the list.
     *
     * Not a cosmetic preference. They share a physical trigger — a box turned up — and have opposite
     * effects on the books, so burying the choice between "Lost" and "Scrapped" is how somebody
     * invents stock while leaving the loss standing. The order is the guidance.
     */
    public function testTheTwoReasonsThatPutStockBackComeFirst(): void
    {
        self::assertSame(
            ['stock_found', 'reverse_write_off'],
            \array_slice(array_keys($this->seededRows()), 0, 2),
        );
    }
}
