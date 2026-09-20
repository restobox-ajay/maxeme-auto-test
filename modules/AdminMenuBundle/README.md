# AdminMenuBundle

**Type:** Configuration · **Name:** Admin Menu · **Edit route:** `admin_bundle_admin_menu_config`

## What it does

Lets Tech Support hide, reorder and reparent individual admin sidebar entries, and add fully
custom link entries of their own — a drag-and-drop builder, not just a checkbox list.

**Hiding/reordering/reparenting a link is not a permission.** The route behind a core entry is
still reachable by URL and still enforces whatever access rules it already had. Nothing in the
app reads this bundle's overrides for authorization, and nothing should start. Core entries can
only be hidden, never renamed or re-iconed; custom entries have no existence in core, so they are
fully deletable instead.

## How it works

Core owns the seam and stays inert without this bundle:

- `App\Menu\Admin\AdminMenuCatalog::defaultTree()` (core) is the default admin sidebar as data —
  label/route/icon/parent/order for every entry, lifted straight from the hardcoded markup it
  replaced. It is the single source of truth both `admin_menu_tree()` and this bundle's builder
  screen read.
- `templates/admin/_main/layout.html.twig` (core) loops `admin_menu_tree()` instead of hand-typing
  each entry.
- `App\Twig\AdminMenuExtension` (core) exposes `admin_menu_tree()`, delegating the merge to
  `App\Menu\Admin\AdminMenuTreeBuilder`: it asks every service tagged
  `app.admin_menu_override_provider` for its overrides, skipping any whose owning bundle is
  Inactive — the same gate `App\Service\TemplateOverrideResolver` uses. **No provider registered
  means the default tree, unchanged**, so deleting `modules/AdminMenuBundle` leaves the sidebar
  exactly as it was, with nothing to clean up.
- `AdminMenuBundle\Menu\AdminMenuOverrideProvider` (here) is the only provider, implementing
  `App\Contract\Menu\AdminMenuOverrideProviderInterface`.

## How it's configured

- **On/off**: the bundle's own Active/Inactive status on App Management. Inactive means the whole
  default menu is back; everything stored here is kept, not erased.
- **What/where**: `/admin/bundles/admin-menu`, `ROLE_TECH_SUPPORT` only — a drag-and-drop table
  covering every core entry plus every custom entry: drag rows to reorder, a Hide/Show button per
  core row, a Parent dropdown per row (core entries: Default / Top level / any existing group;
  custom entries: Top level / any existing group), and full create/edit/delete for custom entries.

The screen is reachable from App Management (its `getEditRoute()`) and also has its own shortcut
in the sidebar: `AdminMenuBundle\Menu\AdminMenuMenuItem` tags itself `app.injection_point_menu_item`
(see [injection-points.md](../../docs/bundles/injection-points.md)), so it shows up under Apps ›
Configuration. Reaching the link doesn't imply access — a non-Tech-Support admin who clicks it
still gets a 403 from the controller's own check, exactly the "hiding is not access control"
distinction this bundle exists to make.

## Storage

Two entities, since (unlike the old hide-only version) this needs real relational shape —
`config/packages/doctrine.yaml`'s `auto_mapping: true` picks up any bundle's own `Entity/`
directory, the same as `NewsBundle\Entity\NewsPost` and core's own
`App\Entity\FrontendMenuItemStatus`/`CustomMenuItem`:

- `AdminMenuBundle\Entity\AdminMenuItemStatus` — one row per *touched* core key (lazily created on
  first hide/reorder/reparent, see `ensureByKey()`): `hidden` (bool), `sortOrder` (nullable int,
  null = no override), `parentKey` (nullable string; null = no override, the sentinel
  `AdminMenuItemStatus::ROOT_PARENT` = explicitly moved to the top level, anything else = a target
  group key). An untouched core key has no row and stays exactly where `defaultTree()` puts it.
- `AdminMenuBundle\Entity\AdminCustomMenuItem` — one row per custom entry: `itemKey` (generated
  once at creation, stable identity for the tree builder), `label`, `url`, `parentKey` (nullable),
  `sortOrder`. Fully deletable, unlike a core key.

Both get their own migration (`migrations/Version20260805050000.php`) rather than an `AppSetting`
row like the old hide-only version: reorder/reparent need queryable relational columns, not a
single JSON blob.

## External dependencies

`App\Contract\Menu\AdminMenuOverrideProviderInterface`, `App\Menu\Admin\AdminMenuCatalog`,
`App\Menu\Admin\AdminMenuNode`, `App\Contract\Hook\InjectionPointMenuItemInterface`,
`App\Validation\Constraint\ValidCustomMenuItemRequest` (reused verbatim from core's Frontend Menu
Management — the same label+url shape) — all core. Core never references this bundle.
