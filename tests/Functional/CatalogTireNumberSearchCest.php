<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use Tests\Support\FunctionalTester;

/**
 * Covers the tire-size search added to CatalogController's /product/index route: a search
 * string like "2356516" should match a product named "...LT235-65R16-121-119R" by comparing
 * digits-only substrings (see App\Service\TireNumberSearchMatcher), in addition to the existing
 * plain SKU/name substring match.
 */
final class CatalogTireNumberSearchCest
{
    public function searchByTireNumberMatchesProductWithDifferentlyFormattedDigits(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Tire Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $matchingProduct = (new ProductCore())
            ->setSku('TIRE-MATCH-SKU')
            ->setName('AQQISHI-AQSONE-A-T-LT235-65R16-121-119R')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($matchingProduct);

        $nonMatchingProduct = (new ProductCore())
            ->setSku('TIRE-NOMATCH-SKU')
            ->setName('AQQISHI-AQSONE-A-T-LT275-70R18-125-122S')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($nonMatchingProduct);

        $I->amOnPage('/product/index?q=2356516');
        $I->seeResponseCodeIsSuccessful();
        $I->see('AQQISHI-AQSONE-A-T-LT235-65R16-121-119R');
        $I->dontSee('AQQISHI-AQSONE-A-T-LT275-70R18-125-122S');
    }

    public function searchByTireNumberIgnoresSeparatorsInSearchStringItself(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Tire Warehouse 2')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $matchingProduct = (new ProductCore())
            ->setSku('TIRE-MATCH-SKU-2')
            ->setName('AQQISHI-AQSONE-A-T-LT235-65R16-121-119R')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($matchingProduct);

        $I->amOnPage('/product/index?q=235+65+16');
        $I->seeResponseCodeIsSuccessful();
        $I->see('AQQISHI-AQSONE-A-T-LT235-65R16-121-119R');
    }

    /**
     * A query with no digits at all is ineligible for the digits-only tire matcher, so the plain
     * raw-text LIKE path is the only thing that can match it — it must never be skipped just
     * because the query isn't a pure number.
     */
    public function searchByLettersOnlyTextStillMatchesOnTheRawTextPath(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Tire Warehouse 3')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $matchingProduct = (new ProductCore())
            ->setSku('TIRE-LETTERS-SKU')
            ->setName('DURINGON DP810 255/45ZR19 104W')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($matchingProduct);

        $nonMatchingProduct = (new ProductCore())
            ->setSku('TIRE-LETTERS-NOMATCH-SKU')
            ->setName('AQQISHI AQSONE A/T LT275/70R18')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($nonMatchingProduct);

        $I->amOnPage('/product/index?q=duringon');
        $I->seeResponseCodeIsSuccessful();
        $I->see('DURINGON DP810 255/45ZR19 104W');
        $I->dontSee('AQQISHI AQSONE A/T LT275/70R18');
    }

    /** Mixed alphanumeric size text (slashes, letters) must never be excluded from searching. */
    public function searchByMixedAlphanumericSizeTextStillMatchesOnTheRawTextPath(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Tire Warehouse 4')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $matchingProduct = (new ProductCore())
            ->setSku('TIRE-MIXED-SKU')
            ->setName('DURINGON DP810 255/45ZR19 104W')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($matchingProduct);

        $I->amOnPage('/product/index?q=255%2F45ZR19');
        $I->seeResponseCodeIsSuccessful();
        $I->see('DURINGON DP810 255/45ZR19 104W');
    }

    /**
     * The reference app also LIKEs the typed value against its `description` column; ours searches
     * both ProductCore descriptions, so size-like text living only there still matches.
     */
    public function searchMatchesRawTextFoundOnlyInTheProductDescription(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Tire Warehouse 5')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $matchingProduct = (new ProductCore())
            ->setSku('TIRE-DESC-SKU')
            ->setName('Sizeless Tire Alpha')
            ->setShortDescription('Size 265/60R18 all-terrain')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($matchingProduct);

        $nonMatchingProduct = (new ProductCore())
            ->setSku('TIRE-DESC-NOMATCH-SKU')
            ->setName('Sizeless Tire Beta')
            ->setLongDescription('Nothing size-like here')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($nonMatchingProduct);

        $I->amOnPage('/product/index?q=265%2F60R18');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Sizeless Tire Alpha');
        $I->dontSee('Sizeless Tire Beta');
    }
}
