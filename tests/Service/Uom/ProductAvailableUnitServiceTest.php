<?php

declare(strict_types=1);

namespace App\Tests\Service\Uom;

use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\ProductAvailableUnitService;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Per-product scoping of a global vocabulary (#659).
 *
 * The one enhancement over stock NetSuite. Conversion is global, so the term list grows without
 * bound as pack sizes accumulate; this is what stops the catalogue reaching the order screen. Two
 * scopes are asserted here and they are different rules: the PRODUCT (only what this row lists) and
 * the FAMILY (only what its base unit measures).
 *
 * Every assertion re-reads `product_available_unit` out of the database rather than inspecting the
 * objects the service just handed back — a service that returned the right list and wrote the wrong
 * rows would pass the second and fail the first.
 */
final class ProductAvailableUnitServiceTest extends DoctrineIntegrationTestCase
{
    private ProductAvailableUnitService $units;

    protected function setUp(): void
    {
        parent::setUp();

        $this->units = self::getContainer()->get(ProductAvailableUnitService::class);
    }

    /** The family is the base unit's family, derived — there is no second column holding it. */
    public function testAProductsFamilyIsItsBaseUnitsFamily(): void
    {
        $kg = $this->unit('AVU-KG', UnitOfMeasure::FAMILY_WEIGHT, '1000');
        $product = $this->product('AVU-FAMILY', $kg);

        self::assertSame(UnitOfMeasure::FAMILY_WEIGHT, $this->units->familyOf($product));
        self::assertNull($this->units->familyOf($this->product('AVU-NO-BASE')), 'no base unit, no family');
    }

    public function testTheTickedUnitsAreStoredAndReadBack(): void
    {
        $each = $this->unit('AVU-EA', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit('AVU-BOX-12', UnitOfMeasure::FAMILY_QUANTITY, '12');
        $pallet = $this->unit('AVU-PAL-240', UnitOfMeasure::FAMILY_QUANTITY, '240');
        $product = $this->product('AVU-STORE', $each);

        $this->units->apply($product, [(int) $box->getId(), (int) $pallet->getId()], null);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['AVU-BOX-12', 'AVU-PAL-240'], $this->storedCodes('AVU-STORE'));
    }

    /**
     * Scope one: the FAMILY. Nothing converts across families, so a weight unit on a counted product
     * is not a preference to honour — it is a conversion the app cannot perform.
     */
    public function testAUnitFromAnotherFamilyIsRefused(): void
    {
        $each = $this->unit('AVU-EA-2', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $kg = $this->unit('AVU-KG-2', UnitOfMeasure::FAMILY_WEIGHT, '1000');
        $product = $this->product('AVU-CROSS', $each);

        try {
            $this->units->apply($product, [(int) $kg->getId()], null);
            self::fail('a weight unit must not be available on a counted product');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('AVU-KG-2', $refusal->getMessage());
            self::assertStringContainsString('weight', $refusal->getMessage());
        }

        $this->em->clear();
        self::assertSame([], $this->storedCodes('AVU-CROSS'));
    }

    /** A product with no base unit has no family, so there is nothing to draw units from. */
    public function testAProductWithNoBaseUnitCannotListUnits(): void
    {
        $box = $this->unit('AVU-BOX-6', UnitOfMeasure::FAMILY_QUANTITY, '6');
        $product = $this->product('AVU-NOBASE-2');

        $this->expectException(UnitOfMeasureRefusal::class);
        $this->expectExceptionMessageMatches('/has not declared a base unit/');

        $this->units->apply($product, [(int) $box->getId()], null);
    }

    /**
     * Blank is allowed — the owner's explicit ruling, and NetSuite's own behaviour.
     *
     * An empty list is not an unconfigured product; it is a product ordered in its base unit and
     * nothing else. The positive control is that its picker still has exactly one choice.
     */
    public function testAProductMayDeclareNoUnitsAtAll(): void
    {
        $each = $this->unit('AVU-EA-3', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $product = $this->product('AVU-BLANK', $each);

        $this->units->apply($product, [], null);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->storedCodes('AVU-BLANK'));

        $reread = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'AVU-BLANK']);
        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame(['AVU-EA-3'], array_map(
            static fn (UnitOfMeasure $u): string => $u->getCode(),
            $this->units->choicesFor($reread),
        ));
    }

    /**
     * The base unit is offered but never stored: a row saying so would be the base unit recorded
     * twice, on `product_core.unit_id` and here, free to disagree.
     */
    public function testTickingTheBaseUnitIsAgreementAndNotARow(): void
    {
        $each = $this->unit('AVU-EA-4', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit('AVU-BOX-4', UnitOfMeasure::FAMILY_QUANTITY, '4');
        $product = $this->product('AVU-BASE-TICK', $each);

        $this->units->apply($product, [(int) $each->getId(), (int) $box->getId()], null);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['AVU-BOX-4'], $this->storedCodes('AVU-BASE-TICK'), 'the base unit is not a row');

        $reread = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'AVU-BASE-TICK']);
        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame(['AVU-EA-4', 'AVU-BOX-4'], array_map(
            static fn (UnitOfMeasure $u): string => $u->getCode(),
            $this->units->choicesFor($reread),
        ), 'and it is still offered, first');
    }

    public function testTheDefaultMustBeOneOfTheAvailableUnits(): void
    {
        $each = $this->unit('AVU-EA-5', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit('AVU-BOX-5', UnitOfMeasure::FAMILY_QUANTITY, '5');
        $untickedBox = $this->unit('AVU-BOX-9', UnitOfMeasure::FAMILY_QUANTITY, '9');
        $product = $this->product('AVU-DEFAULT', $each);

        try {
            $this->units->apply($product, [(int) $box->getId()], (int) $untickedBox->getId());
            self::fail('a default the picker does not offer would post a value the server refuses');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('must be one of the units', $refusal->getMessage());
        }

        // The positive control: the same call with a default that IS ticked goes through.
        $this->units->apply($product, [(int) $box->getId()], (int) $box->getId());
        $this->em->flush();
        $this->em->clear();

        $reread = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'AVU-DEFAULT']);
        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame('AVU-BOX-5', $reread->getDefaultUnit()?->getCode());
    }

    /** Choosing the base unit as the default is the same statement as choosing nothing. */
    public function testABaseUnitDefaultIsStoredAsNull(): void
    {
        $each = $this->unit('AVU-EA-6', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $product = $this->product('AVU-DEFAULT-BASE', $each);

        $this->units->apply($product, [], (int) $each->getId());
        $this->em->flush();
        $this->em->clear();

        $reread = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'AVU-DEFAULT-BASE']);
        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertNull($reread->getDefaultUnit());
    }

    /**
     * Re-applying a list reconciles rather than deleting and re-inserting.
     *
     * A row that is still ticked keeps its id, so an unrelated save on the product does not disturb
     * anything that could ever point at it.
     */
    public function testAReAppliedListKeepsTheRowsItStillHolds(): void
    {
        $each = $this->unit('AVU-EA-7', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit('AVU-BOX-7', UnitOfMeasure::FAMILY_QUANTITY, '7');
        $pallet = $this->unit('AVU-PAL-70', UnitOfMeasure::FAMILY_QUANTITY, '70');
        $product = $this->product('AVU-RECONCILE', $each);

        $this->units->apply($product, [(int) $box->getId(), (int) $pallet->getId()], null);
        $this->em->flush();

        $boxRowId = $this->storedRowId('AVU-RECONCILE', 'AVU-BOX-7');
        self::assertGreaterThan(0, $boxRowId);

        $this->units->apply($product, [(int) $box->getId()], null);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['AVU-BOX-7'], $this->storedCodes('AVU-RECONCILE'));
        self::assertSame($boxRowId, $this->storedRowId('AVU-RECONCILE', 'AVU-BOX-7'), 'the surviving row keeps its id');
    }

    /**
     * The row that must NOT have changed.
     *
     * Two products of the same family sharing the same global terms is the whole point of the model,
     * and it is also exactly where a mistake would be invisible: editing product A's list must not
     * touch product B's, and neither may touch the shared `unit_of_measure` row.
     */
    public function testEditingOneProductsListLeavesAnothersAlone(): void
    {
        $each = $this->unit('AVU-EA-8', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit('AVU-BOX-8', UnitOfMeasure::FAMILY_QUANTITY, '8');

        $control = $this->product('AVU-CONTROL', $each);
        $this->units->apply($control, [(int) $box->getId()], (int) $box->getId());
        $this->em->flush();

        $product = $this->product('AVU-SUBJECT', $each);
        $this->units->apply($product, [(int) $box->getId()], null);
        $this->em->flush();
        $this->units->apply($product, [], null);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->storedCodes('AVU-SUBJECT'));
        self::assertSame(['AVU-BOX-8'], $this->storedCodes('AVU-CONTROL'));

        $rereadControl = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'AVU-CONTROL']);
        self::assertInstanceOf(ProductCore::class, $rereadControl);
        self::assertSame('AVU-BOX-8', $rereadControl->getDefaultUnit()?->getCode());

        $rereadUnit = $this->em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'AVU-BOX-8']);
        self::assertInstanceOf(UnitOfMeasure::class, $rereadUnit);
        self::assertSame('8.000000', $rereadUnit->getFactorToFamilyBase());
    }

    /** Candidates offered on the form are the product's family only — never the whole vocabulary. */
    public function testTheFormOnlyOffersTheProductsOwnFamily(): void
    {
        $each = $this->unit('AVU-EA-9', UnitOfMeasure::FAMILY_QUANTITY, '1');
        $this->unit('AVU-L-9', UnitOfMeasure::FAMILY_VOLUME, '1');
        $box = $this->unit('AVU-BOX-90', UnitOfMeasure::FAMILY_QUANTITY, '90');
        $product = $this->product('AVU-CANDIDATES', $each);

        $codes = array_map(
            static fn (UnitOfMeasure $u): string => $u->getCode(),
            $this->units->familyUnitsFor($product),
        );

        self::assertContains($box->getCode(), $codes);
        self::assertNotContains('AVU-L-9', $codes, 'a litre is not offered on a counted product');
    }

    /** @return list<string> the codes actually on `product_available_unit`, re-read */
    private function storedCodes(string $sku): array
    {
        $rows = $this->em->createQuery(
            'SELECT u.code FROM ' . ProductAvailableUnit::class . ' a JOIN a.product p JOIN a.unit u '
            . 'WHERE p.sku = :sku ORDER BY u.factorToFamilyBase ASC'
        )->setParameter('sku', $sku)->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    private function storedRowId(string $sku, string $code): int
    {
        $rows = $this->em->createQuery(
            'SELECT a.id FROM ' . ProductAvailableUnit::class . ' a JOIN a.product p JOIN a.unit u '
            . 'WHERE p.sku = :sku AND u.code = :code'
        )->setParameter('sku', $sku)->setParameter('code', $code)->getScalarResult();

        return (int) ($rows[0]['id'] ?? 0);
    }

    private function unit(string $code, string $family, string $factor): UnitOfMeasure
    {
        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName('Unit ' . $code)
            ->setFamily($family)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision('1');
        $this->em->persist($unit);
        $this->em->flush();

        return $unit;
    }

    private function product(string $sku, ?UnitOfMeasure $base = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku);
        if ($base instanceof UnitOfMeasure) {
            $product->setBaseUnit($base);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
