<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Contract\Fee\FeeMenuItemInterface;
use App\Contract\Hook\InjectionPointMenuItemInterface;
use App\Contract\Payment\PaymentMenuItemInterface;
use App\Contract\Shipping\ShippingMenuItemInterface;
use App\Contract\Tax\TaxMenuItemInterface;
use App\Repository\BundleStatusRepository;
use App\Repository\CompanyPaymentMethodRepository;
use App\Repository\PaymentMethodRepository;
use App\Twig\AdminBundlesNavExtension;
use FeeBundle\Fee\FeeCalculatorResolver;
use PaymentBundle\Payment\PaymentMethodResolver;
use PHPUnit\Framework\TestCase;
use ShippingBundle\Shipping\ShippingResolver;
use TaxBundle\Tax\TaxCalculatorResolver;
use Twig\TwigFunction;

final class AdminBundlesNavExtensionTest extends TestCase
{
    private function activeRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturn(true);

        return $repo;
    }

    private function shippingItem(string $label, string $route): ShippingMenuItemInterface
    {
        $item = $this->createStub(ShippingMenuItemInterface::class);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);

        return $item;
    }

    private function feeItem(string $label, string $route): FeeMenuItemInterface
    {
        $item = $this->createStub(FeeMenuItemInterface::class);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);

        return $item;
    }

    private function taxItem(string $label, string $route, string $source): TaxMenuItemInterface
    {
        $item = $this->createStub(TaxMenuItemInterface::class);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);
        $item->method('getSource')->willReturn($source);

        return $item;
    }

    private function paymentItem(string $label, string $route): PaymentMenuItemInterface
    {
        $item = $this->createStub(PaymentMenuItemInterface::class);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);

        return $item;
    }

    private function injectionItem(string $label, string $route, string $section): InjectionPointMenuItemInterface
    {
        $item = $this->createStub(InjectionPointMenuItemInterface::class);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);
        $item->method('getSection')->willReturn($section);

        return $item;
    }

    private function extension(
        array $injectionPointMenuItems = [],
        ?BundleStatusRepository $bundleStatusRepo = null,
    ): AdminBundlesNavExtension {
        $repo = $bundleStatusRepo ?? $this->activeRepo();

        $shippingResolver = new ShippingResolver([], [], [$this->shippingItem('Standard', 'admin_shipping_standard')], $repo);
        $feeResolver = new FeeCalculatorResolver([], [$this->feeItem('Handling', 'admin_fee_handling')], $repo);
        $taxResolver = new TaxCalculatorResolver([], [$this->taxItem('BC PST', 'admin_tax_bc', 'TaxBCBundle')], $repo);
        $paymentResolver = new PaymentMethodResolver(
            [],
            [],
            [$this->paymentItem('Credit Card', 'admin_payment_cc')],
            $this->createStub(PaymentMethodRepository::class),
            $this->createStub(CompanyPaymentMethodRepository::class),
            $repo,
        );

        return new AdminBundlesNavExtension(
            $shippingResolver,
            $feeResolver,
            $taxResolver,
            $paymentResolver,
            $injectionPointMenuItems,
            $repo,
        );
    }

    public function testGetShippingMenuItemsDelegatesToShippingResolver(): void
    {
        $extension = $this->extension();

        self::assertSame(
            [['label' => 'Standard', 'route' => 'admin_shipping_standard']],
            $extension->getShippingMenuItems(),
        );
    }

    public function testGetFeeMenuItemsDelegatesToFeeCalculatorResolver(): void
    {
        $extension = $this->extension();

        self::assertSame(
            [['label' => 'Handling', 'route' => 'admin_fee_handling']],
            $extension->getFeeMenuItems(),
        );
    }

    public function testGetTaxMenuItemsDelegatesToTaxCalculatorResolver(): void
    {
        $extension = $this->extension();

        self::assertSame(
            [['label' => 'BC PST', 'route' => 'admin_tax_bc', 'source' => 'TaxBCBundle']],
            $extension->getTaxMenuItems(),
        );
    }

    public function testGetPaymentMenuItemsDelegatesToPaymentMethodResolver(): void
    {
        $extension = $this->extension();

        self::assertSame(
            [['label' => 'Credit Card', 'route' => 'admin_payment_cc']],
            $extension->getPaymentMenuItems(),
        );
    }

    public function testGetInjectionPointMenuItemsGroupsBySectionInFirstAppearanceOrder(): void
    {
        $extension = $this->extension([
            $this->injectionItem('Custom Page A', 'admin_custom_a', 'Custom Page'),
            $this->injectionItem('Number1 Setting', 'admin_n1_setting', 'Number1 Integration'),
            $this->injectionItem('Custom Page B', 'admin_custom_b', 'Custom Page'),
        ]);

        self::assertSame(
            [
                'Custom Page' => [
                    ['label' => 'Custom Page A', 'route' => 'admin_custom_a'],
                    ['label' => 'Custom Page B', 'route' => 'admin_custom_b'],
                ],
                'Number1 Integration' => [
                    ['label' => 'Number1 Setting', 'route' => 'admin_n1_setting'],
                ],
            ],
            $extension->getInjectionPointMenuItems(),
        );
    }

    public function testGetInjectionPointMenuItemsSkipsItemsFromInactiveBundles(): void
    {
        $activeItem = $this->injectionItem('Active Item', 'admin_active', 'Custom Page');
        $inactiveItem = $this->injectionItem('Inactive Item', 'admin_inactive', 'Custom Page');

        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturnCallback(
            static fn (object $instance): bool => $instance !== $inactiveItem,
        );

        $extension = $this->extension([$activeItem, $inactiveItem], $repo);

        self::assertSame(
            ['Custom Page' => [['label' => 'Active Item', 'route' => 'admin_active']]],
            $extension->getInjectionPointMenuItems(),
        );
    }

    public function testGetInjectionPointMenuItemsSkipsNonInterfaceEntries(): void
    {
        $extension = $this->extension([new \stdClass()]);

        self::assertSame([], $extension->getInjectionPointMenuItems());
    }

    public function testGetFunctionsRegistersAllFiveMenuFunctions(): void
    {
        $extension = $this->extension();

        $functions = $extension->getFunctions();

        self::assertCount(5, $functions);
        foreach ($functions as $function) {
            self::assertInstanceOf(TwigFunction::class, $function);
        }

        $names = array_map(static fn (TwigFunction $f): string => $f->getName(), $functions);
        self::assertSame(
            ['shipping_menu_items', 'fee_menu_items', 'tax_menu_items', 'payment_menu_items', 'injection_point_menu_items'],
            $names,
        );
    }
}
