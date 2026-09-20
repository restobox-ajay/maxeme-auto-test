<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Service\QuantityScale;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Entity\TransferOrder;

/**
 * The bundle's front door (#552): what it is, and the two numbers that say whether the warehouse is
 * working.
 *
 * ## Short-pick rate is here because the data is already there
 *
 * Every confirmation writes `quantity_picked` and `quantity_missing` onto the task, because a short
 * pick is three facts and losing any of them leaves stock overstated. Given those two columns, "how
 * often does the shelf disagree with the system" is one query — and it is the single most useful
 * number this bundle can show, because a rising short-pick rate is what a wrong `sort_key`, a
 * mislabelled bin and a leaking process all look like from the outside.
 */
#[Route('/admin/bundles/warehouse-ops')]
final class DashboardController extends AbstractWarehouseOpsController
{
    #[Route('', name: 'admin_bundle_warehouse_ops_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyIfInactive();

        $lists = $this->em->getRepository(PickList::class);
        $transfers = $this->em->getRepository(TransferOrder::class);

        $requested = QuantityScale::canonical($this->em->createQueryBuilder()
            ->select('COALESCE(SUM(t.quantityRequested), 0)')
            ->from(PickTask::class, 't')
            ->getQuery()->getSingleScalarResult());

        $missing = QuantityScale::canonical($this->em->createQueryBuilder()
            ->select('COALESCE(SUM(t.quantityMissing), 0)')
            ->from(PickTask::class, 't')
            ->getQuery()->getSingleScalarResult());

        return $this->render('@WarehouseOps/index.html.twig', [
            'openLists' => $lists->count(['status' => PickList::STATUS_RELEASED]) + $lists->count(['status' => PickList::STATUS_DRAFT]),
            'closedLists' => $lists->count(['status' => PickList::STATUS_CLOSED]),
            'inTransit' => $transfers->count(['status' => TransferOrder::STATUS_DISPATCHED]),
            'requestedUnits' => $requested,
            'missingUnits' => $missing,
            // Percentage of everything ever asked for that was not on the shelf. Zero-safe: a
            // warehouse that has never picked anything has a short-pick rate of nothing, not of
            // division by zero. A rate, not a stored quantity, so float division is the right tool
            // once past the exact bcmath comparison that guards the zero case.
            'shortPickRate' => QuantityScale::compare($requested, 0) > 0 ? round((float) $missing / (float) $requested * 100, 1) : 0.0,
        ]);
    }
}
