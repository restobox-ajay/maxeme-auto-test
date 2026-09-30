<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\ClientNote;
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
        'phone' => 'c.phone1',
        'lastUpdated' => 'c.lastUpdated',
    ];

    /** The Client List's header filter row (filters[field]), in column order. */
    public const FILTERS = ['firstName', 'lastName', 'preferredName', 'email', 'phone', 'address', 'note'];

    private const PHONES = ['c.phone1', 'c.phone2', 'c.phone3', 'c.phone4'];

    /** The grid's search box looks in these. */
    private const SEARCH_COLUMNS = ['c.firstName', 'c.lastName', 'c.preferredName', 'c.email', ...self::PHONES];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @return ListPage<Client> active clients matching the sidebar Customer box (name, preferred
     *                          name, phone, any address in the book or invoice number), the grid's
     *                          search box and its header filter row
     */
    public function findPage(SearchTerm $find, ListQuery $list): ListPage
    {
        return ListPage::paginate($this->inView($find, $list), $list, self::SORTS, self::SEARCH_COLUMNS);
    }

    /**
     * @return list<Client> every client of the Client List's current view (search, search box,
     *                      filter row and sort; no page), with address books and notes loaded
     */
    public function findAllInView(SearchTerm $find, ListQuery $list): array
    {
        /** @var list<Client> $clients */
        $clients = ListPage::filter($this->inView($find, $list), $list, self::SORTS, self::SEARCH_COLUMNS)->getQuery()->getResult();
        foreach (array_chunk($clients, 500) as $chunk) {
            $this->loadAddressesAndNotes($chunk);
        }

        return $clients;
    }

    /** Active clients matching $find and the list's filter row. */
    private function inView(SearchTerm $find, ListQuery $list): QueryBuilder
    {
        $query = $this->createQueryBuilder('c')->andWhere('c.active = true');
        $find->apply(
            $query,
            ['c.firstName', 'c.lastName', 'c.preferredName'],
            self::PHONES,
            // One subquery per word, so each needs its own alias.
            static fn (QueryBuilder $query, string $param): string => sprintf('EXISTS (SELECT 1 FROM %1$s inv_%2$s WHERE inv_%2$s.client = c AND inv_%2$s.id = :%2$s)', Invoice::class, $param),
            self::anyAddressLike(...),
        );

        foreach (array_intersect_key($list->filters, array_flip(self::FILTERS)) as $field => $text) {
            $param = 'filter_' . $field;
            if ($field !== 'phone') {
                $query->setParameter($param, '%' . mb_strtolower($text) . '%');
            }
            $condition = match ($field) {
                'address' => self::anyAddressLike($query, $param),
                'note' => sprintf('EXISTS (SELECT 1 FROM %1$s note_f WHERE note_f.client = c AND LOWER(note_f.text) LIKE :%2$s)', ClientNote::class, $param),
                'phone' => self::anyPhoneLike($query, $param, $text),
                default => sprintf('LOWER(%s) LIKE :%s', self::SORTS[$field], $param),
            };
            $query->andWhere($condition);
        }

        return $query;
    }

    /** Any of the four phones contains $text, compared on digits when it has any ("604 555" finds "(604) 555-0100"). */
    private static function anyPhoneLike(QueryBuilder $query, string $param, string $text): string
    {
        $digits = preg_replace('/\D+/', '', $text) ?? '';
        if ($digits === '') {
            $query->setParameter($param, '%' . mb_strtolower($text) . '%');

            return implode(' OR ', array_map(static fn (string $column): string => sprintf('LOWER(%s) LIKE :%s', $column, $param), self::PHONES));
        }
        $query->setParameter($param . '_digits', '%' . $digits . '%');

        return implode(' OR ', array_map(static fn (string $column): string => sprintf('%s LIKE :%s_digits', SearchTerm::stripped($column), $param), self::PHONES));
    }

    /** Any address in the client's book contains the text in :$param. */
    private static function anyAddressLike(QueryBuilder $query, string $param): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s adr_%2$s WHERE adr_%2$s.client = c AND (LOWER(adr_%2$s.addressLine1) LIKE :%2$s OR LOWER(adr_%2$s.addressLine2) LIKE :%2$s OR LOWER(adr_%2$s.city) LIKE :%2$s OR LOWER(adr_%2$s.province) LIKE :%2$s OR LOWER(adr_%2$s.postalCode) LIKE :%2$s OR LOWER(adr_%2$s.label) LIKE :%2$s))',
            ClientAddress::class,
            $param,
        );
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
