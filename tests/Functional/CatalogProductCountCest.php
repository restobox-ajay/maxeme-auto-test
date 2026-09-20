<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use Tests\Support\FunctionalTester;

/**
 * Covers the guest catalog "Showing …" product count (issue #179). The count lives in the
 * `.product-count` span of customer/catalog/index.html.twig and used to be left EMPTY in the
 * server HTML — only JavaScript filled it in, so a no-JS visitor saw "Showing " with no number.
 *
 * Codeception functional tests do NOT execute JavaScript, so anything these assertions see inside
 * `.product-count` must have been rendered server-side. That's exactly the guarantee we want.
 */
final class CatalogProductCountCest
{
    public function guestCatalogRendersTheProductCountServerSide(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Count Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        // Seed a known number of products (7) so the total is unambiguous.
        for ($n = 1; $n <= 7; $n++) {
            $product = (new ProductCore())
                ->setSku('COUNT-SKU-' . $n)
                ->setName('Countable Product ' . $n)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $I->haveInRepository($product);
        }

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();

        // The number is present in the raw server HTML (no JS involved) and scoped to the
        // .product-count span, proving the count is not JS-dependent. With 7 products on a
        // single page (perPage 24) the label reads "1–7 of 7".
        // The whole label, read out of the span (#627). see('of 7', '.product-count') left the
        // range unasserted: a first page reading "1–24 of 7" satisfied it just as well.
        $I->assertSame('1–7 of 7', trim($I->grabTextFrom('.product-count')), '7 seeded product rows, all on page 1');
    }

    public function guestCatalogWithNoProductsRendersZero(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Empty Count Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();

        // Exact, not a substring (#627): see('0', '.product-count') is satisfied by "1–10 of 10".
        $I->assertSame('0', trim($I->grabTextFrom('.product-count')), 'no visible product rows');
    }
}
