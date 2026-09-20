<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

use App\Menu\Admin\AdminMenuIconSet;
use App\Menu\Admin\AdminMenuNode;
use App\Tests\DoctrineIntegrationTestCase;
use App\Twig\AdminMenuExtension;
use Twig\Environment;

/**
 * Every row affordance the application actually ships names an icon this application actually has
 * (queue item 35).
 *
 * ## Why this discovers its subjects instead of listing them
 *
 * The rule QUEUE.md states: a conformance test naming its subjects in an array passes forever
 * while the next thing added quietly skips the rule. So the subjects here come out of the real
 * container — every registered `app.admin_menu_override_provider`, merged by the real
 * AdminMenuTreeBuilder — and an affordance added by a bundle that did not exist when this file was
 * written is a subject the moment it is registered. Nobody adding one has to remember this test.
 *
 * ## What it is guarding
 *
 * An unknown icon name is not fatal at runtime and must not be: the affordance still renders, just
 * without a glyph, because the icon is the decoration and the link is the function. AdminMenuTreeBuilder
 * logs it. But a log line in production is a poor way to learn that something SHIPPED wrong, and
 * without something enumerating there would be nothing in the suite to tell the difference between
 * "asked for the plus" and "asked for `plu`" — so this fails the build for the second one.
 */
final class AdminMenuIconSetTest extends DoctrineIntegrationTestCase
{
    /**
     * Every affordance in the tree this instance renders — read through the same Twig extension
     * the sidebar itself calls, so the subjects are whatever the real container has registered
     * rather than a list kept here by hand.
     *
     * Extends the Doctrine base rather than a bare KernelTestCase because the merge asks
     * BundleStatusRepository whether each provider's bundle is Active, which is a real query
     * against a real schema — the same question App Management's kill-switch answers.
     *
     * @return list<AdminMenuNode>
     */
    private function shippedAffordances(): array
    {
        /** @var AdminMenuExtension $extension */
        $extension = self::getContainer()->get(AdminMenuExtension::class);

        $affordances = [];
        foreach ($extension->getTree() as $entry) {
            foreach ($entry['affordances'] as $onRow) {
                foreach ($onRow as $affordance) {
                    $affordances[] = $affordance;
                }
            }
        }

        return $affordances;
    }

    public function testEveryShippedAffordanceNamesAnIconThisAppHas(): void
    {
        $affordances = $this->shippedAffordances();

        self::assertNotSame([], $affordances, 'the procurement create screens ship as affordances; none was found, so this test is asserting nothing');

        foreach ($affordances as $affordance) {
            self::assertNotNull($affordance->affordanceIcon, $affordance->key . ' renders as an affordance and must name its icon');
            self::assertTrue(
                AdminMenuIconSet::has($affordance->affordanceIcon),
                sprintf('%s asks for the icon "%s", which App\Menu\Admin\AdminMenuIconSet does not have. Known: %s', $affordance->key, $affordance->affordanceIcon, implode(', ', AdminMenuIconSet::names())),
            );
        }
    }

    /**
     * Every affordance announces something true. The label is almost always already the right
     * thing to say, which is why the explicit field is optional — but "+" never is, and neither is
     * the empty string, so both are refused here rather than discovered by a screen-reader user.
     */
    public function testEveryShippedAffordanceAnnouncesSomethingBetterThanItsIcon(): void
    {
        foreach ($this->shippedAffordances() as $affordance) {
            $name = $affordance->affordanceName();

            self::assertNotSame('', trim($name), $affordance->key . ' must announce something');
            self::assertNotSame('+', trim($name), $affordance->key . ' must announce where it goes, not what it looks like');
        }
    }

    /**
     * Every glyph in the set is drawn to the same spec as the nav icons it sits beside — same box,
     * same stroke weight, same caps — or an affordance reads a shade heavier or lighter than the
     * icon on the row above it at the size all of them are actually seen at.
     */
    public function testEveryGlyphMatchesTheShapeOfTheNavIconsItSitsBeside(): void
    {
        foreach (AdminMenuIconSet::names() as $name) {
            $svg = (string) AdminMenuIconSet::svg($name);

            self::assertStringStartsWith(
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
                $svg,
                $name . ' must be drawn to the same spec as every other sidebar icon',
            );
            self::assertStringEndsWith('</svg>', $svg);
        }
    }

    /**
     * The plus is the one that ships in anger, so its geometry is pinned: centred on (12,12) with
     * even 7-unit arms in a 24-unit box. Anything off-centre reads as a typo in the artwork at
     * 14px, and nothing else in this set is under that scrutiny.
     */
    public function testThePlusIsCentredWithEvenArms(): void
    {
        $plus = (string) AdminMenuIconSet::svg('plus');

        self::assertStringContainsString('<line x1="12" y1="5" x2="12" y2="19"/>', $plus, 'the vertical arm must run 7 units either side of the centre');
        self::assertStringContainsString('<line x1="5" y1="12" x2="19" y2="12"/>', $plus, 'the horizontal arm must match it exactly');
    }

    public function testAnUnknownNameResolvesToNothingRatherThanToMarkup(): void
    {
        self::assertNull(AdminMenuIconSet::svg('no-such-icon'));
        self::assertNull(AdminMenuIconSet::svg('<script>alert(1)</script>'));
        self::assertFalse(AdminMenuIconSet::has(''));
    }

    /**
     * The degrade, rendered rather than reasoned about: an item naming an icon this app does not
     * have still produces a USABLE button — right href, right accessible name, no glyph.
     *
     * Rendered through the real partial and the real Twig environment, because the failure this
     * guards against is a template one: a `{% if %}` around the glyph that accidentally wraps the
     * link too, and the shortcut is gone. Driven here rather than through the sidebar so that
     * exercising a nonsense icon name does not put one in the shipped menu.
     */
    public function testAnItemWithAnUnknownIconStillRendersAUsableButton(): void
    {
        $node = new AdminMenuNode(
            key: 'test.create',
            label: 'New Thing',
            route: 'admin_dashboard',
            icon: null,
            parent: 'products',
            order: 10,
            attachTo: 'products.details',
            affordanceIcon: 'gear',
        );

        $html = self::getContainer()->get(Environment::class)->render(
            'admin/_main/_nav_affordances.html.twig',
            ['affordances' => [$node]],
        );

        self::assertStringContainsString('class="nav-affordance"', $html, 'the button must survive a bad icon name');
        self::assertStringContainsString('href="/admin"', $html, 'and keep the link, which is the part that does anything');
        self::assertStringContainsString('aria-label="New Thing"', $html, 'and keep announcing where it goes');
        self::assertStringNotContainsString('<svg', $html, 'and draw no glyph rather than the wrong one');
    }

    /**
     * The other half of that: a button with nothing in it is only usable if it has a box. The size
     * is set on the rule, not derived from its content, so an empty affordance is still a hit
     * target and still a focus stop — otherwise it would be present in the DOM and absent in
     * practice, which is the failure mode that would make degrading pointless.
     */
    public function testTheAffordanceBoxIsFixedSoAGlyphlessOneIsStillHittable(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/app.css');

        $block = strstr($css, '.site-admin .nav a.nav-affordance {');
        self::assertNotFalse($block);
        $rule = substr((string) $block, 0, (int) strpos((string) $block, '}'));

        self::assertMatchesRegularExpression('/\bwidth:\s*\d+px;/', $rule, 'an empty affordance needs a width of its own');
        self::assertMatchesRegularExpression('/\bheight:\s*\d+px;/', $rule, 'and a height of its own');
        self::assertStringContainsString('flex: 0 0 auto;', $rule, 'and must not be allowed to shrink to its content');
        self::assertStringContainsString('.site-admin .nav a.nav-affordance:focus-visible {', $css, 'and must show a focus ring, having no shape of its own to tint');
    }
}
