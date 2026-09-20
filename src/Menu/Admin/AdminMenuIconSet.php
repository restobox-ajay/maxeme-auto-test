<?php

declare(strict_types=1);

namespace App\Menu\Admin;

/**
 * The named icons a sidebar row affordance may draw (queue item 35). A menu item asks for one BY
 * NAME — `affordanceIcon: 'plus'` — and the markup lives here, in one place, owned by the app.
 *
 * Why a name and not the markup itself, which is how every other sidebar icon is declared today
 * (raw `<svg>` strings in AdminMenuCatalog::ICONS and in the bundles' own menu providers):
 * /admin/bundles/admin-menu/custom/create lets a PERSON create a sidebar item through the UI, and
 * those specs reach App\Menu\Admin\AdminMenuTreeBuilder by exactly the same route a bundle's do.
 * A raw-SVG field on that form would be user-supplied markup rendered unescaped into every admin
 * page — a stored XSS hole with the whole console inside it. A name cannot be: it either matches
 * one of the constants below or it draws nothing. The custom-item form does not offer an icon
 * today, and this is the shape that keeps it safe if it ever does.
 *
 * The set is deliberately SMALL. `plus` is the one that ships in anger; the rest exist so the
 * second kind of affordance needs no further design, not because anybody asked for them yet.
 *
 * Every glyph is the same shape as the nav icons it sits beside — 14x14, a 0 0 24 24 viewBox,
 * `fill="none"`, `stroke="currentColor"`, stroke-width 2.5, round caps and joins — so an
 * affordance optically matches its neighbours rather than sitting a shade heavier or lighter.
 * `plus` is centred on (12,12) with even 7-unit arms, which is what makes it read as square at
 * 14px. The artwork elsewhere is rough on purpose and can be replaced without touching a caller:
 * a name is the contract, the path data is not.
 */
final class AdminMenuIconSet
{
    /** The shared opening tag, identical to the one every existing sidebar icon already uses. */
    private const OPEN = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">';

    /**
     * name => the glyph's path/line children, without the wrapping <svg>.
     *
     * Harvested from SVG this app already draws wherever one existed, rather than drawn fresh:
     *  - plus     the quick-create toggle in templates/admin/_main/layout.html.twig
     *  - search   the catalogue search box in templates/customer/_main/layout.html.twig
     *  - cog      AdminMenuCatalog::ICONS['settings']
     *  - download the upload tray in templates/admin/product/import.html.twig, arrow reversed
     *  - pencil   nothing in the app to harvest; a plain one, artwork deliberately unresolved
     *  - external nothing in the app to harvest; likewise
     *
     * @var array<string, string>
     */
    private const GLYPHS = [
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'cog' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    ];

    /**
     * Every icon name an item may ask for, in declaration order.
     *
     * A name you have to already know is a bad interface, so this is public and every complaint
     * quotes it: App\Menu\Admin\AdminMenuTreeBuilder's log line for an unknown name lists the set,
     * so somebody who wrote 'gear' is shown 'cog' rather than left to go looking. It is also what
     * a UI offering a choice of icon should enumerate, rather than asking anybody to type one.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::GLYPHS);
    }

    /** The known names as one readable list, for error messages and for anything offering a choice. */
    public static function nameList(): string
    {
        return implode(', ', self::names());
    }

    public static function has(string $name): bool
    {
        return isset(self::GLYPHS[$name]);
    }

    /**
     * The `<svg>` for a name, or null for a name this set does not know.
     *
     * Null rather than an exception. Nothing here may stop a menu rendering: an unknown name
     * costs the GLYPH and not the button, so the shortcut still works and still announces itself —
     * see AdminMenuNode::affordanceIconSvg(). AdminMenuTreeBuilder logs the name at merge time,
     * quoting nameList(), so the mistake stays findable without being fatal.
     */
    public static function svg(string $name): ?string
    {
        if (!isset(self::GLYPHS[$name])) {
            return null;
        }

        return self::OPEN . self::GLYPHS[$name] . '</svg>';
    }
}
