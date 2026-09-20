<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Command;

use App\Service\QuantityScale;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Expiry is not self-executing: status only ever changes via a movement, so this sweep is what takes
 * expired stock off sale. **Convention: `expiry` is the last usable day.**
 */
final class ExpireLotsCommandTest extends DoctrineIntegrationTestCase
{
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $bin;
    private InventoryLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('EXP-1')->setName('Perishable')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(1);
        $this->em->persist($this->bin);

        $this->lot = (new InventoryLot())->setProduct($this->product)->setCode('L-1')->setExpiry(new \DateTimeImmutable('2026-09-30'));
        $this->em->persist($this->lot);
        $this->em->flush();

        self::getContainer()->get(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed')
                ->receive($this->product, new DetailKey($this->warehouse, $this->bin, $this->lot), 12)
        );
    }

    private function sweep(string $asOf, bool $dryRun = false): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:inventory-depth:expire-lots'));

        $input = ['--as-of' => $asOf];
        if ($dryRun) {
            $input['--dry-run'] = true;
        }

        $tester->execute($input);

        return $tester;
    }

    private function coreQuantity(): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        // What a customer may still buy, read off the entity rather than re-spelled here.
        //
        // Nothing in this test is sold or on order, so the number reduces to
        // `SUM(available detail)` — an expiring lot leaves the sellable total because its units
        // move to `expired`, which `write_off` sums (#581).
        //
        // Asking the entity means a bucket added later cannot leave this helper quietly asserting
        // an older invariant, which is what happened twice.
        return QuantityScale::canonical($row instanceof ProductInventory ? $row->getAvailableQuantity() : 0);
    }

    /** Stock dated the 30th is sellable through the 30th. */
    public function testStockIsStillSellableOnItsExpiryDate(): void
    {
        $tester = $this->sweep('2026-09-30');
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame('12.0000', $this->coreQuantity(), 'the expiry date itself is a usable day');
    }

    /** …and unsellable from the 1st, at which point the sweep moves it out of the total. */
    public function testTheDayAfterExpiryTheStockLeavesTheSellableTotal(): void
    {
        $tester = $this->sweep('2026-10-01');
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame('0.0000', $this->coreQuantity(), 'expired stock is present but not sellable');

        /** @var list<InventoryDetail> $expired */
        $expired = $this->em->getRepository(InventoryDetail::class)->findBy(['status' => InventoryDetail::STATUS_EXPIRED]);
        self::assertCount(1, $expired);
        self::assertSame('12.0000', $expired[0]->getQuantity());
        // Expired stock keeps its bin — it is still physically sitting there and somebody has to go
        // and get it. Only sold/scrapped/lost drop their location.
        self::assertSame('A-01', $expired[0]->getLocation()?->getCode());
    }

    /**
     * The second sweep (#795): a row with no lot but its own expiry — a serialised or untracked
     * product's date lives on `d.expiry` rather than on a batch — is swept in the same pass, gets
     * its own `status_change` group (there is no lot to fold it under), and keeps its bin exactly as
     * the lot sweep does.
     */
    public function testALotlessRowWithItsOwnExpiryIsSweptInTheSamePass(): void
    {
        $untracked = (new ProductCore())
            ->setSku('EXP-2')->setName('Perishable, no lot')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($untracked);
        $this->em->flush();

        self::getContainer()->get(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-nolot')
                ->receive(
                    $untracked,
                    new DetailKey($this->warehouse, $this->bin, null, null, InventoryDetail::STATUS_AVAILABLE, false, new \DateTimeImmutable('2026-09-30')),
                    5,
                ),
        );

        $tester = $this->sweep('2026-10-01');
        $tester->assertCommandIsSuccessful();
        $this->em->clear();

        /** @var list<InventoryDetail> $expired */
        $expired = $this->em->getRepository(InventoryDetail::class)->findBy(['status' => InventoryDetail::STATUS_EXPIRED]);
        self::assertCount(2, $expired, 'both the lot sweep and the lot-less sweep ran in the same pass');

        $lotless = array_values(array_filter($expired, static fn (InventoryDetail $d): bool => $d->getLot() === null));
        self::assertCount(1, $lotless);
        self::assertSame('5.0000', $lotless[0]->getQuantity());
        self::assertSame('2026-09-30', $lotless[0]->getExpiry()?->format('Y-m-d'));
        self::assertSame('A-01', $lotless[0]->getLocation()?->getCode(), 'expired stock keeps its bin, same as the lot sweep');

        // Its own group — there is no lot to fold it under, unlike the first sweep's per-lot group.
        $groups = $this->em->getRepository(InventoryMovementGroup::class)->findBy(['type' => InventoryMovementGroup::TYPE_STATUS_CHANGE]);
        self::assertCount(2, $groups);
    }

    /** A real status_change group, not a silent filter — that is the whole point of the sweep. */
    public function testItWritesARealStatusChangeGroupNamingTheLot(): void
    {
        $this->sweep('2026-10-01');
        $this->em->clear();

        /** @var list<InventoryMovementGroup> $groups */
        $groups = $this->em->getRepository(InventoryMovementGroup::class)->findBy(['type' => InventoryMovementGroup::TYPE_STATUS_CHANGE]);

        self::assertCount(1, $groups);
        self::assertStringContainsString('L-1', (string) $groups[0]->getReason());
        self::assertStringContainsString('2026-09-30', (string) $groups[0]->getReason(), 'the reason names the lot by code AND expiry');
        self::assertSame('System', $groups[0]->getActor());
    }

    public function testADryRunReportsWithoutWriting(): void
    {
        $tester = $this->sweep('2026-10-01', true);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would move', $tester->getDisplay());

        $this->em->clear();
        self::assertSame('12.0000', $this->coreQuantity(), 'a dry run must write nothing');
    }

    /** Re-running the same day re-applies nothing: the operation id is per lot per day. */
    public function testRunningTwiceOnTheSameDayIsIdempotent(): void
    {
        $this->sweep('2026-10-01');
        $this->sweep('2026-10-01');

        $this->em->clear();
        self::assertSame('0.0000', $this->coreQuantity());
        self::assertCount(1, $this->em->getRepository(InventoryDetail::class)->findBy(['status' => InventoryDetail::STATUS_EXPIRED]));
        self::assertCount(1, $this->em->getRepository(InventoryMovementGroup::class)->findBy(['type' => InventoryMovementGroup::TYPE_STATUS_CHANGE]));
    }
}
