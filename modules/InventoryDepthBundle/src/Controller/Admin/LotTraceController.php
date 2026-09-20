<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Inventory\LotTraceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A recall's two questions, answered from one lot number (#725): where is it now, and who was it
 * sold to. The sharpest, most food-safety-relevant finding in the wholesale-inventory-core parity
 * audit — `MovementController`'s own docblock already said movement history "is also the only place
 * a recall can be answered from... and never 'to whom'". This is the screen that answers "to whom".
 *
 * Search is by CODE, across every product, because that is what arrives on a recall notice from a
 * vendor — nobody hands a warehouse a product id. A code can match more than one row (`InventoryLot`
 * deliberately does not enforce uniqueness per product — see that entity's own docblock), so search
 * lists every match and the operator opens the one row they mean; picking the wrong one and declaring
 * the wrong batch recalled is a worse mistake than one extra click.
 */
#[Route('/admin/bundles/inventory-depth/lots/trace')]
final class LotTraceController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly LotTraceService $trace,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('', name: 'admin_bundle_inventory_depth_lot_trace', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $this->denyIfInactive();

        $code = trim((string) $request->query->get('q', ''));

        $rows = [];
        if ($code !== '') {
            $qb = $this->em->getRepository(InventoryLot::class)->createQueryBuilder('l')
                ->innerJoin('l.product', 'p')->addSelect('p')
                ->andWhere('l.code LIKE :code')->setParameter('code', '%' . $code . '%')
                ->orderBy('p.sku', 'ASC')
                ->addOrderBy('l.expiry', 'ASC')
                ->setMaxResults(200);

            /** @var list<InventoryLot> $rows */
            $rows = $qb->getQuery()->getResult();
        }

        return $this->render('@InventoryDepth/lot_trace_search.html.twig', [
            'q' => $code,
            'rows' => $rows,
        ]);
    }

    #[Route('/{id}', name: 'admin_bundle_inventory_depth_lot_trace_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id): Response
    {
        $this->denyIfInactive();

        $lot = $this->lotOr404($id);

        return $this->render('@InventoryDepth/lot_trace_view.html.twig', [
            'lot' => $lot,
            'locations' => $this->trace->currentLocations($lot),
            'soldTo' => $this->trace->soldTo($lot),
        ]);
    }

    /**
     * The actual recall action: change this lot's status from the trace screen itself, so declaring
     * one does not require a separate trip to the Lots screen and back.
     */
    #[Route('/{id}/status', name: 'admin_bundle_inventory_depth_lot_trace_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setStatus(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $lot = $this->lotOr404($id);
        $status = (string) $request->request->get('status', InventoryLot::STATUS_AVAILABLE);

        if (!\in_array($status, InventoryLot::statuses(), true)) {
            $this->addFlash('error', 'Not a status this system knows. Nothing was changed.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_lot_trace_view', ['id' => $id]);
        }

        $lot->setStatus($status);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Lot %s is now %s. It stops being offered on the Order/Invoice line lot picker%s.',
            $lot->getLabel(),
            $status,
            $status === InventoryLot::STATUS_AVAILABLE ? '' : ', and any save naming it is measured as having nothing available',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_lot_trace_view', ['id' => $id]);
    }

    private function lotOr404(int $id): InventoryLot
    {
        $lot = $this->em->find(InventoryLot::class, $id);
        if (!$lot instanceof InventoryLot) {
            throw $this->createNotFoundException('No such lot.');
        }

        return $lot;
    }
}
