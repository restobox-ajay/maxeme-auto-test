<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackingPolicy>
 */
class TrackingPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackingPolicy::class);
    }

    /**
     * The policy for $product, or an unsaved inert one when it points at nothing.
     *
     * **Never null, and never persisted.** "No policy" is a complete answer — nothing is tracked —
     * and every caller null-checking it would be five copies of one default, of which one would
     * eventually get it backwards and start requiring a lot for everything.
     *
     * Reading a policy must not create one, which also means this stays correct on a database whose
     * `tracking_policy` table is empty: a product with no row behaves exactly as every product did
     * before #573.
     */
    public function policyFor(ProductCore $product): TrackingPolicy
    {
        return $product->getTrackingPolicy() ?? self::inert();
    }

    /**
     * The default policy row, created as `none` if it is missing.
     *
     * **No longer called from any read path.** `TrackingPolicyController::index()` and `::new()`
     * used to open with it, which meant the default policy was created by rendering the screen that
     * lists it. App\Service\ReferenceData\Seeders\TrackingPolicySeeder owns that row now and creates
     * it once, on the first admin login.
     *
     * Kept because a lazy upsert is still the right shape for a caller that genuinely needs the row
     * to exist RIGHT NOW and is not a page render — a fixture, a test, a data-generating command.
     * Do not reintroduce it into a controller.
     */
    public function ensureDefault(): TrackingPolicy
    {
        $policy = $this->findOneBy(['name' => TrackingPolicy::DEFAULT_NAME]);
        if ($policy instanceof TrackingPolicy) {
            return $policy;
        }

        $policy = (new TrackingPolicy())
            ->setName(TrackingPolicy::DEFAULT_NAME)
            ->setMode(TrackingPolicy::MODE_NONE);

        $em = $this->getEntityManager();
        $em->persist($policy);
        $em->flush();

        return $policy;
    }

    /** @return list<TrackingPolicy> */
    public function allByName(): array
    {
        /** @var list<TrackingPolicy> $rows */
        $rows = $this->createQueryBuilder('t')->orderBy('t.name', 'ASC')->getQuery()->getResult();

        return $rows;
    }

    /**
     * Every distinct non-blank sentinel any policy names, which is what the worklist filters on.
     *
     * Includes TrackingPolicy::DEFAULT_SENTINEL unconditionally, because the import has written that
     * value onto sentinel bins and lots since #565 — before any policy existed to name it — and
     * those rows are exactly the ones a worklist is for. No migration touches them (#573); they are
     * found rather than rewritten.
     *
     * @return list<string>
     */
    public function sentinelValues(): array
    {
        $values = [TrackingPolicy::DEFAULT_SENTINEL];

        foreach ($this->allByName() as $policy) {
            foreach ([$policy->getSentinelIn(), $policy->getSentinelOut()] as $sentinel) {
                if ($sentinel !== null) {
                    $values[] = $sentinel;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /** How many products point at $policy — the guard on deleting one. */
    public function productCount(TrackingPolicy $policy): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(p.id) FROM ' . ProductCore::class . ' p WHERE p.trackingPolicy = :policy'
        )->setParameter('policy', $policy)->getSingleScalarResult();
    }

    /** An unsaved `none` policy: the answer for a product that points at nothing. */
    private static function inert(): TrackingPolicy
    {
        return (new TrackingPolicy())
            ->setName(TrackingPolicy::DEFAULT_NAME)
            ->setMode(TrackingPolicy::MODE_NONE);
    }
}
