<?php

declare(strict_types=1);

namespace TaxBundle\Tax;

use App\Contract\Tax\TaxCalculatorInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Contract\Tax\TaxMenuItemInterface;
use App\Contract\Tax\TaxOrderSnapshotProviderInterface;
use App\Entity\SalesOrder;
use App\Exception\TaxJurisdictionNotCovered;
use App\Repository\BundleStatusRepository;

final class TaxCalculatorResolver
{
    /**
     * @param iterable<TaxCalculatorInterface> $calculators
     * @param iterable<TaxMenuItemInterface> $menuItems
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
     * @return array<int, array{label: string, route: string, source: string}>
     */
    public function getMenuItems(): array
    {
        $items = [];
        foreach ($this->menuItems as $item) {
            if (!$item instanceof TaxMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            $items[] = ['label' => $item->getLabel(), 'route' => $item->getRoute(), 'source' => $item->getSource()];
        }

        return $items;
    }

    /** @return TaxLine[] */
    public function calculate(TaxContext $context): array
    {
        foreach ($this->calculators as $calculator) {
            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            if ($calculator->supports($context)) {
                return $calculator->calculate($context);
            }
        }

        // Its own type since item 66, so a caller can tell "nobody covers this jurisdiction" — a
        // permanent, knowable fact about this installation — apart from a calculator that is
        // installed and fell over. It still extends \RuntimeException, so every existing catch
        // behaves exactly as it did.
        throw TaxJurisdictionNotCovered::forProvince($context->province);
    }

    /**
     * Called once by order-creation/edit flows right after an order's tax lines are
     * finalized, so any calculator that also implements TaxOrderSnapshotProviderInterface
     * can capture its own per-order data at that exact moment. Mirrors
     * FeeCalculatorResolver::applyOrderSnapshots().
     */
    public function applyOrderSnapshots(SalesOrder $order, int $companyId): void
    {
        foreach ($this->calculators as $calculator) {
            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            if ($calculator instanceof TaxOrderSnapshotProviderInterface) {
                $calculator->applyOrderSnapshot($order, $companyId);
            }
        }
    }
}
