<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every red action button in the shop's screens (delete, de-activate, decline) names what it asks
 * before it posts, so nobody deletes something with one stray click. maxeme.js asks for any
 * .danger post action anyway; this keeps each one's question specific.
 */
final class DangerousActionsAskFirstTest extends TestCase
{
    public function testEveryDangerPostActionHasAConfirmText(): void
    {
        $missing = [];
        $found = 0;
        foreach ((new Finder())->files()->in(dirname(__DIR__, 3) . '/templates/maxeme')->name('*.twig') as $file) {
            preg_match_all('/<button\b[^>]*>/s', $file->getContents(), $buttons);
            foreach ($buttons[0] as $button) {
                if (!str_contains($button, 'js-post-action') || !preg_match('/class="[^"]*\bdanger\b/', $button)) {
                    continue;
                }
                ++$found;
                if (!str_contains($button, 'data-confirm-title=')) {
                    $missing[] = $file->getRelativePathname() . ': ' . preg_replace('/\s+/', ' ', mb_substr($button, 0, 120));
                }
            }
        }

        self::assertGreaterThan(5, $found);
        self::assertSame([], $missing, "These red actions post without saying what they will do:\n" . implode("\n", $missing));
    }
}
