<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentMethod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentMethod>
 */
class PaymentMethodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentMethod::class);
    }

    public function findBySlug(string $slug): ?PaymentMethod
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return PaymentMethod[] */
    public function findBySource(string $source): array
    {
        return $this->findBy(['source' => $source], ['id' => 'ASC']);
    }

    /**
     * Lazy upsert: finds by slug; if missing, creates from $seedData and returns it.
     *
     * @param array{name: string, source: string} $seedData
     */
    public function ensureBySlug(string $slug, array $seedData): PaymentMethod
    {
        $paymentMethod = $this->findBySlug($slug);
        if ($paymentMethod !== null) {
            return $paymentMethod;
        }

        $paymentMethod = (new PaymentMethod())
            ->setSlug($slug)
            ->setName($seedData['name'])
            ->setSource($seedData['source']);

        $em = $this->getEntityManager();
        $em->persist($paymentMethod);
        $em->flush();

        return $paymentMethod;
    }
}
