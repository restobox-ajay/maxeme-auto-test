<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Import;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Import\DimensionalImportProvider;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * The reconciliation cases from #565, one test per row of the plan's table.
 *
 * Two of them are worth guarding hardest and are marked below: the **negative sentinel row**, which
 * will read as a bug to whoever sees it next and whose obvious "fix" silently deletes the
 * discrepancy the design exists to surface; and the fact that a short count is **not** a refusal.
 */
final class DimensionalImportProviderTest extends DoctrineIntegrationTestCase
{
    private DimensionalImportProvider $provider;
    private InventoryDetailRepository $details;
    private ProductCore $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->details = self::getContainer()->get(InventoryDetailRepository::class);
        $this->provider = self::getContainer()->get(DimensionalImportProvider::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('IMP-1')
            ->setName('Imported Dimensional Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    private function location(Warehouse $warehouse, string $code): WarehouseLocation
    {
        $existing = $this->em->getRepository(WarehouseLocation::class)->findOneBy(['warehouse' => $warehouse, 'code' => $code]);
        if ($existing instanceof WarehouseLocation) {
            return $existing;
        }

        $location = (new WarehouseLocation())->setWarehouse($warehouse)->setCode($code);
        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    /** Seeds a bin with a quantity, bypassing movements — this is about what the import does to rows. */
    private function seedBin(string $code, int $quantity): InventoryDetail
    {
        $row = $this->details->findOrCreate(
            $this->product,
            $this->warehouse,
            $this->location($this->warehouse, $code),
            null,
            null,
            InventoryDetail::STATUS_AVAILABLE,
        );
        $row->setQuantity($quantity)->touch();
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /**
     * The core row the import writes BEFORE handing over to the provider (#572).
     *
     * The sentinel is a plug for `(quantity + received) − SUM(other available rows)`, so both terms
     * have to exist for the total-only cases to mean anything. ProductImportService writes them
     * from the file and the checkbox; these tests call the provider on its own, so they write them
     * here instead. The expected sentinel figures below are unchanged — this supplies the
     * precondition they were always implicitly assuming, back when the provider took the count as a
     * loose argument and the core row was nowhere in the calculation.
     */
    private function seedCore(int $quantity, int $received = 0): void
    {
        $row = $this->em->getRepository(\App\Entity\ProductInventory::class)
            ->findOneBy(['product' => $this->product, 'warehouse' => $this->warehouse])
            ?? (new \App\Entity\ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse);

        $row->setQuantity($quantity)->setReceivedQuantity($received);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function sentinelQuantity(): int
    {
        $this->em->clear();

        return (int) $this->em->getConnection()->fetchOne(
            "SELECT d.quantity FROM inventory_detail d
             JOIN warehouse_location l ON l.id = d.location_id
             WHERE d.product_id = ? AND l.code = '[PENDING]'",
            [$this->product->getId()],
        );
    }

    private function binQuantity(string $code): int
    {
        $this->em->clear();

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT d.quantity FROM inventory_detail d
             JOIN warehouse_location l ON l.id = d.location_id
             WHERE d.product_id = ? AND l.code = ?',
            [$this->product->getId(), $code],
        );
    }

    public function testAGrowingCountWithNoBinsLeavesExistingRowsAloneAndBalancesOnTheSentinel(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);
        $this->seedCore(47);

        $warnings = $this->provider->applyDeclaration($this->product, $this->warehouse, 47, [], false);

        self::assertSame(0, $this->sentinelQuantity(), 'the bins already account for all 47');
        self::assertSame(40, $this->binQuantity('A-01'));
        self::assertSame(7, $this->binQuantity('B-03'));
        self::assertSame([], $warnings, 'nothing is wrong, so nothing is warned about');
    }

    /**
     * THE ONE TO GUARD. A count lower than the rows on record leaves the rows untouched and puts
     * the shortfall on the sentinel as a NEGATIVE number.
     *
     * Clamping it at zero would make the total wrong AND tell nobody. Refusing the import would
     * fail on precisely the day someone discovers stock is missing, which is when they most need
     * the number corrected. Shrinking is a write-off wearing an import's clothes.
     */
    public function testAShortCountWithNoBinsWritesANegativeSentinelAndStillSucceeds(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);
        $this->seedCore(40);

        $warnings = $this->provider->applyDeclaration($this->product, $this->warehouse, 40, [], false);

        self::assertSame(-7, $this->sentinelQuantity(), 'the shortfall is a visible number, not a silent deletion');
        self::assertSame(40, $this->binQuantity('A-01'), 'existing rows are untouched');
        self::assertSame(7, $this->binQuantity('B-03'), 'existing rows are untouched');

        self::assertCount(1, $warnings, 'a short count warns');
        self::assertStringContainsString('7 short', $warnings[0]);
    }

    /** The total comes out right immediately, which is the point of allowing the negative. */
    public function testAShortCountLeavesTheAvailableTotalEqualToWhatWasDeclared(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);
        $this->seedCore(40);

        $this->provider->applyDeclaration($this->product, $this->warehouse, 40, [], false);
        $this->em->clear();

        self::assertSame(
            '40.0000',
            $this->details->availableTotal($this->product, $this->warehouse),
            '40 + 7 − 7: the books balance the moment the import finishes',
        );
    }

    /** It clears itself the moment someone finds the missing units and counts again. */
    public function testFindingTheMissingUnitsClearsTheNegativeRow(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);
        $this->seedCore(40);
        $this->provider->applyDeclaration($this->product, $this->warehouse, 40, [], false);

        $this->em->clear();
        $this->product = $this->em->find(ProductCore::class, $this->product->getId());
        $this->warehouse = $this->em->find(Warehouse::class, $this->warehouse->getId());

        // The next honest count is an import, and an import writes `quantity` before the provider
        // ever runs — so the found units arrive in the core row first, exactly as they do in
        // ProductImportService.
        $this->seedCore(47);
        $this->provider->applyDeclaration($this->product, $this->warehouse, 47, [], false);

        self::assertSame(0, $this->sentinelQuantity(), 'no ceremony needed — the next honest count zeroes it');
    }

    /**
     * `received` is a real term in the plug, not decoration (#572).
     *
     *     [PENDING] = (quantity + received) − SUM(all OTHER available rows)
     *
     * Solving from the file's figure alone — which is what this used to do — is right only while the
     * bucket is empty, and silently 50 out the moment it is not. Step 6 of
     * DimensionalReceivingAndImportSequenceCest is this same arithmetic reached the long way round.
     */
    public function testTheSentinelIsPluggedAgainstQuantityPlusReceivedNotTheFileAlone(): void
    {
        $this->seedBin('A-01', 230);
        $this->seedCore(quantity: 150, received: 50);

        $this->provider->applyDeclaration($this->product, $this->warehouse, 150, [], false);

        self::assertSame(-30, $this->sentinelQuantity(), '(150 + 50) − 230, not 150 − 230');
    }

    public function testBinRowsWithUnspecifiedLeftUntouched(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);

        $this->provider->applyDeclaration($this->product, $this->warehouse, 39, [['bin' => 'A-01', 'quantity' => '39']], false);

        self::assertSame(39, $this->binQuantity('A-01'));
        self::assertSame(7, $this->binQuantity('B-03'), 'a bin the file did not mention is not evidence it is empty');
    }

    public function testBinRowsWithUnspecifiedDeleted(): void
    {
        $this->seedBin('A-01', 40);
        $this->seedBin('B-03', 7);

        $warnings = $this->provider->applyDeclaration($this->product, $this->warehouse, 39, [['bin' => 'A-01', 'quantity' => '39']], true);

        self::assertSame(39, $this->binQuantity('A-01'));
        self::assertSame(0, $this->binQuantity('B-03'), 'the file was declared complete, so what it omits is empty');
        self::assertCount(1, $warnings, 'zeroing a row nobody mentioned is worth saying out loud');
    }

    /**
     * An unknown lot takes the policy's sentinel and a far-future expiry, rather than blocking the
     * row.
     *
     * The product is put on a **lot policy**; nothing is inferred from the rows it already has.
     * Before #573 this test seeded a real `inventory_lot` row for the product and relied on
     * `carriesLots()` noticing it — which is exactly the archaeology the policy replaces.
     */
    public function testAnUnknownLotTakesThePolicySentinelAndSortsLastUnderFefo(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, sentinelIn: '[PENDING]');

        $this->provider->applyDeclaration($this->product, $this->warehouse, 12, [['bin' => 'A-01', 'quantity' => '12']], false);
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT l.code, l.expiry, d.expect_resolution FROM inventory_detail d
             JOIN inventory_lot l ON l.id = d.lot_id
             JOIN warehouse_location loc ON loc.id = d.location_id
             WHERE d.product_id = ? AND loc.code = \'A-01\'',
            [$this->product->getId()],
        );

        self::assertSame('[PENDING]', $row['code'], 'greppable, so the admin worklist is one filter');
        self::assertStringStartsWith('9999', (string) $row['expiry'], 'an unknown date must sort LAST under FEFO, not first');
        self::assertSame(1, (int) $row['expect_resolution'], 'a substituted identity is somebody\'s to-do, and the worklist filters on it');
    }

    /**
     * The one that proves the inference is actually gone.
     *
     * This product has a real lot row and a real serialised detail row in its history — everything
     * `carriesLots()` and `carriesSerials()` used to look at — and no policy. It must come out with
     * a bare row, because history is not a declaration. If either helper ever comes back, this is
     * the test that fails.
     */
    public function testLotAndSerialHistoryNoLongerMakeAProductTracked(): void
    {
        $lot = (new InventoryLot())
            ->setProduct($this->product)
            ->setCode('REAL-LOT')
            ->setExpiry(new \DateTimeImmutable('2027-01-01'));
        $this->em->persist($lot);

        $serialised = $this->details->findOrCreate(
            $this->product,
            $this->warehouse,
            $this->location($this->warehouse, 'Z-99'),
            null,
            'SN-HISTORY',
            InventoryDetail::STATUS_AVAILABLE,
        );
        $serialised->setQuantity(1)->touch();
        $this->em->persist($serialised);
        $this->em->flush();

        $this->provider->applyDeclaration($this->product, $this->warehouse, 12, [['bin' => 'A-01', 'quantity' => '12']], false);
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT d.lot_id, d.serial, d.quantity, d.expect_resolution FROM inventory_detail d
             JOIN warehouse_location loc ON loc.id = d.location_id
             WHERE d.product_id = ? AND loc.code = \'A-01\'',
            [$this->product->getId()],
        );

        // fetchAssociative() returns FALSE when there is no row, and `false['lot_id']` is null with
        // only an E_WARNING that phpunit.dist.xml's <source> restriction never turns into a failure
        // (#594). So all three assertions below passed when applyDeclaration() wrote no detail row
        // at all — the twelve declared units simply vanishing. The row has to exist first.
        self::assertIsArray($row, 'applyDeclaration() has to have written a row in A-01 at all');
        self::assertSame(12, (int) $row['quantity'], 'and it holds the twelve units that were declared');

        self::assertNull($row['lot_id'], 'a lot row in the past is not a declaration that this product is lot-tracked');
        self::assertNull($row['serial'], 'nor is one serialised row in the past');
        self::assertSame(0, (int) $row['expect_resolution'], 'nothing was substituted, so there is nothing to come back for');
    }

    /**
     * The four tests below are one claim in four parts: **a sentinel is a label on the unidentified
     * row.** It is not a real lot code and not a real serial number, so receiving N unidentified
     * units produces ONE row of quantity N wearing the label, whatever `sentinel_in` reads and
     * whichever mode the policy is in.
     *
     * They are written as two pairs — lot and serial, blank sentinel and a value — because the
     * shapes have to match exactly. Same row, same quantity, same flag; the only difference between
     * the members of a pair is what the column reads.
     *
     * Lot mode, blank sentinel: one row, no batch, five units, flagged.
     */
    public function testALotSentinelThatIsBlankLeavesTheDimensionNull(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, sentinelIn: null);

        $this->provider->applyDeclaration($this->product, $this->warehouse, 5, [['bin' => 'A-01', 'quantity' => '5']], false);

        $row = $this->onlyRowInBin('A-01');

        self::assertNull($row['lot_code'], 'blank means the row simply carries NULL for that dimension');
        self::assertSame(5, (int) $row['quantity']);
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /** Lot mode, sentinel `[PENDING]`: the same one row, now wearing the label. */
    public function testALotSentinelThatIsAStringIsWrittenOntoTheSameSingleRow(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, sentinelIn: '[PENDING]');

        $this->provider->applyDeclaration($this->product, $this->warehouse, 5, [['bin' => 'A-01', 'quantity' => '5']], false);

        $row = $this->onlyRowInBin('A-01');

        self::assertSame('[PENDING]', $row['lot_code']);
        self::assertSame(5, (int) $row['quantity']);
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /** Serial mode, blank sentinel: one row, no serial, five units, flagged — not five rows. */
    public function testASerialSentinelThatIsBlankLeavesTheDimensionNull(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, sentinelIn: null);

        $this->provider->applyDeclaration($this->product, $this->warehouse, 5, [['bin' => 'A-01', 'quantity' => '5']], false);

        $row = $this->onlyRowInBin('A-01');

        self::assertNull($row['serial']);
        self::assertSame(5, (int) $row['quantity']);
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /**
     * Serial mode, sentinel `[PENDING]`: the same one row, now wearing the label.
     *
     * THE ONE TO GUARD, because the instinct that produced the bug this replaces will come back:
     * "a serial row is quantity 1, so a sentinel serial cannot hold five." It can, because it is not
     * a serial. `uniq_live_serial` is satisfied — one live row per serial VALUE, and there is one
     * row — and the quantity-1 ceiling is about genuine serial numbers, each of which identifies one
     * physical unit. A label identifies none.
     *
     * The wrong fixes both have obvious tells this catches: writing NULL anyway (the column would
     * not read `[PENDING]`), and generating `PENDING-1`…`PENDING-5` (there would be five rows).
     */
    public function testASerialSentinelThatIsAStringIsWrittenOntoTheSameSingleRow(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, sentinelIn: '[PENDING]');

        $this->provider->applyDeclaration($this->product, $this->warehouse, 5, [['bin' => 'A-01', 'quantity' => '5']], false);

        $row = $this->onlyRowInBin('A-01');

        self::assertSame('[PENDING]', $row['serial'], 'the label is written for serial exactly as it is for lot');
        self::assertSame(5, (int) $row['quantity'], 'one row of five, because a label identifies no unit');
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /**
     * And the rule the exemption must not have disabled: a **genuine** serial still holds one unit.
     *
     * `SN-REAL` is not the policy's sentinel, so it names one physical unit and five of them cannot
     * be on one row. If the exemption is ever widened to "serial mode skips the check", this is what
     * fails.
     */
    public function testAGenuineSerialIsStillHeldToOneUnit(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, sentinelIn: '[PENDING]');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SN-REAL/');

        $this->provider->applyDeclaration(
            $this->product,
            $this->warehouse,
            5,
            [['bin' => 'A-01', 'serial' => 'SN-REAL', 'quantity' => '5']],
            false,
        );
    }

    /**
     * The one row in a bin, with its lot code flattened onto it.
     *
     * `fetchAllAssociative` rather than `fetchAssociative`, because "there is exactly one row" is
     * half of what the pairs above are asserting — a suffix-generating implementation would produce
     * five, and a single-row fetch would happily return the first of them.
     *
     * @return array<string, mixed>
     */
    private function onlyRowInBin(string $code): array
    {
        $this->em->clear();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT l.code AS lot_code, d.serial, d.quantity, d.expect_resolution FROM inventory_detail d
             JOIN warehouse_location loc ON loc.id = d.location_id
             LEFT JOIN inventory_lot l ON l.id = d.lot_id
             WHERE d.product_id = ? AND loc.code = ?',
            [$this->product->getId(), $code],
        );

        self::assertCount(1, $rows, 'N unidentified units are ONE row of N, never N rows');

        return $rows[0];
    }

    /**
     * An untracked product is untouched by every part of this, which is the acceptance criterion.
     *
     * A warehouse mid-transition has real stock and no lot codes for any of it; a product nobody
     * opted in has neither, and must come out exactly as it went in.
     */
    public function testAnUntrackedProductKeepsNullDimensionsAndNoFlag(): void
    {
        $this->provider->applyDeclaration($this->product, $this->warehouse, 5, [['bin' => 'A-01', 'quantity' => '5']], false);
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT d.lot_id, d.serial, d.quantity, d.expect_resolution FROM inventory_detail d
             JOIN warehouse_location loc ON loc.id = d.location_id
             WHERE d.product_id = ? AND loc.code = \'A-01\'',
            [$this->product->getId()],
        );

        // Same guard as above (#594): without it a missing row satisfies every assertion here.
        self::assertIsArray($row, 'applyDeclaration() has to have written a row in A-01 at all');
        self::assertSame(5, (int) $row['quantity'], 'and it holds the five units that were declared');

        self::assertNull($row['lot_id']);
        self::assertNull($row['serial']);
        self::assertSame(0, (int) $row['expect_resolution']);
    }

    /** Points the product at a freshly-made policy. */
    private function putOnPolicy(string $mode, ?string $sentinelIn): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Policy ' . $mode . ' ' . ($sentinelIn ?? 'blank'))
            ->setMode($mode)
            ->setTrackIn(true)
            ->setSentinelIn($sentinelIn);
        $this->em->persist($policy);

        $this->product->setTrackingPolicy($policy);
        $this->em->flush();
    }
}
