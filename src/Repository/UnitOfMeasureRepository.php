<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UnitOfMeasure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnitOfMeasure>
 */
class UnitOfMeasureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnitOfMeasure::class);
    }

    /*
     * ensureSeeded() is gone.
     *
     * It created the four shipped units if the table was empty, and its only two callers were
     * UnitOfMeasureController::index() and ::new() — two GET actions, so the units existed only
     * once somebody had looked at the screen that lists them, and a product's unit dropdown was
     * empty until then. The rows are the same rows; they are now created once, by
     * App\Service\ReferenceData\Seeders\UnitOfMeasureSeeder, on the first admin login.
     *
     * Deleted rather than left in place unused: a lazy upsert on a repository is exactly what the
     * next read path reaches for, and there is no longer any caller that would be right to.
     */

    /** @return list<UnitOfMeasure> */
    public function allByCode(): array
    {
        /** @var list<UnitOfMeasure> $rows */
        $rows = $this->createQueryBuilder('u')->orderBy('u.code', 'ASC')->getQuery()->getResult();

        return $rows;
    }

    /**
     * One page of units, plus the unfiltered-by-page total the server-side pager needs.
     *
     * Paginated in SQL rather than in Twig: a list screen carries `no-paginate` and asks the
     * database for its page, so a thousand units cost the same to render as ten.
     *
     * @param array{code?: string, name?: string, family?: string} $filters
     *
     * @return array{rows: list<UnitOfMeasure>, total: int}
     */
    public function page(array $filters, string $sort, string $dir, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('u');

        foreach (['code' => 'u.code', 'name' => 'u.name'] as $key => $field) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->andWhere($qb->expr()->like('LOWER(' . $field . ')', ':f_' . $key))
                    ->setParameter('f_' . $key, '%' . mb_strtolower($value) . '%');
            }
        }

        $family = trim((string) ($filters['family'] ?? ''));
        if ($family !== '') {
            $qb->andWhere('u.family = :family')->setParameter('family', $family);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $orderable = [
            'code' => 'u.code',
            'name' => 'u.name',
            'family' => 'u.family',
            'factor' => 'u.factorToFamilyBase',
            'precision' => 'u.roundingPrecision',
        ];

        $qb->orderBy($orderable[$sort] ?? 'u.code', strtolower($dir) === 'desc' ? 'DESC' : 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $limit))
            ->setMaxResults($limit);

        /** @var list<UnitOfMeasure> $rows */
        $rows = $qb->getQuery()->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /*
     * There is deliberately no productCount() here any more (#659).
     *
     * "How many products declare this unit" was one of the questions the delete guard asked, and it
     * could only ever ask about the one column it named. UnitOfMeasureService::referenceCounts()
     * asks it of every column in the entity map at once — product_core.unit_id included — and a new
     * document type is covered the day it declares its mapping rather than the day somebody
     * remembers to widen a hand-written query.
     */
}
