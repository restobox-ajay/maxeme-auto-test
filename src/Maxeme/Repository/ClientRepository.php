<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Dto\ClientSearchCriteria;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
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

    /** @return ListPage<Client> active clients matching the sidebar criteria and the grid's search box */
    public function findPage(ClientSearchCriteria $criteria, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('c')->andWhere('c.active = true');
        $this->applyCriteria($query, $criteria);

        return ListPage::paginate($query, $list, self::SORTS, array_values(array_diff(self::SORTS, ['c.lastUpdated'])));
    }

    private function applyCriteria(QueryBuilder $query, ClientSearchCriteria $criteria): void
    {
        if ($criteria->invoiceNumber !== '') {
            $number = ltrim($criteria->invoiceNumber, '0');
            $query->andWhere(sprintf('EXISTS (SELECT 1 FROM %s inv WHERE inv.client = c AND inv.id = :invoiceNumber)', Invoice::class))
                ->setParameter('invoiceNumber', ctype_digit($number) ? (int) $number : 0);
        }

        foreach (['firstName' => 'c.firstName', 'lastName' => 'c.lastName', 'preferredName' => 'c.preferredName'] as $property => $column) {
            if ($criteria->{$property} !== '') {
                $query->andWhere(sprintf('LOWER(%s) LIKE :%s', $column, $property))
                    ->setParameter($property, mb_strtolower($criteria->{$property}) . '%');
            }
        }

        if ($criteria->phoneNumber !== '') {
            $query->andWhere('c.homeNumber = :phone OR c.workNumber = :phone OR c.cellNumber = :phone')
                ->setParameter('phone', $criteria->phoneNumber);
        }

        // Any of the client's vehicles, deleted ones included (as in the legacy join): a vehicle
        // that was sold or scrapped still identifies its owner.
        foreach (['vin' => 'v.vin', 'licensePlate' => 'v.licensePlate', 'manufacturer' => 'v.manufacturer', 'model' => 'v.model'] as $property => $column) {
            if ($criteria->{$property} !== '') {
                $alias = 'v_' . $property;
                $query->andWhere(sprintf(
                    'EXISTS (SELECT 1 FROM %s %s WHERE %s.client = c AND LOWER(%s) = :%s)',
                    Vehicle::class,
                    $alias,
                    $alias,
                    str_replace('v.', $alias . '.', $column),
                    $property,
                ))->setParameter($property, mb_strtolower($criteria->{$property}));
            }
        }
    }
}
