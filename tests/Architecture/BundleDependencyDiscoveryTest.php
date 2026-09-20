<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;
use PHPUnit\Framework\TestCase;

/**
 * Every bundle that hard-imports another bundle's classes declares it via
 * {@see RequiresBundlesInterface}, or is named here with a reason (#788).
 *
 * ## Why this discovers its subjects instead of listing them
 *
 * #788 exists because exactly this kind of coupling went undeclared for a year: WarehouseOpsBundle
 * hard-imports InventoryDepthBundle in 22 files and nothing said so anywhere, so a tech-support
 * user could switch Inventory Depth off while Warehouse Operations stayed Active. A test that
 * lists "WarehouseOpsBundle imports InventoryDepthBundle" as its one known case would pass forever
 * while the NEXT such coupling arrives exactly the same way, undeclared. So the subjects come from
 * scanning every bundle's own `src` directory under `modules/` for `use <OtherBundle>\…` — the
 * same shape of import that hid this the first time — and a bundle that starts importing another
 * one tomorrow is a subject the moment it does, whether or not this file was touched.
 *
 * ## What counts as a dependency worth declaring
 *
 * A `use` import at the top of a class under `modules/<Bundle>/src` naming another bundle's
 * namespace, EXCLUDING imports of the bundle's own namespace (recursion) and core (`App\…`, never
 * a bundle). Every pair found either has the importing bundle's descriptor declare the imported
 * bundle as required, or is named in {@see self::EXCLUDED} with a reason and the same argument
 * repeated in the importing class's own docblock — the same discipline
 * {@see EveryDocumentDeclaresItsContractTest} uses for CommercialDocument.
 */
final class BundleDependencyDiscoveryTest extends TestCase
{
    /**
     * Real imports examined and found to be soft integrations, not a bundle #788 should refuse to
     * activate/deactivate around. Keyed `'ImportingBundle:ImportedBundle'`.
     *
     * @var array<string, string>
     */
    private const EXCLUDED = [
        'ProcurementBundle:BarcodeBundle' =>
            'ReceivingController only injects BarcodeBundle\Barcode\ProductLookup as a scan-assist'
            . ' utility for typing a SKU faster during receiving — it is not gated behind Barcode'
            . ' being Active anywhere, the lookup service keeps working (querying whatever barcode'
            . ' data exists) whether or not Barcode is switched on, and receiving itself never'
            . ' checks the bundle\'s status',
        'ProcurementBundle:TaxBundle' =>
            'TaxBundle is the tax-calculator RESOLVER bundle (TaxCalculatorResolver, discovering'
            . ' concrete Tax* bundles via a tagged_iterator, the reverse direction), not an'
            . ' independently toggleable app: it has no BundleDescriptorInterface, no row on App'
            . ' Management, and no way for anyone to ever switch it off, the same footing as core'
            . ' (App) in BundleStatusRepository::CORE_SOURCE — a source nothing can activate cannot'
            . ' be a requirement',
        'WarehouseOpsBundle:BarcodeBundle' =>
            'Barcode integration here is the same scan-assist shape as ProcurementBundle\'s (see'
            . ' that entry): existing coverage (AdminBundleMenuGroupsCest, LabelEncodesTheProducts'
            . 'BarcodeCest\'s test-time deactivation) already exercises WarehouseOps screens with'
            . ' Barcode Inactive and nothing 404s',
    ];

    public function testEveryCrossBundleImportIsDeclaredOrArgued(): void
    {
        $offenders = [];

        foreach ($this->crossBundleImports() as $importingBundle => $importedBundles) {
            $required = $this->declaredRequirements($importingBundle);

            foreach ($importedBundles as $importedBundle) {
                $key = $importingBundle . ':' . $importedBundle;

                if (in_array($importedBundle, $required, true) || isset(self::EXCLUDED[$key])) {
                    continue;
                }

                $offenders[] = $key;
            }
        }

        sort($offenders);

        self::assertSame([], $offenders, sprintf(
            "These bundles import another bundle's classes without declaring the dependency:\n  %s\n\n"
            . "If the import is load-bearing (the importing bundle cannot run correctly without the"
            . " other one Active — an entity association, a hard constructor dependency that gates a"
            . " real feature), implement RequiresBundlesInterface::getRequiredBundles() on the"
            . " importing bundle's descriptor.\n\n"
            . "If it is a soft integration (a utility that degrades gracefully, or the imported"
            . " bundle can never itself be deactivated), add 'Importer:Imported' to EXCLUDED above"
            . " with a sentence saying why it is safe, and put the same argument in the importing"
            . " class's own docblock.",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * An exclusion that stopped being true is as bad as a missing one — the same reasoning as
     * {@see EveryDocumentDeclaresItsContractTest::testTheArguedExceptionsAreStillExceptions()}.
     */
    public function testTheExclusionsAreStillTrue(): void
    {
        $imports = $this->crossBundleImports();

        foreach (self::EXCLUDED as $key => $why) {
            self::assertNotSame('', trim($why), sprintf('The exclusion for %s has no reason beside it.', $key));

            [$importingBundle, $importedBundle] = explode(':', $key, 2);

            // Both bundles installed and the import genuinely still there — an exclusion for a
            // deleted bundle or a since-removed import describes nothing and should be removed.
            if (!is_dir($this->root() . '/modules/' . $importingBundle) || !is_dir($this->root() . '/modules/' . $importedBundle)) {
                continue;
            }

            self::assertContains($importedBundle, $imports[$importingBundle] ?? [], sprintf(
                '%s no longer imports %s — remove this exclusion rather than leaving it to describe'
                . ' an import that is not there any more.',
                $importingBundle,
                $importedBundle,
            ));

            self::assertNotContains($importedBundle, $this->declaredRequirements($importingBundle), sprintf(
                '%s now DECLARES %s as required, so the exclusion recorded here — "%s" — is out of'
                . ' date. Remove it from EXCLUDED.',
                $importingBundle,
                $importedBundle,
                $why,
            ));
        }
    }

    /** Guards the discovery itself, the same reasoning as EveryDocumentDeclaresItsContractTest's own guard. */
    public function testTheDiscoveryFindsSomethingToCheck(): void
    {
        $imports = $this->crossBundleImports();

        self::assertNotEmpty($imports, 'No cross-bundle imports discovered at all — either every coupling this test exists to catch has genuinely been removed, or the `use <Bundle>\\…` scan broke.');
    }

    /** @return array<string, list<string>> importing bundle source => imported bundle sources */
    private function crossBundleImports(): array
    {
        $modulesDir = $this->root() . '/modules';
        $bundleDirs = glob($modulesDir . '/*', GLOB_ONLYDIR) ?: [];

        $imports = [];
        foreach ($bundleDirs as $bundleDir) {
            $bundle = basename($bundleDir);
            $srcDir = $bundleDir . '/src';

            if (!is_dir($srcDir)) {
                continue;
            }

            $found = [];
            foreach ($this->phpFilesUnder($srcDir) as $file) {
                foreach (file($file) ?: [] as $line) {
                    if (preg_match('/^use\s+([A-Za-z0-9]+Bundle)\\\\/', $line, $m) === 1 && $m[1] !== $bundle) {
                        $found[$m[1]] = true;
                    }
                }
            }

            if ($found !== []) {
                $imports[$bundle] = array_keys($found);
            }
        }

        return $imports;
    }

    /** @return list<string> */
    private function declaredRequirements(string $bundle): array
    {
        foreach ($this->descriptorClasses() as $class) {
            /** @var BundleDescriptorInterface $descriptor */
            $descriptor = new $class();

            if ($descriptor->getSource() === $bundle && $descriptor instanceof RequiresBundlesInterface) {
                return $descriptor->getRequiredBundles();
            }
        }

        return [];
    }

    /** @return list<class-string<BundleDescriptorInterface>> */
    private function descriptorClasses(): array
    {
        $classes = [];
        foreach (glob($this->root() . '/modules/*/src/Bundle/*BundleDescriptor.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (
                preg_match('/^namespace\s+([^;]+);/m', $contents, $ns) === 1
                && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $contents, $cls) === 1
            ) {
                $class = $ns[1] . '\\' . $cls[1];

                if (is_a($class, BundleDescriptorInterface::class, true)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
