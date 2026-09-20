<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fee>
 */
class FeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fee::class);
    }

    /** @return Fee[] */
    public function findBySource(string $source): array
    {
        return $this->findBy(['source' => $source], ['id' => 'ASC']);
    }

    public function findBySlug(string $slug): ?Fee
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Lazy upsert: finds by slug; if missing, creates from $seedData and returns it.
     *
     * @param array{name: string, taxClass: string, source: string, defaultValue?: float} $seedData
     */
    public function ensureBySlug(string $slug, array $seedData): Fee
    {
        $fee = $this->findBySlug($slug);
        if ($fee !== null) {
            return $fee;
        }

        $fee = (new Fee())
            ->setSlug($slug)
            ->setName($seedData['name'])
            ->setTaxClass($seedData['taxClass'])
            ->setDefaultValue($seedData['defaultValue'] ?? 0.0)
            ->setSource($seedData['source']);

        $em = $this->getEntityManager();
        $em->persist($fee);
        $em->flush();

        return $fee;
    }
}
