<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\Warehouse;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A visual layout of one warehouse (#552).
 *
 * ## What it is for, in order of how often it matters
 *
 *  - **Sanity-checking the pick path.** `sort_key` is entered by a person and is wrong the day a
 *    shelf is renumbered. A route that zigzags is a real cost and an invisible one; the map is where
 *    it becomes visible, which is why every cell shows its route position.
 *  - Seeing occupancy at a glance, and where the empty space is.
 *  - Finding which bins hold a product.
 *
 * ## Flat, on purpose
 *
 * Zone and aisle are labels for grouping and filtering, not a hierarchy with tables of its own.
 * Three warehouses of a few hundred bins do not need a tree, and a tree would then need maintaining
 * to stay true — a second model of the building that can disagree with the first.
 *
 * ## Unplaced bins are shown, not hidden
 *
 * A bin with no coordinates is listed beside the grid rather than dropped. The map is only useful if
 * it accounts for every bin; one that quietly showed 180 of 200 would be worse than none.
 */
#[Route('/admin/bundles/warehouse-ops/bin-map')]
final class BinMapController extends AbstractWarehouseOpsController
{
    #[Route('', name: 'admin_bundle_warehouse_ops_bin_map', methods: ['GET'])]
    public function map(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['warehouse', 'zone', 'product']);

        $warehouses = $this->activeWarehouses();
        $warehouse = null;
        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $warehouse = $this->em->find(Warehouse::class, (int) $filters['warehouse']);
        }
        if (!$warehouse instanceof Warehouse) {
            $warehouse = $warehouses[0] ?? null;
        }

        $placed = [];
        $unplaced = [];
        $zones = [];
        $occupancy = [];
        $maxX = 0;
        $maxY = 0;

        if ($warehouse instanceof Warehouse) {
            /** @var list<WarehouseLocation> $bins */
            $bins = $this->em->getRepository(WarehouseLocation::class)
                ->findByWarehouseInPickOrder($warehouse);

            $occupancy = $this->occupancy($warehouse, $filters['product']);

            foreach ($bins as $bin) {
                if ($bin->getZone() !== null) {
                    $zones[$bin->getZone()] = true;
                }
                if ($filters['zone'] !== '' && $bin->getZone() !== $filters['zone']) {
                    continue;
                }

                if (!$bin->isMapped()) {
                    $unplaced[] = $bin;
                    continue;
                }

                $maxX = max($maxX, (int) $bin->getMapX());
                $maxY = max($maxY, (int) $bin->getMapY());
                $placed[] = $bin;
            }
        }

        return $this->render('@WarehouseOps/bin_map.html.twig', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'filters' => $filters,
            'zones' => array_keys($zones),
            'placed' => $placed,
            'unplaced' => $unplaced,
            'occupancy' => $occupancy,
            'columns' => max(1, $maxX),
            'rows' => max(1, $maxY),
        ]);
    }

    /**
     * Saves coordinates for the bins on the screen.
     *
     * Zone, aisle and both coordinates are cleared when left blank rather than kept: taking a bin
     * off the map has to be possible, and "type something else over it" is not the same operation.
     */
    #[Route('/save', name: 'admin_bundle_warehouse_ops_bin_map_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        /** @var array<int|string, mixed> $rows */
        $rows = $request->request->all('bins');
        $saved = 0;

        foreach ($rows as $id => $values) {
            if (!\is_array($values)) {
                continue;
            }

            $bin = $this->binOrNull((int) $id);
            if (!$bin instanceof WarehouseLocation) {
                continue;
            }

            $bin
                ->setZone(\is_scalar($values['zone'] ?? null) ? (string) $values['zone'] : null)
                ->setAisle(\is_scalar($values['aisle'] ?? null) ? (string) $values['aisle'] : null)
                ->setMapX($this->coordinate($values['x'] ?? null))
                ->setMapY($this->coordinate($values['y'] ?? null));

            $saved++;
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d bin(s) updated on the map.', $saved));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_bin_map', [
            'filters' => ['warehouse' => $request->request->getInt('warehouse_id', 0)],
        ]);
    }

    /**
     * Units of available stock per bin, for the occupancy shading.
     *
     * One query for the whole warehouse rather than one per cell: a few hundred bins each asking
     * their own question is what turns a map into a page that takes a minute to load.
     *
     * With a product given, the numbers are that product's units only — which is what turns the
     * map into the answer to "which bins hold this", the third thing this screen exists for. The
     * match is the same substring one every other stock screen uses, so what you type in one box
     * behaves the same way in all of them.
     *
     * @return array<int, int> keyed by location id
     */
    private function occupancy(Warehouse $warehouse, string $product = ''): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.location) AS locationId', 'SUM(d.quantity) AS units')
            ->from(InventoryDetail::class, 'd')
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status = :available')->setParameter('available', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.location IS NOT NULL')
            ->groupBy('d.location');

        if (trim($product) !== '') {
            $qb->innerJoin('d.product', 'p')
                ->andWhere('p.sku LIKE :fproduct OR p.name LIKE :fproduct')
                ->setParameter('fproduct', '%' . trim($product) . '%');
        }

        /** @var list<array{locationId: ?int, units: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['locationId']] = (int) $row['units'];
        }

        return $out;
    }

    /** A blank coordinate is "not on the map", which is a real state and not zero. */
    private function coordinate(mixed $value): ?int
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || !ctype_digit(ltrim($value, '-'))) {
            return null;
        }

        return max(1, min(500, (int) $value));
    }
}
