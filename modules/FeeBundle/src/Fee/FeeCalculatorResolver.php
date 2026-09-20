<?php

declare(strict_types=1);

namespace FeeBundle\Fee;

use App\Contract\Fee\FeeCalculatorInterface;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeMenuItemInterface;
use App\Contract\Fee\FeeOrderSnapshotProviderInterface;
use App\Entity\SalesOrder;
use App\Repository\BundleStatusRepository;

final class FeeCalculatorResolver
{
    /**
     * @param iterable<FeeCalculatorInterface> $calculators
     * @param iterable<FeeMenuItemInterface> $menuItems
     */
    public function __construct(
        private readonly iterable $calculators,
        private readonly iterable $menuItems,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /**
     * Admin sidebar entries for this domain, skipping any whose owning bundle is
     * Inactive — an inactive bundle stays reachable from Bundle Management to
     * reactivate, it just shouldn't clutter the sidebar in the meantime.
     *
     * @return array<int, array{label: string, route: string}>
     */
    public function getMenuItems(): array
    {
        $items = [];
        foreach ($this->menuItems as $item) {
            if (!$item instanceof FeeMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            $items[] = ['label' => $item->getLabel(), 'route' => $item->getRoute()];
        }

        return $items;
    }

    /** @return FeeLine[] */
    public function calculate(FeeContext $context): array
    {
        $lines = [];
        foreach ($this->calculators as $calculator) {
            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            if ($calculator->supports($context)) {
                foreach ($calculator->calculate($context) as $line) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Called once by order-creation/edit flows right after an order's fee lines are
     * finalized, so any calculator that also implements FeeOrderSnapshotProviderInterface
     * can capture its own per-order data at that exact moment. Reuses the same
     * app.fee_calculator-tagged iterator this class already holds — no new injection
     * needed at call sites that already inject FeeCalculatorResolver for calculate().
     */
    public function applyOrderSnapshots(SalesOrder $order, int $companyId): void
    {
        foreach ($this->calculators as $calculator) {
            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            if ($calculator instanceof FeeOrderSnapshotProviderInterface) {
                $calculator->applyOrderSnapshot($order, $companyId);
            }
        }
    }
}
