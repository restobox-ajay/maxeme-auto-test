<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appointment>
 */
final class AppointmentRepository extends ServiceEntityRepository
{
    /** Past Appointments sort key => column (legacy grid: newest first by default). */
    public const PAST_SORTS = [
        'startTime' => 'a.startTime',
        'repairOrder' => 'ro.id',
        'amount' => self::AMOUNT_SORT,
        'note' => 'a.note',
    ];

    /** The hidden select the "$" columns sort by (see selectAmount()). */
    private const AMOUNT_SORT = 'amount_sort';

    /** The technician shown (Appointment::getShownTechnician(): its own, else its repair order's), by name. */
    private const TECHNICIAN = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(atech.firstName, ''), ' ', COALESCE(atech.lastName, ''))), ''), atech.username, atech.email, "
        . "NULLIF(TRIM(CONCAT(COALESCE(mtech.firstName, ''), ' ', COALESCE(mtech.lastName, ''))), ''), mtech.username, mtech.email, '')";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /**
     * Appointments starting in [$from, $to) (UTC), with their client and vehicle. Deleted clients'
     * appointments are included, as in the legacy feed.
     *
     * @return list<Appointment>
     */
    public function startingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // With the repair order: the event's description and links (its services no longer colour it).
        return $this->createQueryBuilder('a')
            ->addSelect('c', 'v', 'ro')
            ->join('a.client', 'c')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.startTime >= :from AND a.startTime < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Appointments › List sort key => column (first = default sort). */
    public const LIST_SORTS = [
        'start' => 'a.startTime',
        'status' => 'a.status',
        'type' => 'a.type',
        // As the columns read (mockup: the client's name; year, make and model), case-insensitive.
        'client' => "LOWER(TRIM(CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))))",
        'vehicle' => "LOWER(TRIM(CONCAT(COALESCE(v.year, ''), ' ', COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''))))",
        'repairOrder' => 'ro.id',
        'technician' => 'LOWER(' . self::TECHNICIAN . ')',
        'amount' => self::AMOUNT_SORT,
    ];

    /** Appointments › List column search boxes (filters[field]) => column. */
    public const LIST_FILTERS = [
        'status' => 'a.status',
        'type' => 'a.type',
        'client' => "CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))",
        'phone' => "CONCAT(COALESCE(c.phone1, ''), ' ', COALESCE(c.phone2, ''))",
        'vehicle' => "CONCAT(COALESCE(v.year, ''), ' ', COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''))",
        'description' => "CONCAT(COALESCE(ro.name, ''), ' ', COALESCE(a.note, ''))",
        'technician' => self::TECHNICIAN,
    ];

    /**
     * Appointments › List (mockup deba1ef): every appointment, or those starting in [$from, $to) (UTC),
     * searchable by client, phone, vehicle and description. $startsOn is the Date box's day,
     * [from, to) in UTC (from = to when the text is not a date: nothing matches).
     *
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null $startsOn
     *
     * @return ListPage<Appointment>
     */
    public function findListPage(ListQuery $list, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?array $startsOn = null): ListPage
    {
        $query = $this->createQueryBuilder('a')
            ->addSelect('c', 'v', 'ro')
            ->join('a.client', 'c')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->leftJoin('a.technician', 'atech')
            ->leftJoin('ro.masterTechnician', 'mtech');
        if ($from !== null && $to !== null) {
            $query->andWhere('a.startTime >= :from AND a.startTime < :to')->setParameter('from', $from)->setParameter('to', $to);
        }
        if ($startsOn !== null) {
            $query->andWhere('a.startTime >= :starts_from AND a.startTime < :starts_to')->setParameter('starts_from', $startsOn[0])->setParameter('starts_to', $startsOn[1]);
        }
        // The Repair order box: its number as shown ("RO-000042", "42"); "RO" alone, those with one.
        $repairOrder = $list->filters['repairOrder'] ?? '';
        if ($repairOrder !== '') {
            if (preg_match('~^(?:[a-z]{1,5}[-#/ ]*)?0*(\d+)$~i', $repairOrder, $match) === 1) {
                $query->andWhere("CONCAT(ro.id, '') LIKE :filter_ro")->setParameter('filter_ro', '%' . $match[1] . '%');
            } elseif (str_starts_with('ro-', strtolower($repairOrder))) {
                $query->andWhere('ro.id IS NOT NULL');
            } else {
                $query->andWhere('1 = 0');
            }
        }
        self::selectAmount($query, $list);

        // Phones are stored as digits but shown as "604-555-0101": the Phone box and the search box
        // compare digits only, so the number finds its appointments however it is typed.
        $filters = self::LIST_FILTERS;
        $phone = $list->filters['phone'] ?? '';
        if ($phone !== '' && ($digits = preg_replace('/\D+/', '', $phone) ?? '') !== '') {
            unset($filters['phone']);
            $query->andWhere(self::phoneDigitsLike(':filter_phone_digits'))->setParameter('filter_phone_digits', '%' . $digits . '%');
        }

        return ListPage::paginate(
            $query,
            $list,
            self::LIST_SORTS,
            ['c.firstName', 'c.lastName', 'c.preferredName', 'c.phone1', 'v.manufacturer', 'v.model', 'a.note', 'ro.name'],
            $filters,
            static function (QueryBuilder $query, string $text): ?string {
                $digits = preg_replace('/\D+/', '', $text) ?? '';
                if (strlen($digits) < 3) {
                    return null;
                }
                $query->setParameter('list_search_phone', '%' . $digits . '%');

                return self::phoneDigitsLike(':list_search_phone');
            },
        );
    }

    /**
     * Adds the appointment's "$" (AppointmentAmountExtension: its repair order's newest invoice that
     * is not cancelled, else the repair order's total; with no repair order, its own invoice) as a
     * hidden select when the list is sorted by it. Needs the "ro" join.
     */
    private static function selectAmount(QueryBuilder $query, ListQuery $list): void
    {
        if ($list->sort !== 'amount') {
            return;
        }
        // DQL takes no subquery inside COALESCE: the two invoices are joined (each at most one row:
        // the newest that is not cancelled), then the first of them, else the repair order's total.
        $invoice = Invoice::class;
        $query
            ->leftJoin($invoice, 'amount_ro', 'WITH', "amount_ro.repairOrder = ro AND amount_ro.status <> 'cancelled' AND NOT EXISTS (SELECT amount_ro2.id FROM {$invoice} amount_ro2 WHERE amount_ro2.repairOrder = ro AND amount_ro2.status <> 'cancelled' AND amount_ro2.id > amount_ro.id)")
            ->leftJoin($invoice, 'amount_own', 'WITH', "ro.id IS NULL AND amount_own.appointment = a AND amount_own.status <> 'cancelled' AND NOT EXISTS (SELECT amount_own2.id FROM {$invoice} amount_own2 WHERE amount_own2.appointment = a AND amount_own2.status <> 'cancelled' AND amount_own2.id > amount_own.id)")
            ->addSelect(sprintf('COALESCE(amount_ro.totalPrice + 0, ro.total + 0, amount_own.totalPrice + 0) AS HIDDEN %s', self::AMOUNT_SORT));
    }

    /** The client's first or second phone, punctuation removed, LIKE $param. */
    private static function phoneDigitsLike(string $param): string
    {
        return sprintf('(%s LIKE %2$s OR %3$s LIKE %2$s)', SearchTerm::stripped('c.phone1'), $param, SearchTerm::stripped('c.phone2'));
    }

    /** Whether the vehicle has an appointment starting at $since (UTC) or later. */
    public function hasStartingSince(Vehicle $vehicle, \DateTimeImmutable $since): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.vehicle = :vehicle')->setParameter('vehicle', $vehicle)
            ->andWhere('a.startTime >= :since')->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<Appointment> the client's new, in-progress and extended appointments, soonest first */
    public function pendingForClient(Client $client): array
    {
        return $this->forClient($client, true)
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The Client Profile tab's Appointments card (mockup e0fde70): up to $limit appointments, pending
     * first then past, each newest first, with how many there are of each.
     *
     * @return array{pending: list<Appointment>, past: list<Appointment>, pendingCount: int, pastCount: int, total: int}
     */
    public function recentForClient(Client $client, int $limit = 10): array
    {
        $query = fn (bool $pending) => $this->forClient($client, $pending)->orderBy('a.startTime', 'DESC');
        $count = fn (bool $pending): int => (int) (clone $query($pending))->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $pending = $query(true)->setMaxResults($limit)->getQuery()->getResult();
        $past = $limit > count($pending) ? $query(false)->setMaxResults($limit - count($pending))->getQuery()->getResult() : [];
        $pendingCount = $count(true);
        $pastCount = $count(false);

        return ['pending' => $pending, 'past' => $past, 'pendingCount' => $pendingCount, 'pastCount' => $pastCount, 'total' => $pendingCount + $pastCount];
    }

    /** @return ListPage<Appointment> the client's past appointments: completed, cancelled, no-show */
    public function findCompletedPageForClient(Client $client, ListQuery $list): ListPage
    {
        $query = $this->forClient($client, false);
        self::selectAmount($query, $list);

        return ListPage::paginate($query, $list, self::PAST_SORTS, ['a.note', 'v.manufacturer', 'v.model']);
    }

    /** @return list<Appointment> every past appointment of the client in the list's search and sort (its Export CSV) */
    public function findCompletedForClient(Client $client, ListQuery $list): array
    {
        $query = $this->forClient($client, false);
        self::selectAmount($query, $list);

        return ListPage::filter($query, $list, self::PAST_SORTS, ['a.note', 'v.manufacturer', 'v.model'])->getQuery()->getResult();
    }

    /** The client's pending (new, in progress, extended) or past appointments, with their vehicle and repair order. */
    private function forClient(Client $client, bool $pending): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('v', 'ro')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere($pending ? 'a.status IN (:pending)' : 'a.status NOT IN (:pending)')->setParameter('pending', AppointmentStatus::pending());
    }

    /** The start of the latest repair-order appointment up to $nowUtc, or null (the board's "show that day" hint). */
    public function latestRepairOrderStartUpTo(\DateTimeImmutable $nowUtc): ?\DateTimeImmutable
    {
        $latest = $this->createQueryBuilder('a')
            ->andWhere('a.repairOrder IS NOT NULL')
            ->andWhere('a.startTime <= :now')->setParameter('now', $nowUtc)
            ->orderBy('a.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latest?->getStartTime();
    }
}
