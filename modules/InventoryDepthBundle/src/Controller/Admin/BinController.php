<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Delete\ReferenceCounter;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\WarehouseLocationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bin management (#550): CRUD over `warehouse_location`, plus bulk creation of a range.
 *
 * The bulk form is not a convenience. Nobody types 200 bins one at a time, and a screen that made
 * them would be a screen nobody used — the aisle/rack/shelf pattern is how racking is actually
 * labelled, so it is what the form asks for.
 *
 * Bins are never deleted, only deactivated. A bin is referenced by every detail row that has ever
 * been in it and by every movement that ever went through it; deleting one would either cascade
 * that history away or leave it pointing at nothing.
 *
 * ## Editing a bin edits the bin (#590)
 *
 * The form used to hardcode `id=0`, so every save took the create branch and was then turned away
 * by the duplicate check — which made `code`, `type`, `sort_key` and `status` write-once. A bin
 * could never be renamed, re-sorted, taken out of service, or put back into it, and `sort_key` is
 * the picker's tiebreak after expiry. The list now carries an Edit link per row, `?edit=<id>` fills
 * the form from that row, and the duplicate check applies to both branches by asking whether the
 * clashing row is a DIFFERENT row rather than whether one exists at all.
 *
 * `warehouse_id` is read **only on the create branch**. Moving a bin between warehouses would move
 * every detail row standing in it without a single movement being written, which is the one thing
 * this layer exists to prevent.
 *
 * ## Closing a bin refuses while stock is still inside it (#590)
 *
 * Deactivating a bin that still holds units strands them: every bin dropdown in the bundle filters
 * `status = 'Active'`, so the stock cannot then be named as a movement source, counted, or scanned.
 * The Active → Inactive transition therefore checks
 * `SUM(inventory_detail.quantity) WHERE location_id = <bin> AND quantity > 0` and refuses with the
 * number. Every status counts, not just `available` — a quarantined carton is as stuck as a
 * sellable one.
 *
 * Only the transition is checked. A bin that is ALREADY Inactive with stock in it is a bin somebody
 * needs to rename or reactivate to dig the stock out, and refusing to save it would be the trap
 * closing behind them.
 *
 * ## Deleting a bin nothing has ever used (#613)
 *
 * "Bins are never deleted, only deactivated" above stays true of every bin that has been used, and
 * the reason is unchanged. What it never covered is the row created by mistake: the bulk form makes
 * two hundred bins in one press, and a typo'd prefix used to leave two hundred rows that could only
 * be closed, never removed, cluttering every bin dropdown in the bundle for good.
 *
 * So delete refuses unless NOTHING points at the row — see ReferenceCounter, which does the asking
 * and names the tables. Refused, never cascaded: all four of those columns are `ON DELETE SET NULL`,
 * so issuing the DELETE anyway would succeed and leave a detail row standing nowhere, which is the
 * one state a cycle count cannot answer. A bin that has held stock is closed, not deleted, and the
 * refusal says so.
 */
#[Route('/admin/bundles/inventory-depth/bins')]
final class BinController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly ReferenceCounter $references,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('', name: 'admin_bundle_inventory_depth_bins', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['warehouse', 'code', 'type', 'status']);

        $qb = $this->em->getRepository(WarehouseLocation::class)->createQueryBuilder('l')
            ->innerJoin('l.warehouse', 'w')->addSelect('w');

        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :fw')->setParameter('fw', (int) $filters['warehouse']);
        }
        if ($filters['code'] !== '') {
            $qb->andWhere('l.code LIKE :fc')->setParameter('fc', '%' . $filters['code'] . '%');
        }
        if ($filters['type'] !== '') {
            $qb->andWhere('l.type = :ft')->setParameter('ft', $filters['type']);
        }
        if ($filters['status'] !== '') {
            $qb->andWhere('l.status = :fst')->setParameter('fst', $filters['status']);
        }

        $paging = $this->paging($request, 'sortKey', 'asc');
        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $orderExpr = match ($paging['sort']) {
            'code' => 'l.code',
            'type' => 'l.type',
            'warehouse' => 'w.name',
            default => 'l.sortKey',
        };

        /** @var list<WarehouseLocation> $rows */
        $rows = $qb->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('l.code', 'ASC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        $editId = $request->query->getInt('edit', 0);
        $editing = $editId > 0 ? $this->em->find(WarehouseLocation::class, $editId) : null;

        return $this->render('@InventoryDepth/bins.html.twig', [
            'rows' => $rows,
            'editing' => $editing instanceof WarehouseLocation ? $editing : null,
            // One query for the whole page, so the Contents column states the number that closing
            // the bin would refuse on rather than leaving the refusal to be discovered.
            'units' => $this->details()->unitsByLocation($rows),
            'warehouses' => $this->activeWarehouses(),
            'types' => WarehouseLocation::types(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    #[Route('/save', name: 'admin_bundle_inventory_depth_bin_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $code = trim((string) $request->request->get('code', ''));

        $bin = $id > 0 ? $this->em->find(WarehouseLocation::class, $id) : null;

        // A named id that resolves to nothing is a stale Edit link. Falling through to create would
        // mint a second bin under the code somebody was trying to rename.
        if ($id > 0 && !$bin instanceof WarehouseLocation) {
            $this->addFlash('error', 'That bin no longer exists. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_bins');
        }

        // The warehouse is the bin's own on an edit, never the request's: moving a bin between
        // warehouses would move every detail row standing in it with no movement written.
        if ($bin instanceof WarehouseLocation) {
            $warehouse = $bin->getWarehouse();
        } else {
            $warehouseId = $request->request->getInt('warehouse_id', 0);
            if ($warehouseId <= 0) {
                $this->addFlash('error', 'A bin needs a code and a warehouse.');

                return $this->redirectToRoute('admin_bundle_inventory_depth_bins');
            }

            $warehouse = $this->warehouseOr404($warehouseId);
        }

        if ($code === '') {
            $this->addFlash('error', 'A bin needs a code and a warehouse.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_bins', $id > 0 ? ['edit' => $id] : []);
        }

        /** @var WarehouseLocationRepository $repo */
        $repo = $this->em->getRepository(WarehouseLocation::class);

        // uniq_wh_location exists in every schema (it needs no COALESCE, so Doctrine emits it too),
        // but checking here turns a constraint exception into a sentence. Asked of BOTH branches
        // now, and phrased as "a different row", so re-saving a bin under its own code is not a
        // collision with itself — which is what made every field on this form write-once.
        $clash = $repo->findOneByCode($warehouse, $code);
        if ($clash instanceof WarehouseLocation && $clash !== $bin) {
            $this->addFlash('error', sprintf(
                '%s already has a bin called %s (row %d).',
                $warehouse->getName(),
                $code,
                $clash->getId(),
            ));

            return $this->redirectToRoute('admin_bundle_inventory_depth_bins', $id > 0 ? ['edit' => $id] : []);
        }

        $status = $request->request->get('status') === 'Inactive' ? 'Inactive' : 'Active';

        // Closing a bin refuses while stock is still inside it. Only the Active → Inactive
        // transition asks: a bin already Inactive with stock in it has to stay editable, or the
        // trap closes behind whoever is trying to dig the stock out.
        if ($status === 'Inactive' && $bin instanceof WarehouseLocation && $bin->isActive()) {
            $units = $this->details()->unitsInLocation($bin);
            if ($units > 0) {
                $this->addFlash('error', sprintf(
                    '%s in %s still holds %d unit(s) and cannot be closed. Every bin dropdown in this bundle is Active-only, so closing it now would strand that stock with no way to name it. Move it out first — Contents shows what is in there.',
                    $bin->getCode(),
                    $warehouse->getName(),
                    $units,
                ));

                return $this->redirectToRoute('admin_bundle_inventory_depth_bins', ['edit' => $id]);
            }
        }

        if (!$bin instanceof WarehouseLocation) {
            $bin = (new WarehouseLocation())->setWarehouse($warehouse);
            $this->em->persist($bin);
        }

        $bin
            ->setCode($code)
            ->setType((string) $request->request->get('type', WarehouseLocation::TYPE_PICK))
            ->setSortKey($request->request->getInt('sort_key', 0))
            ->setStatus($status);

        $this->em->flush();
        $this->addFlash('success', sprintf(
            'Bin %s saved (warehouse_location row %d %s).',
            $bin->getCode(),
            $bin->getId(),
            $id > 0 ? 'updated' : 'created',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_bins');
    }

    /**
     * Deletes a bin nothing has ever pointed at, and refuses every other one by name.
     *
     * POST, and a plain form post at that: a destructive action reachable by following a link is
     * reachable by a crawler and by a browser's link prefetch. There is no JavaScript anywhere in
     * the path — the row's Delete is a one-button form, exactly as core's redirect and custom-field
     * rows are, so it works with scripting off.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_inventory_depth_bin_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $bin = $this->em->find(WarehouseLocation::class, $id);

        // A stale Delete button, from a list rendered before somebody else removed the row. Saying
        // so is the whole response: there is nothing to delete and nothing went wrong.
        if (!$bin instanceof WarehouseLocation) {
            $this->addFlash('error', 'That bin no longer exists. Nothing was deleted.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_bins');
        }

        $holders = $this->references->holdersOfBin($id);
        if ($holders !== []) {
            $this->addFlash('error', sprintf(
                '%s in %s cannot be deleted — %s still name it. Every one of those columns is ON DELETE SET NULL, so deleting the bin would leave those rows standing nowhere rather than refusing. Close it instead: an Inactive bin keeps its history and drops out of every dropdown.',
                $bin->getCode(),
                $bin->getWarehouse()->getName(),
                ReferenceCounter::describe($holders),
            ));

            return $this->redirectToRoute('admin_bundle_inventory_depth_bins', ['edit' => $id]);
        }

        $code = $bin->getCode();
        $warehouse = $bin->getWarehouse()->getName();

        $this->em->remove($bin);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Bin %s in %s deleted (warehouse_location row %d). Nothing pointed at it, so nothing was stranded.',
            $code,
            $warehouse,
            $id,
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_bins');
    }

    private function details(): InventoryDetailRepository
    {
        /** @var InventoryDetailRepository $repo */
        $repo = $this->em->getRepository(InventoryDetail::class);

        return $repo;
    }

    /**
     * Creates a whole range at once: `{prefix}{aisle}-{rack}-{shelf}`.
     *
     * `sort_key` is assigned in generation order, which is the order the loops walk the racking —
     * so the default pick path is the physical one rather than alphabetical, and an aisle that
     * should be walked in reverse is a later edit rather than a re-import.
     *
     * Codes that already exist are skipped and counted, not treated as an error: re-running a range
     * to extend it by one shelf is a normal thing to do.
     */
    #[Route('/bulk', name: 'admin_bundle_inventory_depth_bin_bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        $this->denyIfInactive();

        $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));
        $prefix = trim((string) $request->request->get('prefix', ''));
        $aisles = max(1, min(100, $request->request->getInt('aisles', 1)));
        $racks = max(1, min(100, $request->request->getInt('racks', 1)));
        $shelves = max(1, min(100, $request->request->getInt('shelves', 1)));
        $type = (string) $request->request->get('type', \InventoryDepthBundle\Entity\WarehouseLocation::TYPE_PICK);

        $repo = $this->em->getRepository(WarehouseLocation::class);

        $created = 0;
        $skipped = 0;
        $sortKey = (int) ($this->em->createQueryBuilder()
            ->select('COALESCE(MAX(l.sortKey), 0)')
            ->from(WarehouseLocation::class, 'l')
            ->where('l.warehouse = :w')->setParameter('w', $warehouse)
            ->getQuery()
            ->getSingleScalarResult());

        for ($aisle = 1; $aisle <= $aisles; $aisle++) {
            for ($rack = 1; $rack <= $racks; $rack++) {
                for ($shelf = 1; $shelf <= $shelves; $shelf++) {
                    $code = sprintf('%s%02d-%02d-%02d', $prefix, $aisle, $rack, $shelf);

                    if ($repo->findOneByCode($warehouse, $code) instanceof WarehouseLocation) {
                        $skipped++;
                        continue;
                    }

                    $this->em->persist(
                        (new WarehouseLocation())
                            ->setWarehouse($warehouse)
                            ->setCode($code)
                            ->setType($type)
                            ->setSortKey(++$sortKey)
                    );
                    $created++;
                }
            }
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d bin(s) created in %s%s.',
            $created,
            $warehouse->getName(),
            $skipped > 0 ? sprintf(', %d already existed and were left alone', $skipped) : '',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_bins', ['filters' => ['warehouse' => $warehouse->getId()]]);
    }
}
