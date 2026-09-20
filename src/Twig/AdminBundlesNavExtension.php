<?php

declare(strict_types=1);

namespace App\Twig;

use App\Contract\Hook\InjectionPointMenuItemInterface;
use App\Repository\BundleStatusRepository;
use FeeBundle\Fee\FeeCalculatorResolver;
use PaymentBundle\Payment\PaymentMethodResolver;
use ShippingBundle\Shipping\ShippingResolver;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TaxBundle\Tax\TaxCalculatorResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminBundlesNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly ShippingResolver $shippingResolver,
        private readonly FeeCalculatorResolver $feeCalculatorResolver,
        private readonly TaxCalculatorResolver $taxCalculatorResolver,
        private readonly PaymentMethodResolver $paymentMethodResolver,
        #[AutowireIterator('app.injection_point_menu_item')]
        private readonly iterable $injectionPointMenuItems,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shipping_menu_items', [$this, 'getShippingMenuItems']),
            new TwigFunction('fee_menu_items', [$this, 'getFeeMenuItems']),
            new TwigFunction('tax_menu_items', [$this, 'getTaxMenuItems']),
            new TwigFunction('payment_menu_items', [$this, 'getPaymentMenuItems']),
            new TwigFunction('injection_point_menu_items', [$this, 'getInjectionPointMenuItems']),
        ];
    }

    /** @return array<int, array{label: string, route: string}> */
    public function getShippingMenuItems(): array
    {
        return $this->shippingResolver->getMenuItems();
    }

    /** @return array<int, array{label: string, route: string}> */
    public function getFeeMenuItems(): array
    {
        return $this->feeCalculatorResolver->getMenuItems();
    }

    /** @return array<int, array{label: string, route: string, source: string}> */
    public function getTaxMenuItems(): array
    {
        return $this->taxCalculatorResolver->getMenuItems();
    }

    /** @return array<int, array{label: string, route: string}> */
    public function getPaymentMenuItems(): array
    {
        return $this->paymentMethodResolver->getMenuItems();
    }

    /**
     * Grouped by each item's own section label, in first-appearance order, so bundles
     * that share the generic "injection point" nav mechanism can still render under
     * distinct headings instead of one fixed "Custom Page" bucket for all of them.
     * Skips any item whose owning bundle is Inactive — there's no dedicated hub bundle
     * for injection points the way Shipping/Fee/Tax/Payment have one, so the active
     * check lives here instead.
     *
     * @return array<string, list<array{label: string, route: string}>>
     */
    public function getInjectionPointMenuItems(): array
    {
        $grouped = [];
        foreach ($this->injectionPointMenuItems as $item) {
            if (!$item instanceof InjectionPointMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            $grouped[$item->getSection()][] = ['label' => $item->getLabel(), 'route' => $item->getRoute()];
        }

        return $grouped;
    }
}
