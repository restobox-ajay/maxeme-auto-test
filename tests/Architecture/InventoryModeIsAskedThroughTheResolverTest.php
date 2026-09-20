<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `ProductCore::getInventoryMode()` may only be read by the entity itself and by the resolver (#566).
 *
 * The rule it protects is one sentence: **if the bundle is off, the product is simple, whatever the
 * database says.** `InventoryModeResolver::isDimensional()` is the only thing that answers it
 * correctly, because it requires both that the product opted in and that a provider is live.
 *
 * Reading the column directly answers a different, wrong question — and reads perfectly naturally
 * while doing it. `getInventoryMode() === 'dimensional'` looks like it is asking exactly the right
 * thing. That is why eleven call sites got it wrong and only three got it right, and why this has to
 * be a build failure rather than a convention: nothing about the wrong version looks wrong.
 *
 * What it cost: `StockMovementService` read the column, so a goods receipt booked through
 * ProcurementBundle wrote movement groups and detail rows into a switched-off InventoryDepthBundle.
 * Every screen agreed the bundle was off while data accumulated where no screen could show it.
 *
 * Source-level, in the shape of WarehouseOpsBundle's NoSecondWritePathTest, and for the same reason:
 * a behavioural test proves today's callers are clean and cannot stop tomorrow's from reaching
 * around the resolver.
 */
final class InventoryModeIsAskedThroughTheResolverTest extends TestCase
{
    /** The two files that legitimately touch the raw column: the entity that owns it, and the resolver that wraps it. */
    private const ALLOWED = [
        // Owns the column.
        'src/Entity/ProductCore.php',
        // Wraps it, and is the only correct answer.
        'src/Service/Inventory/InventoryModeResolver.php',
        // The two places that CHANGE the mode, which must read the stored value to change it.
        // They are asking "what does the database currently say", not "is this detail-tracked" —
        // a different question, and the only one the raw column legitimately answers.
        'modules/InventoryDepthBundle/src/Inventory/InventoryModeSwitcher.php',
        'src/Controller/Admin/ProductController.php',
    ];

    private const NEEDLE = 'getInventoryMode(';

    public function testNothingReadsTheRawInventoryModeOutsideTheResolver(): void
    {
        $offenders = [];

        foreach ($this->productionFiles() as $file) {
            $relative = substr($file, strlen($this->root()) + 1);

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($file));

            if (str_contains($source, self::NEEDLE)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These read ProductCore::getInventoryMode() directly:\n  %s\n\n"
            . "Ask InventoryModeResolver::isDimensional(\$product) instead. The column alone says what the\n"
            . "database was told; it does not say whether the bundle that gives it meaning is switched on.\n"
            . "With the bundle off a stored 'dimensional' must read as simple everywhere — including in\n"
            . "whatever you are writing now.",
            implode("\n  ", $offenders),
        ));
    }

    /** The resolver has to keep asking BOTH questions, or every caller above inherits the wrong answer. */
    public function testTheResolverStillRequiresALiveProvider(): void
    {
        $source = (string) file_get_contents($this->root() . '/src/Service/Inventory/InventoryModeResolver.php');

        self::assertStringContainsString('isDimensionalAvailable()', $source);
        self::assertStringContainsString(
            'isActive(',
            $source,
            'the resolver no longer consults bundle status, so "bundle off means simple" is not true anywhere',
        );
    }

    /** @return list<string> */
    private function productionFiles(): array
    {
        $files = [];

        foreach (['/src', '/modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . $dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->getExtension() !== 'php') {
                    continue;
                }

                // Bundle tests live under modules/*/tests. A test may legitimately set up a product
                // in either mode and read it back; the rule is about production code asking the
                // wrong question, not about fixtures.
                if (str_contains($entry->getPathname(), '/tests/')) {
                    continue;
                }

                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /**
     * Comments are stripped before matching, so a docblock that merely NAMES the method — including
     * this rule being explained in prose — is not an offender.
     */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
