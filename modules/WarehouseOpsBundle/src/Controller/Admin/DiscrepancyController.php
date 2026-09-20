<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\Warehouse;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Command\TransferDriftCheckCommand;

/**
 * The findings screen for `inventory_reconciliation_discrepancy` (#587).
 *
 * ## The table had five writers and no readers
 *
 * Core's `app:inventory-recalc`, CartHoldBundle's, Number1ProductImportBundle's and
 * InventoryDepthBundle's detail check have all been writing rows here for several issues, and
 * #587's transfer check now does too. Nothing has ever displayed one. A finding that only exists in
 * a table nobody opens is a finding nobody has, and the admin email each row sends is a
 * notification, not a record you can come back to, filter or work through.
 *
 * So this lists EVERY source, not only the transfer check. The alternative — a screen per bundle
 * showing that bundle's own rows — would put the same five columns on five screens and leave an
 * admin who wants "what is wrong with this warehouse" reading five of them.
 *
 * ## Which is why it is odd that it lives here, and why it does anyway
 *
 * The table is core-owned and shared. A screen for it belongs in core by the same argument the
 * entity does — and core is under a change freeze this work is not allowed to break. WarehouseOps
 * is the bundle that made the screen necessary, so the screen is here, gated on this bundle like
 * every other screen in it. The cost is real and worth stating: switch Warehouse Operations off and
 * the cart-hold and detail-check findings stop being visible too, even though those checks keep
 * running and keep emailing. If this ever moves to core, it should move whole.
 *
 * ## Read-only, and that is the design
 *
 * There is no resolve button, no dismiss, no "recheck now". A discrepancy row is an observation
 * with a timestamp; editing it would be editing history, and clearing one would be the first step
 * toward a screen that makes drift go away without anybody fixing anything. The two row actions go
 * to the places where a human can actually act — the product's stock breakdown, and the transfers
 * that touched the warehouse — and both are ordinary GET links.
 */
#[Route('/admin/bundles/warehouse-ops/discrepancies')]
final class DiscrepancyController extends AbstractWarehouseOpsController
{
    /**
     * Sortable columns, whitelisted. `product` sorts by SKU rather than by name because SKU is what
     * the row shows first and what an operator pastes into the filter.
     */
    private const SORTS = [
        'occurredAt' => 'd.occurredAt',
        'product' => 'p.sku',
        'warehouse' => 'w.name',
        'bucket' => 'd.bucket',
        'source' => 'd.source',
    ];

    #[Route('', name: 'admin_bundle_warehouse_ops_discrepancies', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['product', 'warehouse', 'bucket', 'source']);
        $paging = $this->paging($request, 'occurredAt', 'desc');

        $sort = self::SORTS[$paging['sort']] ?? self::SORTS['occurredAt'];
        $sortKey = \array_key_exists($paging['sort'], self::SORTS) ? $paging['sort'] : 'occurredAt';

        $rows = $this->filtered($filters)
            ->select('d', 'p', 'w')
            ->orderBy($sort, $paging['dir'])
            // A stable tiebreak, so page 2 of a run that logged forty rows in the same second is not
            // a reshuffle of page 1.
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($paging['page'] - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        $total = (int) $this->filtered($filters)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('@WarehouseOps/discrepancies.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($total / $paging['limit'])),
            'currentSort' => $sortKey,
            'currentDir' => strtolower($paging['dir']),
            'filters' => $filters,
            'warehouses' => $this->warehousesWithFindings(),
            'buckets' => $this->distinct('d.bucket'),
            'sources' => $this->distinct('d.source'),
            'transferSource' => TransferDriftCheckCommand::SOURCE,
        ]);
    }

    /**
     * The filtered query, built twice — once for the page of rows and once for the count.
     *
     * Server-side paging is the standing rule for a list screen, and this table is one an instance
     * with a real drift problem can put thousands of rows into in a single cron run. Rebuilding the
     * builder is deliberate: a QueryBuilder mutated into a COUNT and back is how a `select` from the
     * previous call ends up in the count query.
     *
     * @param array<string, string> $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->from(InventoryReconciliationDiscrepancy::class, 'd')
            ->innerJoin('d.product', 'p')
            ->innerJoin('d.warehouse', 'w');

        if ($filters['product'] !== '') {
            // SKU or name, because an operator arriving from an email has one or the other and
            // should not have to know which column the screen searches.
            $qb->andWhere('p.sku LIKE :product OR p.name LIKE :product')
                ->setParameter('product', '%' . $filters['product'] . '%');
        }
        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :warehouse')->setParameter('warehouse', (int) $filters['warehouse']);
        }
        if ($filters['bucket'] !== '') {
            $qb->andWhere('d.bucket = :bucket')->setParameter('bucket', $filters['bucket']);
        }
        if ($filters['source'] !== '') {
            $qb->andWhere('d.source = :source')->setParameter('source', $filters['source']);
        }

        return $qb;
    }

    /**
     * The distinct values actually present in one column, for its filter dropdown.
     *
     * Read from the data rather than from a list of constants, because five different checks write
     * this table and a sixth would otherwise be invisible to the filter until somebody remembered
     * to add it here. An empty instance gets an empty dropdown, which is the truth.
     *
     * @return list<string>
     */
    private function distinct(string $field): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select($field . ' AS value')
            ->from(InventoryReconciliationDiscrepancy::class, 'd')
            ->groupBy($field)
            ->orderBy($field, 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['value'], $rows));
    }

    /**
     * The warehouses that have a finding, not the active ones.
     *
     * A discrepancy against a warehouse somebody has since deactivated is exactly the row an admin
     * most wants to look at, and an active-only dropdown would leave it unreachable by filter while
     * it sat in the list.
     *
     * @return list<Warehouse>
     */
    private function warehousesWithFindings(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.warehouse) AS id')
            ->from(InventoryReconciliationDiscrepancy::class, 'd')
            ->groupBy('d.warehouse')
            ->getQuery()
            ->getScalarResult();

        if ($ids === []) {
            return [];
        }

        /** @var list<Warehouse> $rows */
        $rows = $this->em->getRepository(Warehouse::class)->findBy(
            ['id' => array_map(static fn (array $row): int => (int) $row['id'], $ids)],
            ['name' => 'ASC'],
        );

        return $rows;
    }
}
