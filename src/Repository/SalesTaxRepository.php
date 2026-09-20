<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesTax;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SalesTax>
 */
class SalesTaxRepository extends ServiceEntityRepository
{
    private const COLUMN_MAP = [
        'province_name' => 'provinceName',
        'abbreviation' => 'abbreviation',
        'tax_type' => 'taxType',
        'rate' => 'rate',
        'status' => 'status',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesTax::class);
    }

    /** @return SalesTax[] */
    public function findBySource(string $source): array
    {
        return $this->findBy(['source' => $source], ['id' => 'ASC']);
    }

    /** @return SalesTax[] */
    public function findActive(): array
    {
        return $this->findBy(['status' => 'Active'], ['id' => 'ASC']);
    }

    /** @return SalesTax[] */
    public function findActiveBySource(string $source): array
    {
        return $this->findBy(['status' => 'Active', 'source' => $source], ['id' => 'ASC']);
    }

    /**
     * Lazy upsert: finds by slug; if missing, creates the row from $defaults and returns it.
     *
     * @param array{province_name?: string, abbreviation?: string, tax_type?: string, rate?: float, status?: string, source?: string} $defaults
     */
    public function getBySlug(string $slug, array $defaults = []): SalesTax
    {
        $row = $this->findOneBy(['slug' => $slug]);
        if ($row !== null) {
            return $row;
        }

        $row = (new SalesTax())
            ->setSlug($slug)
            ->setProvinceName($defaults['province_name'] ?? '')
            ->setAbbreviation($defaults['abbreviation'] ?? '')
            ->setTaxType($defaults['tax_type'] ?? '')
            ->setRate($defaults['rate'] ?? 0.0)
            ->setStatus($defaults['status'] ?? 'Active')
            ->setSource($defaults['source'] ?? null);

        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();

        return $row;
    }

    /**
     * Search/sort/filter/paginate for the admin Sales Tax list. Column names are the
     * legacy snake_case keys used by the admin template's sort links and filter inputs.
     *
     * @param array<string, string> $filters
     * @return array{rows: SalesTax[], total: int}
     */
    public function search(string $search, array $filters, ?string $sort, string $dir, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('t');

        if ($search !== '') {
            $orX = $qb->expr()->orX();
            foreach (self::COLUMN_MAP as $property) {
                $orX->add($qb->expr()->like("t.$property", ':search'));
            }
            $qb->andWhere($orX)->setParameter('search', '%'.$search.'%');
        }

        foreach ($filters as $column => $value) {
            if ($value === '' || !isset(self::COLUMN_MAP[$column])) {
                continue;
            }
            $property = self::COLUMN_MAP[$column];
            $param = 'filter_'.$property;
            $qb->andWhere("t.$property LIKE :$param")->setParameter($param, '%'.$value.'%');
        }

        $sortProperty = ($sort !== null && isset(self::COLUMN_MAP[$sort])) ? self::COLUMN_MAP[$sort] : 'provinceName';
        $qb->orderBy("t.$sortProperty", strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC')
            ->addOrderBy('t.id', 'ASC');

        $total = (int) (clone $qb)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        if ($limit > 0) {
            $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        }

        return [
            'rows' => $qb->getQuery()->getResult(),
            'total' => $total,
        ];
    }
}
