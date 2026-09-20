<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Tests\Service;

use Number1RimImportBundle\Service\RimApiTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Covers rim_model: previously buildName() folded the API's `model` column only into the
 * generated product name string, never captured as its own value — the same gap Finish had
 * before it (CATEGORY_PAGE_FILTER_PLAN.md §2d). See scripts/task-loop/TASKS.md's "Wheel category
 * page ... List View: add the rim-spec columns" item, DATA GAP #3.
 */
final class RimApiTransformerTest extends TestCase
{
    public function testTransformCapturesModelAsItsOwnSpecField(): void
    {
        $transformer = new RimApiTransformer();

        $apiRows = [
            ['Part No', 'Brand', 'Model', 'Finish', 'Size', 'Weight', 'Price Shop', 'Price Default', 'Remark', 'Offset'],
            ['SKU-RIM-1', 'Acme', 'GT Line', 'Gloss Black', '18x7.5', '22.5', '150.00', '175.00', '', '35'],
        ];

        $result = $transformer->transform($apiRows, null, null);

        self::assertSame('GT Line', $result->specFieldsBySku['SKU-RIM-1']['rim_model']);
        // Regression: existing spec-field capture (already-shipped Offset) must still work.
        self::assertSame('35', $result->specFieldsBySku['SKU-RIM-1']['rim_offset']);
    }

    public function testTransformOmitsModelKeyWhenApiRowHasNoModelColumn(): void
    {
        $transformer = new RimApiTransformer();

        $apiRows = [
            ['Part No', 'Brand', 'Finish', 'Size', 'Weight', 'Price Shop', 'Price Default', 'Remark'],
            ['SKU-RIM-2', 'Acme', 'Gloss Black', '18x7.5', '22.5', '150.00', '175.00', ''],
        ];

        $result = $transformer->transform($apiRows, null, null);

        self::assertArrayNotHasKey('rim_model', $result->specFieldsBySku['SKU-RIM-2']);
    }
}
