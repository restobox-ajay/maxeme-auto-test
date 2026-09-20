<?php

namespace App\Twig;

use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CustomerNavExtension extends AbstractExtension
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer_nav', [$this, 'getCustomerNav']),
        ];
    }

    /** @return array{categories:list<array{id:string,name:string,count:int,descendantIds:list<string>,children:list<mixed>}>,totalProductsCount:int,uncategorizedCount:int} */
    public function getCustomerNav(): array
    {
        $categories = $this->entityManager->getRepository(ProductCategory::class)
            ->findBy(['status' => 'Visible'], ['name' => 'ASC']);

        $criteriaBase = [
            'visible' => true,
            'deleted' => false,
            'status' => ProductStatus::Active->value,
        ];

        /** @var array<int, ProductCategory> $categoriesById */
        $categoriesById = [];
        /** @var array<int, list<int>> $childrenMap */
        $childrenMap = [];

        foreach ($categories as $category) {
            if (!$category instanceof ProductCategory || $category->getId() === null) {
                continue;
            }

            $id = (int) $category->getId();
            $categoriesById[$id] = $category;
            $parentId = (int) ($category->getParent()?->getId() ?? 0);
            $childrenMap[$parentId][] = $id;
        }

        $buildTree = function (int $parentId) use (&$buildTree, $categoriesById, $childrenMap, $criteriaBase): array {
            $rows = [];
            $childIds = $childrenMap[$parentId] ?? [];

            usort($childIds, static function (int $leftId, int $rightId) use ($categoriesById): int {
                return strcasecmp($categoriesById[$leftId]?->getName() ?? '', $categoriesById[$rightId]?->getName() ?? '');
            });

            foreach ($childIds as $childId) {
                $category = $categoriesById[$childId] ?? null;
                if (!$category instanceof ProductCategory || $category->getId() === null) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) $category->getId(),
                    'name' => $category->getName(),
                    'count' => $this->countProductsForCategoryTree($category, $criteriaBase, $categoriesById, $childrenMap),
                    'descendantIds' => array_map('strval', $this->categoryAndDescendantIds($category, $categoriesById, $childrenMap)),
                    'children' => $buildTree($childId),
                ];
            }

            return $rows;
        };

        $totalProductsCount = (int) $this->entityManager->getRepository(ProductCore::class)->count($criteriaBase);
        $uncategorizedCount = (int) $this->entityManager->getRepository(ProductCore::class)->count($criteriaBase + [
            'category' => null,
        ]);

        return [
            'categories' => $buildTree(0),
            'totalProductsCount' => $totalProductsCount,
            'uncategorizedCount' => $uncategorizedCount,
        ];
    }

    /**
     * @param array<string, mixed> $criteriaBase
     * @param array<int, ProductCategory> $categoriesById
     * @param array<int, list<int>> $childrenMap
     */
    private function countProductsForCategoryTree(
        ProductCategory $category,
        array $criteriaBase,
        array $categoriesById,
        array $childrenMap
    ): int {
        $categoryIds = $this->categoryAndDescendantIds($category, $categoriesById, $childrenMap);
        if ($categoryIds === []) {
            return 0;
        }

        $qb = $this->entityManager->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->andWhere('p.category IN (:categoryIds)')
            ->setParameter('status', $criteriaBase['status'])
            ->setParameter('categoryIds', $categoryIds);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<int, ProductCategory> $categoriesById
     * @param array<int, list<int>> $childrenMap
     * @return list<int>
     */
    private function categoryAndDescendantIds(ProductCategory $category, array $categoriesById, array $childrenMap): array
    {
        if ($category->getId() === null) {
            return [];
        }

        $stack = [(int) $category->getId()];
        $seen = [];

        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === null || isset($seen[$id]) || !isset($categoriesById[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($childrenMap[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_map('intval', array_keys($seen));
    }
}
