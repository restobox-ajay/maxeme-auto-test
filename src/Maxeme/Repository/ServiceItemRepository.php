<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceItem>
 */
final class ServiceItemRepository extends ServiceEntityRepository
{
    /** Services sort key => column (legacy default: Name ascending). */
    public const SORTS = [
        'name' => 's.name',
        'preferredName' => 's.preferredName',
        'price' => 's.price',
        'lastUpdated' => 's.lastUpdated',
    ];

    /** The column search boxes (filters[field]) => column. */
    public const FILTERS = [
        'name' => 's.name',
        'preferredName' => 's.preferredName',
    ];

    private const SEARCH_COLUMNS = ['s.name', 's.preferredName'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceItem::class);
    }

    /** @return ListPage<ServiceItem> the page's services, their lines (and the lines' items) loaded in one more query */
    public function findPage(ListQuery $list): ListPage
    {
        $page = ListPage::paginate($this->active(), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS);
        $this->loadLines($page->items);

        return $page;
    }

    /** @param list<ServiceItem> $services fills each one's lines collection, with their labour, product or fee */
    private function loadLines(array $services): void
    {
        if ($services === []) {
            return;
        }

        $this->createQueryBuilder('s')
            ->select('s', 'l', 'labour', 'product', 'fee')
            ->leftJoin('s.lines', 'l')
            ->leftJoin('l.labour', 'labour')
            ->leftJoin('l.product', 'product')
            ->leftJoin('l.govtFee', 'fee')
            ->andWhere('s IN (:services)')->setParameter('services', $services)
            ->getQuery()
            ->getResult();
    }

    /** @return list<ServiceItem> every active service of the current view (search boxes and sort; no page), lines loaded */
    public function findAllInView(ListQuery $list): array
    {
        $services = ListPage::filter($this->active(), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS)->getQuery()->getResult();
        foreach (array_chunk($services, 500) as $chunk) {
            $this->loadLines($chunk);
        }

        return $services;
    }

    private function active(): QueryBuilder
    {
        return $this->createQueryBuilder('s')->andWhere('s.active = true');
    }

    /** @return list<ServiceItem> active services whose name or preferred name contains $term */
    public function search(string $term, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->andWhere('LOWER(s.name) LIKE :term OR LOWER(s.preferredName) LIKE :term')->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
