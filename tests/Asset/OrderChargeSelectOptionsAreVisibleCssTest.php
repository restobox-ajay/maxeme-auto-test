<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * The fee row's tax-class and placement <select>s (.order-charge-select-input) stay legible in
 * both their closed and open states (#668).
 *
 * A bare <select> with no color-scheme of its own can have its native option list themed by the
 * OS/browser's own dark-mode preference regardless of this page's `:root { color-scheme: light }`
 * — the popup list is drawn by the platform, not this stylesheet, so only the option the platform
 * repaints with its own highlight colours (the selected/hovered one) stays legible while every
 * other option, and the closed box before any repaint, can render light-on-light. Codeception
 * cannot see this (it drives the kernel, not a rendering engine), so it is only checkable as a
 * stylesheet — same reasoning as AdminTitleRowIsNotHiddenCssTest, which this deliberately mirrors
 * at a fraction of the size rather than growing a shared CSS-rule parser for one property check.
 */
final class OrderChargeSelectOptionsAreVisibleCssTest extends TestCase
{
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/app.css';

    public function testTheSelectPinsItsOwnColorScheme(): void
    {
        $rule = $this->rule('.order-charge-line-row .order-charge-select-input');

        self::assertSame(
            'light',
            $rule['color-scheme'] ?? null,
            '.order-charge-select-input no longer pins color-scheme: light, so its native option '
            . 'popup can go back to following the OS/browser\'s ambient dark-mode preference.',
        );
    }

    public function testTheOptionsHaveAnExplicitColorAndBackgroundAndTheyDiffer(): void
    {
        $rule = $this->rule('.order-charge-line-row .order-charge-select-input option');

        self::assertNotNull($rule, '.order-charge-line-row .order-charge-select-input option has no rule of its own.');

        $color = $rule['color'] ?? null;
        $background = $rule['background'] ?? $rule['background-color'] ?? null;

        self::assertNotNull($color, 'option has no explicit color, so a platform can still pick its own.');
        self::assertNotNull($background, 'option has no explicit background, so a platform can still pick its own.');
        self::assertNotSame($color, $background, 'option color and background are the same value — invisible text.');
    }

    /** @return array<string, string>|null */
    private function rule(string $selector): ?array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS_PATH));

        // A plain, single-selector lookup (no nesting, no combinators to resolve): find the exact
        // selector text immediately followed by its declaration block.
        $pattern = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        if (preg_match($pattern, $css, $matches) !== 1) {
            return null;
        }

        $declarations = [];
        foreach (explode(';', $matches[1]) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value = strtolower(trim((string) preg_replace('/!\s*important/i', '', substr($declaration, $colon + 1))));
            if ($property !== '') {
                $declarations[$property] = $value;
            }
        }

        return $declarations;
    }
}
