<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\Assert;

/**
 * Lifts named functions out of the price grid's inline script so they can be executed for real.
 *
 * Two tests in this directory run that script's arithmetic under Deno — one for the Suggested
 * Effective column, one for the per-price-list Effective columns — and both need the same three
 * shared helpers underneath the function they are actually interested in. Lifting is done by naming
 * the function that starts the slice and the function that follows it, rather than by parsing
 * JavaScript, which keeps the extraction obvious and makes a rename fail loudly here with the name
 * that moved rather than silently returning an empty string to the interpreter.
 *
 * Everything lifted is executed verbatim. Nothing is re-implemented, stubbed or paraphrased: the
 * whole value of these tests is that the code under test is the code that ships, so a slice that
 * cannot be executed as-is is a signal to change the template, not to patch the extract.
 */
final class PriceGridScript
{
    public const TEMPLATE = __DIR__ . '/../../templates/admin/product/prices.html.twig';

    public static function source(): string
    {
        return (string) file_get_contents(self::TEMPLATE);
    }

    /** The whole script as executable code — every comment removed. */
    public static function code(): string
    {
        return self::stripComments(self::source());
    }

    /**
     * $javascript with both comment forms removed.
     *
     * Every "this must not come back" assertion in these tests goes through here first. The template
     * explains the divergences of #464 at length, in prose that necessarily quotes the very shapes
     * being banned — `base <= 0`, `productPrice > 0`, `parseFloat(` — and an explanation of why
     * something was removed must not read as evidence that it is still there.
     *
     * Block comments are stripped as well as line comments, and not as a formality: the doc block
     * over resolveBasePrice() is where the old guard is quoted most explicitly, so a line-only
     * stripper would fail the very assertions the doc block exists to justify.
     */
    public static function stripComments(string $javascript): string
    {
        // Block comments first. The pattern needs a literal `/*`, which no regex literal or string
        // in this script contains, so there is nothing here for it to eat by accident.
        $withoutBlocks = (string) preg_replace('~/\*.*?\*/~s', '', $javascript);

        return (string) preg_replace('~^\s*//.*$~m', '', $withoutBlocks);
    }

    /**
     * numericValue(), formatMoney() and resolveBasePrice(): the shared base of both computations.
     *
     * These are lifted rather than stubbed on purpose. numericValue() is the whole of #464's
     * `is_numeric` half and resolveBasePrice() is the whole of its base-resolution half, so a stub
     * would be testing the harness's idea of the rules instead of the grid's — which is exactly the
     * failure mode (two implementations of one rule) that the issue is about.
     */
    public static function sharedHelpers(): string
    {
        return self::slice('function numericValue(', 'function setCellDisplayText(');
    }

    public static function slice(string $from, string $to): string
    {
        $haystack = self::source();

        $start = strpos($haystack, $from);
        Assert::assertNotFalse($start, sprintf('%s has been renamed or removed from the price grid.', $from));

        $end = strpos($haystack, $to, $start);
        Assert::assertNotFalse($end, sprintf('%s no longer follows %s in the price grid.', $to, $from));

        return substr($haystack, $start, $end - $start);
    }
}
