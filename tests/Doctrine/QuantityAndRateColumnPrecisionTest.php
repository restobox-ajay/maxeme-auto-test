<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The precision #601 sets, asserted over the whole entity map rather than over a list (#645).
 *
 * ```
 * quantities            NUMERIC(14, 4)   a third of a case is 0.3333 and two decimals lose it
 * unit prices and rates NUMERIC(18, 6)   $10.00 per case of 12 is 0.833333 per unit
 * money totals          NUMERIC(12, 2)   a total is what somebody pays, and currency has two
 * ```
 *
 * The subjects are discovered from Doctrine's metadata rather than typed out here: #601's rule is
 * "enumerate, do not list", and a list would go stale the first time somebody adds a line table
 * without reading this file. A new `unitCost` at `NUMERIC(12, 4)` fails here on the day it is
 * written, which is the only day the fix is cheap — widening it afterwards is a table rebuild
 * across the layer everything depends on, which is what #645 had to be.
 *
 * Each failure names the column and what it is, because the useful thing to know when this goes red
 * is not that a rule exists but which column broke it.
 */
final class QuantityAndRateColumnPrecisionTest extends KernelTestCase
{
    /**
     * Fields whose name reads like a quantity or a rate and are neither.
     *
     * Empty today, and deliberately present anyway: the sweeps below match on names, so the honest
     * way to disagree with one is a documented exception rather than a weakened rule. Same shape
     * ProductBaseUnitService::NOT_A_QUANTITY uses, for the same reason.
     *
     * @var array<string, string> table.column => why it is not what its name suggests
     */
    private const NOT_WHAT_ITS_NAME_SUGGESTS = [];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        // Also what registers the custom types: DoctrineBundle's ConnectionFactory adds them when
        // it builds the connection, so Type::getType('quantity') below needs this to have run.
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The column is `NUMERIC(14, 4)` and PHP now reads what it holds.
     *
     * This test used to be `…AndStillHandsPhpAnInt`, and it ended with the sentence "Phase 3 (#646)
     * is where the second half changes, and it will fail here first." It did. `QuantityType` extends
     * `DecimalType` now, so `12.3456` comes back as `'12.3456'` rather than as `12`, and every
     * accessor over these 37 columns widened with it.
     *
     * The DDL is deliberately unchanged — this was a mapping change and not a schema one, which is
     * why no migration accompanies it.
     *
     * The read is CANONICALISED at the column's own scale rather than handed back as the driver gave
     * it. SQLite's NUMERIC affinity returns the shortest form, so `36` and `'36.0000'` are the same
     * stored value read two ways, and a `===` over a quantity would otherwise depend on whether the
     * entity had been refreshed since it was written.
     */
    public function testTheQuantityTypeDeclaresAWideColumnAndHandsPhpTheDecimalItHolds(): void
    {
        $platform = new SQLitePlatform();
        $type = Type::getType('quantity');

        self::assertSame('NUMERIC(14, 4)', $type->getSQLDeclaration([], $platform));
        self::assertSame('36.0000', $type->convertToPHPValue('36', $platform));
        self::assertSame('36.0000', $type->convertToPHPValue(36, $platform));
        self::assertSame('12.3456', $type->convertToPHPValue('12.3456', $platform), 'the fraction the column holds survives the read');
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertSame(ParameterType::STRING, $type->getBindingType());
    }

    public function testEveryMappedQuantityCarriesFourDecimals(): void
    {
        $offenders = [];

        foreach ($this->numericFields() as $column => [$property, $type, $precision, $scale]) {
            if (stripos($property, 'quantity') === false || isset(self::NOT_WHAT_ITS_NAME_SUGGESTS[$column])) {
                continue;
            }
            if ($type === 'quantity') {
                continue;
            }
            if ($type !== 'decimal' || $precision < 14 || $scale < 4) {
                $offenders[] = sprintf('%s is %s(%d, %d)', $column, $type, $precision, $scale);
            }
        }

        self::assertSame([], $offenders, "a quantity is NUMERIC(14, 4) or the 'quantity' type (#601)");
    }

    public function testEveryMappedUnitPriceCarriesSixDecimals(): void
    {
        $offenders = [];

        foreach ($this->numericFields() as $column => [$property, $type, $precision, $scale]) {
            $isRate = stripos($property, 'price') !== false || stripos($property, 'cost') !== false;
            if (!$isRate || isset(self::NOT_WHAT_ITS_NAME_SUGGESTS[$column])) {
                continue;
            }
            if ($type !== 'decimal' || $precision < 18 || $scale < 6) {
                $offenders[] = sprintf('%s is %s(%d, %d)', $column, $type, $precision, $scale);
            }
        }

        self::assertSame([], $offenders, 'a price per base unit is NUMERIC(18, 6) (#601)');
    }

    /**
     * The other half of the rule, and the one that is easy to break by being helpful: a total is
     * money, not a rate. Widening one would let an invoice's grand total sit a fraction of a cent
     * away from the sum of its printed lines, in a figure somebody is asked to pay.
     */
    public function testMoneyTotalsWereLeftAtTwoDecimals(): void
    {
        $offenders = [];

        foreach ($this->numericFields() as $column => [$property, $type, $precision, $scale]) {
            $isTotal = \in_array(strtolower($property), ['subtotal', 'total', 'tax', 'amount', 'amountpaid'], true);
            if (!$isTotal || $type !== 'decimal') {
                continue;
            }
            if ($precision !== 12 || $scale !== 2) {
                $offenders[] = sprintf('%s is decimal(%d, %d)', $column, $precision, $scale);
            }
        }

        self::assertSame([], $offenders, 'a money total stays NUMERIC(12, 2) — it is money, not a rate (#601)');
    }

    /**
     * Every numeric field in the application, keyed `table.column`.
     *
     * Keyed by table rather than by entity, so a field a mapped superclass declares is reported
     * once per table that actually has the column — `sales_order.total` and `invoice.total` are two
     * columns in the database however few times the mapping is written, and a failure that named
     * only the abstract would leave the reader looking for a table that does not exist.
     *
     * @return array<string, array{string, string, int, int}> => [property, type, precision, scale]
     */
    private function numericFields(): array
    {
        $fields = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            foreach ($meta->fieldMappings as $property => $mapping) {
                if ($mapping->inherited !== null || $mapping->id === true) {
                    continue;
                }
                if (!\in_array($mapping->type, ['integer', 'smallint', 'bigint', 'decimal', 'float', 'quantity'], true)) {
                    continue;
                }

                $fields[$meta->getTableName() . '.' . $mapping->columnName] = [
                    $property,
                    $mapping->type,
                    (int) ($mapping->precision ?? 0),
                    (int) ($mapping->scale ?? 0),
                ];
            }
        }

        self::assertNotSame([], $fields, 'the sweep found no numeric fields at all, so it proves nothing');

        return $fields;
    }
}
