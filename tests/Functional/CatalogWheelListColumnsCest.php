<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueProduct;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Tests\Support\FunctionalTester;

/**
 * Covers the Wheel category's List View rim-spec columns (scripts/task-loop/TASKS.md's "Wheel
 * category page ... List View: add the rim-spec columns" item): the reference app
 * (number1_inventory's Rim Manager list) shows Model/Dimension/Width/Offset/Pcd/CB/Finish/Weight/
 * Backspace/Seat/Made/Load alongside the shared list columns, which the shared
 * templates/customer/catalog/_products.html.twig used by every other category never has. Only the
 * category literally named "Wheel" gets these — Number1CategoryProductPageBundle's
 * CategoryPageConfig::resolveCategories() find-or-creates/reuses a category by that exact name for
 * every one of its Wheel-role providers (facets, view config, template override, and now
 * WheelCatalogListColumnProvider), so creating a category named "Wheel" here is what makes all of
 * them resolve to the same page.
 */
final class CatalogWheelListColumnsCest
{
    /** @return array<string, string> slug => label, matching WheelCatalogListColumnProvider::getColumns() */
    private const RIM_CUSTOM_FIELDS = [
        'rim_model' => 'Wheel Model Value',
        'rim_dimension' => '18',
        'rim_width' => '7.5',
        'rim_offset' => '35',
        'rim_pcd' => '5x114.3',
        'rim_cb' => '73.1',
        'rim_finish' => 'Gloss Black',
        'rim_backspace' => '4.75',
        'rim_seat' => 'Cone',
        'rim_made' => 'Flow Formed',
        'rim_load_rating' => '1500',
    ];

    public function wheelCategoryListViewShowsAllTwelveRimSpecColumns(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Wheel List Columns Test Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $category = (new ProductCategory())->setName('Wheel');
        $I->haveInRepository($category);

        $product = (new ProductCore())
            ->setSku('WHEEL-LIST-COLUMNS-SKU')
            ->setName('Wheel List Columns Test Product')
            ->setCategory($category)
            ->setWeight('22.5')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        foreach (self::RIM_CUSTOM_FIELDS as $slug => $value) {
            $definition = (new CustomFieldDefinition())
                ->setObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT)
                ->setSlug($slug)
                ->setLabel($slug)
                ->setFieldType(CustomFieldDefinition::FIELD_TYPE_TEXT);
            $I->haveInRepository($definition);

            $cfv = (new CustomFieldValueProduct())
                ->setDefinition($definition)
                ->setProduct($product)
                ->setValue($value);
            $I->haveInRepository($cfv);
        }

        $I->haveHttpHeader('Host', 'localhost');
        $I->amOnPage('/product/index?ProductSearch[category_id]=' . $category->getId() . '&ProductSearch[view]=LIST_VIEW');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Wheel List Columns Test Product');

        // Column headers, in order (#627). Asserting them one at a time with see($label, 'thead')
        // proved only that the twelve words were somewhere in the head; the twelve columns could
        // have been in any order, or all twelve labels could have sat on one <th>, and this passed.
        $I->assertSame(
            ['Image', 'Model', 'Sku', 'Description', 'Dimension', 'Width', 'Offset', 'Pcd', 'CB',
                'Finish', 'Weight', 'Backspace', 'Seat', 'Made', 'Load', 'PriceSuggested', 'Stock', 'Ordered'],
            array_map(
                static fn (string $t): string => trim(preg_replace('/\s+/', '', $t)),
                $I->grabMultiple('.wheel-list-table thead th'),
            ),
            'WheelCatalogListColumnProvider::getColumns() order, framed by the fixed columns',
        );

        // Per-row values: the 11 rim_* custom fields plus Weight (a plain ProductCore column), each
        // read out of the cell that carries it by its data-column slug (#627). see($value, '.wheel-
        // list-row') was a substring match over the whole row, so Width and Offset could swap cells
        // — 7.5 and 35 would both still be "in the row" — and the row could carry every value in
        // one cell. Neither is detectable without naming the cell.
        $cell = static fn (string $slug): string => '.wheel-list-row td[data-column="' . $slug . '"]';

        foreach (self::RIM_CUSTOM_FIELDS as $slug => $value) {
            $I->assertSame(
                $value,
                trim($I->grabTextFrom($cell($slug))),
                'custom_field_value_product.value for definition ' . $slug,
            );
        }
        $I->assertSame('22.5', trim($I->grabTextFrom($cell('weight'))), 'product.weight');
    }
}
