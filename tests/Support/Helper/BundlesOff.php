<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use App\Entity\BundleStatus;
use Codeception\Module;

/**
 * Marks the optional inventory bundles Inactive for every test in the run (#562).
 *
 * Enabled only by the `bundles-off` environment, so the ordinary suite is untouched.
 *
 * ## Why this exists rather than a second copy of each Cest
 *
 * The claim being tested is "the original app behaves identically with these bundles off". That is
 * a property of the whole suite, not of any one scenario, and it cannot be asserted from inside a
 * test: a Cest can only check what it thought to look at. Running the SAME assertions against a
 * differently-configured application is the only thing that actually proves it.
 *
 * Two hand-written versions of each Cest would also drift. The off-version would quietly lose an
 * assertion the on-version gained, and nothing would notice — the failure mode being avoided is
 * precisely a difference nobody is watching for.
 *
 * ## Why bundles default Active, and why that is the hole
 *
 * BundleStatusRepository::ensureBySource() creates an unknown bundle as STATUS_ACTIVE. So the
 * ordinary suite exercises all three of these ON, and proves nothing about OFF. Before this
 * environment existed, the only coverage was InventoryDepthCest asserting one route 404s.
 */
final class BundlesOff extends Module
{
    /**
     * The optional bundles that sit on the inventory path.
     *
     * Deliberately a literal list rather than "everything under modules/". This environment makes
     * a specific claim — that the pre-#550 application is unchanged — and that claim is about
     * these three. Switching off every bundle in the repo would also switch off things the core
     * suite depends on (CartHoldBundle backs cart holds, Number1ProductImportBundle backs the
     * import screens), and the resulting red would say nothing about the property under test.
     */
    public const SOURCES = [
        'InventoryDepthBundle',
        'WarehouseOpsBundle',
        'ProcurementBundle',
        // #607. Barcodes belong on this list for the same reason the other three do: with it off,
        // a scanned value resolves by `product_core.sku` alone and a product label encodes the SKU
        // — which is exactly what happened before `product_barcode` existed. BarcodeDirectory is
        // the one place that answer is produced, and this proves it is honoured everywhere.
        'BarcodeBundle',
    ];

    /**
     * Once, before the suite — not per test.
     *
     * Bundle status is run-level configuration, not fixture data, so this is the right hook on
     * meaning alone. It is also the only one that works, and the reason is worth recording.
     *
     * The Doctrine module wraps each test in a transaction it rolls back afterwards. A row written
     * inside that transaction is uncommitted, and the kernel serving `amOnPage()` reads through its
     * own connection, which cannot see it. So a per-test write produced a row that the test could
     * read back and the application could not — the gate would have passed while exercising the
     * ordinary application with every bundle still Active.
     *
     * Writing before the suite puts the row in place before any transaction opens, committed, so
     * every connection sees it and no rollback removes it.
     *
     * This was caught only because BundlesOffEnvironmentCest asserts the APPLICATION treats the
     * bundles as absent rather than trusting the row to mean something.
     */
    public function _beforeSuite(array $settings = []): void
    {
        $em = $this->getModule('Doctrine')->_getEntityManager();
        $repository = $em->getRepository(BundleStatus::class);

        foreach (self::SOURCES as $source) {
            $status = $repository->findOneBy(['source' => $source]);

            if (!$status instanceof BundleStatus) {
                $status = (new BundleStatus())->setSource($source);
                $em->persist($status);
            }

            $status->setStatus(BundleStatus::STATUS_INACTIVE);
        }

        $em->flush();
    }
}
