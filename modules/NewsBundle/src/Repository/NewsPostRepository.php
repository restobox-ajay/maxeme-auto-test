<?php

declare(strict_types=1);

namespace NewsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use NewsBundle\Entity\NewsPost;

/**
 * @extends ServiceEntityRepository<NewsPost>
 */
class NewsPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsPost::class);
    }

    /** @return NewsPost[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['publishedAt' => 'DESC']);
    }

    /** @return NewsPost[] */
    public function findLatestPublished(int $limit = 3): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
