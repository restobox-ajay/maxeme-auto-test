<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Repository\TrackingPolicyRepository;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * What a tracking policy declares, and what a product with none gets (#573).
 *
 * The load-bearing case is the last one: `policyFor()` must answer for a product that points at
 * nothing without reading, writing or needing a single row — that is what keeps an instance which
 * never opts in behaving exactly as it did before this table existed.
 */
final class TrackingPolicyTest extends DoctrineIntegrationTestCase
{
    private TrackingPolicyRepository $policies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policies = self::getContainer()->get(TrackingPolicyRepository::class);
    }

    /** Lot and serial are exclusive in the LOGIC — one `mode`, not two booleans. */
    public function testLotAndSerialAreExclusive(): void
    {
        $policy = (new TrackingPolicy())->setName('Lot')->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);

        self::assertTrue($policy->tracksLotsInbound());
        self::assertFalse($policy->tracksSerialsInbound());

        $policy->setMode(TrackingPolicy::MODE_SERIAL);

        self::assertFalse($policy->tracksLotsInbound());
        self::assertTrue($policy->tracksSerialsInbound());
    }

    /** An unrecognised mode falls back to `none`, the mode that needs nothing to mean something. */
    public function testAnUnrecognisedModeReadsAsNone(): void
    {
        $policy = (new TrackingPolicy())->setMode('bin-and-pallet-and-vibes');

        self::assertSame(TrackingPolicy::MODE_NONE, $policy->getMode());
        self::assertTrue($policy->isInert());
    }

    /** In and out are independent: capture from the supplier, do not make a picker scan on the way out. */
    public function testInAndOutAreIndependent(): void
    {
        $inOnly = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(true)->setTrackOut(false);

        self::assertTrue($inOnly->tracksSerialsInbound());
        self::assertFalse($inOnly->tracksSerialsOutbound());

        $outOnly = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(false)->setTrackOut(true);

        self::assertFalse($outOnly->tracksSerialsInbound());
        self::assertTrue($outOnly->tracksSerialsOutbound());
    }

    /**
     * Expiry is its own Yes/No (#795): it holds in every mode and needs neither capture direction,
     * because a serialised or untracked product can carry its date on the detail row directly once
     * there is no lot for it to ride on.
     */
    public function testRequiresExpiryHoldsInEveryMode(): void
    {
        $none = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_NONE)->setRequiresExpiry(true);
        $serial = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_SERIAL)->setRequiresExpiry(true);
        $lot = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_LOT)->setRequiresExpiry(true);

        self::assertTrue($none->requiresExpiry());
        self::assertTrue($serial->requiresExpiry());
        self::assertTrue($lot->requiresExpiry());

        self::assertFalse((new TrackingPolicy())->setMode(TrackingPolicy::MODE_LOT)->requiresExpiry());
    }

    /** A blank sentinel is stored as NULL, so "blank" has exactly one representation. */
    public function testABlankSentinelIsNull(): void
    {
        $policy = (new TrackingPolicy())->setSentinelIn('   ')->setSentinelOut('PENDING202608');

        self::assertNull($policy->getSentinelIn());
        self::assertSame('PENDING202608', $policy->getSentinelOut());
    }

    /** A policy that tracks nothing in either direction is inert whatever its mode says. */
    public function testAModeWithNeitherDirectionIsInert(): void
    {
        $policy = (new TrackingPolicy())->setMode(TrackingPolicy::MODE_LOT);

        self::assertTrue($policy->isInert());
        self::assertFalse($policy->tracksLotsInbound());
        self::assertFalse($policy->tracksLotsOutbound());
    }

    /**
     * The acceptance criterion, as a unit test.
     *
     * A product pointing at nothing gets an inert policy, and getting one does not create a row —
     * so no code path this ticket adds can touch a product nobody opted in.
     */
    public function testAProductWithNoPolicyGetsAnInertOneAndNothingIsWritten(): void
    {
        $product = (new ProductCore())->setSku('NOPOL-1')->setName('Untracked');
        $this->em->persist($product);
        $this->em->flush();

        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tracking_policy');

        $policy = $this->policies->policyFor($product);

        self::assertTrue($policy->isInert());
        self::assertFalse($policy->tracksLotsInbound());
        self::assertFalse($policy->tracksSerialsInbound());
        self::assertNull($policy->getId(), 'reading a policy must not persist one');
        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tracking_policy'));
    }

    /** ensureDefault() is a lazy upsert by name, so calling it twice yields one row. */
    public function testEnsureDefaultIsIdempotent(): void
    {
        $first = $this->policies->ensureDefault();
        $second = $this->policies->ensureDefault();

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(TrackingPolicy::MODE_NONE, $first->getMode());
    }

    /**
     * The worklist's sentinel set always contains `[PENDING]`, whatever the policies say.
     *
     * The import has written that value onto sentinel bins and lots since #565, before any policy
     * existed to name it, and #573 deliberately migrates none of those rows. They are found rather
     * than rewritten, which only works if the default is always in the set.
     */
    public function testTheSentinelSetAlwaysIncludesTheHistoricalDefault(): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Cohort')
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setTrackIn(true)
            ->setSentinelIn('PENDING202608');
        $this->em->persist($policy);
        $this->em->flush();

        $sentinels = $this->policies->sentinelValues();

        self::assertContains(TrackingPolicy::DEFAULT_SENTINEL, $sentinels);
        self::assertContains('PENDING202608', $sentinels);
    }
}
