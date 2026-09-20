<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\DenominatedLine;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\ProductBaseUnitService;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The one property #646 exists to protect, stated structurally (#601, phase 3 #646).
 *
 * > **Stock is always stored in the product's base unit. The unit a line was entered in is an
 * > entry-and-display convention that never reaches the inventory layer.**
 *
 * `DenominationNeverReachesTheInventoryLayerTest` proves it for the figures — approve an order for
 * 3 BOX-12 and `product_inventory.sales_hold_quantity` moves by 36. This file proves the same thing
 * one level up, where a future change would break it: **the inventory layer has nowhere to PUT an
 * entered figure.** No `unit_id`, no `quantity_entered`, on any table that holds a quantity of a
 * product and is not a document line. A conversion bug can put the wrong number in a
 * column; only a schema can make the wrong number impossible.
 *
 * ## Enumerate, do not list (#601)
 *
 * Neither side of the comparison is hand-written:
 *
 *   - the **line** tables are every entity implementing {@see DenominatedLine};
 *   - the **inventory** tables are everything {@see ProductBaseUnitService::quantityColumns()}
 *     finds — "points at a product AND carries a quantity" — minus the line tables.
 *
 * So a new bucket table, a new reservation table or a new movement table joins the subjects of this
 * test by existing, and a line table that quietly loses its `DenominatedLine` declaration is caught
 * from the other end rather than silently reclassified.
 */
final class DenominationStaysOutOfTheInventoryLayerTest extends DoctrineIntegrationTestCase
{
    private ProductBaseUnitService $baseUnits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUnits = self::getContainer()->get(ProductBaseUnitService::class);
    }

    /**
     * Not one inventory-layer table may carry either denomination column.
     *
     * The two named at the end are the assertion that this test is not passing vacuously: #646 names
     * `product_inventory` and `inventory_detail` specifically, so a sweep that stopped finding them
     * would report "0 violations" and mean nothing at all.
     */
    public function testNoTableThatHoldsAQuantityOfAProductCarriesAnEnteredFigureUnlessItIsADocumentLine(): void
    {
        $lineTables = $this->lineTables();
        $violations = [];
        $checked = [];

        foreach ($this->baseUnits->quantityColumns() as $column) {
            $table = strstr($column, '.', true);
            if ($table === false || isset($lineTables[$table])) {
                continue;
            }

            $checked[$table] = true;

            foreach ($this->denominationColumnsOn($table) as $offending) {
                $violations[] = $table . '.' . $offending;
            }
        }

        self::assertSame([], $violations, 'the inventory layer must have nowhere to store an entered figure');

        self::assertArrayHasKey('product_inventory', $checked, 'the sweep found no product_inventory to check');
        self::assertArrayHasKey('inventory_detail', $checked, 'the sweep found no inventory_detail to check');
    }

    /**
     * And every document line DOES carry both, so the entered figure has one home and only one.
     *
     * The other half of the same statement: an entered figure reaching the inventory layer would be
     * the defect, and one reaching nowhere at all would be the line model not existing.
     */
    public function testEveryDocumentLineCarriesBothColumnsAndABaseColumnBesideThem(): void
    {
        $lineTables = $this->lineTables();

        self::assertGreaterThanOrEqual(11, \count($lineTables), '#646 names eleven line-bearing tables at least');

        $denominatedTables = [];
        foreach ($this->baseUnits->quantityColumns() as $column) {
            $denominatedTables[(string) strstr($column, '.', true)] = true;
        }

        foreach (array_keys($lineTables) as $table) {
            self::assertSame(
                ['quantity_entered', 'unit_id'],
                $this->denominationColumnsOn($table),
                $table . ' must carry both halves of the entered figure or neither',
            );

            // The entered figure has to resolve INTO something, and `quantity_entered` is excluded
            // from that sweep by name (it counts units of entry, not base units) — so this says the table
            // still has a real base column beside the two new ones.
            self::assertArrayHasKey(
                $table,
                $denominatedTables,
                $table . ' carries an entered figure but no column denominated in the product\'s base unit',
            );
        }
    }

    /**
     * **No inventory service READS an entered figure** (#644, #659).
     *
     * The two tests above say the denomination columns are not on the inventory tables. From phase 4
     * onwards that is no longer the whole rule, because phase 4 is where those accessors start
     * being called: an inventory service holds a document line in its hand, and
     * `getQuantityEntered()` reads exactly like the quantity and is not one. It is 3 on a line of 3
     * cases, and holding 3 units of stock against 36 units of goods is the defect #601's one
     * principle exists to make impossible.
     *
     * Source-level, in the shape of InventoryModeIsAskedThroughTheResolverTest, and for the same
     * reason: a behavioural test proves today's callers are clean and cannot stop tomorrow's from
     * reaching for the wrong figure. Nothing about the wrong version looks wrong.
     *
     * The files are discovered off the filesystem, so a service added tomorrow is covered without
     * anybody remembering to add it here.
     */
    public function testNoInventoryServiceReadsAnEnteredFigure(): void
    {
        $files = $this->inventoryServiceFiles();
        self::assertNotSame([], $files, 'src/Service/Inventory was not found, so this swept nothing');

        $offenders = [];

        foreach ($files as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));
            $relative = substr($file, strlen(\dirname(__DIR__, 2)) + 1);

            foreach (['getQuantityEntered(', 'setEnteredQuantity(', 'getUnitOfMeasure(', 'LineDenomination'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $relative . ' mentions ' . $needle;
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "An inventory service is reading an entered figure:\n  %s\n\n"
            . "Everything here reads the line's BASE column — DocumentLine::getQuantity() — which is the\n"
            . "only figure this layer is entitled to and the only one it has ever read. A line entered as\n"
            . "3 BOX-12 holds 36 units of stock, never 3.",
            implode("\n  ", $offenders),
        ));
    }

    /** @return list<string> */
    private function inventoryServiceFiles(): array
    {
        $files = glob(\dirname(__DIR__, 2) . '/src/Service/Inventory/*.php');

        return $files === false ? [] : array_values($files);
    }

    /** Comments are stripped, so a docblock explaining this very rule is not an offender. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string, ClassMetadata<object>> table name => metadata, for every DenominatedLine */
    private function lineTables(): array
    {
        $tables = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta instanceof ClassMetadata || $meta->isMappedSuperclass) {
                continue;
            }

            if (is_a($meta->getName(), DenominatedLine::class, true)) {
                $tables[$meta->getTableName()] = $meta;
            }
        }

        return $tables;
    }

    /**
     * `quantity_entered` and/or `unit_id` as actually mapped on $table, sorted.
     *
     * Read off the mapping rather than off the class: a property is a promise, a mapped column is
     * the thing that ends up in the database.
     *
     * @return list<string>
     */
    private function denominationColumnsOn(string $table): array
    {
        $found = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta instanceof ClassMetadata || $meta->isMappedSuperclass || $meta->getTableName() !== $table) {
                continue;
            }

            foreach ($meta->fieldMappings as $mapping) {
                if ($mapping->columnName === 'quantity_entered') {
                    $found[] = 'quantity_entered';
                }
            }

            foreach ($meta->associationMappings as $mapping) {
                if ($mapping->targetEntity === UnitOfMeasure::class && $mapping->isToOne() && $mapping->isOwningSide()) {
                    $found[] = 'unit_id';
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }
}
