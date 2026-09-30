<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Part;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Part>
 */
final class PartRepository extends ServiceEntityRepository
{
    /** Parts Inventory sort key => column, in the grid's column order (legacy default: VIN # ascending). */
    public const SORTS = [
        'vin' => 'p.vin',
        'name' => 'p.name',
        'manufacturer' => 'p.manufacturer',
        'type' => 'p.type',
        'description' => 'p.description',
        'vendor' => 'p.vendor',
        'unitPrice' => 'p.unitPrice',
        'salePrice' => 'p.salePrice',
        'quantity' => 'p.quantity',
        'notes' => 'p.notes',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Part::class);
    }

    /** @return ListPage<Part> */
    public function findPage(ListQuery $list): ListPage
    {
        return ListPage::paginate(
            $this->createQueryBuilder('p')->andWhere('p.active = true'),
            $list,
            self::SORTS,
            ['p.vin', 'p.name', 'p.manufacturer', 'p.type', 'p.description', 'p.vendor', 'p.notes'],
        );
    }

    /** @return list<Part> active parts for the Physical Count sheet, by name, narrowed by its search box */
    public function findForCount(SearchTerm $find): array
    {
        $query = $this->createQueryBuilder('p')->andWhere('p.active = true')->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');
        $find->apply($query, ['p.vin', 'p.name', 'p.manufacturer', 'p.vendor', 'p.description']);

        return $query->getQuery()->getResult();
    }

    /** @return list<Part> active parts whose name contains $term (the invoice builder's autocomplete) */
    public function search(string $term, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->andWhere('LOWER(p.name) LIKE :term')->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
