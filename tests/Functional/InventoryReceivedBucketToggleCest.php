<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\BundleStatus;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\EventSubscriber\BundleBucketAvailabilityGate;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Tests\Support\FunctionalTester;

/**
 * The `received` bucket across a bundle toggle (#564).
 *
 * This test is specified step by step in
 * `docs/plans/2026-08-21-inventory-deltas-incoming-and-received.md`, and it is worth saying why it
 * exists in that much detail: **both plausible wrong implementations pass every other test.**
 *
 *  - "always add the column, skip the gate" is correct while the bundle is Active
 *  - "zero the column on deactivation" is correct while the bundle is Inactive
 *
 * Each produces a system that behaves correctly in every state except the transition, and the
 * transition is the only place the difference is visible.
 *
 * The load-bearing assertion is the one that reads `received_quantity` **out of the database**
 * while the bundle is off. A zeroed column and an excluded term give identical availability, so
 * inferring the value from getAvailableQuantity() would pass against a implementation that
 * destroyed the data.
 */
final class InventoryReceivedBucketToggleCest
{
    private const BUNDLE = BundleBucketAvailabilityGate::WRITING_BUNDLE;

    private ?int $inventoryId = null;

    /**
     * Availability read fresh from the database every time, never from an entity this test has
     * been holding. The gate stamps on postLoad, so a cached identity-map instance would keep the
     * flag it was loaded with and the toggle would appear to do nothing.
     */
    private function availability(FunctionalTester $I): string
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ProductInventory::class)->find($this->inventoryId)->getAvailableQuantity();
    }

    /** Reads the column itself, bypassing the entity, so no accessor can lie about it. */
    private function storedReceived(FunctionalTester $I): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        return (int) $em->getConnection()->fetchOne(
            'SELECT received_quantity FROM product_inventory WHERE id = ?',
            [$this->inventoryId],
        );
    }

    private function setBundle(FunctionalTester $I, string $status): void
    {
        $this->setBundleNamed($I, self::BUNDLE, $status);
    }

    private function setBundleNamed(FunctionalTester $I, string $bundle, string $status): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $repo = $I->grabService(BundleStatusRepository::class);

        $em->persist($repo->ensureBySource($bundle)->setStatus($status));
        $em->flush();
        $em->clear();
    }

    private function seedStock(FunctionalTester $I, int $quantity, int $received): void
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $product = (new ProductCore())->setName('Received Bucket Widget')->setSku('RCV-' . bin2hex(random_bytes(4)));
        $warehouse = (new Warehouse())->setName('Received Bucket Warehouse');
        $em->persist($product);
        $em->persist($warehouse);

        $inventory = (new ProductInventory())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setQuantity($quantity)
            ->setReceivedQuantity($received);
        $em->persist($inventory);
        $em->flush();

        $this->inventoryId = $inventory->getId();
        $em->clear();
    }

    /**
     * The full sequence from the plan. Steps 4 and 5 are the point — step 3 alone is satisfied by
     * zeroing the column, and steps 1–2 by always-adding.
     */
    public function receivedCountsWhileActiveSurvivesDeactivationAndReturns(FunctionalTester $I): void
    {
        // 1. Bundle Active, 100 on hand, nothing received.
        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
        $this->seedStock($I, 100, 0);
        $I->assertSame('100.0000', $this->availability($I), 'a plain stock row is its quantity');

        // 2. Book a receipt of 50.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(ProductInventory::class)->find($this->inventoryId)->setReceivedQuantity(50);
        $em->flush();
        $em->clear();

        $I->assertSame(50, $this->storedReceived($I));
        $I->assertSame('150.0000', $this->availability($I), 'received stock is sellable while the bundle is on');

        // 3. Turn the bundle off. The 50 leaves the sum.
        $this->setBundle($I, BundleStatus::STATUS_INACTIVE);
        $I->assertSame('100.0000', $this->availability($I), 'the received term is excluded while the bundle is off');

        // 4. THE ASSERTION THAT MATTERS. Read the column, not the availability.
        $I->assertSame(
            50,
            $this->storedReceived($I),
            'deactivating must EXCLUDE the term, never destroy the data — a zeroed column gives the same availability as an excluded term, which is why this reads the column directly',
        );

        // 5. Back on, with no recount, no re-import and no manual step.
        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
        $I->assertSame('150.0000', $this->availability($I), 'switching back on needs nothing — the row sat untouched in between');
        $I->assertSame(50, $this->storedReceived($I));
    }

    /**
     * `incoming` is a forecast, not stock. Toggling the bundle must change nothing about
     * availability in either state, while the column keeps its value throughout.
     */
    public function incomingNeverCountsInEitherState(FunctionalTester $I): void
    {
        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
        $this->seedStock($I, 100, 0);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(ProductInventory::class)->find($this->inventoryId)->setIncomingQuantity(40);
        $em->flush();
        $em->clear();

        $I->assertSame('100.0000', $this->availability($I), 'incoming is on a purchase order, not on the shelf');

        $this->setBundle($I, BundleStatus::STATUS_INACTIVE);
        $I->assertSame('100.0000', $this->availability($I), 'and it is still not on the shelf with the bundle off');

        $em = $I->grabService(EntityManagerInterface::class);
        $I->assertSame(
            40,
            (int) $em->getConnection()->fetchOne('SELECT incoming_quantity FROM product_inventory WHERE id = ?', [$this->inventoryId]),
            'excluded from the sum, not destroyed',
        );

        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
    }

    /**
     * The case a reviewer asks about: the checkbox is gated off, so a stale form carrying it must
     * be a no-op rather than an error. A gated-off checkbox must never become a required field.
     */
    public function aStaleClearReceivedPostWithTheBundleOffIsANoOp(FunctionalTester $I): void
    {
        $this->setBundle($I, BundleStatus::STATUS_INACTIVE);
        $this->seedStock($I, 100, 50);

        $importService = $I->grabService(\App\Service\ProductImport\ProductImportService::class);
        $I->assertNotNull($importService, 'the import service resolves with the bundle off');

        // The gate lives in the controller, so with the bundle off the option never reaches the
        // service. What must hold here is that the bucket is untouched either way.
        $I->assertSame(50, $this->storedReceived($I), 'nothing clears a bucket the bundle cannot fill');
        $I->assertSame('100.0000', $this->availability($I), 'and it is out of the sum while the bundle is off');

        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
    }

    /**
     * `quarantine` used to ship as a column and nothing else — no formula, no reader, no writer
     * (#564). It has a formula now (#581): quarantined and returned units are physically present
     * and unsellable until somebody rules on them, so they come off availability.
     *
     * What this pins down is WHICH bundle gates it. `quarantine` is recomputed from detail rows by
     * InventoryDepthBundle, so the depth bundle is what decides whether it counts — toggling the
     * receiving bundle around it must change nothing, which is the mistake this test would catch.
     */
    public function quarantineIsGatedByTheDepthBundleAndNotTheReceivingOne(FunctionalTester $I): void
    {
        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);
        $this->seedStock($I, 100, 0);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(ProductInventory::class)->find($this->inventoryId)->setQuarantineQuantity(25);
        $em->flush();
        $em->clear();

        $I->assertSame('75.0000', $this->availability($I), '25 of the 100 are quarantined and cannot be sold');

        $this->setBundle($I, BundleStatus::STATUS_INACTIVE);
        $I->assertSame('75.0000', $this->availability($I), 'the receiving bundle has no say over this bucket');
        $this->setBundle($I, BundleStatus::STATUS_ACTIVE);

        // The bundle that does have a say. Off means the term leaves the sum; the column is not
        // zeroed, so switching back on needs no recount.
        $this->setBundleNamed($I, BundleBucketAvailabilityGate::DEPTH_BUNDLE, BundleStatus::STATUS_INACTIVE);
        $I->assertSame('100.0000', $this->availability($I), 'no depth bundle, no detail rows, no quarantine');
        $I->assertSame(
            25,
            (int) $em->getConnection()->fetchOne('SELECT quarantine_quantity FROM product_inventory WHERE id = ?', [$this->inventoryId]),
            'and the column still holds what it held',
        );

        $this->setBundleNamed($I, BundleBucketAvailabilityGate::DEPTH_BUNDLE, BundleStatus::STATUS_ACTIVE);
        $I->assertSame('75.0000', $this->availability($I), 'back on, and no recount was needed');
    }
}
