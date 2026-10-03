<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Entity\AuditLog;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Service\BusinessDate;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Reads the Activity Log: core's audit_log (every record change plus ActivityRecorder's named
 * actions), filtered by who, which role, which area, which record and when.
 */
final class ActivityLog
{
    public const SORTS = [
        'occurredAt' => 'a.occurredAt',
        'actorName' => 'a.actorName',
        'actorRole' => 'a.actorRole',
        'area' => 'a.area',
        'action' => 'a.action',
    ];

    private const SEARCH_COLUMNS = ['a.actorName', 'a.summary', 'a.entityType'];

    /** Changed fields shown in the list; the rest are on the detail page. */
    private const LIST_CHANGES = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessDate $businessDate,
        private readonly RecordHistory $recordHistory,
    ) {
    }

    /** @return ListPage<AuditLog> */
    public function page(ActivityLogFilter $filter, ListQuery $list): ListPage
    {
        return ListPage::paginate($this->filtered($filter), $list, self::SORTS, self::SEARCH_COLUMNS);
    }

    /** @return list<AuditLog> every row of the current filter and sort (no page), for its CSV */
    public function all(ActivityLogFilter $filter, ListQuery $list): array
    {
        return ListPage::filter($this->filtered($filter), $list, self::SORTS, self::SEARCH_COLUMNS)->getQuery()->getResult();
    }

    /**
     * Rows per role for the current filter (the role filter itself aside), for the summary chips.
     *
     * @return array<string, int> role label (or '' for none) => rows
     */
    public function countByRole(ActivityLogFilter $filter): array
    {
        $rows = $this->filtered($filter, withRole: false)
            ->select('COALESCE(a.actorRole, \'\') AS role, COUNT(a.id) AS n')
            ->groupBy('role')
            ->orderBy('n', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'n', 'role');
    }

    /** @return list<string> the distinct values of a column, for a filter's options */
    public function distinct(string $field): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(sprintf('DISTINCT a.%s AS value', $field))
            ->from(AuditLog::class, 'a')
            ->where(sprintf('a.%s IS NOT NULL', $field))
            ->orderBy('value', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $rows));
    }

    /**
     * What a row changed: field => [before, after]; the list's first few unless $all.
     *
     * @return array{fields: array<string, array{0: mixed, 1: mixed}>, more: int}
     */
    public static function changes(AuditLog $log, bool $all = false): array
    {
        $before = json_decode($log->getDataBefore() ?? '', true);
        $after = json_decode($log->getDataAfter() ?? '', true);
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];

        $fields = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $fields[(string) $field] = [$before[$field] ?? null, $after[$field] ?? null];
        }

        if ($all) {
            return ['fields' => $fields, 'more' => 0];
        }

        return ['fields' => array_slice($fields, 0, self::LIST_CHANGES, true), 'more' => max(0, count($fields) - self::LIST_CHANGES)];
    }

    /**
     * A record's latest history, newest first (the repair order page's History): the same rows its
     * Logs button lists, the records that belong to it included.
     *
     * @return list<AuditLog>
     */
    public function latestFor(object $record, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('a')->from(AuditLog::class, 'a');
        $this->recordHistory->apply($qb, 'a', (new \ReflectionClass($record))->getShortName(), (int) $record->getId());

        return $qb->orderBy('a.occurredAt', 'DESC')->addOrderBy('a.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    private function filtered(ActivityLogFilter $filter, bool $withRole = true): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->select('a')->from(AuditLog::class, 'a');

        if ($withRole && $filter->role === ActivityLogFilter::NO_ROLE) {
            $qb->andWhere('a.actorRole IS NULL');
        } elseif ($withRole && $filter->role !== '') {
            $qb->andWhere('a.actorRole = :role')->setParameter('role', $filter->role);
        }
        if ($filter->userId !== null) {
            $qb->andWhere("a.actorType = 'admin' AND a.actorId = :user")->setParameter('user', $filter->userId);
        }
        if ($filter->area !== '') {
            $qb->andWhere('a.area = :area')->setParameter('area', $filter->area);
        }
        if ($filter->action !== '') {
            $qb->andWhere('a.action = :action')->setParameter('action', $filter->action);
        }
        if ($filter->recordId !== null) {
            $this->recordHistory->apply($qb, 'a', $filter->record, $filter->recordId);
        } elseif ($filter->record !== '') {
            $qb->andWhere('a.entityType = :record')->setParameter('record', $filter->record);
        }
        // Shop days, converted to their UTC window (occurredAt is stored in UTC).
        if ($filter->from !== null) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $this->businessDate->localDayRangeUtc($filter->from)[0], Types::DATETIME_IMMUTABLE);
        }
        if ($filter->to !== null) {
            $qb->andWhere('a.occurredAt < :to')->setParameter('to', $this->businessDate->localDayRangeUtc($filter->to)[1], Types::DATETIME_IMMUTABLE);
        }

        return $qb;
    }
}
