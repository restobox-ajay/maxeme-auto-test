<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\ServiceCategory;
use App\Maxeme\Entity\ServiceItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCategory>
 */
final class ServiceCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCategory::class);
    }

    /** @return list<ServiceCategory> every category in tree order: each one followed by its subcategories, by name */
    public function findTree(): array
    {
        /** @var list<ServiceCategory> $all */
        $all = $this->createQueryBuilder('c')->orderBy('c.name', 'ASC')->addOrderBy('c.id', 'ASC')->getQuery()->getResult();

        $children = [];
        foreach ($all as $category) {
            $children[$category->getParent()?->getId() ?? 0][] = $category;
        }

        $ordered = [];
        $walk = static function (int $parentId) use (&$walk, &$ordered, $children): void {
            foreach ($children[$parentId] ?? [] as $category) {
                $ordered[] = $category;
                $walk((int) $category->getId());
            }
        };
        $walk(0);

        return $ordered;
    }

    /** @return array<int, int> category id => how many active services it has (its own, not its subcategories') */
    public function serviceCounts(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.category) AS category', 'COUNT(s.id) AS services')
            ->from(ServiceItem::class, 's')
            ->andWhere('s.active = true')->andWhere('s.category IS NOT NULL')
            ->groupBy('s.category')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [(int) $row['category'], (int) $row['services']], $rows), 1, 0);
    }

    public function countChildren(ServiceCategory $category): int
    {
        return $this->count(['parent' => $category]);
    }
}
