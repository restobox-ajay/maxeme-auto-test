<?php

declare(strict_types=1);

namespace AdminMenuBundle\Menu;

use AdminMenuBundle\Repository\AdminCustomMenuItemRepository;
use AdminMenuBundle\Repository\AdminMenuItemStatusRepository;
use App\Contract\Menu\AdminMenuOverrideProviderInterface;

/**
 * Feeds every stored hide/reorder/reparent override, plus custom items, to core's
 * admin_menu_tree() Twig function.
 *
 * The only link between this bundle and the sidebar. App\Twig\AdminMenuExtension (via
 * App\Menu\Admin\AdminMenuTreeBuilder) skips this provider entirely while AdminMenuBundle is
 * Inactive, so the App Management kill-switch restores the default tree without touching what is
 * stored — replaces the old AdminMenuVisibilityProvider (hide-only) with the richer interface,
 * same gating.
 */
final class AdminMenuOverrideProvider implements AdminMenuOverrideProviderInterface
{
    /** Must stay equal to AdminMenuBundleDescriptor::getSource(), or the Inactive switch on App
     *  Management would quietly stop applying to this provider. Asserted by a test. */
    public const SOURCE = 'AdminMenuBundle';

    public function __construct(
        private readonly AdminMenuItemStatusRepository $itemStatusRepo,
        private readonly AdminCustomMenuItemRepository $customItemRepo,
    ) {
    }

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function getHiddenKeys(): array
    {
        $hidden = [];
        foreach ($this->itemStatusRepo->findAll() as $status) {
            if ($status->isHidden()) {
                $hidden[] = $status->getItemKey();
            }
        }

        return $hidden;
    }

    public function getOrderOverrides(): array
    {
        $overrides = [];
        foreach ($this->itemStatusRepo->findAll() as $status) {
            if ($status->getSortOrder() !== null) {
                $overrides[$status->getItemKey()] = $status->getSortOrder();
            }
        }

        return $overrides;
    }

    public function getParentOverrides(): array
    {
        $overrides = [];
        foreach ($this->itemStatusRepo->findAll() as $status) {
            if ($status->hasParentOverride()) {
                $overrides[$status->getItemKey()] = $status->getEffectiveParent();
            }
        }

        return $overrides;
    }

    public function getCustomItems(): array
    {
        $items = [];
        foreach ($this->customItemRepo->findAllOrdered() as $customItem) {
            $items[] = [
                'key' => $customItem->getItemKey(),
                'label' => $customItem->getLabel(),
                'url' => $customItem->getUrl(),
                'parent' => $customItem->getParentKey(),
                'order' => $customItem->getSortOrder(),
            ];
        }

        return $items;
    }
}
