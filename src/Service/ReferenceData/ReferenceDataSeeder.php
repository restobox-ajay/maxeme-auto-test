<?php

declare(strict_types=1);

namespace App\Service\ReferenceData;

use App\Contract\ReferenceData\ReferenceDataSeedOutcome;
use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\ReferenceDataSeedMark;
use App\Repository\ReferenceDataSeedMarkRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every registered {@see ReferenceDataSeederInterface} that has not run before.
 *
 * Adding a seeder is registering nothing here — see {@see ReferenceDataSeederInterface}'s docblock
 * for the mechanism. This class only decides WHETHER each one runs, wraps it so it cannot damage
 * the request it is running inside, and records that it did.
 *
 * Shaped after {@see \App\Service\Onboarding\OnboardingChecklistService}, which collects
 * `app.onboarding_check` the same way and defends itself against one bad implementation the same
 * way. Where it diverges is that this one WRITES, so it adds a transaction, a claim and a mark.
 *
 * ## The steady state, which is the state it is in on all but one login ever
 *
 * {@see self::run()} issues ONE query — {@see ReferenceDataSeedMarkRepository::seededKeys()} — and
 * compares its result against the registered keys in PHP. When every key is marked it returns
 * without opening a transaction, without touching the EntityManager, and without calling a single
 * seeder. No seeder's `seed()` body runs, so no `findOneBy()` per row happens either; runs 2..n are
 * one SELECT over a table with one short row per seeder.
 *
 * ## Concurrency: the claim is the INSERT, not the check
 *
 * `seededKeys()` above is an optimisation, NOT the guard — two logins can both read the same empty
 * set. The guard is the UNIQUE index on `reference_data_seed_mark.seeder_key`. Each seeder runs
 * inside one transaction that begins by INSERTing its mark; the loser of a race has that INSERT
 * refused by the database and the rollback takes its half-written rows with it. Under WAL with
 * `busy_timeout = 500` ({@see \App\Doctrine\SqliteWalMiddleware}) the second writer either waits for
 * the first to commit and then loses on the constraint, or fails to get the write lock at all and
 * is reported as a failure and retried on the next login. Both outcomes are "no duplicate rows".
 *
 * The mark is INSERTed FIRST, before the rows, rather than after. Order does not affect atomicity —
 * either the whole transaction lands or none of it does — but claiming first means the loser of a
 * race discovers it before doing the work rather than after.
 *
 * ## Failure: one seeder cannot take the others, or the request, down
 *
 * A seeder that throws has its transaction rolled back, which leaves its mark UNWRITTEN, so it is
 * retried on the next login rather than being recorded as done with rows missing. The exception is
 * logged with the exception attached and the run continues with the next seeder. This is deliberate
 * and it is the same trade `OnboardingChecklistService::runOneSafely()` makes: the caller is the
 * login path, and no reference list is worth costing an administrator their session. A seeder that
 * is permanently broken therefore costs one failed attempt per login until somebody fixes it, which
 * is the right way round — the alternative, marking it done so it stops retrying, would hide a
 * missing list forever.
 *
 * The rollback undoes STATEMENTS. A seeder that throws part way through `seed()` has issued none —
 * its rows are `persist()`ed and pending — so {@see self::discardPendingWork()} gives back exactly
 * what that seeder added. Without it the next seeder's `flush()`, inside the next transaction,
 * writes another bundle's half-written catalogue under its own name.
 *
 * A failed `flush()` closes the EntityManager, which would break every later seeder AND the rest of
 * the request. {@see self::resetIfClosed()} reopens it — from EVERY failure arm, including a unique
 * violation, which is where one bundle's duplicate reference row used to disable every seeder behind
 * it. Because the container hands out
 * `EntityManagerInterface` as a resettable lazy proxy, services that were injected with the old one
 * — including the seeders still to run — see the new one without being rebuilt.
 */
final class ReferenceDataSeeder
{
    /** @param iterable<ReferenceDataSeederInterface> $seeders */
    public function __construct(
        #[AutowireIterator('app.reference_data_seeder')]
        private readonly iterable $seeders,
        private readonly EntityManagerInterface $em,
        private readonly ReferenceDataSeedMarkRepository $marks,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Seed whatever has not been seeded, and say what happened to each.
     *
     * Safe to call from anywhere and at any frequency — that is the contract the login listener
     * relies on. The return value is ordered by seeder key so a caller rendering or logging it does
     * not depend on container iteration order.
     *
     * @return list<ReferenceDataSeedOutcome>
     */
    public function run(): array
    {
        $seeders = $this->registeredByKey();
        if ($seeders === []) {
            return [];
        }

        // The one query of the steady state. Everything below it is skipped when all keys are here.
        $alreadySeeded = $this->marks->seededKeys();

        $outcomes = [];
        foreach ($seeders as $key => $seeder) {
            $outcomes[] = isset($alreadySeeded[$key])
                ? ReferenceDataSeedOutcome::alreadySeeded($key, $seeder->getLabel())
                : $this->runOneSafely($key, $seeder);
        }

        return $outcomes;
    }

    /** True when at least one registered seeder has never run. Cheap — the same single query. */
    public function hasPendingSeeders(): bool
    {
        $alreadySeeded = $this->marks->seededKeys();

        foreach (array_keys($this->registeredByKey()) as $key) {
            if (!isset($alreadySeeded[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registered seeders, keyed and ordered by their declared key.
     *
     * A duplicate key is a programming error that would otherwise present as "one of my two
     * bundles never seeds", so it is refused loudly here rather than silently letting whichever
     * the container yielded second claim the other's mark.
     *
     * @return array<string, ReferenceDataSeederInterface>
     */
    private function registeredByKey(): array
    {
        $byKey = [];

        foreach ($this->seeders as $seeder) {
            if (!$seeder instanceof ReferenceDataSeederInterface) {
                continue;
            }

            $key = $seeder->getKey();
            if (isset($byKey[$key])) {
                throw new \LogicException(sprintf(
                    'Two reference data seeders declare the key "%s": %s and %s. A key is the natural '
                    . 'key of its seed mark, so it must be unique across the whole application.',
                    $key,
                    $byKey[$key]::class,
                    $seeder::class,
                ));
            }

            if ($key === '' || \strlen($key) > ReferenceDataSeedMark::KEY_MAX_LENGTH) {
                throw new \LogicException(sprintf(
                    'Reference data seeder %s declares an unusable key "%s": it must be 1-%d bytes.',
                    $seeder::class,
                    $key,
                    ReferenceDataSeedMark::KEY_MAX_LENGTH,
                ));
            }

            $byKey[$key] = $seeder;
        }

        ksort($byKey);

        return $byKey;
    }

    /**
     * One seeder, inside one transaction, with every way it can go wrong accounted for.
     *
     * The connection-level transaction (rather than `EntityManager::wrapInTransaction()`) is what
     * lets the claim be a raw INSERT: a unique violation raised by the ORM's own flush would close
     * the EntityManager, and a lost race is an ordinary, expected event that must not.
     */
    private function runOneSafely(string $key, ReferenceDataSeederInterface $seeder): ReferenceDataSeedOutcome
    {
        $connection = $this->em->getConnection();

        // Everything the unit of work was ALREADY carrying before this seeder touched it. A seeder
        // that fails has to give back exactly what it added and nothing else — see
        // discardPendingWork(), which is the other half of what the transaction below promises.
        $pendingBefore = $this->pendingInsertions();

        // Whether the mark INSERT got through. It is the FIRST statement in the transaction, so a
        // unique violation raised before this is set is the seed-mark's — a lost race — and one
        // raised after it is the seeder's own reference row hitting its own table's index. The two
        // mean opposite things to an operator and must not be reported as the same thing.
        $claimed = false;

        $connection->beginTransaction();

        try {
            $connection->insert('reference_data_seed_mark', [
                'seeder_key' => $key,
                'rows_created' => 0,
                'seeded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $claimed = true;

            $created = $seeder->seed();
            $this->em->flush();

            // Stated after the fact because the count is not known at claim time and the claim
            // cannot wait for it. Same row, same transaction — not a write to existing data in the
            // sense QUEUE.md means, which is data that existed before this transaction began.
            if ($created > 0) {
                $connection->update(
                    'reference_data_seed_mark',
                    ['rows_created' => $created],
                    ['seeder_key' => $key],
                );
            }

            $connection->commit();

            $this->logger->info('Reference data seeder "{key}" created {rows} row(s).', [
                'key' => $key,
                'rows' => $created,
            ]);

            return ReferenceDataSeedOutcome::seeded($key, $seeder->getLabel(), $created);
        } catch (UniqueConstraintViolationException $e) {
            if ($claimed) {
                // NOT a lost race. The mark INSERT is the first statement above and it got through,
                // so the unique index this hit belongs to a table the SEEDER writes: the bundle
                // shipped a reference row something else already owns. Calling that
                // `claimed_elsewhere` reported a bundle defect as the benign outcome of a race
                // somebody else is completing — which nobody looks at and which is deliberately not
                // logged as an error, so the defect stayed silent on every login forever.
                return $this->containFailure($key, $seeder, $e, $pendingBefore);
            }

            // Somebody else claimed this key between our read of the marks and our INSERT. The
            // rollback takes our rows with it, which is the entire point of claiming inside the
            // transaction that writes them.
            $this->rollBackQuietly();

            return ReferenceDataSeedOutcome::claimedElsewhere($key, $seeder->getLabel());
        } catch (\Throwable $e) {
            return $this->containFailure($key, $seeder, $e, $pendingBefore);
        }
    }

    /**
     * One seeder's failure, contained so that it costs the run nothing but itself.
     *
     * Three things, and the middle one is the one that was missing. The SQL goes back, which leaves
     * the mark unwritten so the seeder is retried. The seeder's PENDING work goes back, because a
     * rollback undoes statements and a seeder fails holding entities that have never been statements
     * yet. And the EntityManager is reopened if the failure closed it, without which every seeder
     * behind this one dies with "The EntityManager is closed."
     *
     * @param array<int, object> $pendingBefore the unit of work as it stood before this seeder ran
     */
    private function containFailure(
        string $key,
        ReferenceDataSeederInterface $seeder,
        \Throwable $e,
        array $pendingBefore,
    ): ReferenceDataSeedOutcome {
        $this->rollBackQuietly();
        $this->discardPendingWork($pendingBefore);
        $this->resetIfClosed();

        // The exception is logged with the object attached, not returned: a caller may put this
        // message in front of an administrator, and an exception message can carry a file path,
        // a DSN or a value that has no business being on screen. Same rule, same reason, as
        // OnboardingChecklistService::runOneSafely().
        $this->logger->error('Reference data seeder "{key}" failed: {message}', [
            'key' => $key,
            'message' => $e->getMessage(),
            'exception' => $e,
        ]);

        return ReferenceDataSeedOutcome::failed(
            $key,
            $seeder->getLabel(),
            'could not be seeded. See the error log for details. It will be retried.',
        );
    }

    /**
     * Give back the entities the failed seeder persisted, and nothing else.
     *
     * `rollBackQuietly()` undoes SQL. A seeder that throws PART WAY has not issued any: `seed()` is
     * documented as `persist()` calls and a count, so its rows are sitting in the unit of work
     * waiting for a `flush()` that never came. They do not simply evaporate — the NEXT seeder's
     * `flush()`, inside the NEXT transaction, writes them. That produced rows on disk from a seeder
     * reported as failed AND left unmarked, so the system believed it had never run while its data
     * was already there.
     *
     * Detaching only what appeared since the snapshot, rather than `clear()`, is the whole point:
     * this runs on the login path, inside somebody's request, and clearing the unit of work would
     * detach entities this seeder never touched and nothing here has any business discarding.
     *
     * @param array<int, object> $before
     */
    private function discardPendingWork(array $before): void
    {
        // A failed flush closes the EntityManager, and resetIfClosed() replaces it outright — which
        // takes the whole unit of work with it. Nothing to detach, and detaching on a closed manager
        // would throw from inside a failure handler.
        if (!$this->em->isOpen()) {
            return;
        }

        foreach ($this->pendingInsertions() as $id => $entity) {
            if (!isset($before[$id])) {
                $this->em->detach($entity);
            }
        }
    }

    /**
     * The entities currently scheduled for insertion, keyed by object identity.
     *
     * @return array<int, object>
     */
    private function pendingInsertions(): array
    {
        if (!$this->em->isOpen()) {
            return [];
        }

        $pending = [];
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            $pending[spl_object_id($entity)] = $entity;
        }

        return $pending;
    }

    /**
     * Roll back without letting the rollback itself become the failure.
     *
     * The connection can already be out of a transaction — SQLite aborts one itself on some errors —
     * and throwing from the handler would replace a logged, recoverable seeder failure with an
     * unhandled exception on the login path, which is the one thing this class must not do.
     */
    private function rollBackQuietly(): void
    {
        try {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Rolling back a failed reference data seed also failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Reopen the EntityManager if a failed flush closed it.
     *
     * Without this, one throwing seeder would take down every seeder after it AND the rest of the
     * request — the user would be seeded-or-not AND logged out with a 500. `resetManager()` swaps a
     * fresh manager into the lazy proxy the container injected, so the seeders still to run pick it
     * up without being reconstructed.
     */
    private function resetIfClosed(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        try {
            $this->registry->resetManager();
        } catch (\Throwable $e) {
            $this->logger->error('Could not reopen the EntityManager after a failed seed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
