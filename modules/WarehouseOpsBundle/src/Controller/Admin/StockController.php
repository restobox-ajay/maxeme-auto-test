<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use Doctrine\ORM\QueryBuilder;
use InventoryDepthBundle\Entity\InventoryDetail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Warehouse → Stock (#787): one filterable table over every stock row, across every product,
 * warehouse and bin — the screen the owner could not find (#787 §1) because "where is it" was
 * previously split across Stock by Location (no lot/expiry/serial filter, one warehouse's bins),
 * Stock by Product (one product at a time) and the Bin Map (no filters at all).
 *
 * Read-only for now (#787 build order step 1 — "ships value alone"); the row checkboxes and "Move
 * selected" button are step 2/3, a separate change.
 *
 * Deliberately NOT a retirement of Stock by Location or Stock by Product (#787 §9.7): this answers
 * "where is it, across everything, with every filter", not "what's in this one bin" or "this one
 * product everywhere" — different enough questions that both existing screens stay.
 */
#[Route('/admin/bundles/warehouse-ops/stock')]
final class StockController extends AbstractWarehouseOpsController
{
    /**
     * Past this many distinct values, a select's own options stop narrowing anything useful and
     * start costing a real render — the control becomes a text box instead. #787 §4's own number;
     * TransferSourceStock's unrelated OPTION_CAP (300) is a different screen's constant for a
     * different reason (a wildcard-matched picker, not a narrowed filter) and is not reused here.
     */
    private const OPTION_CAP = 200;

    private const FILTER_KEYS = ['product', 'warehouse', 'bin', 'lot', 'expiry', 'serial', 'status'];

    #[Route('', name: 'admin_bundle_warehouse_ops_stock', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, self::FILTER_KEYS);

        // Each select's narrowed options are computed from the RAW submitted filters (everything
        // but that select's own column) — before anything is corrected — so a filter that no
        // longer matches anything can be told apart from one that still does.
        $warehouseOptions = $this->warehouseOptions($filters);
        $binOptions = $this->binOptions($filters);
        $lotOptions = $this->lotOptions($filters);
        $statusOptions = $this->statusOptions($filters);

        $notices = [];
        $filters = $this->clearInvalidSelectValues($filters, $warehouseOptions, $binOptions, $lotOptions, $statusOptions, $notices);

        // Recomputed against the now-consistent filters, so a cleared value's own knock-on effect
        // on the OTHER selects (its column no longer applied) is reflected in what renders.
        if ($notices !== []) {
            $warehouseOptions = $this->warehouseOptions($filters);
            $binOptions = $this->binOptions($filters);
            $lotOptions = $this->lotOptions($filters);
            $statusOptions = $this->statusOptions($filters);
        }

        $paging = $this->paging($request, 'product', 'asc');
        // DQL cannot ORDER BY a function call directly ("Expected known function" out of the parser
        // for anything past a bare state field path or a SELECTed alias), so the expiry case selects
        // the coalesced date HIDDEN and orders by that alias instead of the expression itself.
        $orderExpr = match ($paging['sort']) {
            'bin' => 'loc.sortKey',
            'expiry' => 'effectiveExpiry',
            'quantity' => 'd.quantity',
            'status' => 'd.status',
            default => 'p.sku',
        };

        $qb = $this->baseQuery();
        if ($paging['sort'] === 'expiry') {
            $qb->addSelect('COALESCE(lot.expiry, d.expiry) AS HIDDEN effectiveExpiry');
        }
        $this->applyFilters($qb, $filters, '', $binOptions === null, $lotOptions === null);
        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $rows = $qb->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('d.id', 'ASC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        return $this->render('@WarehouseOps/stock.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'notices' => $notices,
            'warehouseOptions' => $warehouseOptions,
            'binOptions' => $binOptions,
            'lotOptions' => $lotOptions,
            'statusOptions' => $statusOptions,
            'availableStatus' => InventoryDetail::STATUS_AVAILABLE,
            'total' => $total,
            'page' => $page,
            'pages' => $pageCount,
            'limit' => $paging['limit'],
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->leftJoin('d.lot', 'lot')->addSelect('lot')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->innerJoin('d.product', 'p')->addSelect('p')
            ->where('d.quantity > 0');
    }

    /**
     * Every filter except $exclude, applied onto $qb. The one place the seven filters' matching
     * rules live — the main rows query calls this with $exclude = '' (every filter applies); each
     * select's own narrowing query excludes its own column, so a choice already made there can
     * still be switched rather than only ever narrowed.
     *
     * $binIsFreeText / $lotIsFreeText: true once that filter's own option list went over
     * OPTION_CAP, at which point its control became a text input (see the template) and its match
     * follows the same substring rule as Product/Serial rather than the select's exact-code match.
     */
    private function applyFilters(QueryBuilder $qb, array $filters, string $exclude, bool $binIsFreeText, bool $lotIsFreeText): void
    {
        if ($exclude !== 'product' && $filters['product'] !== '') {
            $qb->andWhere('p.sku LIKE :fproduct OR p.name LIKE :fproduct')
                ->setParameter('fproduct', '%' . $filters['product'] . '%');
        }

        if ($exclude !== 'warehouse' && $filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :fwarehouse')->setParameter('fwarehouse', (int) $filters['warehouse']);
        }

        if ($exclude !== 'bin' && $filters['bin'] !== '') {
            if ($binIsFreeText) {
                $qb->andWhere('loc.code LIKE :fbin')->setParameter('fbin', '%' . $filters['bin'] . '%');
            } else {
                $qb->andWhere('loc.code = :fbin')->setParameter('fbin', $filters['bin']);
            }
        }

        if ($exclude !== 'lot' && $filters['lot'] !== '') {
            if ($lotIsFreeText) {
                $qb->andWhere('lot.code LIKE :flot')->setParameter('flot', '%' . $filters['lot'] . '%');
            } else {
                $qb->andWhere('lot.code = :flot')->setParameter('flot', $filters['lot']);
            }
        }

        if ($exclude !== 'expiry' && $filters['expiry'] !== '') {
            $qb->andWhere('COALESCE(lot.expiry, d.expiry) IS NOT NULL AND COALESCE(lot.expiry, d.expiry) <= :fexpiry')
                ->setParameter('fexpiry', $filters['expiry']);
        }

        if ($exclude !== 'serial' && $filters['serial'] !== '') {
            $qb->andWhere('d.serial LIKE :fserial')->setParameter('fserial', '%' . $filters['serial'] . '%');
        }

        if ($exclude !== 'status' && $filters['status'] !== '') {
            $qb->andWhere('d.status = :fstatus')->setParameter('fstatus', $filters['status']);
        }
    }

    /** @return list<array{id: int, name: string, count: int}> */
    private function warehouseOptions(array $filters): array
    {
        $qb = $this->baseQuery();
        $this->applyFilters($qb, $filters, 'warehouse', false, false);
        $qb->select('w.id AS id, w.name AS name, COUNT(d.id) AS cnt')
            ->groupBy('w.id, w.name')
            ->orderBy('w.name', 'ASC');

        /** @var list<array{id: int, name: string, cnt: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['cnt']], $rows);
    }

    /** @return list<array{code: string, count: int}>|null null once past OPTION_CAP — caller falls back to a text input */
    private function binOptions(array $filters): ?array
    {
        $qb = $this->baseQuery();
        // Narrowed by everything BUT bin — including, deliberately, the raw (not-yet-free-text)
        // lot filter: whether lot is itself over the cap has no bearing on how bin narrows.
        $this->applyFilters($qb, $filters, 'bin', false, false);
        $qb->andWhere('loc.id IS NOT NULL')
            ->select('loc.code AS code, COUNT(d.id) AS cnt')
            ->groupBy('loc.code')
            ->orderBy('loc.code', 'ASC');

        /** @var list<array{code: string, cnt: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        if (\count($rows) > self::OPTION_CAP) {
            return null;
        }

        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'count' => (int) $r['cnt']], $rows);
    }

    /** @return list<array{code: string, count: int}>|null null once past OPTION_CAP — caller falls back to a text input */
    private function lotOptions(array $filters): ?array
    {
        $qb = $this->baseQuery();
        $this->applyFilters($qb, $filters, 'lot', false, false);
        $qb->andWhere('lot.id IS NOT NULL')
            ->select('lot.code AS code, COUNT(d.id) AS cnt')
            ->groupBy('lot.code')
            ->orderBy('lot.code', 'ASC');

        /** @var list<array{code: string, cnt: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        if (\count($rows) > self::OPTION_CAP) {
            return null;
        }

        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'count' => (int) $r['cnt']], $rows);
    }

    /** @return list<array{status: string, count: int}> */
    private function statusOptions(array $filters): array
    {
        $qb = $this->baseQuery();
        $this->applyFilters($qb, $filters, 'status', false, false);
        $qb->select('d.status AS status, COUNT(d.id) AS cnt')
            ->groupBy('d.status')
            ->orderBy('d.status', 'ASC');

        /** @var list<array{status: string, cnt: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => ['status' => (string) $r['status'], 'count' => (int) $r['cnt']], $rows);
    }

    /**
     * A filter whose current value is no longer among its own (freshly narrowed by every OTHER
     * filter) option list is cleared rather than left to silently return zero rows forever — #787
     * §4's "changing one filter so that another chosen value no longer matches clears that value
     * and shows a notice" rule. Expiry/product/serial are free text, not a fixed vocabulary, so
     * there is nothing for them to have fallen out of; only the four select-backed filters apply.
     *
     * @param list<array{id: int, name: string, count: int}> $warehouseOptions
     * @param list<array{code: string, count: int}>|null $binOptions
     * @param list<array{code: string, count: int}>|null $lotOptions
     * @param list<array{status: string, count: int}> $statusOptions
     * @param list<string> $notices
     *
     * @return array<string, string>
     */
    private function clearInvalidSelectValues(array $filters, array $warehouseOptions, ?array $binOptions, ?array $lotOptions, array $statusOptions, array &$notices): array
    {
        if ($filters['warehouse'] !== '') {
            $stillValid = array_any($warehouseOptions, static fn (array $o): bool => (string) $o['id'] === $filters['warehouse']);
            if (!$stillValid) {
                $notices[] = 'Warehouse cleared: not available with the other filters.';
                $filters['warehouse'] = '';
            }
        }

        // A capped (null) option list means "too many to check membership against" — the value is
        // taken on faith rather than cleared, since it was typed as free text at that point anyway.
        if ($filters['bin'] !== '' && $binOptions !== null) {
            $stillValid = array_any($binOptions, static fn (array $o): bool => $o['code'] === $filters['bin']);
            if (!$stillValid) {
                $notices[] = sprintf('Bin %s cleared: not in the other filters.', $filters['bin']);
                $filters['bin'] = '';
            }
        }

        if ($filters['lot'] !== '' && $lotOptions !== null) {
            $stillValid = array_any($lotOptions, static fn (array $o): bool => $o['code'] === $filters['lot']);
            if (!$stillValid) {
                $notices[] = sprintf('Lot %s cleared: not in the other filters.', $filters['lot']);
                $filters['lot'] = '';
            }
        }

        if ($filters['status'] !== '') {
            $stillValid = array_any($statusOptions, static fn (array $o): bool => $o['status'] === $filters['status']);
            if (!$stillValid) {
                $notices[] = sprintf('Status %s cleared: not in the other filters.', $filters['status']);
                $filters['status'] = '';
            }
        }

        return $filters;
    }
}
