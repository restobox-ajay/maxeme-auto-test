<?php

declare(strict_types=1);

namespace App\Tests\Validation\Constraint;

use App\Validation\Constraint\ValidOrderLinesValidator;
use PHPUnit\Framework\TestCase;

final class ValidOrderLinesValidatorTest extends TestCase
{
    public function testBlankWhenNeitherProductNorNameIsPresent(): void
    {
        self::assertTrue(ValidOrderLinesValidator::isBlankLine([]));
    }

    public function testNotBlankWhenAProductIdIsPresent(): void
    {
        self::assertFalse(ValidOrderLinesValidator::isBlankLine(['product_id' => '5']));
    }

    public function testNotBlankWhenANameIsPresent(): void
    {
        self::assertFalse(ValidOrderLinesValidator::isBlankLine(['name' => 'Custom line']));
    }

    /**
     * A tampered post can turn `lines[N][name]` into a nested array (e.g. `lines[N][name][]=x`).
     * Casting an array straight to string is a PHP warning some environments promote to an
     * uncaught error and a stack-trace 500 (#395) — this runs as the form's own validator, before
     * OrderController's line loop even starts, so it has to treat a non-scalar the same as an
     * absent field rather than crash on it.
     */
    public function testTreatsArrayValuedFieldsAsAbsentRatherThanCrashing(): void
    {
        self::assertTrue(ValidOrderLinesValidator::isBlankLine(['product_id' => ['x'], 'name' => ['y']]));
        self::assertFalse(ValidOrderLinesValidator::isBlankLine(['product_id' => ['x'], 'name' => 'Custom line']));
    }
}
