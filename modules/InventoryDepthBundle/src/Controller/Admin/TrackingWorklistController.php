<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Repository\TrackingPolicyRepository;
use App\Service\Inventory\InventoryModeResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The worklist of stock whose identity is still a placeholder (#573).
 *
 * **Tracking never blocks, so this is the control.** An unidentified unit takes the policy's
 * `sentinel_in` — batch or serial alike, and NULL when the policy leaves the sentinel blank — and
 * the row goes through, because a warehouse mid-transition has real stock and no codes for any of it
 * and refusing those rows makes the system unusable on exactly the day they need it. What replaces
 * the refusal is this screen: every row still carrying a placeholder, filterable by whether anyone
 * is expected to come back and resolve it.
 *
 * ## What counts as a placeholder row
 *
 * Two things, and they are deliberately different questions:
 *
 *  - the row carries a **sentinel value** — a lot code, a serial or a bin equal to one any policy
 *    names, or to `[PENDING]`, which the import has written on bins and lots since #565, before any
 *    policy existed to name it. Found rather than backfilled: no migration touches those rows.
 *  - the row is **flagged** `expect_resolution`, which is what a writer sets when it had to
 *    substitute for a dimension the product's policy says it tracks — including the case where the
 *    policy's sentinel is blank and the substitute is therefore NULL, which no value search can
 *    find.
 *
 * The two overlap and neither contains the other, which is why the list is their union and the
 * flag is a filter over it rather than the query itself.
 *
 * ## The flag is per row, and editable here
 *
 * Whether a sentinel will ever be resolved is not a property of the product class and is not
 * inferable from direction — an outbound sentinel is permanent if you never scan, and a to-do if
 * you scan at delivery instead of at pick. So it is a decision a person makes about one row, and
 * this is where they make it.
 */
#[Route('/admin/bundles/inventory-depth/tracking')]
final class TrackingWorklistController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly TrackingPolicyRepository $policies,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('', name: 'admin_bundle_inventory_depth_tracking_worklist', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['product', 'warehouse', 'expect', 'empty']);
        $sentinels = $this->policies->sentinelValues();

        $qb = $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
            ->innerJoin('d.product', 'p')->addSelect('p')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->leftJoin('d.lot', 'l')->addSelect('l')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->leftJoin('p.trackingPolicy', 'tp')->addSelect('tp')
            ->andWhere('d.expectResolution = true OR l.code IN (:sentinels) OR d.serial IN (:sentinels) OR loc.code IN (:sentinels)')
            ->setParameter('sentinels', $sentinels);

        if ($filters['product'] !== '') {
            $qb->andWhere('p.sku LIKE :fp OR p.name LIKE :fp')->setParameter('fp', '%' . $filters['product'] . '%');
        }
        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :fw')->setParameter('fw', (int) $filters['warehouse']);
        }
        if ($filters['expect'] === 'yes') {
            $qb->andWhere('d.expectResolution = true');
        }
        if ($filters['expect'] === 'no') {
            $qb->andWhere('d.expectResolution = false');
        }
        // A row at zero is history — the units it stood for have been identified and moved on — so
        // it is out of the way by default and one filter away when somebody wants the audit trail.
        if ($filters['empty'] !== 'include') {
            $qb->andWhere('d.quantity <> 0');
        }

        $paging = $this->paging($request, 'updated', 'desc');
        $total = (int) (clone $qb)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $orderExpr = match ($paging['sort']) {
            'product' => 'p.sku',
            'quantity' => 'd.quantity',
            default => 'd.updatedAt',
        };

        /** @var list<InventoryDetail> $rows */
        $rows = $qb->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('d.id', 'ASC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        return $this->render('@InventoryDepth/tracking_worklist.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'sentinels' => $sentinels,
            'warehouses' => $this->activeWarehouses(),
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * Sets or clears `expect_resolution` on one row.
     *
     * Deliberately does NOT touch the identity. Giving a placeholder row a real batch code is a
     * movement — it changes which row the stock is on — and that is the adjustment screen's job.
     * This only records the decision about whether anyone is coming back.
     */
    #[Route('/{id}/expect', name: 'admin_bundle_inventory_depth_tracking_expect', methods: ['POST'])]
    public function expect(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $row = $this->em->find(InventoryDetail::class, $id);
        if (!$row instanceof InventoryDetail) {
            $this->addFlash('error', 'That stock row could not be found.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_tracking_worklist');
        }

        $expect = $request->request->getBoolean('expect');
        $row->setExpectResolution($expect)->touch();
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s is %s.',
            $row->getLabel(),
            $expect ? 'flagged for identification' : 'no longer expected to be identified',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_tracking_worklist');
    }
}
