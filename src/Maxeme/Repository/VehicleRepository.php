<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
final class VehicleRepository extends ServiceEntityRepository
{
    /** Vehicles tab sort key => column. The legacy grid had no initial order, i.e. insertion order. */
    public const SORTS = [
        'id' => 'v.id',
        'manufacturer' => 'v.manufacturer',
        'model' => 'v.model',
        'year' => 'v.year',
        'vin' => 'v.vin',
        'color' => 'v.color',
        'note' => 'v.note',
    ];

    /** Vehicles list sort key => column, in its column order (first = default sort). */
    public const LIST_SORTS = [
        'customer' => 'c.firstName',
        'year' => 'v.year',
        'model' => 'v.model',
        'color' => 'v.color',
        'licensePlate' => 'v.licensePlate',
        'vin' => 'v.vin',
        'manufacturer' => 'v.manufacturer',
        'lastUpdated' => 'v.lastUpdated',
    ];

    /** The Vehicles list's column search boxes (filters[field]) => column. */
    public const LIST_FILTERS = [
        'customer' => "CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))",
        'year' => 'v.year',
        'model' => "CONCAT(COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''))",
        'color' => 'v.color',
        'licensePlate' => 'v.licensePlate',
        'vin' => 'v.vin',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /** @return ListPage<Vehicle> the client's vehicles that are not deleted */
    public function findPageForClient(Client $client, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('v')
            ->andWhere('v.client = :client')->setParameter('client', $client)
            ->andWhere('v.active = true');

        return ListPage::paginate($query, $list, self::SORTS, ['v.manufacturer', 'v.model', 'v.vin', 'v.color', 'v.note']);
    }

    /**
     * @return ListPage<Vehicle> active vehicles of active clients matching the sidebar Vehicle box
     *                           (VIN, licence plate, make, model, year, colour) and the grid's search box
     */
    public function findPage(SearchTerm $find, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('v')
            ->join('v.client', 'c')->addSelect('c')
            ->andWhere('v.active = true')
            ->andWhere('c.active = true');
        $find->apply($query, ['v.vin', 'v.licensePlate', 'v.manufacturer', 'v.model', 'v.year', 'v.color']);

        return ListPage::paginate($query, $list, self::LIST_SORTS, ['v.manufacturer', 'v.model', 'v.vin', 'v.licensePlate', 'v.color', 'c.firstName', 'c.lastName', 'c.preferredName'], self::LIST_FILTERS);
    }
}
