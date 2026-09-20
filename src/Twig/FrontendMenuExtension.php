<?php

declare(strict_types=1);

namespace App\Twig;

use App\Contract\Menu\FrontendMenuItemInterface;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomMenuItemRepository;
use App\Repository\FrontendMenuItemStatusRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FrontendMenuExtension extends AbstractExtension
{
    /** @param iterable<FrontendMenuItemInterface> $menuItems */
    public function __construct(
        #[AutowireIterator('app.frontend_menu_item')]
        private readonly iterable $menuItems,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly FrontendMenuItemStatusRepository $menuItemStatusRepo,
        private readonly CustomMenuItemRepository $customMenuItemRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('frontend_menu_items', [$this, 'getFrontendMenuItems']),
        ];
    }

    /**
     * Customer-facing nav entries — contributed by bundles, built into core, or added by hand
     * via Frontend Menu Management — in the admin-configured order. A code-registered item is
     * skipped if: the owning bundle is Inactive (Bundle Management kill-switch), the admin has
     * turned this specific item off, or the item's own isVisible() says no — that last check
     * lets a bundle layer its own runtime logic on top of the other two, coarser toggles. A
     * custom item is skipped only if the admin has turned it off.
     *
     * @return list<array{label: string, route: ?string, routeParams: array<string, mixed>, url: ?string}>
     */
    public function getFrontendMenuItems(): array
    {
        $items = [];
        foreach ($this->menuItems as $item) {
            if (!$item instanceof FrontendMenuItemInterface || !$this->bundleStatusRepo->isActiveForInstance($item)) {
                continue;
            }

            if (!$this->menuItemStatusRepo->isActive($item->getKey()) || !$item->isVisible()) {
                continue;
            }

            $items[] = [
                'label' => $item->getLabel(),
                'route' => $item->getRoute(),
                'routeParams' => $item->getRouteParams(),
                'url' => null,
                'sortOrder' => $this->menuItemStatusRepo->sortOrderFor($item->getKey()),
            ];
        }

        foreach ($this->customMenuItemRepo->findActiveOrdered() as $customItem) {
            $items[] = [
                'label' => $customItem->getLabel(),
                'route' => null,
                'routeParams' => [],
                'url' => $customItem->getUrl(),
                'sortOrder' => $customItem->getSortOrder(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$a['sortOrder'], $a['label']] <=> [$b['sortOrder'], $b['label']]);

        return array_map(static fn (array $item): array => [
            'label' => $item['label'],
            'route' => $item['route'],
            'routeParams' => $item['routeParams'],
            'url' => $item['url'],
        ], $items);
    }
}
