<?php

declare(strict_types=1);

namespace App\Menu\Admin;

/**
 * One entry in the admin sidebar tree — either a default (core) entry sourced from
 * AdminMenuCatalog, or a custom entry injected by an AdminMenuOverrideProviderInterface.
 *
 * Immutable and cheap to rebuild: AdminMenuTreeBuilder produces a fresh list of these on every
 * call rather than mutating shared state, so unit tests can construct/compare them freely.
 */
final class AdminMenuNode
{
    /**
     * @param list<string> $exactRoutes route names that make this entry (or, for a group, one of
     *     its descendants) render as "current"/"open" — mirrors the `route == '...'` checks the
     *     hardcoded template used to write by hand
     * @param list<string> $routePrefixes route-name prefixes for the same purpose, mirroring the
     *     template's `route starts with '...'` checks
     * @param array<string, mixed> $routeParams extra params for path() — only Product Add uses this
     * @param ?string $icon raw inline `<svg>` markup for a top-level entry's nav icon, rendered
     *     unescaped. Safe only because core and bundle code are the only writers of it. Do NOT
     *     copy this shape for anything a person can supply — see $affordanceIcon below, which is a
     *     name for exactly that reason.
     * @param ?string $attachTo the KEY of the row this item renders on instead of its own, as a
     *     small icon (queue item 35) — "New Bill" as a + on the "Bills" row, not "New Bill" as a
     *     row of its own AND that same + beside "Bills". It NAMES its parent; it does not attach
     *     to whatever happens to sit above it. Ordering here is numeric, bundles inject into the
     *     same groups and App Management can reorder, so a positional rule would one day point at
     *     the wrong row with nothing erroring and looking entirely correct — whereas a named
     *     target that has gone missing simply draws no icon, and says so in the log, and the item
     *     falls back to keeping its own row rather than disappearing (see
     *     AdminMenuTreeBuilder::assemble()). Null (the default) means no icon anywhere, which is
     *     every item that existed before this did.
     * @param ?string $affordanceIcon which icon to draw, BY NAME, from App\Menu\Admin\AdminMenuIconSet
     *     — never markup. Optional: absent, or naming an icon this app does not have, draws the
     *     affordance as a bare button rather than dropping it, because the glyph is decoration and
     *     the link is not. Spelled differently from $icon on purpose, and the difference is not an
     *     inconsistency: $icon below is raw inline `<svg>`, which is safe because only core writes
     *     it, whereas an affordance icon can reach the builder from
     *     /admin/bundles/admin-menu/custom/create — a person's input — where raw markup would be
     *     stored XSS and a name cannot be. See AdminMenuIconSet.
     * @param ?string $accessibleName what a screen reader announces for the affordance, when the
     *     item's own label would not read right in context — a cog labelled "Settings" sitting on
     *     a row called "Purchases" should say "Purchase Settings", since "Settings" alone reads as
     *     a generic link wherever it is encountered. Optional: affordanceName() falls back to the
     *     label, which is usually already the true thing to announce.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $route,
        public readonly ?string $icon,
        public readonly ?string $parent,
        public readonly int $order,
        public readonly ?string $requiresRole = null,
        public readonly bool $custom = false,
        public readonly ?string $url = null,
        public readonly array $routeParams = [],
        public readonly array $exactRoutes = [],
        public readonly array $routePrefixes = [],
        public readonly ?string $attachTo = null,
        public readonly ?string $affordanceIcon = null,
        public readonly ?string $accessibleName = null,
    ) {
    }

    /** True if this item also renders as an icon on another row, beside its own. */
    public function isAffordance(): bool
    {
        return $this->attachTo !== null && $this->attachTo !== '';
    }

    /**
     * The accessible name for this item's affordance: its own stated one, or its label.
     *
     * Never the icon. A row of plus signs announced as "plus, plus, plus" tells a screen-reader
     * user nothing, and this design gets the true answer for free — an affordance is still a real
     * menu item with its own label, route and permission, so there is always something honest to
     * say.
     */
    public function affordanceName(): string
    {
        return $this->accessibleName !== null && $this->accessibleName !== '' ? $this->accessibleName : $this->label;
    }

    /**
     * The `<svg>` this item's affordance draws, or null for no glyph at all.
     *
     * Null when the item names no icon, and null when it names one this app does not have. Neither
     * takes the affordance away: the template still draws the button, with its href, its accessible
     * name and its hover and focus behaviour intact, and app.css gives it a fixed 22x22 box so that
     * a button with nothing in it is still something a person can hit and a keyboard can land on.
     *
     * That split is the point. The icon is the decoration; the LINK is the function. Losing a glyph
     * costs somebody nothing they were using. Losing the shortcut costs them the shortcut, and
     * there is no version of a bad icon name that is worth paying that for. The unknown name is
     * logged by AdminMenuTreeBuilder, with the names that do exist, so it stays findable.
     */
    public function affordanceIconSvg(): ?string
    {
        return $this->affordanceIcon === null ? null : AdminMenuIconSet::svg($this->affordanceIcon);
    }

    /** A new node with the same identity but a different parent/order — used while merging overrides. */
    public function withPlacement(?string $parent, int $order): self
    {
        return new self(
            $this->key,
            $this->label,
            $this->route,
            $this->icon,
            $parent,
            $order,
            $this->requiresRole,
            $this->custom,
            $this->url,
            $this->routeParams,
            $this->exactRoutes,
            $this->routePrefixes,
            $this->attachTo,
            $this->affordanceIcon,
            $this->accessibleName,
        );
    }

    /** True if $route exactly matches, or starts with, one of this node's declared route hints. */
    public function matchesRoute(?string $route): bool
    {
        if ($route === null || $route === '') {
            return false;
        }

        if (in_array($route, $this->exactRoutes, true)) {
            return true;
        }

        foreach ($this->routePrefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
