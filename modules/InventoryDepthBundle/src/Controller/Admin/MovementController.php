<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Movement history (#550) — the audit surface.
 *
 * "Who moved this and why" has to be answerable without a database client, which is the whole
 * reason `inventory_movement_group` carries a reason, an actor and a reference at all.
 *
 * It is NOT where a recall is answered from any more (that was true until #725): a terminal `sold`
 * detail row is shared by every shipment of a lot from a warehouse, so this screen only ever
 * answered "how much left", never "to whom" — the group's `reference` alone did, and reading it out
 * of a movement list is not a recall notice. {@see \InventoryDepthBundle\Controller\Admin\LotTraceController}
 * is the real answer to both of a recall's questions: where a lot is now, from
 * `InventoryDetailRepository::rowsForLot()`, and every invoice line that actually shipped units of
 * it, from `LotTraceService::soldTo()`.
 */
#[Route('/admin/bundles/inventory-depth/movements')]
final class MovementController extends AbstractInventoryDepthController
{
    #[Route('', name: 'admin_bundle_inventory_depth_movements', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, [
            'product', 'productId', 'warehouse', 'location', 'lot', 'serial', 'type', 'actor', 'reference', 'from', 'to',
        ]);

        $repo = $this->em->getRepository(InventoryMovement::class);
        $qb = $repo->filteredQueryBuilder($filters);

        $paging = $this->paging($request, 'occurred', 'desc');

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(m.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $orderExpr = match ($paging['sort']) {
            'product' => 'p.sku',
            'type' => 'g.type',
            'actor' => 'g.actor',
            'quantity' => 'm.quantity',
            default => 'g.occurredAt',
        };

        /** @var list<InventoryMovement> $rows */
        $rows = $qb
            ->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        return $this->render('@InventoryDepth/movements.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'warehouses' => $this->activeWarehouses(),
            'bins' => $this->em->getRepository(WarehouseLocation::class)->findBy([], ['sortKey' => 'ASC', 'code' => 'ASC']),
            'types' => InventoryMovementGroup::types(),
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }
}
