<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SalesDocumentLineWarnings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The coercion rules themselves, away from the two controllers that apply them: 0 is a quantity, a
 * negative one is not, a price that cannot be read is not a price, and no rewrite is ever silent.
 */
final class SalesDocumentLineWarningsTest extends TestCase
{
    public function testZeroIsAQuantityAndIsNotAWarning(): void
    {
        $warnings = new SalesDocumentLineWarnings();

        self::assertSame(0.0, $warnings->quantityForRow(0.0, 0));
        self::assertSame(7.5, $warnings->quantityForRow(7.5, 1));

        self::assertTrue($warnings->isEmpty());
        self::assertSame([], $warnings->messages());
        self::assertSame([], $warnings->qtyRows());
    }

    public function testANegativeQuantityBecomesZeroAndNamesItsLine(): void
    {
        $warnings = new SalesDocumentLineWarnings();

        self::assertSame(2.0, $warnings->quantityForRow(2.0, 0));
        self::assertSame(0.0, $warnings->quantityForRow(-5.0, 1));
        self::assertSame(0.0, $warnings->quantityForRow(-0.5, 3));

        self::assertFalse($warnings->isEmpty());
        // Rows are zero-based; the message counts from 1, the way the form's rows read.
        self::assertSame([1, 3], $warnings->qtyRows());
        self::assertSame(
            [
                'Line 2: negative quantity was saved as 0.',
                'Line 4: negative quantity was saved as 0.',
            ],
            $warnings->messages(),
        );
    }

    public function testAReadablePriceIsTakenAsTypedIncludingANegativeOne(): void
    {
        $warnings = new SalesDocumentLineWarnings();

        self::assertSame(12.5, $warnings->priceForRow('12.50', 0));
        self::assertSame(0.0, $warnings->priceForRow('0', 1));
        // A credit. Deliberate, and not a coercion.
        self::assertSame(-25.0, $warnings->priceForRow('-25.00', 2));
        // is_numeric() takes these, so they are prices and not warnings.
        self::assertSame(1234.56, $warnings->priceForRow('1234.56', 3));
        self::assertSame(1200.0, $warnings->priceForRow('1.2e3', 4));

        self::assertTrue($warnings->isEmpty());
        self::assertSame([], $warnings->priceRows());
    }

    public function testAnUnreadablePriceBecomesZeroAndNamesItsLine(): void
    {
        $warnings = new SalesDocumentLineWarnings();

        // The spreadsheet paste from #253. It is not is_numeric(), and nothing here guesses at what
        // the separators meant — the quote used to file it as "No pricing" and the order's (float)
        // cast used to read it as 1.00.
        self::assertSame(0.0, $warnings->priceForRow('1,234.56', 0));
        self::assertSame(0.0, $warnings->priceForRow('$99', 1));
        self::assertSame(0.0, $warnings->priceForRow('12.5O', 2));
        self::assertSame(0.0, $warnings->priceForRow('n/a', 3));

        self::assertFalse($warnings->isEmpty());
        self::assertSame([0, 1, 2, 3], $warnings->priceRows());
        self::assertSame(
            [
                'Line 1: the price was not a number and was saved as 0.',
                'Line 2: the price was not a number and was saved as 0.',
                'Line 3: the price was not a number and was saved as 0.',
                'Line 4: the price was not a number and was saved as 0.',
            ],
            $warnings->messages(),
        );
    }

    public function testAQuantityAndAPriceCoercionAreReportedSideBySide(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $warnings = new SalesDocumentLineWarnings();
        $warnings->quantityForRow(-5.0, 0);
        $warnings->priceForRow('n/a', 1);
        $warnings->flashOnto($request);

        $taken = SalesDocumentLineWarnings::takeFromFlash($request);
        self::assertSame(
            [
                'Line 1: negative quantity was saved as 0.',
                'Line 2: the price was not a number and was saved as 0.',
            ],
            $taken['messages'],
        );
        // Two row lists, so the form paints the quantity cell on one row and the price cell on the
        // other rather than reddening both cells of both rows.
        self::assertSame([0], $taken['qtyRows']);
        self::assertSame([1], $taken['priceRows']);
    }

    public function testTheWarningsSurviveTheRedirectEverySavePathMakes(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $warnings = new SalesDocumentLineWarnings();
        $warnings->quantityForRow(-5.0, 1);
        $warnings->flashOnto($request);

        // The row numbers travel as their own flash type so the form can paint the cell, not just
        // print the sentence.
        $taken = SalesDocumentLineWarnings::takeFromFlash($request);
        self::assertSame(['Line 2: negative quantity was saved as 0.'], $taken['messages']);
        self::assertSame([1], $taken['qtyRows']);

        // Shown once: a second render of the same page must not re-accuse a row nothing happened to.
        self::assertSame(['messages' => [], 'qtyRows' => [], 'priceRows' => []], SalesDocumentLineWarnings::takeFromFlash($request));
    }

    public function testASaveWithNothingToReportLeavesNothingBehind(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        (new SalesDocumentLineWarnings())->flashOnto($request);

        self::assertSame([], $request->getSession()->getFlashBag()->keys());
    }

    /** A request with no session at all (an API-style POST) must not be a fatal on the way out. */
    public function testWithoutASessionThereIsNothingToCarryAndNothingBreaks(): void
    {
        $request = new Request();

        $warnings = new SalesDocumentLineWarnings();
        $warnings->quantityForRow(-1.0, 0);
        $warnings->flashOnto($request);

        self::assertSame(['messages' => [], 'qtyRows' => [], 'priceRows' => []], SalesDocumentLineWarnings::takeFromFlash($request));
    }
}
