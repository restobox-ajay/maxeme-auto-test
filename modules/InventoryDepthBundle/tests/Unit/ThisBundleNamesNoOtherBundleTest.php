<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * This bundle survives its neighbours being deleted, asserted rather than remembered (#8).
 *
 * ## Why a source scan and not a behavioural test
 *
 * The failure it guards against cannot be reached from inside a running suite: every module is
 * installed here, so a `use ProcurementBundle\...` or an `@Procurement/...` include works perfectly
 * until somebody deletes the folder in production — at which point `config/bundles.php`'s `glob()`
 * simply stops matching it, the Twig namespace and its routes go with it, and an INVENTORY screen
 * 500s over a purchasing module it never needed. WarehouseOpsBundle's NoSecondWritePathTest greps
 * the source for the same reason: a behavioural test can only prove today's paths are clean.
 *
 * The shared product field is what made this worth pinning. It was written in ProcurementBundle,
 * and the obvious way to put it on the lots and reorder-level screens was to include that bundle's
 * partial — which would have been exactly this failure, on the one screen that already announces
 * in a banner that it still works with purchasing switched off.
 *
 * ## What is allowed, and why
 *
 * Prose. Several files here discuss ProcurementBundle at length — where `incoming_quantity` comes
 * from, why a reorder level is not a purchasing setting — and that reasoning is worth more than the
 * uniformity of banning the word. What is banned is a CODE reference: an import of one of its
 * classes, or an include of one of its templates. The one legitimate way this bundle asks about its
 * neighbour is by NAME, as a string, through `BundleStatusRepository::isActive()` and the router —
 * both of which answer "no" instead of exploding when the answer is that it is gone.
 */
final class ThisBundleNamesNoOtherBundleTest extends TestCase
{
    /**
     * Every other first-party module, discovered the way the kernel discovers them.
     *
     * Enumerated rather than listed: a test naming ProcurementBundle alone would pass forever while
     * somebody imported WarehouseOpsBundle instead.
     *
     * @return list<string>
     */
    private function otherModules(): array
    {
        $dirs = glob($this->root() . '/modules/*', \GLOB_ONLYDIR) ?: [];

        $names = array_values(array_filter(
            array_map('basename', $dirs),
            static fn (string $name): bool => $name !== 'InventoryDepthBundle',
        ));

        self::assertNotEmpty($names, 'no sibling modules found at all, so this test is asserting nothing');

        return $names;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $found = [];

        foreach (['/src', '/templates'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . '/modules/InventoryDepthBundle' . $dir),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $found[] = $file->getPathname();
                }
            }
        }

        self::assertNotEmpty($found, 'no source files found, so this test is asserting nothing');

        return $found;
    }

    public function testNoPhpFileImportsAnotherModulesClass(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($this->otherModules() as $module) {
                if (preg_match('/^use\s+' . preg_quote($module, '/') . '\\\\/m', $source) === 1) {
                    $offenders[] = sprintf('%s imports a %s class', basename($path), $module);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", [
            'This bundle must keep working when another module is deleted — modules are discovered',
            'by glob() in config/bundles.php, so a deleted folder takes its classes with it and an',
            'import of one is a fatal error on a screen that never needed it. Ask by name through',
            'BundleStatusRepository::isActive() instead, or move the shared thing into core:',
            ...$offenders,
        ]));
    }

    public function testNoTemplateIncludesAnotherModulesTemplate(): void
    {
        $offenders = [];
        $namespaces = array_map(
            // '@Procurement' — the Twig namespace a module registers is its name without the suffix.
            static fn (string $module): string => '@' . preg_replace('/Bundle$/', '', $module),
            $this->otherModules(),
        );

        foreach ($this->sourceFiles() as $path) {
            if (!str_ends_with($path, '.twig')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($namespaces as $namespace) {
                if (str_contains($source, $namespace . '/')) {
                    $offenders[] = sprintf('%s includes a %s template', basename($path), $namespace);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", [
            'A deleted module takes its Twig namespace and its routes with it, so an include of its',
            'partial — or a path() to one of its routes — is a 500 on a screen that has nothing to do',
            'with it. The shared product field lives in core for exactly this reason:',
            ...$offenders,
        ]));
    }

    private function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
