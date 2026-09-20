<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The rule the whole issue hangs on, asserted against the source rather than against behaviour:
 *
 * > **Scanning is a fast path into the same service, never a second write path.**
 *
 * A behavioural test can prove that the code paths written today go through StockMovementService.
 * It cannot stop the next screen from reaching around it — and the failure mode when that happens is
 * not a red test, it is two implementations of "move stock" drifting apart over months, with
 * different validation, different reservation handling and different audit.
 *
 * So this reads every file in the bundle and asserts what none of them contain. It is a blunt check
 * and it is deliberately blunt: the things it forbids have no legitimate use here, and a grep that
 * says why is a better guard than a convention nobody remembers.
 */
final class NoSecondWritePathTest extends TestCase
{
    /** @return list<string> every PHP file in the bundle's src/ */
    private function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testTheBundleHasSourceToCheck(): void
    {
        // A test that silently passes because it found nothing to read is not a test.
        self::assertGreaterThan(15, \count($this->sourceFiles()));
    }

    /**
     * The file's CODE, with every comment and docblock removed.
     *
     * This whole file greps for things that must not appear, and the bundle is heavily commented —
     * including comments that quote the very names being forbidden, because explaining why something
     * is not done means naming it. Matching a docblock would make the check fire on its own
     * explanation, which is both wrong and the fastest way to have it deleted.
     */
    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testTheCommentStripperActuallyStrips(): void
    {
        // The stripper is what every assertion below depends on, so it gets its own check: the
        // abstract controller's docblock names MovementRequest::of() while its code never calls it.
        $abstract = \dirname(__DIR__, 2) . '/src/Controller/Admin/AbstractWarehouseOpsController.php';

        self::assertStringContainsString('MovementRequest::of(', (string) file_get_contents($abstract));
        self::assertStringNotContainsString('MovementRequest::of(', $this->codeOf($abstract));
    }

    public function testNothingInTheBundleWritesADetailRowsQuantity(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $source = $this->codeOf($file);

            // InventoryDetail::setQuantity() is documented as "only StockMovementService should
            // reach this, and only inside a movement". Reaching it from here would move stock
            // without a movement row behind it.
            self::assertDoesNotMatchRegularExpression(
                '/->setQuantity\(/',
                $source,
                sprintf('%s writes a quantity directly; stock changes belong in a MovementRequest.', basename($file)),
            );
        }
    }

    public function testNothingInTheBundleWritesStockInSql(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $source = $this->codeOf($file);

            foreach (['inventory_detail', 'product_inventory', 'order_inventory_reservation'] as $table) {
                self::assertDoesNotMatchRegularExpression(
                    '/(INSERT INTO|UPDATE|DELETE FROM)\s+' . $table . '/i',
                    $source,
                    sprintf('%s writes %s in SQL; that is a second write path.', basename($file), $table),
                );
            }
        }
    }

    /**
     * The reservation ledgers are core's, and core recomputes them by diffing each document's target
     * against what is stored. A write from here is restored on the next flush that touches the
     * document — so it would not even work, quietly, which is the worst kind of not working.
     */
    public function testNothingInTheBundleWritesCoresReservationLedger(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $source = $this->codeOf($file);

            self::assertDoesNotMatchRegularExpression(
                '/new (Order|Invoice)InventoryReservation\(/',
                $source,
                sprintf('%s creates a reservation row; core owns that ledger and recomputes it.', basename($file)),
            );

            foreach (['setSalesHoldQuantity', 'adjustSalesHold', 'setApprovedQuantity', 'setPendingQuantity', 'setCartHoldQuantity'] as $writer) {
                self::assertStringNotContainsString(
                    $writer,
                    $source,
                    sprintf('%s writes a hold bucket; those are claims core maintains, not stock this bundle moves.', basename($file)),
                );
            }
        }
    }

    /**
     * Every stock change in the bundle is a MovementRequest handed to StockMovementService, so the
     * two names travel together. A file that builds a request and never applies it — or applies one
     * it did not build — is worth a second look.
     */
    public function testEveryFileThatBuildsAMovementAlsoHandsItToTheService(): void
    {
        $builders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = $this->codeOf($file);

            if (str_contains($source, 'MovementRequest::of(')) {
                $builders[] = basename($file);
                self::assertStringContainsString(
                    'StockMovementService',
                    $source,
                    sprintf('%s builds a movement request without the service that applies it.', basename($file)),
                );
            }
        }

        self::assertNotEmpty($builders, 'the bundle is supposed to move stock through movement requests');
    }
}
