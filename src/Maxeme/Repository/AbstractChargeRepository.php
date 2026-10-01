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
}
