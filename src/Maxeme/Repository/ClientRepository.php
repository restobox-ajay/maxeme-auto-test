<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
final class ClientRepository extends ServiceEntityRepository
{
    /** Client List sort key => column, in the grid's column order (first = default sort). */
    public const SORTS = [
        'firstName' => 'c.firstName',
        'lastName' => 'c.lastName',
        'preferredName' => 'c.preferredName',
        'email' => 'c.email',
        'homeNumber' => 'c.homeNumber',
        'workNumber' => 'c.workNumber',
        'cellNumber' => 'c.cellNumber',
        'address' => 'c.address',
        'note' => 'c.note',
        'lastUpdated' => 'c.lastUpdated',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @return ListPage<Client> active clients matching the sidebar Customer box (name, preferred
     *                          name, phone, address or invoice number) and the grid's search box
     */
    public function findPage(SearchTerm $find, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('c')->andWhere('c.active = true');
        $find->apply(
            $query,
            ['c.firstName', 'c.lastName', 'c.preferredName', 'c.address'],
            ['c.homeNumber', 'c.workNumber', 'c.cellNumber'],
            // One subquery per word, so each needs its own alias.
            static fn (QueryBuilder $query, string $param): string => sprintf('EXISTS (SELECT 1 FROM %1$s inv_%2$s WHERE inv_%2$s.client = c AND inv_%2$s.id = :%2$s)', Invoice::class, $param),
        );

        return ListPage::paginate($query, $list, self::SORTS, array_values(array_diff(self::SORTS, ['c.lastUpdated'])));
    }
}
