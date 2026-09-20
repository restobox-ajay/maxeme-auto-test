<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\ProductBaseUnitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A product's typed label follows the base unit it declares, and cannot drift from it (#601).
 *
 * `product_core.unit` is the free-text VARCHAR an admin used to type; `product_core.base_unit_id`
 * is the declaration #643 added beside it. For as long as both were writable a product could
 * display `EA` while declaring `KG`, and nothing in the app could say which was right — the
 * two-places-hold-one-fact shape behind #589, #590 and #591.
 *
 * The free-text box is gone and `ProductBaseUnitService::assign()` is the only writer of both.
 * These are the object-level rules. The matching claim ABOUT THE DATA — that no stored row
 * disagrees — needs a migrated database and lives in `ProductBaseUnitCest`, because the PHPUnit
 * kernel here runs against a schema that predates `base_unit_id`.
 *
 * No database is touched below, and that is a property of the code rather than an accident of the
 * test: `assign()` only queries when a product ALREADY has a base unit and is being moved off it.
 * Every case here is a first declaration or a re-declaration of the same unit, so the guard never
 * needs to count anything.
 */
final class EveryProductsUnitLabelMatchesItsBaseUnitTest extends TestCase
{
    private function service(): ProductBaseUnitService
    {
        // The EntityManager is a constructor dependency that the paths under test never reach. A
        // mock with no expectations is the honest way to say so: if a change here started querying,
        // this test would fail on an unexpected call rather than quietly passing.
        return new ProductBaseUnitService($this->createMock(EntityManagerInterface::class));
    }

    private function unit(string $code, string $name): UnitOfMeasure
    {
        return (new UnitOfMeasure())
            ->setCode($code)
            ->setName($name)
            ->setFamily('quantity')
            ->setFactorToFamilyBase('1.000000')
            ->setRoundingPrecision('1.000000');
    }

    private function product(string $sku, ?string $label): ProductCore
    {
        return (new ProductCore())
            ->setSku($sku)
            ->setName('Unit Label Test')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setUnit($label);
    }

    /**
     * Declaring a base unit brings the label with it, over a label that disagreed.
     *
     * The starting label is deliberately wrong: a product whose typed label says one thing while
     * its declaration says another is the exact state this change exists to make unreachable.
     */
    public function testDeclaringABaseUnitRewritesALabelThatDisagreed(): void
    {
        $box = $this->unit('BOX', 'Box');
        $product = $this->product('UOM-LABEL-TEST', 'WRONG');

        $this->service()->assign($product, $box);

        self::assertSame($box, $product->getBaseUnit(), 'the declaration was made');
        self::assertSame('BOX', $product->getUnit(), 'and the label follows it rather than staying WRONG');
    }

    /**
     * Re-declaring the unit a product already holds still repairs the label.
     *
     * This is the early-return path, and it is the one that would rot silently. `assign()` returns
     * as soon as the ids match, so a backfilled row whose label was never in step — or one a future
     * caller tampered with — would keep its stale label forever if the sync sat after that return.
     */
    public function testReDeclaringTheSameUnitStillRepairsAStaleLabel(): void
    {
        $box = $this->unit('BOX', 'Box');
        $product = $this->product('UOM-STALE-TEST', null);

        $this->service()->assign($product, $box);
        self::assertSame('BOX', $product->getUnit());

        $product->setUnit('STALE');
        $this->service()->assign($product, $box);

        self::assertSame('BOX', $product->getUnit(), 'the same-unit path must still bring the label into step');
    }

    /**
     * Clearing the declaration leaves the legacy label alone.
     *
     * A product that has never declared a base unit still has whatever was typed on it, and roughly
     * forty readers print that column. `12/Case` is the real shape of those: it is the example the
     * product import's template guide used to document the `unit` column with, and it is a PACK SIZE
     * and not a unit of measure. Under #659 it would be its own TERM — `CASE-12`, with a ratio of 12 — but which term
     * a given `12/Case` meant is a person's call, not a migration's. Blanking the label would be a
     * visible regression traded for tidiness, and mapping it to a unit would invent a fact nobody
     * stated.
     */
    public function testClearingTheDeclarationLeavesALegacyPackSizeLabelInPlace(): void
    {
        $product = $this->product('UOM-LEGACY-TEST', '12/Case');

        $this->service()->assign($product, null);

        self::assertNull($product->getBaseUnit());
        self::assertSame('12/Case', $product->getUnit(), 'a legacy pack-size label survives rather than being cleared');
    }

    /**
     * A product that declares nothing and was never labelled stays empty rather than gaining one.
     */
    public function testAProductWithNeitherDeclarationNorLabelGainsNothing(): void
    {
        $product = $this->product('UOM-EMPTY-TEST', null);

        $this->service()->assign($product, null);

        self::assertNull($product->getBaseUnit());
        self::assertNull($product->getUnit());
    }
}
