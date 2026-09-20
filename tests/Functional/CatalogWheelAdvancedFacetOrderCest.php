<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;
use Tests\Support\FunctionalTester;

/**
 * Issue #502: toggling "Advanced View" must never shift an already-visible basic facet out of
 * place. The admin config screen lets facets be saved in any order (CategoryPageConfigTest's
 * round-trip test saves basic before advanced, but nothing stops the reverse), and
 * WheelCatalogFacetProvider/CatalogFacetResolver pass that order straight through unchanged. This
 * covers wheel_index.html.twig's fix: basic facets always render before advanced ones in the DOM,
 * regardless of the configured order, so revealing .is-advanced facets via the CSS-only toggle
 * only ever appends to the row instead of inserting ahead of a basic facet.
 */
final class CatalogWheelAdvancedFacetOrderCest
{
    public function basicFacetsRenderBeforeAdvancedOnesEvenWhenConfiguredInReverseOrder(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Wheel Facet Order Test Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $category = (new ProductCategory())->setName('Wheel');
        $I->haveInRepository($category);

        $em = $I->grabService(EntityManagerInterface::class);
        $config = $I->grabService(CategoryPageConfig::class);

        // Advanced facet listed first, basic facet listed second — the reverse of what the DOM
        // must show, so this only passes if the template reorders rather than trusting config order.
        $config->saveWheelFacets($em, [
            ['slug' => 'rim_offset', 'label' => 'Offset', 'advanced' => true],
            ['slug' => 'rim_dimension', 'label' => 'Dimension', 'advanced' => false],
        ]);

        $I->haveHttpHeader('Host', 'localhost');
        $I->amOnPage('/product/index?ProductSearch[category_id]=' . $category->getId() . '&ProductSearch[view]=LIST_VIEW');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $dimensionPos = strpos($html, 'aria-label="Dimension"');
        $offsetPos = strpos($html, 'aria-label="Offset"');

        $I->assertNotFalse($dimensionPos, 'Basic "Dimension" facet should be present.');
        $I->assertNotFalse($offsetPos, 'Advanced "Offset" facet should be present.');
        $I->assertTrue(
            $dimensionPos < $offsetPos,
            'The basic facet must render before the advanced facet, regardless of configured order.',
        );

        // The advanced facet must still carry is-advanced so the CSS toggle can hide/reveal it.
        $I->seeElement('.wheel-facet.is-advanced[aria-label="Offset"]');
        $I->dontSeeElement('.wheel-facet.is-advanced[aria-label="Dimension"]');
    }
}
