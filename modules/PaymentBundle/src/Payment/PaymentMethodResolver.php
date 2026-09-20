<?php

declare(strict_types=1);

namespace PaymentBundle\Payment;

use App\Contract\Payment\PaymentMenuItemInterface;
use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Payment\PaymentMethodProviderInterface;
use App\Entity\Company;
use App\Entity\PaymentMethod;
use App\Repository\BundleStatusRepository;
use App\Repository\CompanyPaymentMethodRepository;
use App\Repository\PaymentMethodRepository;

final class PaymentMethodResolver
{
    /**
     * @param iterable<PaymentMethodInterface> $paymentMethods tagged app.payment_method — one instance per method
     * @param iterable<PaymentMethodProviderInterface> $paymentMethodProviders tagged app.payment_method_provider — one instance backs N methods
     * @param iterable<PaymentMenuItemInterface> $menuItems
     */
    public function __construct(
        private readonly iterable $paymentMethods,
        private readonly iterable $paymentMethodProviders,
        private readonly iterable $menuItems,
        private readonly PaymentMethodRepository $paymentMethodRepo,
        private readonly CompanyPaymentMethodRepository $companyPaymentMethodRepo,
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
            if (!$item instanceof PaymentMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            $items[] = ['label' => $item->getLabel(), 'route' => $item->getRoute()];
        }

        return $items;
    }

    /**
     * Registers every single-instance tagged method into the payment_method table
     * (lazy upsert, same pattern as Fee bundles). Must run before
     * CompanyPaymentMethodRepository::findEnabledForCompany() is consulted — that
     * repo's opt-out model treats "no row yet" as enabled, so a method missing its
     * row at read time would wrongly look unavailable on a fresh install.
     */
    private function ensureRegistered(): void
    {
        foreach ($this->paymentMethods as $method) {
            $this->paymentMethodRepo->ensureBySlug($method->getSlug(), [
                'name' => $method->getName(),
                'source' => $method::class,
            ]);
        }
    }

    /** @return PaymentMethodInterface[] */
    private function allMethods(): array
    {
        $methods = [];

        foreach ($this->paymentMethods as $method) {
            if ($this->bundleStatusRepo->isActiveForInstance($method)) {
                $methods[] = $method;
            }
        }

        foreach ($this->paymentMethodProviders as $provider) {
            if (!$this->bundleStatusRepo->isActiveForInstance($provider)) {
                continue;
            }

            foreach ($provider->getPaymentMethods() as $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** @return PaymentMethodInterface[] */
    public function getAvailableForCompany(Company $company): array
    {
        $this->ensureRegistered();

        $enabledSlugs = array_map(
            static fn (PaymentMethod $pm): string => $pm->getSlug(),
            $this->companyPaymentMethodRepo->findEnabledForCompany($company),
        );

        $available = array_values(array_filter(
            $this->allMethods(),
            static fn (PaymentMethodInterface $method): bool => in_array($method->getSlug(), $enabledSlugs, true),
        ));

        usort($available, static fn (PaymentMethodInterface $a, PaymentMethodInterface $b): int => $a->getPriority() <=> $b->getPriority());

        return $available;
    }

    /** @return PaymentMethodInterface[] */
    public function getAllMethods(): array
    {
        $this->ensureRegistered();

        $methods = $this->allMethods();
        usort($methods, static fn (PaymentMethodInterface $a, PaymentMethodInterface $b): int => $a->getPriority() <=> $b->getPriority());

        return $methods;
    }

    public function getBySlug(string $slug): ?PaymentMethodInterface
    {
        $this->ensureRegistered();

        foreach ($this->allMethods() as $method) {
            if ($method->getSlug() === $slug) {
                return $method;
            }
        }

        return null;
    }
}
