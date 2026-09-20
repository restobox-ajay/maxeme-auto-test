<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use WarehouseOpsBundle\Transfer\TransferSourceStock;

/**
 * What a typed lot or serial pattern means (#610, #611).
 *
 * The screen offers a dropdown of what the source warehouse holds AND a box to type a pattern into,
 * because a dropdown stops being a picker somewhere around a few hundred options and because a
 * browser with scripting off has no other way to search. The pattern is resolved on the server, so
 * these rules are the feature — not a convenience the client happens to implement.
 */
final class TransferSourceStockMatchTest extends TestCase
{
    /** No wildcard at all is a substring search, which is what typing into a search box means. */
    public function testAPlainTermIsASubstringSearch(): void
    {
        self::assertTrue(TransferSourceStock::matches('SEA', 'LOT-SEA-2609'));
        self::assertTrue(TransferSourceStock::matches('2609', 'LOT-SEA-2609'));
        self::assertFalse(TransferSourceStock::matches('SEB', 'LOT-SEA-2609'));
    }

    /** A wildcard anchors the match, the way LIKE does — `SEA*` is a prefix, not a substring. */
    public function testAWildcardAnchorsTheMatchAtBothEnds(): void
    {
        self::assertTrue(TransferSourceStock::matches('SEA-26*', 'SEA-2609'));
        self::assertTrue(TransferSourceStock::matches('*2609', 'LOT-SEA-2609'));
        self::assertTrue(TransferSourceStock::matches('*SEA*', 'LOT-SEA-2609'));
        self::assertFalse(TransferSourceStock::matches('SEA*', 'LOT-SEA-2609'));
    }

    /** `%` and `_` mean what they mean in SQL, because somebody will type them. */
    public function testSqlWildcardsWorkToo(): void
    {
        self::assertTrue(TransferSourceStock::matches('SEA-26%', 'SEA-2609'));
        self::assertTrue(TransferSourceStock::matches('SEA-260_', 'SEA-2609'));
        self::assertFalse(TransferSourceStock::matches('SEA-26_', 'SEA-2609'));
    }

    /** Nobody types a batch code in the case it was stored in. */
    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertTrue(TransferSourceStock::matches('sea-26*', 'SEA-2609'));
        self::assertTrue(TransferSourceStock::matches('sea', 'SEA-2609'));
    }

    /**
     * A pattern is quoted before its wildcards are put back, so a batch code carrying regex syntax —
     * a `.` or a `+` in a code is ordinary — is matched literally rather than compiled.
     */
    public function testRegexSyntaxInAPatternIsLiteral(): void
    {
        self::assertTrue(TransferSourceStock::matches('A.1*', 'A.1-2026'));
        self::assertFalse(TransferSourceStock::matches('A.1*', 'AX1-2026'));
        self::assertTrue(TransferSourceStock::matches('B+C', 'LOT-B+C-9'));
    }

    /** An empty pattern names nothing, rather than naming everything. */
    public function testAnEmptyPatternMatchesNothing(): void
    {
        self::assertFalse(TransferSourceStock::matches('', 'SEA-2609'));
        self::assertFalse(TransferSourceStock::matches('   ', 'SEA-2609'));
    }
}
