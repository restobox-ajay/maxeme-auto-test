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
     *  - calendar, wrench, chart, users  the Maxeme sidebar groups (config/packages/maxeme.yaml);
     *    users is AdminMenuCatalog::ICONS['users']
     *  - sliders  the Maxeme sidebar's Settings group (config/packages/maxeme.yaml)
     *  - database the Maxeme sidebar's Database group (config/packages/maxeme.yaml); a plain cylinder
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
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'chart' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'log' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="16" y2="15"/><line x1="8" y1="19" x2="12" y2="19"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'car' => '<path d="M5 17h14M5 17a2 2 0 1 1-4 0v-5l2-5h14l2 5v5a2 2 0 1 1-4 0"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'money' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        'sliders' => '<line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
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
