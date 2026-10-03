<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceReminderQueueEntry>
 */
final class ServiceReminderQueueRepository extends ServiceEntityRepository
{
    /** Service Reminder Queue sort key => column (first = default sort, newest first). */
    public const SORTS = [
        'queuedAt' => 'q.queuedAt',
        'sentAt' => 'q.sentAt',
        'email' => 'q.email',
        'client' => 'c.lastName',
        'service' => 'q.serviceName',
        'reminderDays' => 'q.reminderDays',
        'status' => 'q.status',
    ];

    /** The column search boxes (filters[field]) => column; status is matched exactly. */
    public const FILTERS = [
        'email' => 'q.email',
        'phone' => "CONCAT(COALESCE(c.phone1, ''), ' ', COALESCE(c.phone2, ''), ' ', COALESCE(c.phone3, ''), ' ', COALESCE(c.phone4, ''))",
        'client' => "CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))",
        'vehicle' => "CONCAT(COALESCE(v.year, ''), ' ', COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''), ' ', COALESCE(v.licensePlate, ''))",
        'service' => 'q.serviceName',
    ];

    private const SEARCH_COLUMNS = ['q.email', 'q.serviceName', 'c.firstName', 'c.lastName', 'c.preferredName', 'v.licensePlate'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceReminderQueueEntry::class);
    }

    /** @return ListPage<ServiceReminderQueueEntry> */
    public function findPage(ListQuery $list): ListPage
    {
        return ListPage::paginate($this->inView($list), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS);
    }

    /** @return list<ServiceReminderQueueEntry> every entry of the current view (no page), for its CSV */
    public function findAllInView(ListQuery $list): array
    {
        return ListPage::filter($this->inView($list), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS)->getQuery()->getResult();
    }

    public function hasEntriesFor(RepairOrder $repairOrder): bool
    {
        return $this->count(['repairOrder' => $repairOrder]) > 0;
    }

    /** @return list<ServiceReminderQueueEntry> the queued reminders of a client's vehicle */
    public function findQueuedFor(Client $client, Vehicle $vehicle): array
    {
        return $this->findBy(['client' => $client, 'vehicle' => $vehicle, 'status' => ServiceReminderQueueStatus::Queued]);
    }

    /** @return list<ServiceReminderQueueEntry> queued reminders due by $now, oldest first */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('c', 'v')
            ->join('q.client', 'c')
            ->join('q.vehicle', 'v')
            ->andWhere('q.status = :queued')->setParameter('queued', ServiceReminderQueueStatus::Queued)
            ->andWhere('q.dueAt <= :now')->setParameter('now', $now)
            ->orderBy('q.dueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function inView(ListQuery $list): QueryBuilder
    {
        $query = $this->createQueryBuilder('q')
            ->addSelect('c', 'v', 'r')
            ->join('q.client', 'c')
            ->join('q.vehicle', 'v')
            ->leftJoin('q.repairOrder', 'r');

        $status = $list->filters['status'] ?? '';
        if ($status !== '') {
            $query->andWhere('q.status = :status')->setParameter('status', $status);
        }

        return $query;
    }
}
