<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A sidebar item can render as an icon affordance on another row (queue item 35), conducted
 * against the real sidebar rather than against the tree that feeds it.
 *
 * Conducted per #624: every assertion below drives the actual admin console and reads the actual
 * rendered DOM. The unit half lives in App\Tests\Menu\Admin\AdminMenuTreeBuilderTest — that proves
 * the merge; this proves the page. The two that matter most are the ones that would otherwise be
 * assumed:
 *
 *  - the affordance is on the row it NAMED, not merely somewhere on the page. `see('New appointment')`
 *    would have passed with the icon rendered on Repair Order, or in the footer, or twice.
 *  - an UNFLAGGED item still renders as its own row. That is the claim the whole design rests on
 *    — three optional fields, nothing else touched — and it is the one that silently stops being
 *    true if the row markup ever grows a wrapper unconditionally.
 *
 * #627 applies here too: nothing below asserts a bare string against the whole page. Every check
 * is scoped to an element — by CSS selector, or by XPath where the claim is that the icon sits in
 * one particular row.
 */
final class AdminSidebarRowAffordanceCest
{
    /**
     * Every create item in the shipped sidebar. The shop's sidebar (App\Maxeme\Menu\MaxemeAdminMenuProvider)
     * hides every core and module catalog entry, so these are the `add:` entries of
     * `maxeme.admin_menu` in config/packages/maxeme.yaml, each announcing its stated label.
     *
     * Written out by href rather than derived from the tree on purpose: deriving it would assert
     * that the code agrees with itself, which it cannot help doing. What is worth pinning is that
     * these specific icons sit on these specific rows and go to these specific pages — the thing a
     * person would check by looking at the sidebar.
     *
     * @var list<array{string, string, string}>
     */
    private const FLAGGED = [
        // [the row it sits on, the affordance's own href, the name it announces]
        ['/admin/repair-orders', '/admin/clients/pick/repair_order', 'New repair order'],
        ['/admin/appointments', '/admin/clients/pick/appointment', 'New appointment'],
        ['/admin/reminders', '/admin/clients/pick/reminder', 'New reminder'],
        ['/admin/services', '/admin/services#manageAddModal', 'Add a new service'],
        ['/admin/product/detail/index', '/admin/product/inventory/create', 'Add a product'],
        ['/admin/category/index', '/admin/category/create', 'Add a product category'],
        ['/admin/bundles/procurement/vendors', '/admin/bundles/procurement/vendors/new', 'Add a vendor'],
        ['/admin/clients', '/admin/clients#manageAddModal', 'Add a new client'],
        ['/admin/staff', '/admin/staff#register-modal', 'Add a new user'],
        ['/admin/service-categories', '/admin/service-categories/new', 'Add a service category'],
        ['/admin/service-reminders', '/admin/service-reminders#reminderAddModal', 'Add a service reminder'],
        ['/admin/service-reminder-templates', '/admin/service-reminder-templates/new', 'Add a reminder template'],
        ['/admin/labour', '/admin/labour#manageAddModal', 'Add labour'],
        ['/admin/government-fees', '/admin/government-fees#manageAddModal', 'Add a government fee'],
    ];

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sidebar-affordance-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * XPath for the affordance sitting on one specific row: the `.nav-sub-row` wrapper that
     * contains the list link $listHref, and inside it the affordance pointing at $createHref.
     *
     * XPath rather than CSS because the assertion that matters is a containment one — the icon is
     * on THAT row — and `:has()` is not something Symfony's CSS selector compiles. $extra appends
     * a further predicate, e.g. an aria-label.
     */
    private function affordanceOnRow(string $listHref, string $createHref, string $extra = ''): string
    {
        $hasClass = static fn (string $class): string => sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $class);

        return sprintf(
            '//nav[@id="primary-navigation"]//div[%s][./a[%s][@href="%s"]]/a[%s][@href="%s"]%s',
            $hasClass('nav-sub-row'),
            $hasClass('nav-sub'),
            $listHref,
            $hasClass('nav-affordance'),
            $createHref,
            $extra,
        );
    }

    /**
     * Requirement 1. Each flagged item is an affordance ON THE ROW IT NAMED, and the link behind
     * that icon is its own route — not the row's, which would make the whole thing a decoration.
     */
    public function aFlaggedItemRendersOnItsNamedParentAndLinksToItsOwnRoute(FunctionalTester $I): void
    {
        foreach (self::FLAGGED as [$listHref, $createHref, $name]) {
            $I->seeElement($this->affordanceOnRow($listHref, $createHref));
            // And nowhere else: an affordance that also appeared on a neighbouring row would pass
            // a page-wide check just as happily.
            $I->assertSame(
                [$createHref],
                $I->grabMultiple('nav#primary-navigation a.nav-affordance[href="' . $createHref . '"]', 'href'),
                $name . ' must render exactly once, on the row it named',
            );
        }

        // The row it named, specifically. New appointment on Appointments, not on Repair Order.
        $I->dontSeeElement($this->affordanceOnRow('/admin/repair-orders', '/admin/clients/pick/appointment'));
    }

    /**
     * Requirement 2, the regression guard that proves the feature's effect is scoped to flagged
     * items and nothing else: every row nobody flagged is untouched, still a plain row with no
     * wrapper and no icon.
     */
    public function anUnflaggedItemStillRendersAsItsOwnRow(FunctionalTester $I): void
    {
        // Lists, reports, logs and settings — the shop's rows with no `add:` in maxeme.yaml.
        $unflagged = [
            '/admin/bundles/inventory-depth/adjust',
            '/admin/bundles/inventory-depth/movements',
            '/admin/product/import',
            '/admin/product/units-of-measure',
            '/admin/accounting/summary-report',
            '/admin/roles',
            '/admin/shop-settings',
            '/admin/logs/activity',
            '/admin/email-log',
            '/admin/error-log',
            '/admin/db',
        ];

        foreach ($unflagged as $href) {
            $I->seeElement('nav#primary-navigation a.nav-sub', ['href' => $href]);
            $I->dontSeeElement('nav#primary-navigation a.nav-affordance', ['href' => $href]);
            // Not wrapped either: the row markup only changes for rows something attached to.
            $I->dontSeeElement('nav#primary-navigation .nav-sub-row a.nav-sub[href="' . $href . '"]');
        }

        // Exactly as many rows grew a wrapper as there are flagged items, and no more: this is the
        // assertion that catches the wrapper being added unconditionally, which would be invisible
        // in every other check here because the rows would still look right.
        $I->assertCount(count(self::FLAGGED), $I->grabMultiple('nav#primary-navigation .nav-sub-row', 'class'));
        $I->assertSame(
            array_column(self::FLAGGED, 1),
            $I->grabMultiple('nav#primary-navigation a.nav-affordance', 'href'),
            'the affordances in the sidebar, in document order, are exactly the flagged items and nothing else',
        );
    }

    /**
     * The create ROWS are gone, on purpose: a list row and a `+` beside it going to the same
     * create screen used to both render, which read as two links for one workflow rather than one
     * link with a shortcut. Each create item renders ONLY as its affordance now — its own `.nav-sub`
     * row is what a flagged item gives up in exchange for the icon, not something it keeps as well.
     * If this fails, the row came back, which is the duplication this removed.
     */
    public function theCreateRowItShortcutsReplacesIsNotThereAnyMore(FunctionalTester $I): void
    {
        foreach (self::FLAGGED as [, $createHref]) {
            $I->dontSeeElement('nav#primary-navigation a.nav-sub', ['href' => $createHref]);
        }
    }

    /**
     * Requirement 4. The icon announces where it goes, never what it looks like — a column of
     * plus signs read out as "plus, plus, plus" is useless. Every shop entry states its own label
     * (`add.label` in maxeme.yaml), and that is what it announces, exactly once.
     */
    public function theAffordanceCarriesAnAccessibleNameAndAStatedOneOverridesTheLabel(FunctionalTester $I): void
    {
        foreach (self::FLAGGED as [$listHref, $createHref, $name]) {
            $I->seeElement($this->affordanceOnRow($listHref, $createHref, sprintf('[@aria-label="%s"]', $name)));
            $I->assertSame([$name], $I->grabMultiple('nav#primary-navigation a.nav-affordance[href="' . $createHref . '"]', 'aria-label'));
        }
    }

    /**
     * Requirement 5. The app works without JavaScript (QUEUE.md, and tests/Functional/AdminNoJs*Cest).
     * This tester runs no JS at all, so everything asserted above is already proof the affordance
     * is in the server-rendered DOM — what is left to state is that the link is a real href rather
     * than a button waiting for a handler, and that nothing in the sidebar markup carries a `js-`
     * hook the affordance would depend on.
     */
    public function theAffordanceIsARealLinkInTheDomAndNeedsNoJavaScript(FunctionalTester $I): void
    {
        foreach (self::FLAGGED as [, $createHref]) {
            // A real href in the server-rendered DOM, not a button waiting on a handler.
            $I->seeElement('nav#primary-navigation a.nav-affordance', ['href' => $createHref]);
        }

        // Following it is the point of the shortcut, so follow it. Not $I->click(): the admin
        // console answers on its own host, which this tester reaches by a Host header rather than
        // a configured base URL, and the module reads a link resolved against that host as an
        // external URL. Going to the href directly asks the same question of the same route.
        $I->amOnPage('/admin/service-categories/new');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/service-categories/new');

        $I->amOnPage('/admin');
        $I->dontSeeElement('nav#primary-navigation a.nav-affordance[data-js]');
        $I->dontSeeElement('nav#primary-navigation a.nav-affordance.js-only');
    }

    /**
     * The affordance is styled by CSS alone, and by the CSS the design settled on. Asserted
     * against the stylesheet because there is no browser here to measure a computed style with,
     * and because each of these three is a decision that stops being visible the moment it is
     * quietly reverted: `display: none` would take the link out of the tab order and the
     * accessibility tree, `:hover` without `:focus-within` would leave a keyboard user tabbing to
     * something invisible, and a hover-only control is unreachable on a device with no hover.
     */
    public function theRevealIsPureCssAndKeepsTheLinkReachable(FunctionalTester $I): void
    {
        // Line endings normalised: a Windows checkout (core.autocrlf) has CRLF in app.css.
        $css = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../public/assets/css/app.css'));

        $block = strstr($css, '.site-admin .nav a.nav-affordance {');
        $I->assertNotFalse($block, 'the affordance must be styled in app.css, not inline or by script');
        $rule = substr((string) $block, 0, (int) strpos((string) $block, '}'));

        $I->assertStringContainsString('opacity: 0;', $rule);
        $I->assertStringContainsString('visibility: hidden;', $rule);
        $I->assertStringNotContainsString('display: none', $rule, 'display:none would remove the link from the tab order and the accessibility tree');

        $I->assertStringContainsString('.site-admin .nav .nav-sub-row:hover a.nav-affordance,', $css);
        $I->assertStringContainsString('.site-admin .nav .nav-sub-row:focus-within a.nav-affordance,', $css);

        // Always visible below the sidebar's own breakpoint: touch has no hover to reveal with.
        $touch = strstr($css, '@media (max-width: 980px) {' . "\n" . '    .site-admin .nav a.nav-affordance {');
        $I->assertNotFalse($touch, 'the affordance must be always-visible below the touch breakpoint');
        $I->assertStringContainsString('visibility: visible;', substr((string) $touch, 0, 240));
    }
}
