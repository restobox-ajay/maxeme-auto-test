<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
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
}
