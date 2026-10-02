<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * The Labour and Government Fees lists: code, name and the column search boxes.
 *
 * @template T of AbstractCharge
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractChargeRepository extends ServiceEntityRepository
{
    /** Sort key => column (first = default sort). */
    public const SORTS = [
        'code' => 'x.code',
        'name' => 'x.name',
        'price' => 'x.price',
        'active' => 'x.active',
    ];

    /** The column search boxes (filters[field]) => column. */
    public const FILTERS = [
        'code' => 'x.code',
        'name' => 'x.name',
    ];

    /** @return ListPage<T> */
    public function findPage(ListQuery $list): ListPage
    {
        return ListPage::paginate($this->createQueryBuilder('x'), $list, static::SORTS, ['x.code', 'x.name'], static::FILTERS);
    }

    /** @return T|null the one with this code (any case), active or not */
    public function findOneByCode(string $code): ?AbstractCharge
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /**
     * @param array<string, mixed> $criteria more field => value conditions
     *
     * @return list<T> active ones whose code or name contains $term, by name
     */
    public function searchActive(string $term, array $criteria = [], int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('x')
            ->andWhere('x.active = true')
            ->andWhere('LOWER(x.code) LIKE :term OR LOWER(x.name) LIKE :term')->setParameter('term', '%' . mb_strtolower(trim($term)) . '%')
            ->orderBy('x.name', 'ASC')
            ->setMaxResults($limit);
        foreach ($criteria as $field => $value) {
            $qb->andWhere(sprintf('x.%s = :%s', $field, $field))->setParameter($field, $value);
        }

        return $qb->getQuery()->getResult();
    }
}
