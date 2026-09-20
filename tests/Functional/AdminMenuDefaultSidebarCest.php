<?php

declare(strict_types=1);

namespace Tests\Functional;

use AdminMenuBundle\Entity\AdminCustomMenuItem;
use AdminMenuBundle\Entity\AdminMenuItemStatus;
use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Tests\Menu\Admin\SidebarNavRows;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The #439 guarantee that the tree-driven sidebar still renders the menu the hardcoded template
 * did when no override provider is active, checked two ways:
 *
 *  1. Structurally, against a snapshot of the sidebar HTML captured from the pre-#439 hardcoded
 *     template (tests/Support/Fixtures/admin_default_sidebar_nav.html) — same admin, same route,
 *     same page.
 *
 *     ### What this assertion guarantees, and what it does NOT
 *
 *     **It is not a byte comparison, and nothing in this file requires the fixture to match the
 *     rendered page byte for byte.** Both sides go through `normalize()` first, and that DELETES
 *     every run of whitespace between tags. So:
 *
 *       - GUARANTEED: the same elements, in the same order, with the same attributes in the same
 *         order and the same values, and the same text in each — every link, label, href, icon,
 *         and is-current/is-open class. Change any of those and this fails.
 *       - NOT GUARANTEED: inter-tag whitespace. Re-indenting the fixture, or changing the
 *         template so the renderer emits newlines between tags, passes unchanged.
 *
 *     Worth stating plainly, because the repo's folklore said "byte for byte" and agents were
 *     told to prove fixture edits against this test on that basis. The two coincide TODAY only by
 *     accident: the committed fixture is a single 27,847-byte line containing ZERO inter-tag
 *     whitespace runs, so `normalize()` is a no-op on it — it strips the trailing newline via
 *     `trim()` and changes nothing else. Regenerate the fixture with a renderer that indents, or
 *     hand-edit it with newlines between tags, and the two stop coinciding, silently: from then
 *     on a whitespace-only difference passes.
 *
 *     That limit is deliberate and stays. The hand-typed template's idiosyncratic indentation
 *     (mixed tabs/spaces per section) is exactly what turning it into a generated loop was meant
 *     to replace, so requiring raw bytes would defeat the refactor. Ruled, and not re-opened
 *     here — what changed is the description, which claimed a stronger rule than the code has.
 *  2. Via a regression check that the bundle's own Active/Inactive kill-switch on App Management
 *     still restores the default sidebar unchanged, even with stored hide/reorder/reparent/custom
 *     overrides in place — the same guarantee AdminMenuVisibilityCest asserted before #439's
 *     AdminMenuOverrideProvider replaced AdminMenuVisibilityProvider.
 *
 * Lives in tests/Functional (not modules/AdminMenuBundle/tests) because it also exercises core's
 * own no-provider-registered default (see App\Tests\Menu\Admin\AdminMenuTreeBuilderTest for that
 * case without any bundle involved at all).
 *
 * The fixture stopped being a pure pre-#439 snapshot the day the sidebar itself was deliberately
 * changed on top of it: every create item ROW_AFFORDANCES flags now renders only as the `+` on the
 * row it names, not as a row of its own too (queue item 35's later revision, filed against the
 * literal complaint that a list row and a `+` going to the same create screen read as two links
 * for one workflow), and the rows those `+`s sit on dropped their "List " prefix to match — "List
 * Orders" undersold a row that is also where Create Order lives; "Orders" does not. "Create
 * Invoice" is new outright: admin_invoice_create grew a real standalone path (a company picker,
 * same as Create Order/Create Quote) since the sidebar was first written, and the catalog had not
 * caught up.
 *
 * It moved again with the Sales consolidation: Customers, Carts, Quotes and Orders (with
 * Invoices/Credit Notes/Sales Returns already riding under Orders) were four separate top-level
 * groups that all led to the same place — a sell-side document, or the customers/carts that
 * precede one — so they became one "Sales" group, in workflow order (customer, quote, order,
 * invoice, credit note, return, cart). "Sales Orders" replaces "Orders" as that row's own label,
 * since the group now sits one level above Purchase Orders' sibling group and two rows both
 * called "Orders" would be the same ambiguity Create Order's accessible name already exists to
 * avoid on the icon.
 *
 * The fixture was regenerated from the real rendered output after each of these changes rather
 * than hand-edited, specifically so it stays an exact DOM capture and not a guess at one.
 */
final class AdminMenuDefaultSidebarCest
{
    private const FIXTURE = __DIR__ . '/../Support/Fixtures/admin_default_sidebar_nav.html';

    public function _before(FunctionalTester $I): void
    {
        // AppSettings caches its rows in a pool outside the per-test transaction; without
        // clearing it, one test's writes could keep affecting sidebar-rendering tests that run
        // after it in the same process.
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsTechSupport(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nav-snapshot-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Deletes every run of whitespace BETWEEN tags, on both sides, before they are compared.
     *
     * This is the one line that decides what the comparison below is worth, so: it makes the
     * assertion insensitive to inter-tag whitespace, and to nothing else. Whitespace inside a
     * text node, attribute order, attribute values, element order and element nesting all still
     * have to match exactly. See the class docblock for the full guaranteed/not-guaranteed list,
     * and for why this deliberately is not a byte comparison however much it may look like one
     * against today's single-line fixture.
     *
     * Why strip rather than collapse to one space: the hardcoded template's indentation was
     * hand-typed and inconsistent (some sections used tabs, some none at all right before an
     * icon), and the generated loop does not reproduce those idiosyncrasies. What must match is
     * the DOM this produces — every element, in the same order, with the same attributes and
     * text — not incidental whitespace that renders identically either way inside these
     * block-level nav elements.
     */
    private function normalize(string $html): string
    {
        return trim((string) preg_replace('/>\s+</', '><', $html));
    }

    private function navFragment(string $pageSource): string
    {
        preg_match('/<nav id="primary-navigation".*?<\/nav>/s', $pageSource, $matches);

        return $matches[0] ?? '';
    }

    /**
     * The rule, unchanged — but saying what differs instead of showing 28 KB of it.
     *
     * Both strings arrive here already normalised; this compares them exactly, so every
     * difference the class docblock lists as GUARANTEED fails here. The fixture is ~28 KB on a
     * single line, so `assertSame` on it prints expected and actual in full and a one-row
     * difference is invisible between them. That failure has now been misdiagnosed more than
     * once, including as "a docs-only commit cannot possibly reach the menu" — it can, and
     * `SidebarNavRows` says so in the message when that is the cause.
     *
     * The rule itself does not move. The `assertSame` below IS the assertion; the branch above it
     * can only be entered where that assertion was going to fail anyway, and only replaces what
     * gets printed. Nothing here can make a differing sidebar pass.
     */
    private function assertNavMatchesFixture(FunctionalTester $I, string $expected, string $actual, string $because): void
    {
        if ($expected !== $actual) {
            $I->fail($because . "\n\n" . SidebarNavRows::explainDifference($expected, $actual));
        }

        $I->assertSame($expected, $actual, $because);
    }

    public function theDefaultSidebarMatchesThePreRefactorSnapshotWithNoBundleOverridesStored(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $actual = $this->normalize($this->navFragment($I->grabPageSource()));
        $expected = $this->normalize((string) file_get_contents(self::FIXTURE));

        $this->assertNavMatchesFixture($I, $expected, $actual, 'the tree-driven sidebar must render the same DOM the hardcoded template did, with no override provider active');
    }

    /**
     * Same snapshot, but this time AdminMenuBundle has real stored overrides (a hidden core key,
     * a reordered one, a reparented one, and a custom item) — turning the bundle Inactive on App
     * Management must still fall back to the exact default tree, ignoring all of it.
     *
     * One deliberate, unrelated difference from the raw fixture: AdminMenuBundle's own sidebar
     * shortcut (AdminMenuBundle\Menu\AdminMenuMenuItem, under Apps > Configuration) is itself an
     * `app.injection_point_menu_item` gated by the SAME BundleStatusRepository::isActiveForInstance()
     * check every bundle's injection-point contribution goes through — nothing this ticket
     * touched. Turning the bundle Inactive correctly drops that link too, same as it always did;
     * the fixture (captured with the bundle Active) is adjusted to match before comparing, so
     * this test only asserts what #439 is actually responsible for.
     */
    public function turningTheBundleInactiveRestoresTheDefaultSidebarEvenWithOverridesStored(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->haveInRepository((new AdminMenuItemStatus())->setItemKey('quotes')->setHidden(true));
        $I->haveInRepository((new AdminMenuItemStatus())->setItemKey('help')->setSortOrder(-999));
        $I->haveInRepository((new AdminMenuItemStatus())->setItemKey('settings.sales_tax')->setParentKey('apps'));
        $I->haveInRepository(
            (new AdminCustomMenuItem())->setItemKey('custom.regression_test')->setLabel('Regression Custom Item')->setUrl('/custom-regression')
        );
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('AdminMenuBundle');

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $actual = $this->normalize($this->navFragment($I->grabPageSource()));
        $expected = $this->normalize((string) file_get_contents(self::FIXTURE));
        $expectedWithoutOwnInjectionPointLink = str_replace(
            '<span class="nav-sub-section">Configuration</span><a class="nav-sub " href="/admin/bundles/admin-menu"><span class="nav-label">Admin Menu</span></a>',
            '',
            $expected,
        );

        $this->assertNavMatchesFixture($I, $expectedWithoutOwnInjectionPointLink, $actual, 'Inactive must restore the exact default sidebar regardless of what is stored');
        $I->dontSee('Regression Custom Item');
    }

    /**
     * The other half of the same guarantee: with the bundle left Active but every override table
     * empty (a fresh install, before any admin has ever opened the builder UI), the sidebar is
     * also exactly the default — App\Tests\Menu\Admin\AdminMenuTreeBuilderTest asserts the same
     * thing at the unit level; this is the end-to-end version through the real controller/DB/Twig
     * stack.
     */
    public function aFreshInstallWithNoStoredOverridesRendersTheDefaultSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $actual = $this->normalize($this->navFragment($I->grabPageSource()));
        $expected = $this->normalize((string) file_get_contents(self::FIXTURE));

        $this->assertNavMatchesFixture($I, $expected, $actual, 'a fresh install with no stored overrides must render the default sidebar');
    }
}
