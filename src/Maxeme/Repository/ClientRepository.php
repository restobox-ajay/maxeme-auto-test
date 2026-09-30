<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
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
        'phone1' => 'c.phone1',
        'phone2' => 'c.phone2',
        'phone3' => 'c.phone3',
        'phone4' => 'c.phone4',
        'lastUpdated' => 'c.lastUpdated',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @return ListPage<Client> active clients matching the sidebar Customer box (name, preferred
     *                          name, phone, any address in the book or invoice number) and the grid's search box
     */
    public function findPage(SearchTerm $find, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('c')->andWhere('c.active = true');
        $find->apply(
            $query,
            ['c.firstName', 'c.lastName', 'c.preferredName'],
            ['c.phone1', 'c.phone2', 'c.phone3', 'c.phone4'],
            // One subquery per word, so each needs its own alias.
            static fn (QueryBuilder $query, string $param): string => sprintf('EXISTS (SELECT 1 FROM %1$s inv_%2$s WHERE inv_%2$s.client = c AND inv_%2$s.id = :%2$s)', Invoice::class, $param),
            static fn (QueryBuilder $query, string $param): string => sprintf(
                'EXISTS (SELECT 1 FROM %1$s adr_%2$s WHERE adr_%2$s.client = c AND (LOWER(adr_%2$s.addressLine1) LIKE :%2$s OR LOWER(adr_%2$s.addressLine2) LIKE :%2$s OR LOWER(adr_%2$s.city) LIKE :%2$s OR LOWER(adr_%2$s.postalCode) LIKE :%2$s OR LOWER(adr_%2$s.label) LIKE :%2$s))',
                ClientAddress::class,
                $param,
            ),
        );

        return ListPage::paginate($query, $list, self::SORTS, array_values(array_diff(self::SORTS, ['c.lastUpdated'])));
    }

    /**
     * Loads the address books and notes of $clients in two queries, so a list showing each
     * client's first address and newest note does not query once per row.
     *
     * @param list<Client> $clients
     */
    public function loadAddressesAndNotes(array $clients): void
    {
        if ($clients === []) {
            return;
        }
        foreach (['addresses', 'notes'] as $collection) {
            $this->createQueryBuilder('c')
                ->select('c', 'x')
                ->leftJoin('c.' . $collection, 'x')
                ->andWhere('c IN (:clients)')->setParameter('clients', $clients)
                ->getQuery()->getResult();
        }
    }
}
