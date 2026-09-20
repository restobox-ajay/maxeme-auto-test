<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\ReferenceData\ReferenceDataSeedOutcome;
use App\Entity\AdminUser;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Repository\ReferenceDataSeedMarkRepository;
use App\Service\ReferenceData\ReferenceDataSeeder;
use App\Service\ReferenceData\Seeders\UnitOfMeasureSeeder;
use App\Tests\Support\Fixtures\ClaimsAUnitCodeReferenceDataSeeder;
use App\Tests\Support\Fixtures\HalfWrittenReferenceDataSeeder;
use App\Tests\Support\Fixtures\LateBundleReferenceDataSeeder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Reference-data seeding, attacked (#624).
 *
 * `ReferenceDataSeedingCest` is green and covers the happy path thoroughly: seeding ten times,
 * a deleted row staying deleted, an emptied list staying empty, a seeder that throws on its first
 * statement, and a simulated race for a seed MARK. Nothing here repeats any of that.
 *
 * What it leaves open, and what each case below attacks:
 *
 * | case                                            | the gap                                          |
 * |-------------------------------------------------|--------------------------------------------------|
 * | `aSeederThatPersistsRowsThenThrows...`          | the shipped throwing fixture persists NOTHING before it throws, so "nothing is left half-written" — the property the transaction exists for — was never actually asserted |
 * | `aSeedersOwnDuplicateRowIsReportedFailed...`    | the race test contests a seed KEY; two seeders contesting the same reference ROW is a different collision and is unarbitrated |
 * | `aUnitSomebodyCreatedByHand...`                 | every existing case starts from an empty table or a seeded one, never from a table a person had already put one row in |
 *
 * Each builds the REAL {@see ReferenceDataSeeder} by hand with a chosen list of seeders — the
 * established pattern in this suite, and the only way to vary "which bundles are installed". The
 * marks table, the entity tables and the transactions are all genuine.
 */
final class ReferenceDataSeedingUnderAttackCest
{
    private const HAND_MADE_CODE = 'ZZQ';
    private const CONTESTED_CODE = 'ADVX';

    public function _before(FunctionalTester $I): void
    {
        HalfWrittenReferenceDataSeeder::reset();

        // The suite reuses one Cest instance across a class's methods and this fixture's switch is
        // process-global, so it is reset on the way in AND on the way out.
        $this->loginAsAdmin($I);
    }

    public function _after(FunctionalTester $I): void
    {
        HalfWrittenReferenceDataSeeder::reset();
    }

    /**
     * A seeder that persists its first rows and THEN throws leaves none of them.
     *
     * This is the property the whole transaction in `ReferenceDataSeeder::runOneSafely()` exists
     * for, and the shipped suite cannot demonstrate it: `ThrowingReferenceDataSeeder::seed()` throws
     * on its first statement, so there is nothing persisted for a rollback to undo. It proves the
     * mark is unwritten and the seeder is retried — both true and both already covered — and says
     * nothing about half-written ROWS.
     *
     * The realistic failure is a catalogue whose fifth row is bad: four persisted, then a throw. If
     * the rollback did not reach them, the retry would find them already there, create fewer rows
     * than it counted, and the customer would be left with a silently partial catalogue that no
     * later run ever completes — because the mark written by the successful retry closes the door.
     */
    public function aSeederThatPersistsRowsThenThrowsLeavesNoneOfThem(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $halfWritten = new HalfWrittenReferenceDataSeeder($em);
        // A sibling with rows of its own, to prove the failure is contained rather than taking the
        // whole run down with it.
        $sibling = new LateBundleReferenceDataSeeder($em);

        // ── PART ONE: the failing seeder ALONE. Nothing flushes after the throw, so its persisted
        //    rows never reach disk and the guarantee holds. This is the control, and it is what
        //    makes part two's result mean something specific rather than "rollback is broken".
        HalfWrittenReferenceDataSeeder::failOnNextRun();

        $aloneStates = [];
        foreach ($this->buildSeeder($I, [$halfWritten])->run() as $outcome) {
            $aloneStates[$outcome->key] = $outcome->state;
        }

        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_FAILED,
            $aloneStates[HalfWrittenReferenceDataSeeder::KEY] ?? '',
            'the seeder that threw is reported as failed',
        );
        $I->assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                HalfWrittenReferenceDataSeeder::POLICY_NAMES,
            ),
            'with nothing else in the run, the rows persisted before the throw never reached disk',
        );

        // A fresh request. Without this the leftovers from part one would be flushed by part two and
        // the two halves could not be told apart.
        $em->clear();

        // ── PART TWO: the SAME failure, with one innocent seeder after it in the same run.
        HalfWrittenReferenceDataSeeder::failOnNextRun();

        $states = [];
        foreach ($this->buildSeeder($I, [$halfWritten, $sibling])->run() as $outcome) {
            $states[$outcome->key] = $outcome->state;
        }

        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_FAILED,
            $states[HalfWrittenReferenceDataSeeder::KEY] ?? '',
            'the seeder that threw is still reported as failed',
        );

        // THE assertion. Both rows were persisted before the throw; neither may survive it.
        $I->assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                HalfWrittenReferenceDataSeeder::POLICY_NAMES,
            ),
            'DEFECT: the rows a FAILED seeder persisted are on disk. Its own transaction rolled back '
            . 'correctly — part one proves that — but the throw happened before its flush(), so the '
            . 'entities stayed pending in the EntityManager\'s unit of work, and the NEXT seeder\'s '
            . 'flush() inside the NEXT transaction wrote them. rollBackQuietly() undoes SQL; it does '
            . 'not clear the unit of work, and resetIfClosed() only acts when the EntityManager was '
            . 'actually closed, which a plain exception does not do',
        );

        $I->assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', [HalfWrittenReferenceDataSeeder::KEY]),
            'and its mark is unwritten, so it will be retried',
        );

        // Contained: the sibling in the same run seeded normally. Without this the assertions above
        // would also pass if the entire run had collapsed.
        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_SEEDED,
            $states[LateBundleReferenceDataSeeder::KEY] ?? '',
            'the sibling seeder in the same run was unaffected',
        );
        $I->assertSame(
            \count(LateBundleReferenceDataSeeder::POLICY_NAMES),
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                LateBundleReferenceDataSeeder::POLICY_NAMES,
            ),
            'and its rows really are on disk',
        );

        // ── the retry. The bad row is fixed and the same seeder runs again.
        HalfWrittenReferenceDataSeeder::succeedFromNowOn();

        $retryStates = [];
        foreach ($this->buildSeeder($I, [$halfWritten, $sibling])->run() as $outcome) {
            $retryStates[$outcome->key] = $outcome->state;
        }

        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_SEEDED,
            $retryStates[HalfWrittenReferenceDataSeeder::KEY] ?? '',
            'the retry seeded',
        );
        $I->assertSame(
            \count(HalfWrittenReferenceDataSeeder::POLICY_NAMES),
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                HalfWrittenReferenceDataSeeder::POLICY_NAMES,
            ),
            'and the catalogue is COMPLETE — the retry created every row, not just the ones after the failure',
        );
        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_ALREADY_SEEDED,
            $retryStates[LateBundleReferenceDataSeeder::KEY] ?? '',
            'while the sibling, already marked, wrote nothing the second time',
        );
    }

    /**
     * Two bundles whose seeders both claim the same reference ROW.
     *
     * The shipped race test contests a seed KEY, which the unique index on
     * `reference_data_seed_mark.seeder_key` arbitrates. This is the other collision and nothing
     * arbitrates it: two DIFFERENT keys, each marking itself correctly, both wanting one
     * `unit_of_measure.code`. Every shipped seeder avoids it by looking its natural key up first,
     * but that is a convention each seeder must remember — `ReferenceDataSeederInterface::seed()`
     * asks only for `persist()` calls and a count — so the question is what it costs when one does
     * not.
     *
     * What must be true whatever the design decides: the contested row exists exactly ONCE, and the
     * collision stays inside the bundle that caused it.
     */
    public function aSeedersOwnDuplicateRowIsReportedFailedAndLeavesLaterSeedersRunning(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $em = $I->grabService(EntityManagerInterface::class);

        // Keys chosen so the run order is deterministic and readable; the outcome is asserted by
        // key rather than by position regardless.
        $first = new ClaimsAUnitCodeReferenceDataSeeder($em, 'aaa.claims_unit_first', self::CONTESTED_CODE, 'First claimant');
        $second = new ClaimsAUnitCodeReferenceDataSeeder($em, 'bbb.claims_unit_second', self::CONTESTED_CODE, 'Second claimant');
        $bystanderSeeder = new LateBundleReferenceDataSeeder($em);

        $states = [];
        $outcomes = [];
        foreach ($this->buildSeeder($I, [$first, $second, $bystanderSeeder])->run() as $outcome) {
            $states[$outcome->key] = $outcome->state;
            $outcomes[$outcome->key] = $outcome;
        }

        // One row, whoever won it. Two would be the integrity failure.
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM unit_of_measure WHERE code = ?', [self::CONTESTED_CODE]),
            'unit_of_measure holds the contested code exactly once',
        );

        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_SEEDED,
            $states['aaa.claims_unit_first'] ?? '',
            'the first claimant seeded',
        );

        // The loser must not be recorded as having done its job — its row is not there under its
        // name, and a mark would say it was.
        $I->assertNotSame(
            ReferenceDataSeedOutcome::STATE_SEEDED,
            $states['bbb.claims_unit_second'] ?? '',
            'the second claimant did not create the row, so it must not report that it did',
        );

        // **DEFECT.** What it reports instead is `claimed_elsewhere`, which means something specific
        // and untrue: "another process claimed this seeder's KEY between our read and our INSERT".
        // Nothing of the sort happened — this seeder holds its key uncontested, and what failed was
        // its own `unit_of_measure.code` hitting the entity table's unique index.
        //
        // `ReferenceDataSeeder.php:209` catches `UniqueConstraintViolationException` and assumes the
        // only unique index reachable inside that try block is `reference_data_seed_mark.seeder_key`.
        // Every entity table a seeder writes has its own, and the catch cannot tell them apart.
        //
        // It matters because the two states mean opposite things operationally: `claimed_elsewhere`
        // is the benign outcome of a race that somebody else is completing, so nobody looks at it,
        // and it is not logged as an error. A bundle shipping a colliding row is therefore silent.
        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_FAILED,
            $states['bbb.claims_unit_second'] ?? '',
            'DEFECT: a collision on the seeder\'s OWN reference row is reported as claimed_elsewhere '
            . '— the benign "another process got there first" outcome — because '
            . 'ReferenceDataSeeder.php:209 catches UniqueConstraintViolationException and attributes '
            . 'it to the seed-mark INSERT, which is not the only unique index in that transaction',
        );

        // **DEFECT.** The collision must stay inside the bundle that caused it. It does not: the
        // failed flush closes the EntityManager, and the `UniqueConstraintViolationException` arm at
        // ReferenceDataSeeder.php:209-215 calls rollBackQuietly() but NOT resetIfClosed() — which
        // the generic arm two lines below it, at :216-218, does call.
        //
        // So every seeder after the collision dies with "The EntityManager is closed." One bundle
        // shipping one duplicate code takes down every bundle that seeds after it, in key order,
        // on every login.
        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_SEEDED,
            $states[LateBundleReferenceDataSeeder::KEY] ?? '',
            'DEFECT: the seeder that ran after the collision failed with "The EntityManager is '
            . 'closed" — ReferenceDataSeeder.php:209-215 rolls back but never calls resetIfClosed(), '
            . 'unlike the generic catch at :216-218, so one bundle\'s duplicate row disables every '
            . 'seeder behind it',
        );
        $I->assertSame(
            \count(LateBundleReferenceDataSeeder::POLICY_NAMES),
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                LateBundleReferenceDataSeeder::POLICY_NAMES,
            ),
            'and its rows landed',
        );

        // What the loser's state means for tomorrow: an unmarked seeder is retried on EVERY admin
        // login, and this one can never succeed, so it fails and logs forever. Recorded here as the
        // fact it is, next to the count that proves the data itself is sound.
        $loserIsMarked = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?',
            ['bbb.claims_unit_second'],
        );
        $I->assertSame(
            0,
            $loserIsMarked,
            'the loser is left unmarked, so it is retried on every login — a collision one bundle '
            . 'ships becomes a permanent per-login failure, which is the cost worth knowing',
        );
    }

    /**
     * A unit somebody created by hand, before the catalogue was ever seeded.
     *
     * `UnitOfMeasureSeeder::seed()` gates on the table being COMPLETELY empty, and its docblock
     * defends that at length: a per-code top-up would re-create units a customer had deliberately
     * deleted on the upgrade that introduced the marks table. Every existing case starts from an
     * empty table or an already-seeded one, so the gate itself has never been exercised from the
     * state that actually triggers it.
     *
     * Two things must hold, and the second is the sharp one:
     *
     *  1. the row the person made is not touched — not renamed, not re-factored, not replaced;
     *  2. the seeder MARKS ITSELF anyway, having created nothing. So one hand-made row suppresses
     *     all four shipped units permanently: no later login revisits the decision, because the
     *     mark says the job is done.
     *
     * That is the documented design working. It is pinned here because the consequence is easy to
     * reach by accident — an admin adding one unit on a fresh install, before their first login
     * finishes — and impossible to discover afterwards from the screen, which simply shows a short
     * list.
     */
    public function aUnitSomebodyCreatedByHandSuppressesTheShippedCatalogueForGood(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM unit_of_measure'), 'the table starts empty');

        // Somebody adds one unit of their own before anything seeded.
        $em->persist(
            (new UnitOfMeasure())
                ->setCode(self::HAND_MADE_CODE)
                ->setName('Hand Made Drum')
                ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
                ->setFactorToFamilyBase('1')
                ->setRoundingPrecision('1')
        );
        $em->flush();

        $handMadeId = (int) $connection->fetchOne('SELECT id FROM unit_of_measure WHERE code = ?', [self::HAND_MADE_CODE]);
        $I->assertGreaterThan(0, $handMadeId);

        $outcomes = [];
        foreach ($this->buildSeeder($I, [$I->grabService(UnitOfMeasureSeeder::class)])->run() as $outcome) {
            $outcomes[$outcome->key] = $outcome;
        }

        $unitOutcome = $outcomes['core.unit_of_measure'] ?? null;
        $I->assertNotNull($unitOutcome, 'the unit seeder ran');
        $I->assertSame(0, $unitOutcome->rowsCreated, 'it created nothing, because the table was not empty');

        // 1 — the person's row is exactly as they left it.
        $row = $connection->fetchAssociative('SELECT id, code, name FROM unit_of_measure WHERE code = ?', [self::HAND_MADE_CODE]);
        $I->assertSame($handMadeId, (int) $row['id'], 'unit_of_measure.id is the same row, not a replacement');
        $I->assertSame('Hand Made Drum', (string) $row['name'], 'and unit_of_measure.name was not restated');

        // 2 — and it is the ONLY row. None of the four shipped units were added beside it.
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM unit_of_measure'),
            'the shipped catalogue was suppressed entirely by the one hand-made row',
        );
        foreach (['EA', 'BOX', 'PR', 'BAG'] as $shipped) {
            $I->assertSame(
                0,
                (int) $connection->fetchOne('SELECT COUNT(*) FROM unit_of_measure WHERE code = ?', [$shipped]),
                'the shipped unit ' . $shipped . ' was not created',
            );
        }

        // The door is closed behind it: the mark is written despite nothing being created, so no
        // later login reconsiders.
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', ['core.unit_of_measure']),
            'and the seeder marked itself, so the suppression is permanent',
        );

        // Proof that the mark is what closes it, rather than the run having been a no-op: a second
        // run reports ALREADY_SEEDED rather than looking at the table again.
        $second = [];
        foreach ($this->buildSeeder($I, [$I->grabService(UnitOfMeasureSeeder::class)])->run() as $outcome) {
            $second[$outcome->key] = $outcome->state;
        }
        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_ALREADY_SEEDED,
            $second['core.unit_of_measure'] ?? '',
            'the second run never even asked the table',
        );
        $I->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM unit_of_measure'), 'and still one row');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);

        $admin = (new AdminUser())->setEmail('qa-admin+seed-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'qa-local-only-2026'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The REAL seeder, over a chosen list of seeders.
     *
     * The same construction `ReferenceDataSeedingCest` uses, and the only way to vary which bundles
     * a run can see — a container-tagged service is always present and so can never be the "bundle
     * that is not installed yet" or "the bundle that collides".
     *
     * @param list<\App\Contract\ReferenceData\ReferenceDataSeederInterface> $seeders
     */
    private function buildSeeder(FunctionalTester $I, array $seeders): ReferenceDataSeeder
    {
        return new ReferenceDataSeeder(
            $seeders,
            $I->grabService(EntityManagerInterface::class),
            $I->grabService(ReferenceDataSeedMarkRepository::class),
            $I->grabService('doctrine'),
            $I->grabService(LoggerInterface::class),
        );
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
