<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Unit;

use CustomHeaderFooterBundle\Tests\Support\CreatesInMemoryStore;
use PHPUnit\Framework\TestCase;

final class CustomHeaderFooterStoreTest extends TestCase
{
    use CreatesInMemoryStore;

    public function testHeaderAndFooterAreEmptyByDefault(): void
    {
        [$store] = $this->createStore();

        self::assertSame('', $store->getHeaderHtml());
        self::assertSame('', $store->getFooterHtml());
    }

    public function testSaveHeaderHtmlPersistsAndIsReadBack(): void
    {
        [$store] = $this->createStore();

        $store->saveHeaderHtml('<script>console.log("header");</script>');

        self::assertSame('<script>console.log("header");</script>', $store->getHeaderHtml());
        self::assertSame('', $store->getFooterHtml());
    }

    public function testSaveFooterHtmlPersistsAndIsReadBack(): void
    {
        [$store] = $this->createStore();

        $store->saveFooterHtml('<style>body{color:red}</style>');

        self::assertSame('<style>body{color:red}</style>', $store->getFooterHtml());
        self::assertSame('', $store->getHeaderHtml());
    }

    public function testSavingHeaderTwiceUpdatesTheSameRowInsteadOfCreatingASecondOne(): void
    {
        [$store, $countRows] = $this->createStore();

        $store->saveHeaderHtml('<meta name="first">');
        $store->saveHeaderHtml('<meta name="second">');

        self::assertSame('<meta name="second">', $store->getHeaderHtml());
        self::assertSame(1, $countRows(), 'expected a single custom_header_html AppSetting row, not one per save');
    }

    public function testHeaderAndFooterAreStoredUnderDistinctKeysAndDoNotClobberEachOther(): void
    {
        [$store, $countRows] = $this->createStore();

        $store->saveHeaderHtml('<!-- header -->');
        $store->saveFooterHtml('<!-- footer -->');

        self::assertSame('<!-- header -->', $store->getHeaderHtml());
        self::assertSame('<!-- footer -->', $store->getFooterHtml());
        self::assertSame(2, $countRows());
    }

    public function testRawHtmlIsPreservedVerbatimIncludingScriptAndStyleTags(): void
    {
        [$store] = $this->createStore();

        $raw = "<script>alert('xss-is-the-point-here');</script>\n<style>.x{color:#fff}</style>";
        $store->saveFooterHtml($raw);

        self::assertSame($raw, $store->getFooterHtml());
    }
}
