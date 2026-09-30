<?php

declare(strict_types=1);

namespace App\Maxeme\Menu;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Menu\Admin\AdminMenuCatalog;
use App\Menu\Admin\AdminMenuIconSet;
use App\Repository\BundleStatusRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Maxeme admin sidebar: hides every core (B2B) catalog entry and injects the shop's own groups
 * from the `maxeme.admin_menu` parameter (config/packages/maxeme.yaml).
 *
 * Hiding is presentational only; the core routes stay reachable and keep their own access rules.
 *
 * A child's `add` becomes the row's "+" (core's row affordance): a link to `route` (with `params`), opening the
 * modal named by `modal` on arrival (maxeme.js), shown to `role` (default: the row's own).
 *
 * @phpstan-type MenuAdd array{label: string, route: string, params?: array<string, string>, modal?: string, role?: string}
 * @phpstan-type MenuChild array{key: string, label: string, route: string, role?: string, match?: list<string>, add?: MenuAdd}
 * @phpstan-type MenuGroup array{key: string, label: string, icon: string, role?: string, children: list<MenuChild>}
 */
final class MaxemeAdminMenuProvider implements AdminMenuOverrideProviderInterface
{
    private const KEY_PREFIX = 'maxeme.';

    /** @param list<MenuGroup> $menu */
    public function __construct(
        #[Autowire(param: 'maxeme.admin_menu')]
        private readonly array $menu,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getSource(): string
    {
        return BundleStatusRepository::CORE_SOURCE;
    }

    public function getHiddenKeys(): array
    {
        return AdminMenuCatalog::keys();
    }

    public function getOrderOverrides(): array
    {
        return [];
    }

    public function getParentOverrides(): array
    {
        return [];
    }

    public function getCustomItems(): array
    {
        $items = [];
        $order = 0;

        foreach ($this->menu as $group) {
            $groupKey = self::KEY_PREFIX . $group['key'];
            $groupMatches = [];
            $children = [];

            foreach ($group['children'] as $child) {
                $matches = $child['match'] ?? [];
                $groupMatches = [...$groupMatches, $child['route'], ...$matches];
                $childKey = $groupKey . '.' . $child['key'];
                $children[] = [
                    'key' => $childKey,
                    'label' => $child['label'],
                    'url' => '',
                    'route' => $child['route'],
                    'routePrefixes' => $matches,
                    'parent' => $groupKey,
                    'order' => $order += 10,
                    'requiresRole' => $child['role'] ?? null,
                ];

                if (isset($child['add'])) {
                    $add = $child['add'];
                    $children[] = [
                        'key' => $childKey . '.add',
                        'label' => $add['label'],
                        'url' => $this->urls->generate($add['route'], ($add['params'] ?? []) + (isset($add['modal']) ? ['_fragment' => $add['modal']] : [])),
                        'parent' => $groupKey,
                        'order' => $order += 10,
                        'requiresRole' => $add['role'] ?? $child['role'] ?? null,
                        'attachTo' => $childKey,
                        'affordanceIcon' => 'plus',
                        'accessibleName' => $add['label'],
                    ];
                }
            }

            // The group itself first, so the children's parent resolves when they are merged.
            $items[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'url' => '',
                'group' => true,
                'icon' => AdminMenuIconSet::svg($group['icon']),
                'routePrefixes' => array_values(array_unique($groupMatches)),
                'parent' => null,
                'order' => $order += 10,
                'requiresRole' => $group['role'] ?? null,
            ];
            array_push($items, ...$children);
        }

        return $items;
    }
}
