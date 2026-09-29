<?php

declare(strict_types=1);

namespace App\Menu\Admin;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Repository\BundleStatusRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Merges App\Menu\Admin\AdminMenuCatalog::defaultTree() with every active
 * AdminMenuOverrideProviderInterface's hide/reorder/reparent/custom-item overrides into the tree
 * App\Twig\AdminMenuExtension::adminMenuTree() hands to the sidebar template.
 *
 * "Active" means the same thing it does for App\Twig\TemplateOverrideResolver and the interface
 * this replaces (AdminMenuVisibilityProviderInterface): BundleStatusRepository::isActive() must
 * answer true for the provider's getSource(), so switching a bundle Inactive on App Management
 * drops its overrides without the provider needing to know its own status. With no provider
 * registered or active, build() returns the default tree completely unchanged.
 *
 * Every merge step is defensive rather than exception-throwing, because the data driving it can
 * be edited directly in a bundle's storage and later drift out of sync with the running code (a
 * parent key can be deleted from AdminMenuCatalog after being saved, two providers can disagree,
 * a stored order value can be corrupted) — see the "adversarial" tests in
 * App\Tests\Menu\Admin\AdminMenuTreeBuilderTest for the exact cases this guards:
 *
 *  - a hidden key hides its whole subtree, regardless of a descendant's own hidden flag;
 *  - reparenting under a hidden, deleted, or non-existent key is ignored (falls back to the
 *    node's default parent) instead of orphaning the entry;
 *  - reparenting is restricted to existing top-level keys, so an override can move an entry
 *    between groups or to the root, but can never build a third level of nesting;
 *  - a parent-override cycle (however many providers or hops it takes to close) is broken by
 *    reverting every node on the cycle to its own default parent;
 *  - a custom item whose key collides with a core key, or an earlier custom item, is dropped
 *    rather than overwriting or duplicating anything;
 *  - an order value is coerced to an int and clamped to a sane range, so a malformed or
 *    oversized stored value can't corrupt the rest of the sort.
 *
 * Row affordances (queue item 35) are no exception to that, and the rule is worth stating outright
 * because the temptation to make them one is real: THE MENU ALWAYS RENDERS. An affordance is
 * decoration, and no decoration may prevent a menu, a row, or a link from working. A missing
 * `attachTo` target costs the icon; an unknown `affordanceIcon` costs the glyph and keeps the
 * button, because the icon is the decoration but the link is the function. Neither throws.
 *
 * That was argued the other way first, and the argument was not silly: attachTo is declared in code
 * beside the item, so a target that does not resolve is usually a typo, and swallowing a typo
 * leaves an icon nobody can find. What it missed is proportion, and one case it does not cover — a
 * bundle may attach to a CORE key, and core can rename or restructure that key knowing nothing
 * about the bundle. Throwing would let one bundle's decoration take the whole console's navigation
 * down for everybody. So these degrade and log instead: a warning naming the item and what it
 * wanted keeps a mistake findable without making it fatal.
 */
final class AdminMenuTreeBuilder
{
    private const MIN_ORDER = -1_000_000;
    private const MAX_ORDER = 1_000_000;

    public function __construct(
        private readonly BundleStatusRepository $bundleStatusRepo,
        // Defaulted so the many unit tests that build this directly need not care, and autowired to
        // the real logger in the app. A null logger is the right default for a collaborator whose
        // only job is to make a decoration's mistakes findable.
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param iterable<AdminMenuOverrideProviderInterface> $providers
     *
     * @return list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}>
     */
    public function build(iterable $providers): array
    {
        $active = $this->activeProviders($providers);
        $nodes = $this->collectNodes($active);

        /** @var array<string, true> $hidden */
        $hidden = [];
        /** @var array<string, int> $orderOverrides */
        $orderOverrides = [];
        /** @var array<string, ?string> $parentOverrides */
        $parentOverrides = [];

        // Checked against $nodes AFTER every active provider's own custom items (bundle groups
        // and screens included) are already merged in below — not, as this used to read, against
        // core's keys alone. A bundle's own top-level group had no row here at that point, so a
        // stored order/hidden/parent override naming it was silently accepted by nothing and
        // dropped: the ONE mechanism this app has for a person to override a menu position could
        // never reach a bundle-contributed key. See AdminMenuConfigController::index(), which
        // reads defaultNodes() below for the same reason: it could not even list these rows to
        // drag.
        foreach ($active as $provider) {
            foreach ($provider->getHiddenKeys() as $key) {
                if (is_string($key) && $key !== '') {
                    $hidden[$key] = true;
                }
            }

            foreach ($provider->getOrderOverrides() as $key => $order) {
                if (is_string($key) && isset($nodes[$key])) {
                    $orderOverrides[$key] = self::sanitizeOrder($order);
                }
            }

            foreach ($provider->getParentOverrides() as $key => $parent) {
                if (is_string($key) && isset($nodes[$key])) {
                    $parentOverrides[$key] = is_string($parent) ? $parent : null;
                }
            }
        }

        $this->reportUnresolvableAffordances($nodes);
        $effectiveParent = $this->resolveParents($nodes, $parentOverrides);
        $this->breakCycles($nodes, $effectiveParent);

        $visible = $this->resolveVisibility($nodes, $effectiveParent, $hidden);

        $placed = [];
        foreach ($visible as $key) {
            $order = $orderOverrides[$key] ?? $nodes[$key]->order;
            $placed[$key] = $nodes[$key]->withPlacement($effectiveParent[$key], $order);
        }

        return $this->assemble($placed);
    }

    /**
     * Every node a person can drag, hide or reparent on /admin/bundles/admin-menu — core's own
     * catalog PLUS every active bundle's structural items (its own top-level groups and their
     * screens), each still carrying its own default order/parent, before any stored override is
     * applied. Admin-created custom links (AdminMenuNode::$custom) are excluded: those come from
     * AdminCustomMenuItemRepository and AdminMenuConfigController::index() already lists them
     * separately, with full CRUD rather than a hide/reorder/reparent override.
     *
     * @param iterable<AdminMenuOverrideProviderInterface> $providers
     *
     * @return array<string, AdminMenuNode>
     */
    public function defaultNodes(iterable $providers): array
    {
        $nodes = $this->collectNodes($this->activeProviders($providers));

        return array_filter($nodes, static fn (AdminMenuNode $node): bool => !$node->custom);
    }

    /**
     * @param iterable<AdminMenuOverrideProviderInterface> $providers
     *
     * @return list<AdminMenuOverrideProviderInterface>
     */
    private function activeProviders(iterable $providers): array
    {
        $active = [];
        foreach ($providers as $provider) {
            if ($provider instanceof AdminMenuOverrideProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    /**
     * Core's default tree, with every active provider's own custom items (bundle groups/screens,
     * and AdminMenuBundle's admin-created links alike) merged in — the full set of keys a
     * hide/order/parent override, or a reparent target, can legally name.
     *
     * @param list<AdminMenuOverrideProviderInterface> $activeProviders
     *
     * @return array<string, AdminMenuNode>
     */
    private function collectNodes(array $activeProviders): array
    {
        /** @var array<string, AdminMenuNode> $nodes keyed by node key, default parent/order still on the node itself */
        $nodes = [];
        foreach (AdminMenuCatalog::defaultTree() as $node) {
            $nodes[$node->key] = $node;
        }

        /** @var list<array{key: string, label: string, url: string, parent: ?string, order: int}> $customSpecs */
        $customSpecs = [];
        foreach ($activeProviders as $provider) {
            foreach ($provider->getCustomItems() as $spec) {
                if (is_array($spec)) {
                    $customSpecs[] = $spec;
                }
            }
        }

        $this->mergeCustomItems($nodes, $customSpecs);

        return $nodes;
    }

    /**
     * @param array<string, AdminMenuNode> $nodes
     * @param list<array{key: string, label: string, url: string, parent: ?string, order: int}> $customSpecs
     */
    private function mergeCustomItems(array &$nodes, array $customSpecs): void
    {
        foreach ($customSpecs as $spec) {
            $key = isset($spec['key']) && is_string($spec['key']) ? trim($spec['key']) : '';
            if ($key === '' || isset($nodes[$key])) {
                // Empty/missing key, or a collision with a core key (or an earlier custom item):
                // dropped rather than overwriting anything a user or admin already relies on.
                continue;
            }

            $label = isset($spec['label']) && is_string($spec['label']) && $spec['label'] !== '' ? $spec['label'] : $key;
            $url = isset($spec['url']) && is_string($spec['url']) ? $spec['url'] : '';
            $parent = isset($spec['parent']) && is_string($spec['parent']) ? $spec['parent'] : null;

            // A custom item's own declared parent goes through the same guard as a parent
            // override: falls back to root rather than being dropped or crashing.
            if ($parent !== null && !self::isValidParentTarget($nodes, $parent)) {
                $parent = null;
            }

            $route = isset($spec['route']) && is_string($spec['route']) && $spec['route'] !== '' ? $spec['route'] : null;
            $prefixes = isset($spec['routePrefixes']) && is_array($spec['routePrefixes']) ? array_values(array_filter($spec['routePrefixes'], 'is_string')) : [];

            $nodes[$key] = new AdminMenuNode(
                key: $key,
                label: $label,
                route: $route,
                icon: isset($spec['icon']) && is_string($spec['icon']) ? $spec['icon'] : null,
                parent: $parent,
                order: self::sanitizeOrder($spec['order'] ?? 0),
                // Optional, the same rendering-only role check core entries get from
                // AdminMenuCatalog::REQUIRES_ROLE. Not authorization: the route enforces its own.
                requiresRole: isset($spec['requiresRole']) && is_string($spec['requiresRole']) && $spec['requiresRole'] !== '' ? $spec['requiresRole'] : null,
                custom: $route === null && !(isset($spec['group']) && $spec['group'] === true),
                url: $url,
                exactRoutes: $route !== null ? [$route] : [],
                routePrefixes: $prefixes,
                // The three row-affordance fields (queue item 35), read here because this is where
                // every provider's spec array lands — a bundle's and, through
                // AdminMenuBundle\Menu\AdminMenuOverrideProvider, the ones a person creates at
                // /admin/bundles/admin-menu/custom/create. The contract is written out in full on
                // App\Contract\Menu\AdminMenuOverrideProviderInterface::getCustomItems(), which is
                // where a bundle author reads it; in short:
                //
                //   attachTo        the KEY of the row this entry also draws an icon on
                //   affordanceIcon  which icon, BY NAME, from App\Menu\Admin\AdminMenuIconSet
                //   accessibleName  what a screen reader announces, defaulting to the label
                //
                // All three optional, so a spec written before they existed builds exactly the node
                // it always did. affordanceIcon is a NAME and never markup — and that difference
                // from the `icon` key above is the reason this comment is here rather than nowhere:
                // `icon` is raw `<svg>` and only core and bundle code write it, but specs from that
                // custom-item FORM arrive on this very line, so a raw-markup field there would be a
                // person's input rendered unescaped into every admin page. A name cannot be.
                attachTo: isset($spec['attachTo']) && is_string($spec['attachTo']) && $spec['attachTo'] !== '' ? $spec['attachTo'] : null,
                affordanceIcon: isset($spec['affordanceIcon']) && is_string($spec['affordanceIcon']) ? $spec['affordanceIcon'] : null,
                accessibleName: isset($spec['accessibleName']) && is_string($spec['accessibleName']) && $spec['accessibleName'] !== '' ? $spec['accessibleName'] : null,
            );
        }
    }

    /**
     * @param array<string, AdminMenuNode> $nodes
     * @param array<string, ?string> $parentOverrides
     *
     * @return array<string, ?string>
     */
    private function resolveParents(array $nodes, array $parentOverrides): array
    {
        $effective = [];
        foreach ($nodes as $key => $node) {
            $effective[$key] = $node->parent;
        }

        foreach ($parentOverrides as $key => $newParent) {
            if ($newParent === null) {
                $effective[$key] = null;
                continue;
            }

            if ($newParent === $key || !isset($nodes[$newParent])) {
                continue; // self-reference, or a since-deleted/non-existent parent: ignored
            }

            if (!self::isValidParentTarget($nodes, $newParent)) {
                continue;
            }

            $effective[$key] = $newParent;
        }

        return $effective;
    }

    /**
     * Breaks any parent-override cycle (a two-hop A<->B swap, or a longer loop across several
     * overrides) by reverting every node on the cycle to its own default parent. Walking every
     * node's ancestor chain up to `count($nodes) + 1` steps means a genuine cycle is always
     * detected — it cannot resolve to null in fewer steps than there are nodes — without needing
     * a separate "already validated" cache.
     *
     * @param array<string, AdminMenuNode> $nodes
     * @param array<string, ?string> $effectiveParent
     */
    private function breakCycles(array $nodes, array &$effectiveParent): void
    {
        $limit = count($nodes) + 1;
        // Traversal reads a frozen snapshot, never the array being mutated: fixing one member of
        // a multi-node cycle must not change what the NEXT member's walk sees, or detection
        // becomes order-dependent — e.g. two groups pointing at each other would resolve to only
        // one of them reverting (whichever key the foreach reaches second would walk into the
        // first one's already-fixed, no-longer-cyclic parent and stop, quietly nesting the second
        // group under the first instead of restoring both to top level).
        $snapshot = $effectiveParent;

        foreach (array_keys($effectiveParent) as $key) {
            $cursor = $snapshot[$key];
            $steps = 0;
            $cyclic = false;

            while ($cursor !== null) {
                if ($cursor === $key) {
                    $cyclic = true;
                    break;
                }

                if (++$steps > $limit || !array_key_exists($cursor, $snapshot)) {
                    break;
                }

                $cursor = $snapshot[$cursor];
            }

            if ($cyclic) {
                $effectiveParent[$key] = $nodes[$key]->parent;
            }
        }
    }

    /**
     * A hidden key hides its whole subtree: a node is invisible if it (or any ancestor, following
     * effective parents) is in $hidden. Ancestor-walk is bounded the same way breakCycles() is,
     * so a residual cycle that survived (there should not be one) can't spin forever.
     *
     * @param array<string, AdminMenuNode> $nodes
     * @param array<string, ?string> $effectiveParent
     * @param array<string, true> $hidden
     *
     * @return list<string>
     */
    private function resolveVisibility(array $nodes, array $effectiveParent, array $hidden): array
    {
        $limit = count($nodes) + 1;
        $visible = [];

        foreach (array_keys($nodes) as $key) {
            $cursor = $key;
            $steps = 0;
            $isHidden = false;

            while ($cursor !== null) {
                if (isset($hidden[$cursor])) {
                    $isHidden = true;
                    break;
                }

                if (++$steps > $limit) {
                    break;
                }

                $cursor = $effectiveParent[$cursor] ?? null;
            }

            if (!$isHidden) {
                $visible[] = $key;
            }
        }

        return $visible;
    }

    /**
     * Logs the two ways an affordance's own declaration can fail to resolve. Neither stops
     * anything: assemble() files no icon for a target it cannot find, and the template draws a
     * glyphless button for an icon name it cannot resolve. This exists so a mistake is FINDABLE,
     * which is the whole reason to log rather than stay silent — without it the only symptom is an
     * icon that never appeared, which looks exactly like nobody having added one.
     *
     * A target that exists but is HIDDEN is not reported: that is an admin configuring their own
     * sidebar, not a mistake, and warning about it would train people to ignore the warnings.
     *
     * @param array<string, AdminMenuNode> $nodes
     */
    private function reportUnresolvableAffordances(array $nodes): void
    {
        foreach ($nodes as $key => $node) {
            if (!$node->isAffordance()) {
                continue;
            }

            if (!isset($nodes[(string) $node->attachTo])) {
                $this->logger->warning(
                    'Admin sidebar item "{item}" attaches to "{target}", which no menu item declares, so no icon is'
                    . ' drawn for it. The item keeps its own row. attachTo names its parent row by key — check the'
                    . ' key, and that whatever declares it is still there.',
                    ['item' => $key, 'target' => (string) $node->attachTo],
                );
            }

            // Naming no icon at all is not a mistake — the field is optional and the affordance
            // renders as a bare button. Naming one that does not exist is, and the message says
            // what the names ARE, so somebody who wrote 'gear' reads 'cog' off the log rather than
            // going looking for the set.
            if ($node->affordanceIcon !== null && !AdminMenuIconSet::has($node->affordanceIcon)) {
                $this->logger->warning(
                    'Admin sidebar item "{item}" asks for the affordance icon "{icon}", which this app does not have,'
                    . ' so its affordance is drawn without a glyph. The icon names that do exist are: {known}.',
                    ['item' => $key, 'icon' => $node->affordanceIcon, 'known' => AdminMenuIconSet::nameList()],
                );
            }
        }
    }

    /**
     * @param array<string, AdminMenuNode> $placed
     *
     * @return list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}>
     */
    private function assemble(array $placed): array
    {
        $byOrder = static function (AdminMenuNode $a, AdminMenuNode $b): int {
            return [$a->order, $a->key] <=> [$b->order, $b->key];
        };

        // A flagged item is filed under the row it names INSTEAD OF keeping a row of its own —
        // "List Orders" as its own row and a `+` beside it going to the same workflow read as two
        // links, not one link with a shortcut. This used to be additive (both rendered); it no
        // longer is, and the two are mutually exclusive per item, decided below.
        //
        // A node whose OWN target resolves in $placed is a CANDIDATE to be swallowed into a pure
        // icon — computed first and kept separate from the decision below, because a candidate can
        // itself be the target of another one (a bundle attaching to another bundle's create
        // affordance). Swallowing both would draw the second icon on a row that no longer exists to
        // carry it, which is worse than the duplication this feature removes: an icon nobody can
        // find beats a row that used to exist. So a candidate is actually swallowed only when its
        // target is NOT itself a candidate — a cycle of two candidates naming each other resolves
        // the same safe way, since neither's target then qualifies as an unswallowed host, and both
        // keep their own row.
        //
        // A target that is real (assertAffordanceTargetsExist() saw to that) but not in $placed
        // means an admin has HIDDEN that row. Stored data, not a coding error, and the menu always
        // renders (see the class docblock) — the item is simply never a candidate, and keeps the
        // row it already had.
        /** @var array<string, true> $candidates */
        $candidates = [];
        foreach ($placed as $node) {
            if ($node->isAffordance() && isset($placed[(string) $node->attachTo])) {
                $candidates[$node->key] = true;
            }
        }

        // Keyed by target key, sorted by the item's own existing order number, exactly as siblings
        // sort. There is no cap: the owner is the only person adding these and asked not to
        // bother, so this is written down instead — two icons on a sidebar row is tight and three
        // is cramped. Nobody should assume there is room for five.
        /** @var array<string, list<AdminMenuNode>> $affordancesOf */
        $affordancesOf = [];
        /** @var array<string, true> $attachedKeys keys successfully drawn as a `+` — excluded below */
        $attachedKeys = [];

        foreach ($placed as $node) {
            if (!isset($candidates[$node->key])) {
                continue;
            }

            $targetKey = (string) $node->attachTo;
            if (isset($candidates[$targetKey])) {
                // The row this would attach to is itself being swallowed — see the note above.
                continue;
            }

            $affordancesOf[$targetKey][] = $node;
            $attachedKeys[$node->key] = true;
        }

        foreach ($affordancesOf as $targetKey => $items) {
            usort($items, $byOrder);
            $affordancesOf[$targetKey] = $items;
        }

        $topLevel = [];
        $childrenOf = [];

        foreach ($placed as $node) {
            if (isset($attachedKeys[$node->key])) {
                continue;
            }

            if ($node->parent === null) {
                $topLevel[] = $node;
            } else {
                // A parent that isn't itself in $placed (e.g. hidden) can't happen here: hiding
                // cascades to descendants in resolveVisibility(), so a visible node's effective
                // parent, if non-null, is always visible too.
                $childrenOf[$node->parent][] = $node;
            }
        }

        usort($topLevel, $byOrder);

        $tree = [];
        foreach ($topLevel as $node) {
            $children = $childrenOf[$node->key] ?? [];
            usort($children, $byOrder);

            // Each entry carries only the affordances belonging to rows it actually draws — its
            // own and its children's — so the template can look one up by row key without
            // searching the whole tree.
            $affordances = [];
            foreach (array_merge([$node], $children) as $row) {
                if (isset($affordancesOf[$row->key])) {
                    $affordances[$row->key] = $affordancesOf[$row->key];
                }
            }

            $tree[] = ['node' => $node, 'children' => $children, 'affordances' => $affordances];
        }

        return $tree;
    }

    /**
     * A valid reparent target is an existing, non-custom, non-hidden-by-construction node that
     * is itself a container in the DEFAULT tree (route === null) — i.e. one of the catalog's
     * group keys. This rules out: a since-deleted/non-existent key, another child (so overrides
     * move entries between groups/root but never build a third level), a custom item (custom
     * items are simple label+url leaves, never groups), and a single-link top-level entry like
     * "help" or "technical_docs" that has a route of its own and therefore renders as a link (or,
     * for technical_docs, a group with its own dynamically-sourced children) rather than a
     * generic children list — reparenting under one of those would silently orphan the entry.
     *
     * @param array<string, AdminMenuNode> $nodes
     */
    private static function isValidParentTarget(array $nodes, string $key): bool
    {
        if (!isset($nodes[$key])) {
            return false;
        }

        $target = $nodes[$key];

        return !$target->custom && $target->parent === null && $target->route === null;
    }

    /** Coerces a stored/provided order value to a bounded int, so a malformed or absurd value (a string, NAN, PHP_INT_MAX, a huge negative) can't corrupt the sort. */
    private static function sanitizeOrder(mixed $value): int
    {
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            return 0;
        }

        $int = (int) $value;

        return max(self::MIN_ORDER, min(self::MAX_ORDER, $int));
    }

    /**
     * Depth-first (top level in order, each one's children in order) flattening of a built tree
     * back to a flat key list — used to compare the effective sidebar order against
     * App\Menu\Admin\AdminMenuCatalog::keys() with no overrides applied.
     *
     * @param list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}> $tree
     *
     * @return list<string>
     */
    public static function flattenKeys(array $tree): array
    {
        $keys = [];
        foreach ($tree as $entry) {
            $keys[] = $entry['node']->key;
            foreach ($entry['children'] as $child) {
                $keys[] = $child->key;
            }
        }

        return $keys;
    }
}
