<?php

declare(strict_types=1);

namespace ShippingBundle\Shipping;

use App\Contract\Shipping\ShippingMenuItemInterface;
use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;
use App\Repository\BundleStatusRepository;

final class ShippingResolver
{
    /**
     * @param iterable<ShippingOptionInterface> $calculators
     * @param iterable<ShippingOptionInterface> $fallbackCalculators
     * @param iterable<ShippingMenuItemInterface> $menuItems
     */
    public function __construct(
        private readonly iterable $calculators,
        private readonly iterable $fallbackCalculators,
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
            if (!$item instanceof ShippingMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            $items[] = ['label' => $item->getLabel(), 'route' => $item->getRoute()];
        }

        return $items;
    }

    /**
     * The delivery options available for a document — a cart, an order or an estimate alike.
     *
     * @return ShippingOption[]
     */
    public function getAvailableOptions(AbstractSalesDocument $document): array
    {
        $options = $this->collect($this->calculators, $document);
        if ($options !== []) {
            return $options;
        }

        return $this->collect($this->fallbackCalculators, $document);
    }

    /**
     * @param iterable<ShippingOptionInterface> $calculators
     * @return ShippingOption[]
     */
    private function collect(iterable $calculators, AbstractSalesDocument $document): array
    {
        $options = [];
        foreach ($calculators as $calculator) {
            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            if ($calculator->supports($document)) {
                foreach ($calculator->getOptions($document) as $option) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }
}
