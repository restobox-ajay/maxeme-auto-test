<?php

declare(strict_types=1);

namespace App\Contract\Menu;

/**
 * Optional supplier of admin sidebar overrides: which core entries are hidden, reordered or
 * reparented, plus custom entries to inject.
 *
 * Core defines the seam but implements nothing: with no provider registered — i.e. with
 * modules/AdminMenuBundle deleted — App\Menu\Admin\AdminMenuTreeBuilder returns exactly
 * App\Menu\Admin\AdminMenuCatalog::defaultTree() and templates/admin/_main/layout.html.twig
 * renders exactly what that hardcodes.
 *
 * Same shape as the interface this replaces (AdminMenuVisibilityProviderInterface) and
 * TemplateOverrideProviderInterface: getSource() names the owning bundle so the Bundle
 * Management Active/Inactive kill-switch applies to the provider too, without the provider having
 * to check its own status — see App\Menu\Admin\AdminMenuTreeBuilder::build().
 *
 * Every override here is purely presentational: hiding, reordering or reparenting a core key
 * never changes the security attributes on the route behind it, and a hidden/reparented entry's
 * route stays reachable by URL. Core items can only be hidden, never renamed or re-iconed —
 * getCustomItems() is the only way to add new labels/links, and those are fully deletable since
 * they have no existence in core to fall back to.
 */
interface AdminMenuOverrideProviderInterface
{
    /** The owning bundle's source, checked against BundleStatusRepository::isActive(). */
    public function getSource(): string;

    /**
     * Keys from App\Menu\Admin\AdminMenuCatalog that must not be rendered. Hiding a group hides
     * its descendants too, regardless of their own hidden state — the same as the old template's
     * `{% if admin_menu_enabled('group') %}` wrapping a group's whole sub-list.
     *
     * @return list<string>
     */
    public function getHiddenKeys(): array;

    /**
     * Sidebar-order overrides for core keys. A key absent here keeps its default catalog order.
     * Only relative order among siblings (same effective parent) matters.
     *
     * @return array<string, int>
     */
    public function getOrderOverrides(): array;

    /**
     * Parent overrides for core keys: key => new parent key, or key => null to move it to the top
     * level. A key absent here keeps its default catalog parent (App\Menu\Admin\AdminMenuCatalog::groupOf()).
     *
     * @return array<string, ?string>
     */
    public function getParentOverrides(): array;

    /**
     * Custom entries to inject — links with no existence in core, so (unlike core keys) they can
     * be fully deleted, not just hidden.
     *
     * ## Row affordances (queue item 35)
     *
     * Three OPTIONAL extra keys also draw an entry as a small icon on the right of ANOTHER row —
     * a `+` on "Bills" for "New Bill", so the create screen is one click from the list. If your
     * bundle contributes a create screen, this is how it gets one, and it is two attributes:
     *
     *     ['key' => 'purchases.bill_new', 'label' => 'New Bill', 'url' => '',
     *      'parent' => 'purchases', 'order' => -840, 'route' => 'admin_bundle_procurement_bill_new',
     *      'attachTo' => 'purchases.bills', 'affordanceIcon' => 'plus']
     *
     *  - `attachTo`        the KEY of the row this entry also draws its icon on. It NAMES its
     *                      parent; it is never positional, because ordering here is numeric,
     *                      several bundles inject into the same groups and App Management can
     *                      reorder all of it — so "the row above me" would one day mean a
     *                      different row and look entirely correct doing it.
     *  - `affordanceIcon`  which icon, BY NAME, out of App\Menu\Admin\AdminMenuIconSet — never
     *                      markup. Call AdminMenuIconSet::names() for the set; today it is
     *                      plus, search, cog, download, pencil, external. It is a name and not an
     *                      `<svg>` string (which is how the `icon` key above works) for a concrete
     *                      reason: specs from /admin/bundles/admin-menu/custom/create, where a
     *                      PERSON creates a menu item through the UI, arrive at the builder by this
     *                      very method, so a raw-markup field would be user input rendered
     *                      unescaped into every admin page. A name cannot be.
     *  - `accessibleName`  what a screen reader announces. Falls back to the entry's own label,
     *                      which is usually already right — state one when the label would not be,
     *                      read on its own with no row around it: an abbreviation ("New PO"), or a
     *                      word that names two different things in this app ("Create Order").
     *
     * All three are optional and an entry that omits them renders exactly as entries always have —
     * there is no migration here and nothing to adopt on a schedule. The entry also KEEPS its own
     * sidebar row: the icon is a shortcut, not a move.
     *
     * Nothing here can break the menu. A target nothing declares, or an icon name that does not
     * exist, is logged and degraded — no icon in the first case, a button with no glyph in the
     * second — never thrown. An affordance is decoration; decoration does not get to stop a
     * navigation rendering.
     *
     * @return list<array{key: string, label: string, url: string, parent: ?string, order: int, attachTo?: string, affordanceIcon?: string, accessibleName?: string}>
     */
    public function getCustomItems(): array;
}
